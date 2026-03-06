# PHAPI Test Coverage Report

> Generated: 2026-03-06
> PHPUnit 10.0 | pcov 1.0.12 | PHP 8.3.30 (Swoole)

## Summary

| Metric | Value |
|--------|-------|
| **Total Tests** | 984 (+integration) |
| **Errors / Failures** | 0 |
| **Source Files** | 112 (.php in src/) |
| **Test Files** | 96 (.php in tests/) |

### Test Breakdown

| Category | Files | Tests | Type |
|----------|-------|-------|------|
| Unit tests | ~60 | ~537 | Mocks, in-memory kernel |
| Supabase unit tests | 12 | 201 | Mocks — Auth, Database, Storage, Middleware, QueryBuilder, Realtime |
| Integration tests | 8 | ~113 | Real MySQL, Redis, OpenFGA, Swoole HTTP + WebSocket server |
| Supabase integration | 8 | ~101 | Real Supabase Docker stack (GoTrue, PostgREST, Storage, Inbucket, Realtime) |
| Telemetry tests | 6 | 38 | Unit (mocks) + integration (real MySQL/Redis) |
| WebSocket server tests | 3 | 14 | 7 unit (mocks), 7 integration (real Swoole WS server) |
| Supabase Realtime tests | 6 | 77 | 71 unit (FakeRealtimeSocket), 6 integration (real Supabase WS) |

---

## Per-Class Coverage

### Fully Covered (100% lines)

| Class | Methods | Lines |
|-------|---------|-------|
| `Core\HttpKernelFactory` | 1/1 | 17/17 |
| `Database\PhapiModel` | 1/1 | 2/2 |
| `Exceptions\DatabaseException` | 3/3 | 5/5 |
| `Exceptions\MethodNotAllowedException` | 2/2 | 3/3 |
| `Exceptions\OpenFgaException` | 3/3 | 3/3 |
| `Exceptions\PhapiException` | 2/2 | 2/2 |
| `Exceptions\ValidationException` | 2/2 | 3/3 |
| `HTTP\ResponseEnvelope` | 3/3 | 9/9 |
| `HTTP\Validator` | 8/8 | 119/119 |
| `Logging\ChannelLogger` | 5/5 | 14/14 |
| `Server\ErrorHandler` | 4/4 | 26/26 |

### Well Covered (>= 90% lines)

| Class | Methods | Lines | Notes |
|-------|---------|-------|-------|
| `Auth\AuthManager` | 7/9 (78%) | 29/31 (94%) | |
| `Auth\AuthMiddleware` | 2/3 (67%) | 18/19 (95%) | |
| `Auth\TokenGuard` | 5/6 (83%) | 33/37 (89%) | |
| `Core\Container` | 8/10 (80%) | 54/59 (92%) | |
| `Core\ProviderLoader` | 1/2 (50%) | 9/10 (90%) | |
| `HTTP\RequestContext` | 3/4 (75%) | 17/18 (94%) | |
| `HTTP\Response` | 20/21 (95%) | 53/56 (95%) | |
| `Logging\Logger` | 24/26 (92%) | 138/143 (97%) | |
| `Server\MiddlewareManager` | 7/8 (88%) | 26/27 (96%) | |
| `Routing\Route` | 2/5 (40%) | 44/49 (90%) | |
| `Services\SwooleRealtime` | 1/2 (50%) | 14/15 (93%) | |
| `Services\WebSocketConnection` | 6/7 (86%) | 11/12 (92%) | |
| `Telemetry\TracingHttpClient` | 8/9 (89%) | 56/60 (93%) | |
| `Telemetry\TracingMiddleware` | 1/3 (33%) | 40/42 (95%) | Low method% from constructor + static factory |

### Adequately Covered (70–89% lines)

