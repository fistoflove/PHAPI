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
    // Boot-time hook (Swoole only).
});

$api->onWorkerStart(function ($server, int $workerId): void {
    // Worker hook (Swoole only).
});

$api->onRequestStart(function (\PHAPI\HTTP\Request $request): void {
    // Request hook (all runtimes).
});

$api->onRequestEnd(function (\PHAPI\HTTP\Request $request, \PHAPI\HTTP\Response $response): void {
    // Request hook (all runtimes).
});

$api->onShutdown(function (): void {
    // Shutdown hook (Swoole only).
});

$runtime = $api->runtime();
if ($runtime->supportsWebSockets() && $runtime instanceof SwooleDriver) {
    $api->spawnProcess(function () {
        return new \Swoole\Process(function ($process) {
            while (true) {
                $process->read();
            }
        }, false, SOCK_STREAM, true);
    }, function (\Swoole\Process $process): void {
        \Swoole\Event::add($process->pipe, function () use ($process) {
            $process->read();
        });
    });

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
