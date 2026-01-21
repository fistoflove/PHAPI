<?php

use PHAPI\Examples\MultiRuntime\Controllers\StatusController;
use PHAPI\Examples\MultiRuntime\Services\ExternalService;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api->get('/', function (): Response {
    return Response::json(['message' => 'Multi-runtime example']);
});

$api->get('/status', [StatusController::class, 'show']);

$api->get('/tasks', function (): Response {
    $results = PHAPI::app()?->tasks()->parallel([
        'first' => fn() => ['ok' => true],
        'second' => fn() => ['count' => 2],
    ]);
    return Response::json(['results' => $results]);
});

$api->get('/fetch', function () use ($api): Response {
    $service = $api->container()->get(ExternalService::class);
    try {
        return Response::json(['data' => $service->fetch()]);
    } catch (\Throwable $e) {
        return Response::error('Upstream error', 502, ['status' => $e->getCode()]);
    }
});

$api->get('/broadcast', function (): Response {
    PHAPI::app()?->realtime()->broadcast('updates', ['ok' => true]);
    return Response::json(['sent' => true]);
});

$api->get('/jobs', function (): Response {
    return Response::json(['jobs' => PHAPI::app()?->jobLogs()]);
});