| Class | Methods | Lines | Notes |
|-------|---------|-------|-------|
| `Auth\SessionGuard` | 3/7 (43%) | 22/29 (76%) | |
| `Core\ConfigLoader` | 4/6 (67%) | 37/43 (86%) | |
| `HTTP\RouteBuilder` | 3/12 (25%) | 28/53 (53%) | Fluent API — many methods untested |
| `Server\HttpKernel` | 6/12 (50%) | 145/167 (87%) | |
| `Server\Router` | 5/16 (31%) | 123/148 (83%) | |
| `Providers\OrmMysqlProvider` | 3/7 (43%) | 126/155 (81%) | |
| `Services\OpenFgaHttpClient` | 5/14 (36%) | 136/156 (87%) | Low method% — 14 methods, only 5 directly tested |
| `Services\WebSocketMessage` | 3/4 (75%) | 6/7 (86%) | |
| `Runtime\RuntimeSelector` | 1/3 (33%) | 14/17 (82%) | |
| `Telemetry\TracingOpenFgaClient` | 8/12 (67%) | 40/50 (80%) | |
| `Telemetry\HeadersGetter` | 1/3 (33%) | 8/11 (73%) | |

### Under-Covered (< 70% lines)

| Class | Methods | Lines | Gap Analysis |
|-------|---------|-------|------|
| `PHAPI` | 26/80 (33%) | 157/391 (40%) | Facade class — 80 methods, many are thin proxies. Hard to unit-test without full server; covered transitively. |
| `Core\AppBootstrapper` | 1/4 (25%) | 17/47 (36%) | Bootstrap logic requires full server lifecycle. |
| `Core\AuthConfigurator` | 0/2 (0%) | 15/24 (63%) | Covered indirectly via auth tests. |
| `Core\DefaultEndpoints` | 0/2 (0%) | 9/20 (45%) | Covered via integration tests (HttpKernelIntegration). |
| `Core\JobsScheduler` | 0/1 (0%) | 2/16 (13%) | Requires Swoole timer runtime. |
| `Core\RuntimeManager` | 3/5 (60%) | 5/9 (56%) | |
| `Runtime\Capabilities` | 1/5 (20%) | 4/8 (50%) | Enum-like, little logic. |
| `Runtime\SwooleDriver` | 12/46 (26%) | 110/296 (37%) | Swoole server lifecycle — requires running server process. Integration tests cover HTTP and WebSocket paths. |
| `Services\Database` | 1/7 (14%) | 6/20 (30%) | ORM wrapper — tested via OrmMysqlProvider integration. |
| `Services\JobsManager` | 15/24 (63%) | 170/193 (88%) | 21 tests covering registration, execution, locking, logging, rotation, state. |
| `Services\MySqlPool` | 6/15 (40%) | 70/103 (68%) | Pool management methods partially covered by integration. |
| `Services\SwooleHttpClient` | 3/7 (43%) | 40/82 (49%) | Swoole coroutine HTTP — hard to unit-test. |
| `Services\SwooleRedisClient` | 1/18 (6%) | 13/63 (21%) | 18 methods, most exercised through TracingRedisClient integration but pcov attributes to wrapper. |
| `Services\SwooleTaskRunner` | 1/2 (50%) | 42/70 (60%) | Requires coroutine context. |
| `Services\RealtimeManager` | 1/2 (50%) | 1/9 (11%) | Minimal — just a resolver. |
| `HTTP\Request` | 13/18 (72%) | 45/84 (54%) | 5 untested methods (query helpers, content-length). |
| `Exceptions\HttpRequestException` | 1/4 (25%) | 5/8 (63%) | |
| `Exceptions\RouteNotFoundException` | 0/1 (0%) | 4/6 (67%) | |

---

## Not in Coverage Report (Interfaces / Abstract / Traits)

These 25 files contain no executable lines and are excluded from pcov coverage:

