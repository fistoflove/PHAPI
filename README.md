# PHAPI

Micro MVC framework for PHP with a runtime-agnostic core. Write the same routes, middleware, auth, and jobs for PHP-FPM, FPM+AMPHP, or Swoole.

## Requirements

- PHP 8.1+
- Optional: Swoole extension for Swoole runtime
- Optional: `amphp/amp` and `amphp/http-client` for AMPHP runtime

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
    'runtime' => getenv('APP_RUNTIME') ?: 'fpm',
    'host' => '0.0.0.0',
    'port' => 9503,
    'debug' => true,
]);

$api->get('/', function (): Response {
    return Response::json(['message' => 'Hello']);
});

$api->run();
```

## Runtime Selection

- `APP_RUNTIME=fpm` (default)
- `APP_RUNTIME=fpm_amphp` or `amphp`
- `APP_RUNTIME=swoole`

Swoole uses the native PHP extension only.

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

Jobs are scheduled in app code. In Swoole, they run automatically. In FPM/AMPHP, use cron with `bin/phapi-jobs`.

```php
$api->schedule('cleanup', 300, function () {
    echo 'cleanup';
}, [
    'log_file' => 'cleanup-job.log',
    'log_enabled' => true,
    'lock_mode' => 'skip', // or 'block'
]);
```

Cron example:

```bash
* * * * * php /path/to/phapi/bin/phapi-jobs /path/to/app.php
```

Job logs live under `var/jobs` by default and rotate by size.

## Job Logs Endpoint

```php
$api->get('/jobs', function (): Response {
    return Response::json(['jobs' => PHAPI::app()?->jobLogs()]);
});
```

## Task Runner

```php
$results = $api->tasks()->parallel([
    'a' => fn() => ['ok' => true],
    'b' => fn() => ['ok' => true],
]);
```

- FPM: sequential
- AMPHP: futures
- Swoole: coroutines

## HTTP Client

```php
$data = $api->http()->getJson('https://example.com/api');
```

## Realtime

```php
$api->realtime()->broadcast('channel', ['event' => 'ping']);
```

- Swoole: WebSocket broadcast
- FPM/AMPHP: fallback/no-op (configure a fallback handler)

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

## SQLite Helpers

```php
use PHAPI\Database\DatabaseFacade;

DatabaseFacade::configure(__DIR__ . '/var/app.sqlite');
DatabaseFacade::setOption('site_name', 'My App');
$value = DatabaseFacade::option('site_name');
```

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

## Examples

- `example.php`
- `examples/single-file.php`
- `examples/multi-file/app.php`

## Tests

```bash
composer test
```

## License

MIT
