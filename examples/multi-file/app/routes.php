<?php

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Examples\MultiFile\Controllers\UserController;

$api->get('/', function(): Response {
    return Response::json(['message' => 'Multi-file app running']);
});

$api->get('/users/{id}', function(): Response {
    $request = PHAPI::request();
    $app = PHAPI::app();
    return Response::json([
        'user_id' => $request?->param('id'),
        'url' => $app ? $app->url('users.show', ['id' => $request?->param('id')]) : null,
    ]);
})->name('users.show');

$api->get('/users', [UserController::class, 'index']);

$api->get('/search/{query?}', function(): Response {
    $request = PHAPI::request();
    return Response::json([
        'query' => $request?->param('query'),
    ]);
})->name('search');

$api->get('/runtime', function(): Response {
    $app = PHAPI::app();
    $runtime = $app?->runtime();

    return Response::json([
        'runtime' => $runtime?->name(),
        'async_io' => $runtime?->capabilities()->supportsAsyncIo(),
        'websockets' => $runtime?->supportsWebSockets(),
        'streaming' => $runtime?->capabilities()->supportsStreamingResponses(),
        'persistent_state' => $runtime?->capabilities()->supportsPersistentState(),
        'long_running' => $runtime?->isLongRunning(),
    ]);
});

$api->get('/time', function(): Response {
    $clock = PHAPI::app()?->container()->get(\DateTimeInterface::class);
    return Response::json(['now' => $clock?->format(DATE_ATOM)]);
});

$api->get('/plugin', function(): Response {
    $message = PHAPI::app()?->resolve('greeting');
    return Response::json(['message' => $message]);
});

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
