<?php

// Support both Composer and bootstrap.php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../bootstrap.php')) {
    require __DIR__ . '/../bootstrap.php';
}

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Core\Container;
use PHAPI\HTTP\Request;

final class ExampleStatusController
{
    public function __construct(private \DateTimeInterface $clock)
    {
    }

    public function show(): Response
    {
        return Response::json([
            'now' => $this->clock->format(DATE_ATOM),
            'runtime' => PHAPI::app()?->runtime()->name(),
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

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'fpm',
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
]);

$api->enableCORS();
$api->enableSecurityHeaders();

$api->container()->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
$api->extend('greeting', function (Container $container): string {
    return 'Hello from PHAPI';
});
$api->middleware(ExampleMiddleware::class);

$api->onBoot(function (): void {
    // Boot-time hook for runtime initialization.
});

$api->onWorkerStart(function ($server, int $workerId): void {
    // Swoole-only hook for per-worker setup.
});

$api->onShutdown(function (): void {
    // Cleanup resources.
});

$api->get('/', fn() => Response::json(['message' => 'Hello from PHAPI']));

$api->get('/users/{id}', function (): Response {
    $request = PHAPI::request();
    $app = PHAPI::app();
    return Response::json([
        'user_id' => $request?->param('id'),
        'url' => $app ? $app->url('users.show', ['id' => $request?->param('id')]) : null,
    ]);
})->name('users.show');

$api->get('/search/{query?}', function (): Response {
    $request = PHAPI::request();
    return Response::json([
        'query' => $request?->param('query'),
    ]);
})->name('search');

$api->get('/runtime', function (): Response {
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

$api->get('/time', function (): Response {
    $clock = PHAPI::app()?->container()->get(\DateTimeInterface::class);
    return Response::json(['now' => $clock?->format(DATE_ATOM)]);
});

$api->get('/info', [ExampleStatusController::class, 'show']);

$api->get('/plugin', function (): Response {
    $message = PHAPI::app()?->resolve('greeting');
    return Response::json(['message' => $message]);
});

$api->get('/jobs', function (): Response {
    $app = PHAPI::app();
    return Response::json(['jobs' => $app?->jobLogs() ?? []]);
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

$api->post('/users', function (): Response {
    $request = PHAPI::request();
    $data = $request?->body() ?? [];
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

$api->run();
