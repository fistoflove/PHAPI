# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What Is PHAPI

PHAPI is a lightweight PHP 8.0+ micro MVC framework built exclusively on Swoole. It provides async HTTP routing, middleware, DI container, validation, authentication, job scheduling, WebSocket support, and connection pooling for Redis/MySQL.

## Commands

```bash
composer test                    # PHPUnit suite with testdox output
composer test:integration        # Integration tests only (@integration group)
composer phpstan                 # PHPStan level 8 strict analysis (src/ only)
composer lint                    # PHP-CS-Fixer dry-run check
composer lint:fix                # Apply PHP-CS-Fixer formatting
```

Run a single test file or filter:
```bash
./vendor/bin/phpunit tests/RouterTest.php
./vendor/bin/phpunit --filter testMethodName
```

Run an example app:
```bash
php bin/phapi-run example.php
```

## Architecture

**Entry point**: `src/PHAPI.php` — the main application class. Exposes route registration (`get()`, `post()`, etc.), middleware, lifecycle hooks, service accessors (`http()`, `redis()`, `mysql()`, `tasks()`, `realtime()`), and `run()` to start the Swoole server.

**Request lifecycle**: `PHAPI` → `HttpKernel` (dispatches requests) → `Router` (matches routes via first-segment indexing) → `MiddlewareManager` (global + per-route pipeline) → handler callable/class → `Response`.

**DI Container** (`src/Core/Container.php`): PSR-11 compliant with autowiring. Three scopes: `singleton()` (per-worker), `bind()` (transient), `request()` (per-request). Autowires class-typed constructor params; scalars need defaults or explicit bindings.

**Service Providers** (`ServiceProviderInterface`): Two-phase lifecycle — `register()` binds to container, `boot()` runs after all providers register. Declared in config `providers` array, loaded by `ProviderLoader`.

**Runtime**: `SwooleDriver` (`src/Runtime/SwooleDriver.php`) is the sole runtime. Lifecycle hooks: `onBoot`, `onWorkerStart`, `onRequestStart`, `onRequestEnd`, `onShutdown`. Coroutine hooks enabled by default.

**Routing**: `Router` parses route patterns into segments, compiles regex for parameterized routes, and uses first-segment indexing for fast lookup. Route groups support prefix stacking and deferred middleware. Named routes enable URL generation via `PHAPI::url()`.

**Middleware**: Global middleware runs on all requests. Named middleware is registered via `addMiddleware()` and attached per-route. After-middleware runs post-response. All middleware classes resolved through the DI container.

**Authentication**: `AuthManager` with pluggable guards (`TokenGuard`, `SessionGuard`). Named middleware: `auth`, `role:name`, `role_all:name1|name2`.

**Services**: `HttpClient` (async), `TaskRunner` (coroutine-based parallelism), `JobsManager` (scheduled jobs with file locking/rotation), `RedisClient`/`MySqlPool` (connection pooling), `Realtime` (WebSocket pub/sub).

**ResponseEnvelope** (`src/HTTP/ResponseEnvelope.php`): Canonical response envelope for all API responses. Static methods:
- `ResponseEnvelope::ok($data, $status)` — returns `Response` with `{"ok": true, "data": $data}`
- `ResponseEnvelope::error($code, $message, $status)` — returns `Response` with `{"ok": false, "error": {"code": "...", "message": "..."}}`
- `ResponseEnvelope::success($data)` — returns the success envelope as a plain array

**HttpClient custom headers**: All HttpClient methods (`getJson`, `getJsonWithMeta`, `postFormWithMeta`, `postJson`, `postJsonWithMeta`) accept an optional `array $headers = []` parameter for custom HTTP headers (e.g., `Authorization`, `Content-Type`).

**OpenFGA client** (`src/Services/OpenFgaClient.php`): Interface + implementation (`OpenFgaHttpClient`) for Zanzibar-based fine-grained authorization via OpenFGA. Accessed via `$app->openfga()`. Uses PHAPI's `HttpClient` internally — no additional dependencies.

Config (`config/phapi.php`):
```php
'openfga' => [
    'api_url'   => 'http://localhost:8080',
    'store_id'  => '',
    'model_id'  => '',     // optional, uses latest if empty
    'api_token' => '',     // pre-shared key, empty = no auth
],
```

Methods: `check(user, relation, object): bool`, `batchCheck(checks): array`, `writeTuples(writes): void`, `deleteTuples(deletes): void`, `readTuples(?user, ?relation, ?object): array`, `listObjects(user, relation, type): array`, `listUsers(object, relation, userType): array`, `expand(relation, object): array`, `writeAuthorizationModel(typeDefinitions, schemaVersion): string`, `readAuthorizationModel(?id): array`.

Throws `OpenFgaException` on API errors (carries `fgaCode()`, `httpStatus()`).

**Testing approach**: Use `PHAPI::kernel()` to get the `HttpKernel` for in-memory request testing without starting a Swoole server. Create a `Request`, pass to `$kernel->handle()`, assert on the `Response`.

## Coding Standards

- `declare(strict_types=1);` in all `src/` files
- PSR-12 enforced via PHP-CS-Fixer (`.php-cs-fixer.php`)
- PHPStan level 8 with strict rules; Swoole stubs in `stubs/swoole.stub.php`
- Single quotes, short array syntax, ordered imports (alpha), trailing commas in multiline arrays, no unused imports
- Conventional Commits for commit messages (e.g., `feat:`, `fix:`, `test:`, `refactor:`)

## Exception Hierarchy

All custom exceptions extend `PhapiException` (`src/Exceptions/`). HTTP-related: `NotFoundException`, `MethodNotAllowedException`, `ForbiddenException`, `UnauthorizedException`. Domain: `ValidationException`, `DatabaseException`, `ContainerException`, `ConfigException`, `HttpRequestException`.

## Configuration

Default config in `config/phapi.php`. Key settings: `host`, `port`, `debug`, `enable_websockets`, `swoole_settings` (passed to `Swoole\Server::set()`), `providers`, `redis`, `mysql`, `orm`.
