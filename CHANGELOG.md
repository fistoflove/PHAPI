# Changelog

All notable changes to PHAPI are documented in this file.

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
