<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHAPI\Core\Container;
use PHAPI\Core\ServiceProviderInterface;
use PHAPI\PHAPI;
use PHAPI\Services\HttpClient;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Services\RedisClient;

final class TracingServiceProvider implements ServiceProviderInterface
{
    private ?TracerProvider $tracerProvider = null;

    public function register(Container $container, PHAPI $app): void
    {
        /** @var array{enabled?: bool, service_name?: string, service_version?: string, exporter_endpoint?: string} $telemetryConfig */
        $telemetryConfig = $app->config()['telemetry'] ?? [];

        if (!$this->isEnabled($telemetryConfig)) {
            return;
        }

        $this->installSwooleContextStorage();

        $serviceName = $telemetryConfig['service_name'] ?? 'phapi';
        $serviceVersion = $telemetryConfig['service_version'] ?? '0.0.0';
        $endpoint = $telemetryConfig['exporter_endpoint'] ?? 'http://localhost:4318';

        $resource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $serviceName,
            ResourceAttributes::SERVICE_VERSION => $serviceVersion,
        ]));

        $transport = (new OtlpHttpTransportFactory())->create(
            $endpoint . '/v1/traces',
            'application/x-protobuf'
        );

        $exporter = new SpanExporter($transport);

        $this->tracerProvider = new TracerProvider(
            new BatchSpanProcessor($exporter, ClockFactory::getDefault()),
            null,
            $resource
        );

        $tracer = $this->tracerProvider->getTracer($serviceName, $serviceVersion);

        $container->set(TracerProviderInterface::class, $this->tracerProvider);
        $container->set(TracerInterface::class, $tracer);
    }

    public function boot(PHAPI $app): void
    {
        /** @var array{enabled?: bool} $telemetryConfig */
        $telemetryConfig = $app->config()['telemetry'] ?? [];

        if (!$this->isEnabled($telemetryConfig)) {
            return;
        }

        $container = $app->container();
        $tracer = $container->get(TracerInterface::class);
        \assert($tracer instanceof TracerInterface);
        $propagator = TraceContextPropagator::getInstance();

        // Install root span middleware as the first global middleware.
        $app->middleware(new TracingMiddleware($tracer, $propagator));

        // Wrap the HttpClient with tracing decorator.
        $innerHttp = $container->get(HttpClient::class);
        \assert($innerHttp instanceof HttpClient);
        $tracingHttp = new TracingHttpClient($innerHttp, $tracer, $propagator);
        $container->set(HttpClient::class, $tracingHttp);

        // Replace MySqlPool singleton with tracing decorator.
        $container->singleton(MySqlPool::class, static function () use ($app, $tracer): TracingMySqlPool {
            return new TracingMySqlPool($app->services()->mysql(), $tracer);
        });

        // Replace RedisClient singleton with tracing decorator.
        $container->singleton(RedisClient::class, static function () use ($app, $tracer): TracingRedisClient {
            return new TracingRedisClient($app->services()->redis(), $tracer);
        });

        // Replace OpenFgaClient singleton with tracing decorator.
        $container->singleton(OpenFgaClient::class, static function () use ($app, $tracer): TracingOpenFgaClient {
            return new TracingOpenFgaClient($app->services()->openfga(), $tracer);
        });

        // Flush spans on shutdown.
        $tracerProvider = $this->tracerProvider;
        if ($tracerProvider !== null) {
            $app->onShutdown(static function () use ($tracerProvider): void {
                $tracerProvider->shutdown();
            });
        }
    }

    private function installSwooleContextStorage(): void
    {
        $inner = Context::storage();
        Context::setStorage(new SwooleContextStorage($inner));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isEnabled(array $config): bool
    {
        return (bool) ($config['enabled'] ?? false);
    }
}
