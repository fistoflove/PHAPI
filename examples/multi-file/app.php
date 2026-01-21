<?php

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\PHAPI;
use PHAPI\Core\Container;

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'fpm',
    'host' => '0.0.0.0',
    'port' => 9503,
    'debug' => true,
    'max_body_bytes' => 1024 * 1024,
    'access_logger' => function ($request, $response, array $meta) {
        $line = sprintf(
            '[%s] %s %s %d %sms %s',
            date('c'),
            $request->method(),
            $request->path(),
            $response->status(),
            $meta['duration_ms'],
            $meta['request_id']
        );
        error_log($line);
    },
    'auth' => [
        'default' => 'token',
        'token_resolver' => function (string $token) {
            if ($token === 'test-token') {
                return ['id' => 1, 'roles' => ['admin']];
            }
            return null;
        },
        'session_key' => 'user',
    ],
]);

$api->enableCORS();
$api->enableSecurityHeaders();
$api->container()->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
$api->extend('greeting', function (Container $container): string {
    return 'Hello from PHAPI';
});
$api->onBoot(function (): void {
    // Boot-time hook for runtime initialization.
});
$api->onWorkerStart(function ($server, int $workerId): void {
    // Swoole-only hook for per-worker setup.
});
$api->onShutdown(function (): void {
    // Cleanup resources.
});
$api->loadApp(__DIR__ . '/..');

$api->run();
