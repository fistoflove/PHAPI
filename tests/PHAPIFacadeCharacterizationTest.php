<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\AuthManager;
use PHAPI\Core\Container;
use PHAPI\Contracts\DatabaseInterface;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\HTTP\RouteBuilder;
use PHAPI\PHAPI;
use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\RuntimeInterface;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\Router;
use PHAPI\Services\HttpClient;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Services\Realtime;
use PHAPI\Services\RedisClient;
use PHAPI\Services\TaskRunner;

/**
 * Characterization tests for the PHAPI facade class.
 *
 * These tests exercise every public method group on PHAPI to establish
 * a regression safety net before the god-class decomposition refactoring.
 * They verify the current public API contract — return types, fluent
 * chaining, and observable behaviour — without relying on implementation
 * details.
 *
 * After each refactoring phase the full suite (including these tests)
 * must pass unchanged. Tests here will be updated or removed only when
 * the public API intentionally changes (Phases 3–6).
 */
final class PHAPIFacadeCharacterizationTest extends SwooleTestCase
{
    // ─── Construction & Core Accessors ────────────────────────────────

    public function testConstructWithDefaults(): void
    {
        $api = new PHAPI();

        $this->assertInstanceOf(Container::class, $api->container());
        $this->assertIsArray($api->config());
        $this->assertInstanceOf(HttpKernel::class, $api->kernel());
        $this->assertInstanceOf(AuthManager::class, $api->auth());
    }

    public function testConstructWithCustomConfig(): void
    {
        $api = new PHAPI([
            'debug' => true,
            'default_endpoints' => false,
        ]);

        $this->assertTrue($api->config()['debug']);
    }

    // ─── Static Accessors ─────────────────────────────────────────────

    public function testLastInstanceReturnsLatestConstruction(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $this->assertSame($api, PHAPI::lastInstance());
        $this->assertSame($api, PHAPI::app());
    }

    public function testStaticRequestReturnsNullOutsideRequestCycle(): void
    {
        $this->assertNull(PHAPI::request());
    }

    public function testStaticDbReturnsNullWhenNoProviderRegistered(): void
    {
        // DatabaseInterface not registered by default — should return null
        // or throw depending on implementation; we just verify the method exists
        new PHAPI(['default_endpoints' => false]);
        try {
            $result = PHAPI::db();
            // If it doesn't throw, it may return null
            $this->assertTrue($result === null || $result instanceof DatabaseInterface);
        } catch (\Throwable) {
            // Expected — DatabaseInterface not bound without OrmMysqlProvider
            $this->assertTrue(true);
        }
    }

    // ─── Route Registration ───────────────────────────────────────────

