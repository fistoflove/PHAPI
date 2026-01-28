# PHAPI

Micro MVC framework for PHP built for Swoole. Write routes, middleware, auth, and jobs with a single Swoole runtime, including portable Swoole.
PHAPI supports only the `swoole` and `portable_swoole` runtimes.

## Requirements

- PHP 8.0+
- Swoole extension (native or portable)

## Install

```bash
composer require phapi/phapi
```

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use PHAPI\PHAPI;
use PHAPI\HTTP\Response;

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'swoole',
    'host' => '0.0.0.0',
    'port' => 9503,
    'debug' => true,
    'default_endpoints' => false,
]);

$api->get('/', function (): Response {
    return Response::json(['message' => 'Hello']);
});

$api->run();
```

## Dependency Injection

PHAPI exposes a PSR-11 container with autowiring and bindings.

```php
$api->container()->bind(
    \DateTimeInterface::class,
    \DateTimeImmutable::class,
    true
);

$api->get('/time', function (): Response {
    $clock = PHAPI::app()?->container()->get(\DateTimeInterface::class);
    return Response::json(['now' => $clock?->format(DATE_ATOM)]);
});
```

## Service Providers

Register service providers via config to keep modules isolated.

```php
final class CacheProvider implements \PHAPI\Core\ServiceProviderInterface
{
    public function register(\PHAPI\Core\Container $container, \PHAPI\PHAPI $app): void
    {
        $container->singleton(CacheInterface::class, FilesystemCache::class);
    }

    public function boot(\PHAPI\PHAPI $app): void
    {
    }
}

$api = new PHAPI([
    'providers' => [
        CacheProvider::class,
    ],
]);
```

Providers run in the order listed. Later providers can override earlier bindings.

## Lifecycle Hooks

Register hooks for boot, worker start, and shutdown.

```php
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
```

Runtime hook semantics:

| Hook | Swoole |
| --- | --- |
| `onRequestStart` | once per request |
| `onRequestEnd` | once per request |
| `onBoot` | once on server start |
| `onWorkerStart` | once per worker |
| `onShutdown` | once on server shutdown |
Multiple `onWorkerStart()` handlers are supported; they run in registration order.

## Runtime Interface

```php
$runtime = $api->runtime();

$runtime->name(); // swoole or portable_swoole
$runtime->supportsWebSockets();
$runtime->isLongRunning();
```

## Public Contracts

Stable interfaces for integrations live under `PHAPI\Contracts`:

- `PHAPI\Contracts\RuntimeInterface`
- `PHAPI\Contracts\HttpClientInterface`
- `PHAPI\Contracts\TaskRunnerInterface`
- `PHAPI\Contracts\WebSocketDriverInterface`

## Class-Based Handlers

Controllers can be referenced using callable arrays and will be resolved via the container.
By default they are instantiated per request unless you bind them as singletons.

```php
final class UserController
{
    public function index(): Response
    {
        return Response::json(['ok' => true]);
    }
}

$api->get('/users', [UserController::class, 'index']);
```

## Recommended Project Structure (Optional)

PHAPI does not enforce a layout, but this keeps larger apps clean:

```
app/
  Controllers/
  Services/
  Middleware/
routes/
config/
var/
public/
```

See `docs/project-structure.md` for notes.

## DI & Autowiring Example

```php
final class UserRepository
{
    public function all(): array
    {
        return [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Linus'],
        ];
    }
}

final class UserController
{
    public function __construct(private UserRepository $repo)
    {
    }

    public function index(): Response
    {
        return Response::json(['users' => $this->repo->all()]);
    }
}

$api->get('/users', [UserController::class, 'index']);
```

## Container Lifecycles

- `singleton()` returns one instance for the app/worker lifetime.
- `bind()` (or `bind(..., false)`) returns a new instance per `get()`.
- `request()` returns one instance per request.

## Autowiring Rules

- Only class-typed constructor parameters are autowired.
- Scalars must have defaults or be bound in the container.
- Default values are used when available.
- Circular dependencies throw a `ContainerException`.

## Tiny Plugin System

```php
$api->extend('cache', function (Container $container) {
    return new RedisCache($container->get(Redis::class));
});

$cache = $api->resolve('cache');
```

`extend()` is sugar for container bindings. Use providers for reusable packages/modules, and `extend()` for app-local utilities.

Suggested naming to avoid collisions: `vendor.feature` or `feature.variant` (e.g., `metrics.prometheus`).

## Swoole WebSocket Example

```php
$api = new PHAPI([
    'runtime' => 'swoole',
    'enable_websockets' => true,
]);

$api->setWebSocketHandler(function ($server, $frame, $driver): void {
    $payload = json_decode($frame->data ?? '', true);
    if (!is_array($payload)) {
        return;
    }

    if ($payload['action'] === 'subscribe') {
        $driver->subscribe($frame->fd, (string)$payload['channel']);
    }
});

