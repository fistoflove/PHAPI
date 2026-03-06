<?php

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

$api->get('/', function (): Response {
    return Response::json(['message' => 'Multi-file app running']);
});

$api->get('/users/{id}', function (Request $request) use ($api): Response {
    return Response::json([
        'user_id' => $request->param('id'),
        'url' => $api->url('users.show', ['id' => $request->param('id')]),
    ]);
})->name('users.show');

$api->get('/search/{query?}', function (Request $request): Response {
    return Response::json([
        'query' => $request->param('query'),
    ]);
})->name('search');

$api->get('/runtime', function () use ($api): Response {
    $runtime = $api->runtime();

    return Response::json([
        'runtime' => $runtime->name(),
        'async_io' => $runtime->capabilities()->supportsAsyncIo(),
        'websockets' => $runtime->supportsWebSockets(),
        'streaming' => $runtime->capabilities()->supportsStreamingResponses(),
        'persistent_state' => $runtime->capabilities()->supportsPersistentState(),
        'long_running' => $runtime->isLongRunning(),
    ]);
});

$api->get('/time', function () use ($api): Response {
    $clock = $api->container()->get(\DateTimeInterface::class);
    return Response::json(['now' => $clock->format(DATE_ATOM)]);
});

$api->get('/plugin', function () use ($api): Response {
    $message = $api->container()->get('greeting');
    return Response::json(['message' => $message]);
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

$api->get('/protected', function (): Response {
    return Response::json(['message' => 'Authenticated']);
})->middleware($api->requireAuth());

$api->get('/admin', function (): Response {
    return Response::json(['message' => 'Admin ok']);
})->middleware($api->requireRole('admin'));

$api->get('/manager', function (): Response {
    return Response::json(['message' => 'Manager ok']);
})->middleware('role:manager');

$api->get('/multi-role', function (): Response {
    return Response::json(['message' => 'Admin + Manager ok']);
})->middleware('role_all:admin|manager');

$api->post('/users', function (Request $request): Response {
    return Response::json(['created' => true, 'user' => $request->body() ?? []], 201);
})->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
]);
