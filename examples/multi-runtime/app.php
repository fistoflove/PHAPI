<?php

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\PHAPI;
use PHAPI\Examples\MultiRuntime\Providers\AppServiceProvider;
use PHAPI\Runtime\SwooleDriver;

spl_autoload_register(function (string $class): void {
    $prefix = 'PHAPI\\Examples\\MultiRuntime\\';
    $baseDir = __DIR__ . '/app/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'fpm',
    'host' => '0.0.0.0',
    'port' => 9503,
    'debug' => true,
    'max_body_bytes' => 1024 * 1024,
    'providers' => [
        AppServiceProvider::class,
    ],
]);

$api->enableSecurityHeaders();

$api->onBoot(function (): void {
    // Warm caches or initialize resources.
});

$api->onWorkerStart(function ($server, int $workerId): void {
    // Worker-specific setup in Swoole.
});

$api->onShutdown(function (): void {
    // Cleanup resources.
});

$runtime = $api->runtime();
if ($runtime->supportsWebSockets() && $runtime instanceof SwooleDriver) {
    $api->setWebSocketHandler(function ($server, $frame, $driver): void {
        $payload = json_decode($frame->data ?? '', true);
        if (!is_array($payload)) {
            return;
        }

        if (($payload['action'] ?? '') === 'subscribe' && !empty($payload['channel'])) {
            $driver->subscribe($frame->fd, (string)$payload['channel']);
        }
    });
}

$api->loadApp(__DIR__);

$api->run();