$api->get('/broadcast', function (): Response {
    PHAPI::app()?->realtime()->broadcast('updates', ['ok' => true]);
    return Response::json(['sent' => true]);
});
```

`$driver` is the active Swoole runtime driver. It manages subscriptions via
`subscribe($fd, $channel)` / `unsubscribe($fd, $channel)` and broadcasts only to subscribers.

Security: authenticate WebSocket upgrades and validate subscribe requests before
joining channels.

## Task Runner (Advanced)

Requires Swoole (native or portable). If invoked outside a coroutine, PHAPI will start one when supported.

```php
$results = PHAPI::app()?->tasks()->parallel([
    'a' => fn() => ['ok' => true],
    'b' => fn() => ['count' => 42],
]);
```

If any task throws, the task runner throws the first error it encounters.

You can configure a timeout (seconds) for task completion:

```php
$api = new PHAPI([
    'task_timeout' => 5.0,
]);
```

## Jobs (Lock/Block)

```php
$api->schedule('cleanup', 300, function () {
    // ...
}, [
    'log_enabled' => true,
    'log_file' => 'cleanup.log',
    'lock_mode' => 'block', // or 'skip'
]);
```

Lock modes:

- `skip`: if the lock is held, the run is skipped and logged as `skipped`.
- `block`: waits for the lock (blocking file lock, no timeout).

If a job throws, the run is recorded as `error` and the message is logged.

## Runtime Selection

- `APP_RUNTIME=swoole` (default)
- `APP_RUNTIME=portable_swoole` (loads a bundled `swoole.so`)

Swoole uses the native PHP extension only.

Portable Swoole will attempt to load a bundled `swoole.so` from
`portable-swoole/bin/extensions` or a path provided via:

- `PHAPI_PORTABLE_SWOOLE_DIR`
- `PHAPI_PORTABLE_SWOOLE_EXT`

If your PHP build does not allow `dl()`, run via:

```bash
APP_RUNTIME=portable_swoole php -d extension=/path/to/swoole.so app.php
```

Or use the runner:

```bash
# When installed via Composer
APP_RUNTIME=portable_swoole php vendor/bin/phapi-run app.php

# When running from this repository
APP_RUNTIME=portable_swoole php bin/phapi-run app.php
```

PHAPI registers `/monitor` by default. Disable it if you want to provide your own handler:

```php
$api = new PHAPI([
    'default_endpoints' => [
        'monitor' => false,
    ],
]);
```

When using the runner, PHAPI will mark the runtime as `portable_swoole` in `/monitor`.

## Routing

```php
$api->get('/users/{id}', function (): Response {
    $request = PHAPI::request();
    return Response::json(['id' => $request?->param('id')]);
})->name('users.show');

$api->get('/search/{query?}', function (): Response {
    return Response::json(['query' => PHAPI::request()?->param('query')]);
})->name('search');

$url = $api->url('users.show', ['id' => 42], ['tab' => 'profile']);
// /users/42?tab=profile
```

### Method and Host Constraints

```php
$api->post('/users', fn() => Response::json(['ok' => true]))
    ->host('api.example.com');

$api->get('/internal', fn() => Response::json(['ok' => true]))
    ->host('/^internal\./');
```

## Middleware

```php
$api->middleware(function ($request, $next) {
    return $next($request);
});

$api->addMiddleware('auth', $api->requireAuth());

$api->get('/protected', fn() => Response::json(['ok' => true]))
    ->middleware('auth');
```

Class-based middleware is resolved via the container:

```php
final class AuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