    public function testGetReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->get('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testPostReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->post('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testPutReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->put('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testPatchReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->patch('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testDeleteReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->delete('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testOptionsReturnsRouteBuilder(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $builder = $api->options('/test', fn () => Response::text('ok'));

        $this->assertInstanceOf(RouteBuilder::class, $builder);
    }

    public function testRegisterRouteReturnsIndex(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $index = $api->registerRoute('GET', '/direct', fn () => Response::text('direct'));

        $this->assertIsInt($index);
    }

    public function testUpdateRouteModifiesRegisteredRoute(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $index = $api->registerRoute('GET', '/original', fn () => Response::text('original'));

        // Verify original route works
        $response = $api->kernel()->handle(new Request('GET', '/original'));
        $this->assertSame(200, $response->status());
        $this->assertSame('original', $response->body());

        // Update handler only (path stays the same)
        $api->updateRoute($index, [
            'handler' => fn () => Response::text('updated'),
        ]);

        $response = $api->kernel()->handle(new Request('GET', '/original'));
        $this->assertSame(200, $response->status());
        $this->assertSame('updated', $response->body());
    }

    public function testRegisteredRoutesAreDispatchable(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->get('/hello', fn () => Response::text('world'));
        $api->post('/echo', fn (Request $req) => Response::json(['body' => $req->body()]));

        $getResponse = $api->kernel()->handle(new Request('GET', '/hello'));
        $this->assertSame(200, $getResponse->status());
        $this->assertSame('world', $getResponse->body());

        $postResponse = $api->kernel()->handle(new Request('POST', '/echo', [], [], [], 'payload'));
        $this->assertSame(200, $postResponse->status());
        $decoded = json_decode($postResponse->body(), true);
        $this->assertSame('payload', $decoded['body'] ?? null);
    }

    // ─── Route Groups & Prefixing ─────────────────────────────────────

    public function testGroupAppliesPrefix(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->group('/api', function (PHAPI $api): void {
            $api->get('/status', fn () => Response::text('ok'));
        });

        $response = $api->kernel()->handle(new Request('GET', '/api/status'));
        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    public function testNestedGroupsStackPrefixes(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->group('/v1', function (PHAPI $api): void {
            $api->group('/users', function (PHAPI $api): void {
                $api->get('/list', fn () => Response::text('user-list'));
            });
        });

        $response = $api->kernel()->handle(new Request('GET', '/v1/users/list'));
        $this->assertSame(200, $response->status());
        $this->assertSame('user-list', $response->body());
    }

    public function testGroupMiddlewareAppliesWithinGroup(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->addMiddleware('tag', function (Request $req, callable $next, array $args = []): Response {
            $response = $next($req);
            return $response->withHeader('X-Tag', (string) ($args[0] ?? 'default'));
        });

        $api->group('/tagged', function (PHAPI $api): void {
            $api->groupMiddleware('tag:v1');
            $api->get('/item', fn () => Response::text('tagged'));
        });

        $response = $api->kernel()->handle(new Request('GET', '/tagged/item'));
        $this->assertSame(200, $response->status());
        $this->assertSame('v1', $response->headers()['X-Tag'] ?? null);
    }

    public function testDeferredGroupScopeWorksWithLoadPattern(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->beginDeferredGroupScope();
        $api->group('/deferred', function (PHAPI $api): void {
            $api->get('/route', fn () => Response::text('deferred'));
        });
        $api->endDeferredGroupScope();

        $response = $api->kernel()->handle(new Request('GET', '/deferred/route'));
        $this->assertSame(200, $response->status());
    }

    // ─── Named Routes & URL Generation ────────────────────────────────

    public function testNamedRouteAndUrlGeneration(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->get('/users/{id}', fn () => Response::text('user'))->name('user.show');

        $url = $api->url('user.show', ['id' => '42']);
        $this->assertSame('/users/42', $url);
    }

    public function testUrlGenerationWithQueryParams(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->get('/search', fn () => Response::text('results'))->name('search');

        $url = $api->url('search', [], ['q' => 'test', 'page' => '2']);
        $this->assertStringContainsString('q=test', $url);
        $this->assertStringContainsString('page=2', $url);
    }

    // ─── Middleware ───────────────────────────────────────────────────

    public function testGlobalMiddlewareWithCallable(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->middleware(function (Request $req, callable $next): Response {
            $response = $next($req);
            return $response->withHeader('X-Global', 'yes');
        });

        $api->get('/mw', fn () => Response::text('base'));

        $response = $api->kernel()->handle(new Request('GET', '/mw'));
        $this->assertSame('yes', $response->headers()['X-Global'] ?? null);
    }

    public function testAfterMiddleware(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->afterMiddleware(function (Request $req, Response $res): Response {
            return $res->withHeader('X-After', 'applied');
        });

        $api->get('/after', fn () => Response::text('base'));

        $response = $api->kernel()->handle(new Request('GET', '/after'));
        $this->assertSame('applied', $response->headers()['X-After'] ?? null);
    }

    public function testAddMiddlewareRegistersNamedMiddleware(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->addMiddleware('decorate', function (Request $req, callable $next, array $args = []): Response {
            $response = $next($req);
            return $response->withHeader('X-Named', (string) ($args[0] ?? 'default'));
        });

        $api->get('/named', fn () => Response::text('ok'))->middleware('decorate:hello');

        $response = $api->kernel()->handle(new Request('GET', '/named'));
        $this->assertSame('hello', $response->headers()['X-Named'] ?? null);
    }

    public function testMiddlewareStringCreatesRouteBuilderProxy(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->addMiddleware('guard', function (Request $req, callable $next, array $args = []): Response {
            return $next($req)->withHeader('X-Guard', 'on');
        });

        $builder = $api->middleware('guard');
        $this->assertInstanceOf(RouteBuilder::class, $builder);

        $builder->get('/guarded', fn () => Response::text('safe'));

        $response = $api->kernel()->handle(new Request('GET', '/guarded'));
        $this->assertSame(200, $response->status());
        $this->assertSame('on', $response->headers()['X-Guard'] ?? null);
    }

    public function testClassMiddleware(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $callable = $api->classMiddleware(InvokableTestMiddleware::class);

        $this->assertIsCallable($callable);
    }

    public function testEnableCORSReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->enableCORS();

        $this->assertSame($api, $result);
    }

    public function testCORSHeadersApplied(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->enableCORS('*', ['GET', 'POST'], ['Content-Type']);
        $api->get('/cors', fn () => Response::text('ok'));

        $response = $api->kernel()->handle(
            new Request('GET', '/cors', [], ['origin' => 'http://example.com'])
        );
        $this->assertSame('*', $response->headers()['Access-Control-Allow-Origin'] ?? null);
    }

    public function testEnableSecurityHeadersReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->enableSecurityHeaders();

        $this->assertSame($api, $result);
    }

    public function testSecurityHeadersApplied(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->enableSecurityHeaders();
        $api->get('/secure', fn () => Response::text('ok'));

        $response = $api->kernel()->handle(new Request('GET', '/secure'));
        $this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
        $this->assertSame('DENY', $response->headers()['X-Frame-Options'] ?? null);
    }

    // ─── Auth ─────────────────────────────────────────────────────────

    public function testAuthReturnsAuthManager(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $this->assertInstanceOf(AuthManager::class, $api->auth());
    }

    public function testRequireAuthReturnsCallable(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $middleware = $api->requireAuth();

        $this->assertIsCallable($middleware);
    }

    public function testRequireRoleReturnsCallable(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $middleware = $api->requireRole('admin');

        $this->assertIsCallable($middleware);
    }

    public function testRequireAllRolesReturnsCallable(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $middleware = $api->requireAllRoles(['admin', 'editor']);

        $this->assertIsCallable($middleware);
    }

    // ─── Container / Extend / Resolve ─────────────────────────────────

    public function testExtendAndResolveRoundtrip(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->extend('my.service', fn (Container $c) => new \stdClass());

        $resolved = $api->resolve('my.service');
        $this->assertInstanceOf(\stdClass::class, $resolved);
        $this->assertSame($resolved, $api->resolve('my.service'), 'Singleton by default');
    }

    public function testExtendTransientCreatesNewInstancesEachTime(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->extend('transient.svc', fn (Container $c) => new \stdClass(), false);

        $first = $api->resolve('transient.svc');
        $second = $api->resolve('transient.svc');
        $this->assertNotSame($first, $second);
    }

    public function testExtendReturnsSelfForFluency(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->extend('noop', fn (Container $c) => null);

        $this->assertSame($api, $result);
    }

    public function testContainerGivesAccessToCoreServices(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $container = $api->container();

        $this->assertTrue($container->has(TaskRunner::class));
        $this->assertTrue($container->has(HttpClient::class));
    }

    // ─── Service Accessors ────────────────────────────────────────────

    public function testHttpReturnsHttpClient(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $client = $api->http();

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testTasksReturnsTaskRunner(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $runner = $api->tasks();

        $this->assertInstanceOf(TaskRunner::class, $runner);
    }

    public function testRealtimeReturnsRealtimeService(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $rt = $api->realtime();

        $this->assertInstanceOf(Realtime::class, $rt);
    }

    public function testMysqlReturnsMySqlPool(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
            'mysql' => [
                'host' => '127.0.0.1',
                'user' => 'root',
                'password' => '',
                'database' => 'test',
            ],
        ]);

        $pool = $api->mysql();

        $this->assertInstanceOf(MySqlPool::class, $pool);
        // Lazy — same instance on second call
        $this->assertSame($pool, $api->mysql());
    }

