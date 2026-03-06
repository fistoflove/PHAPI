<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHAPI\Services\HttpClient;

/**
 * Decorator that wraps an HttpClient to create CLIENT spans for every
 * outbound HTTP call, and injects W3C traceparent/tracestate headers
 * for distributed trace propagation.
 */
final class TracingHttpClient implements HttpClient
{
    private HttpClient $inner;
    private TracerInterface $tracer;
    private TraceContextPropagator $propagator;

    public function __construct(HttpClient $inner, TracerInterface $tracer, TraceContextPropagator $propagator)
    {
        $this->inner = $inner;
        $this->tracer = $tracer;
        $this->propagator = $propagator;
    }

    /**
     * @param string $url
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->traced('GET', $url, function (array $h) use ($url, $headers): array {
            return $this->inner->getJson($url, array_merge($headers, $h));
        });
    }

    /**
     * @param string $url
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function getJsonWithMeta(string $url, array $headers = []): array
    {
        /** @var array{data: array<string, mixed>|null, status: int, body: string} */
        return $this->tracedWithMeta('GET', $url, function (array $h) use ($url, $headers): array {
            return $this->inner->getJsonWithMeta($url, array_merge($headers, $h));
        });
    }

    /**
     * @param string $url
     * @param array<string, scalar|null> $form
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postFormWithMeta(string $url, array $form, array $headers = []): array
    {
        /** @var array{data: array<string, mixed>|null, status: int, body: string} */
        return $this->tracedWithMeta('POST', $url, function (array $h) use ($url, $form, $headers): array {
            return $this->inner->postFormWithMeta($url, $form, array_merge($headers, $h));
        });
    }

    /**
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        return $this->traced('POST', $url, function (array $h) use ($url, $data, $headers): array {
            return $this->inner->postJson($url, $data, array_merge($headers, $h));
        });
    }

    /**
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postJsonWithMeta(string $url, array $data, array $headers = []): array
    {
        /** @var array{data: array<string, mixed>|null, status: int, body: string} */
        return $this->tracedWithMeta('POST', $url, function (array $h) use ($url, $data, $headers): array {
            return $this->inner->postJsonWithMeta($url, $data, array_merge($headers, $h));
        });
    }

    /**
     * Execute an HTTP call inside a CLIENT span (for methods returning plain data).
     *
     * @param callable(array<string, string>): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function traced(string $method, string $url, callable $call): array
    {
        $parsedUrl = parse_url($url);
        $peerName = \is_array($parsedUrl) ? ($parsedUrl['host'] ?? 'unknown') : 'unknown';

        $span = $this->tracer->spanBuilder('HTTP ' . $method)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $url)
            ->setAttribute('net.peer.name', $peerName)
            ->startSpan();

        $scope = $span->activate();

        try {
            $traceHeaders = $this->injectTraceContext();

            /** @var array<string, mixed> $result */
            $result = $call($traceHeaders);

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Execute an HTTP call inside a CLIENT span (for methods returning meta with status).
     *
     * @param callable(array<string, string>): array{data: array<string, mixed>|null, status: int, body: string} $call
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    private function tracedWithMeta(string $method, string $url, callable $call): array
    {
        $parsedUrl = parse_url($url);
        $peerName = \is_array($parsedUrl) ? ($parsedUrl['host'] ?? 'unknown') : 'unknown';

        $span = $this->tracer->spanBuilder('HTTP ' . $method)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $url)
            ->setAttribute('net.peer.name', $peerName)
            ->startSpan();

        $scope = $span->activate();

        try {
            $traceHeaders = $this->injectTraceContext();

            $result = $call($traceHeaders);

            $span->setAttribute('http.status_code', $result['status']);
            if ($result['status'] >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Inject W3C traceparent/tracestate into an outbound header array.
     *
     * @return array<string, string>
     */
    private function injectTraceContext(): array
    {
        /** @var array<string, string> $carrier */
        $carrier = [];
        $this->propagator->inject($carrier);

        return $carrier;
    }
}
