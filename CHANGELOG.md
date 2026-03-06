# Changelog

All notable changes to PHAPI are documented in this file.

## v1.6.0 — 2026-03-06

### Added
- **Native Supabase Integration** — Auth, Database (PostgREST), and Storage clients
  - `SupabaseProvider` service provider with config validation and container bindings
  - `SupabaseFactory` singleton for creating request-scoped `SupabaseContext` instances
  - `SupabaseContext` with lazy `auth()`, `db()`, `storage()` accessors
  - `AuthClient` — GoTrue client: `user()`, `signInWithPassword()`, `signUp()`, `signInWithOtp()`, `verifyOtp()`, `refreshToken()`, `signOut()`
  - `AdminClient` — service-role admin: `listUsers()`, `getUser()`, `createUser()`, `updateUser()`, `deleteUser()`
  - `DatabaseClient` + `QueryBuilder` — fluent PostgREST queries with immutable chaining, filters, ordering, pagination, insert/update/upsert/delete
  - `StorageClient` — bucket management, file upload/download/delete/copy/move, public URLs, signed URLs
  - `SupabaseAuthMiddleware` (`supabase.auth`) — bearer token extraction with custom resolver support
  - `SupabaseRoleMiddleware` (`supabase.role`) — JWT role-based access control
  - `SupabaseTransport` — Swoole coroutine HTTP transport supporting all HTTP methods
  - Typed exception hierarchy: `SupabaseException`, `SupabaseAuthException`, `SupabaseDatabaseException`, `SupabaseStorageException`
  - 92 unit tests covering all components

## v1.5.1 — 2026-03-06

### Fixed
- TracingIntegrationTest test server uses removed `PHAPI::request()`; replaced with `Request` type-hint
- TracingMySqlPoolTest errors instead of skipping when MySQL unavailable
- Job autowiring test fails due to unwritable lock directory
- MySqlPool now health-checks borrowed connections; stale connections are replaced

### Changed
- SwooleHttpClient timeout configurable via `http_timeout` config key (default: 5.0s)
- GoogleIdTokenVerifier cache TTL configurable via `google_oidc.cache_ttl` (default: 300s)
- `guzzlehttp/guzzle` removed (was unused)
- `hyperf/*` and `open-telemetry/*` moved from `require` to `suggest`

### Added
- 20 new JobsManager tests covering registration, execution, locking, logging, rotation, state
- `class_exists` guards in OrmMysqlProvider and TracingServiceProvider with install instructions

## v1.5.0 — 2026-02-18

### Added
- `PHAPIBuilder` (`src/Core/PHAPIBuilder.php`) — fluent, validated configuration builder. Use `PHAPI::builder()->...->build()` instead of `new PHAPI([...])`
- `ServiceAccessor` (`src/Core/ServiceAccessor.php`) — centralized lazy-instantiation of service clients (`mysql()`, `redis()`, `openfga()`, `http()`, `database()`, `tasks()`, `realtime()`, `googleIdTokenVerifier()`). Accessed via `$api->services()`
- `GoogleIdTokenVerifier` (`src/Auth/GoogleIdTokenVerifier.php`) — Google OIDC ID token verification with RS256 JWT validation, JWKS certificate fetching and caching
- `AuthException` (`src/Auth/AuthException.php`) — thrown by `GoogleIdTokenVerifier` on verification failures
- `google_oidc` config block for certificate URL and audience configuration
- OpenTelemetry tracing module (`src/Telemetry/`) with opt-in `TracingServiceProvider`
- `TracingMiddleware` — global middleware for root server spans with W3C trace context extraction
- `TracingHttpClient` — decorator for outbound HTTP spans with cross-service W3C context propagation
- `TracingMySqlPool` — decorator for MySQL query spans (`db.system=mysql`, operation + table parsing)
- `TracingRedisClient` — decorator for Redis command spans (`db.system=redis`)
- `TracingOpenFgaClient` — decorator for OpenFGA authorization spans with FGA semantic attributes
- `TracingServiceProvider` — registers tracer, installs middleware and decorators, configures OTLP exporter with Swoole context storage
- `HeadersGetter` — W3C `PropagationGetterInterface` implementation for request header extraction
- New dependencies: `open-telemetry/api`, `open-telemetry/sdk`, `open-telemetry/context-swoole`, `open-telemetry/exporter-otlp`, `open-telemetry/sem-conv`
- 37 new Telemetry tests (18 unit + 19 integration using real MySQL and Redis)
- 7 Auth unit tests (GoogleIdTokenVerifier)
- Test coverage report at `docs/test-coverage.md`

