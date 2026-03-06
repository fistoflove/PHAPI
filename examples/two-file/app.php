<?php

declare(strict_types=1);

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class ExampleStatusController
{
    public function __construct(
        private \DateTimeInterface $clock,
        private \PHAPI\Runtime\RuntimeInterface $runtime,
    ) {
    }

    public function show(): Response
    {
        return Response::json([
            'now' => $this->clock->format(DATE_ATOM),
            'runtime' => $this->runtime->name(),
        ]);
    }
}

final class ExampleMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

$api->container()->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
$api->container()->singleton('greeting', fn(): string => 'Hello from PHAPI');
$api->middleware(ExampleMiddleware::class);

$api->onBoot(function (): void {
    // Boot-time hook (Swoole only).
});

$api->onWorkerStart(function ($server, int $workerId): void {
    // Worker hook (Swoole only).
});

$api->onRequestStart(function (Request $request): void {
    // Request hook.
});

$api->onRequestEnd(function (Request $request, Response $response): void {
    // Request hook.
});

$api->onShutdown(function (): void {
    // Shutdown hook (Swoole only).
});

$api->get('/', fn() => Response::json(['message' => 'Hello from PHAPI']));

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

$api->get('/info', [ExampleStatusController::class, 'show']);

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
    $data = $request->body() ?? [];
    return Response::json(['created' => true, 'user' => $data], 201);
})->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
]);

$api->schedule('cleanup', 300, function () {
    echo "cleanup executed";
}, [
    'log_file' => 'cleanup-job.log',
    'log_enabled' => true,
    'lock_mode' => 'skip',
]);

$api->schedule('silent', 120, function () {
    // No logging for this job.
}, [
    'log_enabled' => false,
    'lock_mode' => 'block',
]);

return $api;
