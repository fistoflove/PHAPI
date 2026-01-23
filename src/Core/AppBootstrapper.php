<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Auth\AuthManager;
use PHAPI\Database\DatabaseFacade;
use PHAPI\Exceptions\FeatureNotSupportedException;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Services\FallbackRealtime;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\Realtime;
use PHAPI\Services\RealtimeManager;
use PHAPI\Services\TaskRunner;

final class AppBootstrapper
{
    private AuthConfigurator $authConfigurator;

    public function __construct()
    {
        $this->authConfigurator = new AuthConfigurator();
    }

    /**
     * Register core services into the container and middleware.
     *
     * @param PHAPI $app
     * @param Container $container
     * @param MiddlewareManager $middleware
     * @param JobsManager $jobs
     * @param AuthManager $auth
     * @param TaskRunner $taskRunner
     * @param HttpClient $httpClient
     * @param DriverCapabilities $capabilities
     * @param SwooleDriver|null $driver
     * @param bool $debug
     * @param callable(string, array<string, mixed>): void|null $realtimeFallback
     * @param callable(\Swoole\WebSocket\Server, mixed, SwooleDriver): void|null $webSocketHandler
     * @return void
     */
    public function registerCoreServices(
        PHAPI $app,
        Container $container,
        MiddlewareManager $middleware,
        JobsManager $jobs,
        AuthManager $auth,
        TaskRunner $taskRunner,
        HttpClient $httpClient,
        DriverCapabilities $capabilities,
        ?SwooleDriver $driver,
        bool $debug,
        ?callable $realtimeFallback,
        ?callable $webSocketHandler
    ): void {
        $container->set(PHAPI::class, $app);
        $container->set(TaskRunner::class, $taskRunner);
        $container->set(HttpClient::class, $httpClient);
        $container->set(AuthManager::class, $auth);
        $container->set('auth', $auth);

        $this->authConfigurator->registerMiddleware($middleware, $auth);

        $fallback = new FallbackRealtime($debug, $realtimeFallback);
        $container->set(Realtime::class, new RealtimeManager(
            $capabilities,
            $driver,
            $fallback
        ));

        if ($driver instanceof SwooleDriver && $webSocketHandler !== null) {
            $driver->setWebSocketHandler($webSocketHandler);
        }
    }

    /**
     * Register safety middleware based on config.
     *
     * @param MiddlewareManager $middleware
     * @param array<string, mixed> $config
     * @return void
     */
    public function registerSafetyMiddleware(MiddlewareManager $middleware, array $config): void
    {
        $maxBody = $config['max_body_bytes'] ?? null;
        if ($maxBody !== null) {
            $limit = (int)$maxBody;
            $middleware->addGlobalMiddleware(static function ($request, $next) use ($limit) {
                $length = $request->contentLength();
                if ($length !== null && $length > $limit) {
                    return Response::error('Payload too large', 413, [
                        'max_bytes' => $limit,
                        'received_bytes' => $length,
                    ]);
                }
                return $next($request);
            });
        }
    }

    /**
     * Configure optional database integration.
     *
     * @param array<string, mixed> $config
     * @param \PHAPI\Runtime\RuntimeInterface $runtime
     * @return void
     */
    public function configureDatabase(array $config, \PHAPI\Runtime\RuntimeInterface $runtime): void
    {
        $database = $config['database'] ?? null;
        if ($database === null) {
            return;
        }

        $driver = 'sqlite';
        if (is_array($database) && isset($database['driver'])) {
            $driver = (string)$database['driver'];
        }

        if ($driver === 'turso') {
            if (!$runtime instanceof SwooleDriver) {
                throw new FeatureNotSupportedException('Turso driver requires Swoole runtime.');
            }

            if (!class_exists(\Swoole\Coroutine\Http\Client::class)) {
                throw new FeatureNotSupportedException('Turso driver requires the Swoole HTTP client.');
            }

            $url = is_array($database) ? ($database['turso_url'] ?? $database['url'] ?? null) : null;
            $token = is_array($database) ? ($database['turso_token'] ?? $database['token'] ?? null) : null;
            if (!is_string($url) || $url === '' || !is_string($token) || $token === '') {
                throw new \RuntimeException('Turso configuration requires url and token.');
            }

            $options = is_array($database) ? ($database['options'] ?? []) : [];
            DatabaseFacade::configureTurso($url, $token, is_array($options) ? $options : []);
            return;
        }

        $path = is_array($database) ? ($database['path'] ?? null) : $database;
        if (!is_string($path) || $path === '') {
            return;
        }

        if (!class_exists(\PDO::class) || !extension_loaded('pdo_sqlite')) {
            return;
        }

        $options = [];
        if (is_array($database)) {
            $options = $database['options'] ?? [];
        }

        DatabaseFacade::configure($path, is_array($options) ? $options : []);
    }

    /**
     * Register Swoole job timers.
     *
     * @param JobsManager $jobs
     * @param SwooleDriver|null $driver
     * @param callable(callable(mixed ...$args): mixed): array{result: mixed, output: string} $executor
     * @return void
     */
    public function registerSwooleJobs(JobsManager $jobs, ?SwooleDriver $driver, callable $executor): void
    {
        if ($driver === null) {
            return;
        }

        $registered = $jobs->jobs();
        if ($registered === []) {
            return;
        }

        $driver->onWorkerStart(function ($server, int $workerId) use ($registered, $jobs, $executor) {
            if ($workerId !== 0) {
                return;
            }

            foreach ($registered as $name => $job) {
                $intervalMs = $job['interval'] * 1000;
                \Swoole\Timer::tick($intervalMs, function () use ($jobs, $executor, $name) {
                    $jobs->runScheduled($name, function (callable $handler) use ($executor) {
                        return $executor($handler);
                    });
                });
            }
        });
    }
}
