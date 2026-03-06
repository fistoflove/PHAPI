<?php

use PHAPI\Examples\FullStack\Controllers\StatusController;
use PHAPI\Examples\FullStack\Services\ExternalService;
use PHAPI\HTTP\Response;

$api->get('/', function (): Response {
    return Response::json(['message' => 'Example app']);
});

$api->get('/status', [StatusController::class, 'show']);

$api->get('/tasks', function () use ($api): Response {
    $results = $api->services()->tasks()->parallel([
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

$api->get('/broadcast', function () use ($api): Response {
    $api->services()->realtime()->broadcast('updates', ['ok' => true]);
    return Response::json(['sent' => true]);
});

$api->get('/redis', function () use ($api): Response {
    $redis = $api->services()->redis();

    try {
        $redis->set('phapi:hello', 'world', 30);
        $value = $redis->get('phapi:hello');
        return Response::json(['value' => $value]);
    } catch (\Throwable $e) {
        return Response::error('Redis error', 500, ['message' => $e->getMessage()]);
    }
});

$api->get('/mysql', function () use ($api): Response {
    $mysql = $api->services()->mysql();

    try {
        $rows = $mysql->query('SELECT 1 AS ok');
        return Response::json(['rows' => $rows]);
    } catch (\Throwable $e) {
        return Response::error('MySQL error', 500, ['message' => $e->getMessage()]);
    }
});

$api->get('/jobs', function () use ($api): Response {
    return Response::json(['jobs' => $api->jobLogs()]);
});
