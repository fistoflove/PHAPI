<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Auth\AuthManager;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Services\HttpClient;
use PHAPI\Services\JobsManager;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\RedisClient;
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
     * @param SwooleDriver|null $driver
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
        ?SwooleDriver $driver,
        ?callable $webSocketHandler
    ): void {
        $container->set(PHAPI::class, $app);
        $container->set(TaskRunner::class, $taskRunner);
        $container->set(HttpClient::class, $httpClient);
        $container->set(AuthManager::class, $auth);
        $container->set('auth', $auth);
        $container->singleton(RedisClient::class, static function () use ($app) {
            return $app->redis();
        });
        $container->singleton(MySqlPool::class, static function () use ($app) {
            return $app->mysql();
        });

        $this->authConfigurator->registerMiddleware($middleware, $auth);

        $container->set(Realtime::class, new RealtimeManager($driver));

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