| File | Type |
|------|------|
| `Contracts\DatabaseInterface` | Interface |
| `Contracts\RuntimeInterface` | Interface |
| `Contracts\HttpClientInterface` | Interface |
| `Contracts\TaskRunnerInterface` | Interface |
| `Contracts\WebSocketDriverInterface` | Interface |
| `Core\ServiceProviderInterface` | Interface |
| `Auth\GuardInterface` | Interface |
| `Services\HttpClient` | Interface |
| `Services\TaskRunner` | Interface |
| `Services\Realtime` | Interface |
| `Services\RedisClient` | Interface (extends SwooleRedisClient) |
| `Services\OpenFgaClient` | Interface |
| `Services\DefaultHttpClient` | Thin default impl |
| `Services\DefaultTaskRunner` | Thin default impl |
| `Services\WebSocketRealtime` | Interface |
| `Runtime\RuntimeInterface` | Interface |
| `Runtime\HttpRuntimeDriver` | Abstract |
| `Runtime\DriverCapabilities` | Value object |
| `Exceptions\ConfigException` | Simple exception |
| `Exceptions\NotFoundException` | Simple exception |
| `Exceptions\ForbiddenException` | Simple exception |
| `Exceptions\UnauthorizedException` | Simple exception |
| `Exceptions\ContainerException` | Simple exception |
| `Exceptions\ServerNotRunningException` | Simple exception |
| `Telemetry\TracingMySqlPool` | Covered by integration (runs in separate process) |
| `Telemetry\TracingRedisClient` | Covered by integration (runs in separate process) |
| `Telemetry\TracingServiceProvider` | Requires Swoole server boot |

Note: `TracingMySqlPool`, `TracingRedisClient`, and `TracingServiceProvider` contain executable code but are not reported in the text coverage because the integration tests that exercise them run through the Swoole coroutine runtime which pcov may not attribute correctly. These are functionally tested — see `tests/Telemetry/TracingMySqlPoolTest.php` (6 tests), `tests/Telemetry/TracingRedisClientTest.php` (7 tests), and `tests/Telemetry/TracingIntegrationTest.php` (6 tests).

---

## Coverage by Module

| Module | Files | Lines Covered | Lines Total | Coverage |
|--------|-------|--------------|-------------|----------|
| Auth | 4 | 102 | 116 | 87.9% |
| Core | 8 | 163 | 245 | 66.5% |
| Database | 1 | 2 | 2 | 100.0% |
| Exceptions | 7 | 32 | 42 | 76.2% |
| HTTP | 6 | 290 | 339 | 85.5% |
| Logging | 2 | 152 | 157 | 96.8% |
| Providers | 1 | 126 | 155 | 81.3% |
| Routing | 1 | 44 | 49 | 89.8% |
| Runtime | 3 | 133 | 330 | 40.3% |
| Server | 8 | 518 | 577 | 89.8% |
| Services | 13 | 541 | 968 | 55.9% |
| Telemetry | 7 | 192 | 234 | 82.1% |
| PHAPI (facade) | 1 | 157 | 391 | 40.2% |

### Module Analysis

**Strong coverage (>85%):** Auth, HTTP, Logging, Server, Database, Routing — these are the most-tested modules and cover the critical request handling path.

**Adequate coverage (70-85%):** Exceptions, Providers, Telemetry — newly added Telemetry module is at 82% with both unit and integration tests.

**Lower coverage (<70%):** Core (bootstrap/lifecycle), Runtime (Swoole server internals), Services (connection pools, coroutine-dependent code), PHAPI facade. These are structurally harder to test without a full running Swoole server. The integration tests (`SwooleServerTest`, `HttpKernelIntegrationTest`) cover the critical paths through these modules.

---

## Recommendations

1. **Highest ROI improvements:**
   - `HTTP\Request` — 5 untested methods are easy to cover with unit tests
   - `HTTP\RouteBuilder` — fluent API methods are straightforward to test

2. **Structurally difficult to improve:**
   - `PHAPI` facade (40%) — 80 proxy methods, coverage comes from integration tests
   - `Runtime\SwooleDriver` (37%) — requires live Swoole server process
   - `Services\SwooleRedisClient` (21%) — covered by TracingRedisClient integration tests, but pcov can't trace through composition

3. **No action needed:**
   - All 25 interface/abstract files — no executable code
   - Exception classes — trivial constructors, covered by error path tests
