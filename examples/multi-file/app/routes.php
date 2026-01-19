<?php

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api->get('/', function(): Response {
    return Response::json(['message' => 'Multi-file app running']);
});

$api->get('/health', function(): Response {
    $request = PHAPI::request();
    $app = PHAPI::app();
    $start = $request?->server()['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $durationMs = round((microtime(true) - $start) * 1000, 2);

    return Response::json([
        'ok' => true,
        'time' => date('c'),
        'runtime' => $app?->capabilities()->supportsPersistentState() ? 'swoole' : ($app?->capabilities()->supportsAsyncIo() ? 'fpm_amphp' : 'fpm'),
        'response_ms' => $durationMs,
    ]);
});

$api->get('/users/{id}', function(): Response {
    $request = PHAPI::request();
    $app = PHAPI::app();
    return Response::json([
        'user_id' => $request?->param('id'),
        'url' => $app ? $app->url('users.show', ['id' => $request?->param('id')]) : null,
    ]);
})->name('users.show');

$api->get('/search/{query?}', function(): Response {
    $request = PHAPI::request();
    return Response::json([
        'query' => $request?->param('query'),
    ]);
})->name('search');

$api->get('/jobs', function(): Response {
    $app = PHAPI::app();
    return Response::json(['jobs' => $app?->jobLogs() ?? []]);
});

$api->get('/protected', function(): Response {
    return Response::json(['message' => 'Authenticated']);
})->middleware($api->requireAuth());

$api->get('/admin', function(): Response {
    return Response::json(['message' => 'Admin ok']);
})->middleware($api->requireRole('admin'));

$api->get('/manager', function(): Response {
    return Response::json(['message' => 'Manager ok']);
})->middleware('role:manager');

$api->get('/multi-role', function(): Response {
    return Response::json(['message' => 'Admin + Manager ok']);
})->middleware('role_all:admin|manager');

$api->post('/users', function(): Response {
    $request = PHAPI::request();
    return Response::json(['created' => true, 'user' => $request?->body() ?? []], 201);
})->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
]);