### Breaking Changes
- **Static globals removed**: `PHAPI::app()`, `PHAPI::lastInstance()`, `PHAPI::request()`, `PHAPI::db()` — no global application instance; pass `$api` explicitly or type-hint `Request` in handlers
- **Service proxy methods removed**: `$api->mysql()`, `$api->redis()`, `$api->openfga()`, `$api->http()`, `$api->database()`, `$api->tasks()`, `$api->realtime()` — use `$api->services()->...()` instead
- **DI shortcuts removed**: `$api->extend()` and `$api->resolve()` — use `$api->container()->singleton()` and `$api->container()->get()` instead
- **ServiceProviderInterface changed**: `register(Container $container, PHAPI $app)` is now `register(Container $container, array $config)`
- **PHAPI class decomposed**: Monolithic class split into focused traits (`RoutesRequests`, `ManagesMiddleware`, `ManagesRuntime`, `SchedulesJobs`). Public API is unchanged for routing/middleware/hooks

### Fixed
- `SwooleServerTest` hardcoded `/workspaces/PHAPI/vendor/autoload.php` path replaced with dynamic resolution
- `TracingIntegrationTest` autoload path made portable

## v1.4.0 — 2026-02-18

### Added
- OpenFGA authorization client (`OpenFgaClient` interface + `OpenFgaHttpClient` implementation) for Zanzibar-based fine-grained authorization
- `OpenFgaException` for OpenFGA API error context (carries `fgaCode`, `fgaMessage`, `httpStatus`)
- `$api->services()->openfga()` accessor method for lazy-initialized OpenFGA client (via `ServiceAccessor`)
- `OpenFgaClient` DI singleton registration in `AppBootstrapper`
- `openfga` config block in `config/phapi.php` (`api_url`, `store_id`, `model_id`, `api_token`)
- OpenFGA service in Docker Compose development environment (MySQL-backed, with migrate init container)
- Unit tests for all OpenFGA client methods (19 tests with MockHttpClient)
- Integration tests for OpenFGA client (`@group integration @group openfga`)

## v1.3.0 — 2026-02-16

### Added
- Docker Compose development environment with Swoole app, MySQL 8.0, and Redis 7 services
- `docker/Dockerfile` for containerized development and CI
- `.dockerignore` for optimized image builds

## v1.2.0 — 2026-02-16

### Added
- `ResponseEnvelope` class (`PHAPI\HTTP\ResponseEnvelope`) for canonical API response formatting
  - `success(mixed $data): array` — builds `{"ok": true, "data": $data}` envelope
  - `error(string $code, string $message, int $httpStatus = 400): Response` — builds error response with `{"ok": false, "error": {"code": "...", "message": "..."}}`
  - `ok(mixed $data, int $httpStatus = 200): Response` — builds success response with the envelope

## v1.1.0 — 2026-02-16

### Added

- Custom header support on all HttpClient methods (`getJson`, `getJsonWithMeta`, `postFormWithMeta`) via optional `array $headers` parameter
- New `postJson(string $url, array $data, array $headers = [])` method for posting JSON-encoded data with decoded response
- New `postJsonWithMeta(string $url, array $data, array $headers = [])` method for posting JSON-encoded data with response metadata
- Custom headers are merged with defaults; caller-supplied headers override built-in defaults

## v1.0.0 — 2026-02-14

First stable release. This tag marks the version consumed by Yard (control plane),
YardApp ORAS, and YardApp WP-MCP at the time of version policy adoption.

### Included

- Swoole-based async HTTP server with coroutine hooks (`SWOOLE_HOOK_ALL`)
- Route registration with parameterized paths, groups, and named routes
- Middleware pipeline (global, per-route named, after-middleware)
- PSR-11 compliant DI container with autowiring and three scopes (singleton, transient, request)
- Input validation engine with rule DSL
- Authentication manager with pluggable guards (token, session)
- Async HTTP client (`SwooleHttpClient`) with `getJson`, `getJsonWithMeta`, `postFormWithMeta`
- MySQL connection pool (`MySqlPool`) with PDO and coroutine hooks
- Redis client (`SwooleRedisClient`) with coroutine-safe connection pooling
- WebSocket pub/sub broadcast abstraction (`Realtime`)
- Parallel task execution via Swoole task workers (`TaskRunner`)
- Scheduled job management with file-locking and log rotation (`JobsManager`)
- ORM layer backed by Hyperf database packages (`PhapiModel`, `Database`)
- Service provider system with two-phase lifecycle
- Structured multi-channel logging (`Logger`)
- CORS handler with preflight support
- Error handler with debug mode
- Default `/monitor` health endpoint
