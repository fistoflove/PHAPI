<?php

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\PHAPI;
use PHAPI\Core\Container;

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'swoole',
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
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'auth' => getenv('REDIS_AUTH') ?: null,
        'db' => getenv('REDIS_DB') !== false ? (int)getenv('REDIS_DB') : null,
        'timeout' => getenv('REDIS_TIMEOUT') !== false ? (float)getenv('REDIS_TIMEOUT') : 1.0,
    ],
    'mysql' => [
        'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
        'user' => getenv('MYSQL_USER') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'database' => getenv('MYSQL_DATABASE') ?: '',
        'charset' => getenv('MYSQL_CHARSET') ?: 'utf8mb4',
        'timeout' => getenv('MYSQL_TIMEOUT') !== false ? (float)getenv('MYSQL_TIMEOUT') : 1.0,
    ],
]);

$api->enableCORS();
$api->enableSecurityHeaders();
$api->container()->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
$api->extend('greeting', function (Container $container): string {
    return 'Hello from PHAPI';
});
$api->onBoot(function (): void {
    // Boot-time hook (Swoole only).
});
$api->onWorkerStart(function ($server, int $workerId): void {
    // Worker hook (Swoole only).
});
$api->onRequestStart(function (\PHAPI\HTTP\Request $request): void {
    // Request hook.
});
$api->onRequestEnd(function (\PHAPI\HTTP\Request $request, \PHAPI\HTTP\Response $response): void {
    // Request hook.
});
$api->onShutdown(function (): void {
    // Shutdown hook (Swoole only).
});
$api->loadApp(__DIR__ . '/..');

$api->run();