$api->middleware(AuthMiddleware::class);
```

Named middleware supports arguments: `role:admin|manager`.

## Validation

```php
$api->post('/users', function (): Response {
    $data = PHAPI::request()?->body() ?? [];
    return Response::json(['created' => true, 'user' => $data], 201);
})->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
]);
```

## Auth

```php
$api = new PHAPI([
    'auth' => [
        'default' => 'token',
        'token_resolver' => function (string $token) {
            return $token === 'test-token' ? ['id' => 1, 'roles' => ['admin']] : null;
        },
        'session_key' => 'user',
        'session_allow_in_swoole' => false,
    ],
]);
```

Helpers:

- `$api->requireAuth()`
- `$api->requireRole('admin')`
- `$api->requireAllRoles(['admin', 'manager'])`

Named middleware:

- `auth`
- `role:admin|manager`
- `role_all:admin|manager`

## Jobs

Jobs are scheduled in app code and run automatically under Swoole.

```php
$api->schedule('cleanup', 300, function () {
    echo 'cleanup';
}, [
    'log_file' => 'cleanup-job.log',
    'log_enabled' => true,
    'lock_mode' => 'skip', // or 'block'
]);
```

Job logs live under `var/jobs` by default and rotate by size.

## Background Processes (Swoole)

Register processes before `run()` to start in worker 0 (outside coroutines):

```php
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
```

## Job Logs Endpoint

```php
$api->get('/jobs', function (): Response {
    return Response::json(['jobs' => PHAPI::app()?->jobLogs()]);
});
```

## Task Runner

Requires Swoole (native or portable). If invoked outside a coroutine, PHAPI will start one when supported.

```php
$results = $api->tasks()->parallel([
    'a' => fn() => ['ok' => true],
    'b' => fn() => ['ok' => true],
]);
```

- Swoole: coroutines

## HTTP Client

Requires Swoole (native or portable). If invoked outside a coroutine, PHAPI will start one when supported.

```php
$data = $api->http()->getJson('https://example.com/api');
```

Swap the HTTP client by binding the interface:

```php
$api->container()->singleton(\PHAPI\Services\HttpClient::class, MyHttpClient::class);
```

Errors thrown by `getJson()` include HTTP status and raw body via `HttpRequestException`.

## Redis (Swoole Coroutine)

Requires a coroutine context (request handlers, jobs, or tasks).

```php
$redis = $api->redis();
$redis->set('greeting', 'hello', 30);
$value = $redis->get('greeting');
```

Config:

```php
'redis' => [
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,
    'db' => null,
    'timeout' => 1.0,
],
```

## MySQL (Swoole Coroutine)

Requires a coroutine context (request handlers, jobs, or tasks).

```php
$mysql = $api->mysql();
$rows = $mysql->query('SELECT 1 AS ok');
$mysql->execute('INSERT INTO users(name) VALUES (?)', ['Ada']);
```

Config:

```php
'mysql' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'password' => '',
    'database' => '',
    'charset' => 'utf8mb4',
    'timeout' => 1.0,
],
```

## Error Responses

`Response::error()` returns a JSON payload with `error` plus any extra fields you pass.

## Realtime

```php
$api->realtime()->broadcast('channel', ['event' => 'ping']);
```

- Swoole: WebSocket broadcast

### WebSocket Subscriptions (Swoole)

```php
$api->setWebSocketHandler(function ($server, $frame, $driver) {
    $data = json_decode($frame->data ?? '', true);
    if (!is_array($data)) {
        return;
    }

    if (($data['action'] ?? '') === 'subscribe' && !empty($data['channel'])) {
        $driver->subscribe($frame->fd, $data['channel']);
    }

    if (($data['action'] ?? '') === 'unsubscribe' && !empty($data['channel'])) {
        $driver->unsubscribe($frame->fd, $data['channel']);
    }
});
```

Broadcast only to subscribers:

```php
$api->realtime()->broadcast('player:123', ['event' => 'ping']);
```

The `$driver` argument is the active Swoole runtime driver. Authenticate WebSocket
connections and validate subscription messages before joining channels.

## Request Context Helpers

Handlers can be `function (): Response` and access context statically:

```php
$request = PHAPI::request();
$app = PHAPI::app();
```

## Security Headers

```php
$api->enableSecurityHeaders([
    'X-Frame-Options' => 'DENY',
]);
```

## Request Size Limit

```php
$api = new PHAPI([
    'max_body_bytes' => 1024 * 1024,
]);
```

## Access Logging

```php
$api = new PHAPI([
    'access_logger' => function ($request, $response, array $meta) {
        error_log(json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->status(),
            'request_id' => $meta['request_id'],
            'duration_ms' => $meta['duration_ms'],
        ]));
    },
]);
```

## Example Structure

Two-file:

```
examples/two-file/
  index.php
  app.php
```

Multi-file:

```
examples/multi-file/
  app.php
  app/
    Controllers/
    jobs.php
    middlewares.php
    routes.php
    tasks.php
```

## Example App

The full example lives at `examples/multi-runtime/app.php` and demonstrates:
providers, DI/autowiring, class middleware, jobs, tasks, HTTP client, and
WebSocket subscriptions.

Run it with:

```bash
# Native Swoole
APP_RUNTIME=swoole php examples/multi-runtime/app.php

# Portable Swoole
APP_RUNTIME=portable_swoole php bin/phapi-run examples/multi-runtime/app.php
```

## Examples

- `example.php`
- `examples/two-file/index.php`
- `examples/multi-file/app.php`
- `examples/multi-runtime/app.php`

## Testing PHAPI Apps

You can exercise routes in memory by calling the kernel directly:

```php
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api = new PHAPI(['runtime' => 'swoole']);

$api->get('/hello', fn() => Response::json(['ok' => true]));

$request = new Request('GET', '/hello');
$response = $api->kernel()->handle($request);

assert($response->status() === 200);
```

Override container bindings to inject fakes:

```php
$api->container()->singleton(\PHAPI\Services\HttpClient::class, FakeHttpClient::class);
```

## Tests

```bash
composer test
```

## Process Supervision

PHAPI should run under a supervisor so it restarts on failure or reboot.
See `docs/process-supervision.md` for systemd, supervisord, Docker, and PM2 examples.

## License

MIT
