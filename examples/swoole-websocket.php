<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api = new PHAPI([
    'runtime' => 'swoole',
    'enable_websockets' => true,
]);

$api->setWebSocketHandler(function ($server, $frame, $driver): void {
    $payload = json_decode($frame->data ?? '', true);
    if (!is_array($payload)) {
        return;
    }

    if (($payload['action'] ?? '') === 'subscribe') {
        $driver->subscribe($frame->fd, (string)($payload['channel'] ?? ''));
    }

    if (($payload['action'] ?? '') === 'unsubscribe') {
        $driver->unsubscribe($frame->fd, (string)($payload['channel'] ?? ''));
    }
});

$api->get('/broadcast', function (): Response {
    PHAPI::app()?->realtime()->broadcast('updates', ['ok' => true]);
    return Response::json(['sent' => true]);
});

$api->run();
