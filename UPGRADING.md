# Upgrading PHAPI

## Upgrading to v1.5.1

### Optional Dependencies

`hyperf/*` and `open-telemetry/*` packages are no longer required by default. If you use the ORM or tracing features, install them explicitly:

```bash
# ORM (Hyperf database)
composer require hyperf/config:^3.1 hyperf/database:^3.1 hyperf/db-connection:^3.1

# OpenTelemetry tracing
composer require open-telemetry/api:^1.0 open-telemetry/sdk:^1.0 open-telemetry/context-swoole:^1.0 open-telemetry/exporter-otlp:^1.0 open-telemetry/sem-conv:^1.0
```

`guzzlehttp/guzzle` was removed entirely — it was never used by PHAPI (the framework uses Swoole's native coroutine HTTP client).

Both `OrmMysqlProvider` and `TracingServiceProvider` now throw a `ConfigException` with install instructions if their packages are missing.

### New Config Options

- `http_timeout` (float, default `5.0`) — timeout in seconds for all HTTP client requests
- `google_oidc.cache_ttl` (int, default `300`) — JWKS certificate cache TTL in seconds

---

# Upgrading to PHAPI v1.5.0

PHAPI v1.5.0 decomposes the monolithic `PHAPI` class into focused modules, adds a fluent builder, and centralizes service access behind `ServiceAccessor`. This guide covers every breaking change and how to update your code.

## Quick Summary

| Change | Find | Replace With |
|--------|------|-------------|
| Static globals removed | `PHAPI::app()` | Pass `$api` instance explicitly |
| Static globals removed | `PHAPI::request()` | Type-hint `Request $request` in handler |
| Static globals removed | `PHAPI::db()` | `$api->services()->database()` |
| Service proxies removed | `$api->mysql()` | `$api->services()->mysql()` |
| Service proxies removed | `$api->redis()` | `$api->services()->redis()` |
| Service proxies removed | `$api->openfga()` | `$api->services()->openfga()` |
| Service proxies removed | `$api->http()` | `$api->services()->http()` |
| Service proxies removed | `$api->database()` | `$api->services()->database()` |
| Service proxies removed | `$api->tasks()` | `$api->services()->tasks()` |
| Service proxies removed | `$api->realtime()` | `$api->services()->realtime()` |
| DI shortcuts removed | `$api->extend(Foo::class, fn)` | `$api->container()->singleton(Foo::class, fn)` |
| DI shortcuts removed | `$api->resolve(Foo::class)` | `$api->container()->get(Foo::class)` |
| Provider interface | `register(Container, PHAPI)` | `register(Container, array $config)` |
| Builder (optional) | `new PHAPI([...])` | `PHAPI::builder()->...->build()` |
| MySQL config consolidated | Duplicate `mysql` + `orm.mysql` | Single `mysql` key; `orm.mysql` falls back to it |
| Dead classes removed | `Server\CORSHandler`, `JobManager`, `TaskManager`, `PerformanceMonitor` | Use `enableCORS()`, `JobsManager`, `SwooleTaskRunner`, OpenTelemetry |
| Debug path leak fixed | Full server paths in error JSON | Base path stripped automatically |
| Architecture enforcement | No layer validation | `composer phpstan` enforces layer rules + dead code detection |

## 1. Static Globals Removed

`PHAPI::app()`, `PHAPI::lastInstance()`, `PHAPI::request()`, and `PHAPI::db()` have been removed. There is no global application instance.

**Route handlers:** Type-hint `Request $request` as a parameter — the HTTP kernel automatically injects it:

```php
// Before
$api->get('/users', function () {
    $request = PHAPI::request();
    return Response::json($request->query());
});

// After
$api->get('/users', function (Request $request) {
    return Response::json($request->query());
});
```

**Accessing the app instance from closures:** Use `$api` from the enclosing scope:

```php
// Before
$api->get('/status', function () {
    $app = PHAPI::app();
    return Response::json(['runtime' => $app->runtimeName()]);
});

// After
$api->get('/status', function () use ($api) {
    return Response::json(['runtime' => $api->runtimeName()]);
});
```

## 2. Service Proxy Methods Removed

Direct service accessors have moved behind `$api->services()`:

```php
// Before
$pool = $api->mysql();
$redis = $api->redis();
$fga = $api->openfga();

// After
$pool = $api->services()->mysql();
$redis = $api->services()->redis();
$fga = $api->services()->openfga();
```

`extend()` and `resolve()` are replaced by container methods:

```php
// Before
$api->extend(Logger::class, fn() => new FileLogger());
$logger = $api->resolve(Logger::class);

// After
$api->container()->singleton(Logger::class, fn() => new FileLogger());
$logger = $api->container()->get(Logger::class);
```

## 3. ServiceProviderInterface Changed

The `register()` method now receives `(Container $container, array $config)` instead of `(Container $container, PHAPI $app)`. The `boot()` method is unchanged.

```php
// Before
public function register(Container $container, PHAPI $app): void
{
    $config = $app->config();
    $container->singleton(MyService::class, fn() => new MyService($config['key']));
}

// After
public function register(Container $container, array $config): void
{
    $container->singleton(MyService::class, fn() => new MyService($config['key']));
}
```

If your `register()` accessed services on `$app`, resolve them from the container instead:

```php
// Before
$container->singleton(Rbac::class, fn() => new Rbac($app->openfga()));

// After
$container->singleton(Rbac::class, fn(Container $c) => new Rbac($c->get(OpenFgaClient::class)));
```

## 4. PHAPIBuilder (Optional)

A new fluent builder is available. The array constructor still works but is marked `@internal`:

```php
// Before
$api = new PHAPI([
    'host' => '0.0.0.0',
    'port' => 9501,
    'debug' => true,
    'mysql' => [...],
    'providers' => [MyProvider::class],
]);

// After
$api = PHAPI::builder()
    ->host('0.0.0.0')
    ->port(9501)
    ->debug(true)
    ->mysql([...])
    ->providers([MyProvider::class])
    ->build();
```

The builder validates configuration at build time (e.g., port range 1-65535).

## 5. MySQL Configuration Consolidated

The default config (`config/phapi.php`) now has a single `mysql` key as the source of truth. The `orm.mysql` section defaults to empty — `OrmMysqlProvider` automatically falls back to the shared `mysql` config when `orm.mysql` is not set.

```php
// config/phapi.php — single source of truth
'mysql' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'password' => '',
    'database' => 'myapp',
    'charset' => 'utf8mb4',
    'timeout' => 1.0,
    'pool_size' => 5,
    'pool_timeout' => 1.0,
],

// Only override orm.mysql if you need ORM-specific tuning
// (collation, prefix, advanced pool settings). Otherwise leave empty.
'orm' => [
    'mysql' => [],
],
```

## 6. Examples Updated

All example files under `examples/` have been updated to use the v1.5.0 API. They no longer reference `PHAPI::app()`, `PHAPI::request()`, or the removed service proxy methods. Use the examples as reference for the current API patterns:

- **Route handlers**: type-hint `Request $request` and use `$api` via closure `use ($api)`
- **Services**: access via `$api->services()->redis()`, `->mysql()`, `->tasks()`, etc.
- **DI**: use `$api->container()->singleton()` / `->get()` instead of `extend()` / `resolve()`
- **Controllers**: inject dependencies via constructor (DI container autowires them)

## 7. Dead Code Removed

The following classes in `src/Server/` were unused (never wired into the framework) and have been removed:

- `CORSHandler` — redundant with `ManagesMiddleware::enableCORS()`
- `JobManager` — redundant with `Services\JobsManager` + `Core\JobsScheduler`
- `TaskManager` — redundant with `Services\SwooleTaskRunner`
- `PerformanceMonitor` — superseded by OpenTelemetry tracing (`Telemetry\TracingMiddleware`)

If you were importing any of these classes directly, use the alternatives listed above.

## 8. Architecture Enforcement via PHPStan

Two new PHPStan extensions are now part of the dev toolchain:

- **phpat/phpat** — enforces layer dependency rules (e.g., `Services` must not import from `Server`, `Contracts` must not depend on concrete classes). Rules are defined in `tests/Architecture/LayerDependencyTest.php`.
- **tomasvotruba/unused-public** — flags unused public methods, properties, and constants. Legitimate public API classes are annotated with `@api` to suppress false positives.

Both run as part of `composer phpstan`. Any new code that violates the layer rules or introduces dead public methods will fail the check.

## 9. Debug Mode No Longer Leaks Server Paths

`ErrorHandler` now strips the application base path from file paths and stack traces in debug error responses. Instead of exposing `/var/www/myapp/src/Server/Router.php`, responses show `src/Server/Router.php`. The base path is auto-detected from `getcwd()` and can be overridden via the `base_path` config key.

## Architecture Overview (v1.5.0)

| File | Lines | Role |
|------|-------|------|
| `src/PHAPI.php` | 278 | Thin facade with traits |
| `src/Concerns/RoutesRequests.php` | 449 | Route registration |
| `src/Concerns/ManagesMiddleware.php` | 223 | Middleware management |
| `src/Concerns/ManagesRuntime.php` | 272 | Runtime/lifecycle hooks |
| `src/Concerns/SchedulesJobs.php` | 96 | Job scheduling |
| `src/Core/ServiceAccessor.php` | 217 | Typed service resolution |
| `src/Core/PHAPIBuilder.php` | 163 | Fluent config builder |

Methods that were on `PHAPI` directly (routing, middleware, runtime, jobs) are now in traits. The public API is identical — `$api->get()`, `$api->middleware()`, `$api->onBoot()`, etc. all work exactly as before.