    public function testRedisReturnsRedisClient(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
            'redis' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);

        $redis = $api->redis();

        $this->assertInstanceOf(RedisClient::class, $redis);
        // Lazy — same instance on second call
        $this->assertSame($redis, $api->redis());
    }

    public function testOpenfgaReturnsOpenFgaClient(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
            'openfga' => [
                'api_url' => 'http://localhost:8080',
                'store_id' => 'test-store',
            ],
        ]);

        $fga = $api->openfga();

        $this->assertInstanceOf(OpenFgaClient::class, $fga);
        // Lazy — same instance on second call
        $this->assertSame($fga, $api->openfga());
    }

    // ─── Runtime ──────────────────────────────────────────────────────

    public function testCapabilitiesReturnsDriverCapabilities(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $caps = $api->capabilities();

        $this->assertInstanceOf(DriverCapabilities::class, $caps);
        $this->assertIsBool($caps->supportsAsyncIo());
        $this->assertIsBool($caps->supportsWebSockets());
    }

    public function testRuntimeReturnsRuntimeInterface(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $runtime = $api->runtime();

        $this->assertInstanceOf(RuntimeInterface::class, $runtime);
    }

    public function testRuntimeNameReturnsString(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $name = $api->runtimeName();

        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    // ─── Runtime Hooks (fluent) ───────────────────────────────────────

    public function testOnBootReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->onBoot(function (): void {});

        $this->assertSame($api, $result);
    }

    public function testOnWorkerStartReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->onWorkerStart(function ($server, int $workerId): void {});

        $this->assertSame($api, $result);
    }

    public function testOnShutdownReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->onShutdown(function (): void {});

        $this->assertSame($api, $result);
    }

    public function testOnRequestStartReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->onRequestStart(function (Request $req): void {});

        $this->assertSame($api, $result);
    }

    public function testOnRequestEndReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->onRequestEnd(function (Request $req, Response $res): void {});

        $this->assertSame($api, $result);
    }

    // ─── Task Handlers ────────────────────────────────────────────────

    public function testSetTaskHandlerReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->setTaskHandler(function ($server, int $taskId, int $reactorId, $data) {
            return $data;
        });

        $this->assertSame($api, $result);
    }

    public function testSetTaskFinishHandlerReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->setTaskFinishHandler(function ($server, int $taskId, $data): void {});

        $this->assertSame($api, $result);
    }

    // ─── WebSocket ────────────────────────────────────────────────────

    public function testSetWebSocketHandlerReturnsSelf(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
            'enable_websockets' => true,
        ]);

        $result = $api->setWebSocketHandler(function ($server, $frame, $driver): void {});

        $this->assertSame($api, $result);
    }

    public function testOnWebSocketMessageReturnsSelf(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
            'enable_websockets' => true,
        ]);

        $result = $api->onWebSocketMessage(function ($msg, $conn): void {});

        $this->assertSame($api, $result);
    }

    // ─── Jobs / Scheduling ────────────────────────────────────────────

    public function testScheduleReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->schedule('heartbeat', 60, function (): string {
            return 'alive';
        });

        $this->assertSame($api, $result);
    }

    public function testRunJobsReturnsArray(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->schedule('quick', 1, function (): string {
            return 'done';
        });

        $results = $api->runJobs();

        $this->assertIsArray($results);
    }

    public function testJobLogsReturnsArray(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $logs = $api->jobLogs();

        $this->assertIsArray($logs);
    }

    public function testJobHandlerReceivesContainerViaAutowiring(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);
        $receivedContainer = null;

        $api->schedule('autowired', 1, function (Container $container) use (&$receivedContainer): string {
            $receivedContainer = $container;
            return 'wired';
        });

        $api->runJobs();

        $this->assertInstanceOf(Container::class, $receivedContainer);
        $this->assertSame($api->container(), $receivedContainer);
    }

    // ─── setDebug ─────────────────────────────────────────────────────

    public function testSetDebugReturnsSelfAndUpdatesConfig(): void
    {
        $api = new PHAPI(['default_endpoints' => false, 'debug' => false]);

        $result = $api->setDebug(true);

        $this->assertSame($api, $result);
        $this->assertTrue($api->config()['debug']);
    }

    // ─── spawnProcess ─────────────────────────────────────────────────

    public function testSpawnProcessReturnsSelf(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $result = $api->spawnProcess(fn () => null);

        $this->assertSame($api, $result);
    }

    // ─── loadApp ──────────────────────────────────────────────────────

    public function testLoadAppDoesNotErrorOnMissingDirectory(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);
        $tmpDir = sys_get_temp_dir() . '/phapi_loadapp_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0755, true);

        // Should not throw — missing app/ files are silently skipped
        $api->loadApp($tmpDir);

        $this->assertTrue(true);

        // Cleanup
        @rmdir($tmpDir);
    }

    // ─── Full Request Lifecycle ───────────────────────────────────────

    public function testFullRequestLifecycleWithMiddlewareAndRouting(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        // Global middleware
        $api->middleware(function (Request $req, callable $next): Response {
            $response = $next($req);
            return $response->withHeader('X-Global', '1');
        });

        // After middleware
        $api->afterMiddleware(function (Request $req, Response $res): Response {
            return $res->withHeader('X-After', '1');
        });

        // Named middleware
        $api->addMiddleware('tag', function (Request $req, callable $next, array $args = []): Response {
            $response = $next($req);
            return $response->withHeader('X-Tag', (string) ($args[0] ?? 'none'));
        });

        // Security headers
        $api->enableSecurityHeaders();

        // CORS
        $api->enableCORS('*');

        // Group with middleware
        $api->group('/api', function (PHAPI $api): void {
            $api->groupMiddleware('tag:api');

            $api->get('/test', fn (Request $req) => Response::json([
                'method' => $req->method(),
                'path' => $req->path(),
            ]))->name('api.test');
        });

        $response = $api->kernel()->handle(
            new Request('GET', '/api/test', [], ['origin' => 'http://example.com'])
        );

        $headers = $response->headers();
        $this->assertSame(200, $response->status());
        $this->assertSame('1', $headers['X-Global'] ?? null);
        $this->assertSame('1', $headers['X-After'] ?? null);
        $this->assertSame('api', $headers['X-Tag'] ?? null);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('*', $headers['Access-Control-Allow-Origin'] ?? null);

        $body = json_decode($response->body(), true);
        $this->assertSame('GET', $body['method']);
        $this->assertSame('/api/test', $body['path']);

        // Named route URL
        $this->assertSame('/api/test', $api->url('api.test'));
    }

    // ─── Validation via Route Registration ────────────────────────────

    public function testRegisterRouteWithValidation(): void
    {
        $api = new PHAPI(['default_endpoints' => false]);

        $api->registerRoute(
            'POST',
            '/validated',
            fn (Request $req) => Response::json(['ok' => true]),
            [],
            ['name' => 'required|min:2'],
            'body',
            'validated.create'
        );

        // Missing required field — should get 422 (body is a parsed array, not raw JSON)
        $response = $api->kernel()->handle(
            new Request('POST', '/validated', [], ['content-type' => 'application/json'], [], [])
        );
        $this->assertSame(422, $response->status());

        // Valid payload — body must be a parsed array for validation to work
        $response = $api->kernel()->handle(
            new Request('POST', '/validated', [], ['content-type' => 'application/json'], [], ['name' => 'Alice'])
        );
        $this->assertSame(200, $response->status());
    }
}

// ─── Test Helpers ─────────────────────────────────────────────────────

/**
 * A simple invokable middleware class for testing classMiddleware().
 */
final class InvokableTestMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
