# Changelog

All notable changes to PHAPI are documented in this file.

## v1.5.0 — 2026-02-18

### Added
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
- Test coverage report at `docs/test-coverage.md`

### Fixed
- `SwooleServerTest` hardcoded `/workspaces/PHAPI/vendor/autoload.php` path replaced with dynamic resolution
- `TracingIntegrationTest` autoload path made portable

## v1.4.0 — 2026-02-18

### Added
- OpenFGA authorization client (`OpenFgaClient` interface + `OpenFgaHttpClient` implementation) for Zanzibar-based fine-grained authorization
- `OpenFgaException` for OpenFGA API error context (carries `fgaCode`, `fgaMessage`, `httpStatus`)
- `PHAPI::openfga()` accessor method for lazy-initialized OpenFGA client
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
