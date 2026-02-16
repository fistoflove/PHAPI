# Changelog

All notable changes to PHAPI are documented in this file.

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
