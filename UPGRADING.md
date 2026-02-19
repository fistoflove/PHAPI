# Upgrading to PHAPI v2.0.0

PHAPI v2.0.0 decomposes the monolithic `PHAPI` class (1,428 lines, 80 methods) into focused modules. This guide covers every breaking change and how to update your code.

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

## Architecture Overview (v2.0)

| File | Lines | Role |
|------|-------|------|
| `src/PHAPI.php` | 268 | Thin facade with traits |
| `src/Concerns/RoutesRequests.php` | 449 | Route registration |
| `src/Concerns/ManagesMiddleware.php` | 223 | Middleware management |
| `src/Concerns/ManagesRuntime.php` | 272 | Runtime/lifecycle hooks |
| `src/Concerns/SchedulesJobs.php` | 96 | Job scheduling |
| `src/Core/ServiceAccessor.php` | 198 | Typed service resolution |
| `src/Core/PHAPIBuilder.php` | 163 | Fluent config builder |

Methods that were on `PHAPI` directly (routing, middleware, runtime, jobs) are now in traits. The public API is identical — `$api->get()`, `$api->middleware()`, `$api->onBoot()`, etc. all work exactly as before.
