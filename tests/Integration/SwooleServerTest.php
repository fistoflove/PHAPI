<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Boots a real Swoole HTTP server and exercises the full PHAPI stack
 * over the network.
 */
final class SwooleServerTest extends TestCase
{
    private static int $port = 9599;
    private static string $serverScript = '/tmp/phapi_swoole_test_server.php';
    private static ?int $pid = null;

    public static function setUpBeforeClass(): void
    {
        self::writeServerScript();
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        @unlink(self::$serverScript);
    }

    // ── Routing ──────────────────────────────────────────────

    public function testGetRoute(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['pong']);
    }

    public function testRouteWithParam(): void
    {
        $res = self::http('GET', '/users/42');
        $this->assertSame(200, $res['status']);
        $this->assertSame('42', $res['body']['id']);
    }

    public function testOptionalParamPresent(): void
    {
        $res = self::http('GET', '/search/hello');
        $this->assertSame(200, $res['status']);
        $this->assertSame('hello', $res['body']['query']);
    }

    public function testOptionalParamAbsent(): void
    {
        $res = self::http('GET', '/search');
        $this->assertSame(200, $res['status']);
        $this->assertNull($res['body']['query']);
    }

    public function testRouteNotFound(): void
    {
        $res = self::http('GET', '/nonexistent');
        $this->assertSame(404, $res['status']);
        $this->assertArrayHasKey('error', $res['body']);
    }

    public function testMethodNotAllowed(): void
    {
        $res = self::http('DELETE', '/ping');
        $this->assertSame(405, $res['status']);
        $this->assertContains('GET', $res['body']['allowed_methods']);
    }

    // ── Request/Response translation ─────────────────────────

    public function testPostJsonBody(): void
    {
        $res = self::http('POST', '/echo', ['hello' => 'world']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(['hello' => 'world'], $res['body']['body']);
    }

    public function testQueryParams(): void
    {
        $res = self::http('GET', '/query?foo=bar&n=1');
        $this->assertSame(200, $res['status']);
        $this->assertSame('bar', $res['body']['foo']);
        $this->assertSame('1', $res['body']['n']);
    }

    public function testResponseHeaders(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertNotEmpty($res['headers']['x-request-id'] ?? '');
    }

    public function testCustomResponseHeader(): void
    {
        $res = self::http('GET', '/custom-header');
        $this->assertSame(200, $res['status']);
        $this->assertSame('test-value', $res['headers']['x-custom'] ?? null);
    }

    // ── Auth ─────────────────────────────────────────────────

    public function testProtectedRouteWithoutToken(): void
    {
        $res = self::http('GET', '/protected');
        $this->assertSame(401, $res['status']);
    }

    public function testProtectedRouteWithValidToken(): void
    {
        $res = self::http('GET', '/protected', null, ['Authorization: Bearer secret']);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['ok']);
    }

    public function testProtectedRouteWithInvalidToken(): void
    {
        $res = self::http('GET', '/protected', null, ['Authorization: Bearer wrong']);
        $this->assertSame(401, $res['status']);
    }

    public function testRoleCheckPasses(): void
    {
        $res = self::http('GET', '/admin', null, ['Authorization: Bearer secret']);
        $this->assertSame(200, $res['status']);
    }

    public function testRoleCheckFails(): void
    {
        $res = self::http('GET', '/admin', null, ['Authorization: Bearer limited']);
        $this->assertSame(403, $res['status']);
    }

    // ── Validation ───────────────────────────────────────────

    public function testValidationPasses(): void
    {
        $res = self::http('POST', '/validated', ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->assertSame(201, $res['status']);
        $this->assertTrue($res['body']['created']);
    }

    public function testValidationFails(): void
    {
        $res = self::http('POST', '/validated', ['name' => 'A']);
        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('errors', $res['body']);
    }

    // ── CORS ─────────────────────────────────────────────────

    public function testCorsPreflightReturns204(): void
    {
        $res = self::http('OPTIONS', '/ping', null, [
            'Origin: https://example.com',
            'Access-Control-Request-Method: GET',
        ]);
        $this->assertSame(204, $res['status']);
        $this->assertNotNull($res['headers']['access-control-allow-origin'] ?? null);
        $this->assertNotNull($res['headers']['access-control-allow-methods'] ?? null);
    }

    public function testCorsHeaderOnNormalRequest(): void
    {
        $res = self::http('GET', '/ping', null, ['Origin: https://example.com']);
        $this->assertNotNull($res['headers']['access-control-allow-origin'] ?? null);
    }

    // ── Error handling ───────────────────────────────────────

    public function testHandlerExceptionReturns500(): void
    {
        $res = self::http('GET', '/throw');
        $this->assertSame(500, $res['status']);
        $this->assertArrayHasKey('error', $res['body']);
    }

    // ── Global middleware ────────────────────────────────────

    public function testGlobalMiddlewareAddsHeader(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertSame('applied', $res['headers']['x-global'] ?? null);
    }

    // ── Streaming responses ─────────────────────────────────

    public function testStreamIterableResponse(): void
    {
        $res = self::httpRaw('GET', '/stream/iterable');
        $this->assertSame(200, $res['status']);
        $this->assertSame('chunk1chunk2chunk3', $res['body']);
        $this->assertSame('text/plain', $res['headers']['content-type'] ?? null);
    }

    public function testStreamStringResponse(): void
    {
        $res = self::httpRaw('GET', '/stream/string');
        $this->assertSame(200, $res['status']);
        $this->assertSame('single-string-body', $res['body']);
    }

    public function testStreamNullResponse(): void
    {
        $res = self::httpRaw('GET', '/stream/null');
        $this->assertSame(200, $res['status']);
        $this->assertSame('', $res['body']);
    }

    // ── Security headers ────────────────────────────────────

    public function testSecurityHeadersPresent(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertSame('nosniff', $res['headers']['x-content-type-options'] ?? null);
        $this->assertSame('DENY', $res['headers']['x-frame-options'] ?? null);
        $this->assertSame('no-referrer', $res['headers']['referrer-policy'] ?? null);
        $this->assertSame('0', $res['headers']['x-xss-protection'] ?? null);
    }

    // ── Route groups ────────────────────────────────────────

    public function testGroupedRouteWithPrefix(): void
    {
        $res = self::http('GET', '/api/v1/status');
        $this->assertSame(200, $res['status']);
        $this->assertSame('v1-ok', $res['body']['status']);
    }

    public function testGroupMiddlewareApplies(): void
    {
        $res = self::http('GET', '/api/v1/status');
        $this->assertSame('v1', $res['headers']['x-api-version'] ?? null);
    }

    public function testGroupMiddlewareDoesNotLeakOutside(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertNull($res['headers']['x-api-version'] ?? null);
    }

    public function testNestedGroupRoute(): void
    {
        $res = self::http('GET', '/api/v1/nested/deep');
        $this->assertSame(200, $res['status']);
        $this->assertSame('deep-ok', $res['body']['level']);
    }

    // ── Named routes / URL generation ───────────────────────

    public function testNamedRouteUrlGeneration(): void
    {
        $res = self::http('GET', '/url-for/99');
        $this->assertSame(200, $res['status']);
        $this->assertSame('/users/99', $res['body']['url']);
    }

    public function testNamedRouteWithQueryParams(): void
    {
        $res = self::http('GET', '/url-for-query');
        $this->assertSame(200, $res['status']);
        $this->assertSame('/users/1?page=2', $res['body']['url']);
    }

    // ── After-middleware ─────────────────────────────────────

    public function testAfterMiddlewareAddsHeader(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertSame('after-applied', $res['headers']['x-after'] ?? null);
    }

    // ── Container / DI injection ────────────────────────────

    public function testHandlerReceivesInjectedService(): void
    {
        $res = self::http('GET', '/di-test');
        $this->assertSame(200, $res['status']);
        $this->assertSame('injected-greeting', $res['body']['greeting']);
    }

    public function testExtendAndResolve(): void
    {
        $res = self::http('GET', '/resolve-test');
        $this->assertSame(200, $res['status']);
        $this->assertSame('extended-value', $res['body']['value']);
    }

    // ── All HTTP methods ────────────────────────────────────

    public function testPutMethod(): void
    {
        $res = self::http('PUT', '/resource/1', ['name' => 'updated']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('PUT', $res['body']['method']);
        $this->assertSame('1', $res['body']['id']);
        $this->assertSame('updated', $res['body']['body']['name']);
    }

    public function testPatchMethod(): void
    {
        $res = self::http('PATCH', '/resource/1', ['field' => 'patched']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('PATCH', $res['body']['method']);
    }

    public function testDeleteMethod(): void
    {
        $res = self::http('DELETE', '/resource/1');
        $this->assertSame(200, $res['status']);
        $this->assertSame('DELETE', $res['body']['method']);
        $this->assertSame('1', $res['body']['id']);
    }

    // ── Large body ──────────────────────────────────────────

    public function testLargeJsonRoundTrip(): void
    {
        // 50KB payload — well under the 100KB limit
        $data = str_repeat('x', 50000);
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/echo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $encoded = json_encode(['data' => $data]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($encoded),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        $this->assertSame(50000, strlen($decoded['body']['data']));
    }

    public function testPayloadTooLargeRejected(): void
    {
        // Server configured with max_body_bytes = 100000
        // Send an actual body that exceeds the limit (120KB)
        $largeBody = json_encode(['data' => str_repeat('y', 120000)]);
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/echo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $largeBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($largeBody),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(413, $status);
    }

    // ── Concurrent request isolation ────────────────────────

    public function testConcurrentRequestsDoNotLeak(): void
    {
        $mh = curl_multi_init();
        $handles = [];
        $ids = [10, 20, 30, 40, 50];

        foreach ($ids as $id) {
            $ch = curl_init('http://127.0.0.1:' . self::$port . '/users/' . $id);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        // Execute all in parallel
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $expectedId => $ch) {
            $body = json_decode(curl_multi_getcontent($ch), true);
            $this->assertSame((string) $expectedId, $body['id'], "Request for user $expectedId got wrong id");
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    // ── Lifecycle hooks ─────────────────────────────────────

    public function testRequestStartHookFires(): void
    {
        // onRequestStart fires before handle(), so the handler sees the flag
        $res = self::http('GET', '/hooks-check');
        $this->assertSame(200, $res['status']);
        $this->assertSame('fired', $res['body']['request_start']);
    }

    public function testRequestEndHookFires(): void
    {
        // onRequestEnd fires after emit(), so we need a warmup request first,
        // then the next request sees the flag from the previous request's end hook
        self::http('GET', '/ping'); // warmup — triggers onRequestEnd
        $res = self::http('GET', '/hooks-check');
        $this->assertSame(200, $res['status']);
        $this->assertSame('fired', $res['body']['request_end']);
    }

    // ── MySQL via Swoole server ────────────────────────────

    public function testMysqlPingViaServer(): void
    {
        $res = self::http('GET', '/mysql/ping');
        if ($res['status'] === 500) {
            $this->markTestSkipped('MySQL not available on server');
        }
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['body']['ok']);
    }

    public function testMysqlInsertAndQuery(): void
    {
        $res = self::http('POST', '/mysql/insert', ['name' => 'alice', 'value' => 'wonderland']);
        if ($res['status'] === 500) {
            $this->markTestSkipped('MySQL not available on server');
        }
        $this->assertSame(201, $res['status']);

        $res = self::http('GET', '/mysql/query');
        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['rows']);
        $this->assertSame('alice', $res['body']['rows'][0]['name']);
        $this->assertSame('wonderland', $res['body']['rows'][0]['value']);
    }

    public function testMysqlTransaction(): void
    {
        $res = self::http('POST', '/mysql/transaction', [
            'items' => [
                ['name' => 'tx1', 'value' => 'a'],
                ['name' => 'tx2', 'value' => 'b'],
            ],
        ]);
        if ($res['status'] === 500) {
            $this->markTestSkipped('MySQL not available on server');
        }
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['body']['committed']);

        $res = self::http('GET', '/mysql/query');
        // May include rows from previous test; at least 2 from this transaction
        $this->assertGreaterThanOrEqual(2, count($res['body']['rows']));
    }

    // ── Redis via Swoole server ─────────────────────────────

    public function testRedisPingViaServer(): void
    {
        $res = self::http('GET', '/redis/ping');
        if ($res['status'] === 500) {
            $this->markTestSkipped('Redis not available on server');
        }
        $this->assertSame(200, $res['status']);
        $this->assertSame('pong', $res['body']['pong']);
    }

    public function testRedisSetAndGet(): void
    {
        $res = self::http('POST', '/redis/set', ['key' => 'test:swoole', 'value' => 'hello']);
        if ($res['status'] === 500) {
            $this->markTestSkipped('Redis not available on server');
        }
        $this->assertSame(200, $res['status']);

        $res = self::http('GET', '/redis/get?key=test:swoole');
        $this->assertSame(200, $res['status']);
        $this->assertSame('hello', $res['body']['value']);
    }

    public function testRedisHashViaServer(): void
    {
        $res = self::http('POST', '/redis/hash', [
            'key' => 'test:hash',
            'data' => ['field1' => 'val1', 'field2' => 'val2'],
        ]);
        if ($res['status'] === 500) {
            $this->markTestSkipped('Redis not available on server');
        }
        $this->assertSame(200, $res['status']);

        $res = self::http('GET', '/redis/hash?key=test:hash&field=field1');
        $this->assertSame(200, $res['status']);
        $this->assertSame('val1', $res['body']['value']);
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    private static function http(string $method, string $path, ?array $jsonBody = null, array $extraHeaders = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $headers = $extraHeaders;
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($encoded);
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => json_decode((string) $body, true),
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Like http() but returns raw body string instead of JSON-decoding.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private static function httpRaw(string $method, string $path, array $extraHeaders = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if ($extraHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => (string) $body,
            'headers' => $responseHeaders,
        ];
    }

    private static function writeServerScript(): void
    {
        $script = <<<'PHP'
<?php
require '/workspaces/PHAPI/vendor/autoload.php';

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

// --- Service class for DI testing ---
class GreetingService {
    public function greet(): string { return 'injected-greeting'; }
}

// --- Hook tracking (shared via static) ---
class HookTracker {
    public static bool $requestStartFired = false;
    public static bool $requestEndFired = false;
}

$api = new PHAPI([
    'host' => '127.0.0.1',
    'port' => 9599,
    'debug' => true,
    'max_body_bytes' => 100000,
    'swoole' => ['worker_num' => 1, 'log_level' => SWOOLE_LOG_ERROR],
    'auth' => [
        'default' => 'token',
        'token_resolver' => function (string $token) {
            if ($token === 'secret') return ['id' => 1, 'roles' => ['admin']];
            if ($token === 'limited') return ['id' => 2, 'roles' => ['viewer']];
            return null;
        },
    ],
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'phapi',
        'password' => 'phapi_pass',
        'database' => 'phapi_test',
        'charset' => 'utf8mb4',
        'timeout' => 2.0,
        'pool_size' => 4,
        'pool_timeout' => 2.0,
    ],
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => null,
        'db' => 2,
        'timeout' => 2.0,
    ],
]);

// Create test table for MySQL tests
try {
    $pdo = new \PDO('mysql:host=127.0.0.1;port=3306;dbname=phapi_test', 'phapi', 'phapi_pass');
    $pdo->exec('CREATE TABLE IF NOT EXISTS swoole_test (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        value TEXT
    )');
    $pdo->exec('TRUNCATE TABLE swoole_test');
} catch (\Throwable $e) {
    // MySQL not available — MySQL tests will fail gracefully
}

$api->enableCORS();
$api->enableSecurityHeaders();

// --- Container / DI ---
$api->container()->singleton(GreetingService::class, fn() => new GreetingService());
$api->extend('custom-value', fn() => 'extended-value');

// --- Global middleware ---
$api->middleware(function (Request $req, callable $next) {
    $response = $next($req);
    return $response->withHeader('X-Global', 'applied');
});

// --- After-middleware ---
$api->afterMiddleware(function (Request $req, Response $res): Response {
    return $res->withHeader('X-After', 'after-applied');
});

// --- Lifecycle hooks ---
$api->onRequestStart(function (Request $request): void {
    HookTracker::$requestStartFired = true;
});
$api->onRequestEnd(function (Request $request, Response $response): void {
    HookTracker::$requestEndFired = true;
});

// ── Basic routes ─────────────────────────────────────────

$api->get('/ping', fn() => Response::json(['pong' => true]));

$api->get('/users/{id}', function () {
    $req = PHAPI::request();
    return Response::json(['id' => $req?->param('id')]);
})->name('users.show');

$api->get('/search/{query?}', function () {
    $req = PHAPI::request();
    return Response::json(['query' => $req?->param('query')]);
});

$api->post('/echo', function () {
    $req = PHAPI::request();
    return Response::json(['body' => $req?->body()]);
});

$api->get('/query', function () {
    $req = PHAPI::request();
    return Response::json($req?->queryAll() ?? []);
});

$api->get('/custom-header', fn() => Response::json(['ok' => true])->withHeader('X-Custom', 'test-value'));

$api->get('/throw', fn() => throw new \RuntimeException('Intentional test error'));

// ── Auth routes ──────────────────────────────────────────

$api->get('/protected', fn() => Response::json(['ok' => true]))
    ->middleware($api->requireAuth());

$api->get('/admin', fn() => Response::json(['ok' => true]))
    ->middleware($api->requireRole('admin'));

// ── Validation ───────────────────────────────────────────

$api->post('/validated', function () {
    $req = PHAPI::request();
    return Response::json(['created' => true, 'data' => $req?->body()], 201);
})->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
]);

// ── Streaming ────────────────────────────────────────────

$api->get('/stream/iterable', function () {
    return Response::stream(function () {
        yield 'chunk1';
        yield 'chunk2';
        yield 'chunk3';
    }, 200, ['Content-Type' => 'text/plain']);
});

$api->get('/stream/string', function () {
    return Response::stream(fn() => 'single-string-body', 200, ['Content-Type' => 'text/plain']);
});

$api->get('/stream/null', function () {
    return Response::stream(fn() => null, 200, ['Content-Type' => 'text/plain']);
});

// ── Route groups ─────────────────────────────────────────

$api->group('/api/v1', function (PHAPI $api) {
    $api->groupMiddleware(function (Request $req, callable $next) {
        $response = $next($req);
        return $response->withHeader('X-Api-Version', 'v1');
    });

    $api->get('/status', fn() => Response::json(['status' => 'v1-ok']));

    $api->group('/nested', function (PHAPI $api) {
        $api->get('/deep', fn() => Response::json(['level' => 'deep-ok']));
    });
});

// ── Named route URL generation ───────────────────────────

$api->get('/url-for/{id}', function () {
    $req = PHAPI::request();
    $app = PHAPI::app();
    return Response::json(['url' => $app?->url('users.show', ['id' => $req?->param('id')])]);
});

$api->get('/url-for-query', function () {
    $app = PHAPI::app();
    return Response::json(['url' => $app?->url('users.show', ['id' => 1], ['page' => 2])]);
});

// ── DI / Container ──────────────────────────────────────

$api->get('/di-test', function (Request $req, GreetingService $svc) {
    return Response::json(['greeting' => $svc->greet()]);
});

$api->get('/resolve-test', function () {
    $app = PHAPI::app();
    return Response::json(['value' => $app?->resolve('custom-value')]);
});

// ── All HTTP methods ─────────────────────────────────────

$api->put('/resource/{id}', function () {
    $req = PHAPI::request();
    return Response::json(['method' => 'PUT', 'id' => $req?->param('id'), 'body' => $req?->body()]);
});

$api->patch('/resource/{id}', function () {
    $req = PHAPI::request();
    return Response::json(['method' => 'PATCH', 'id' => $req?->param('id'), 'body' => $req?->body()]);
});

$api->delete('/resource/{id}', function () {
    $req = PHAPI::request();
    return Response::json(['method' => 'DELETE', 'id' => $req?->param('id')]);
});

// ── Lifecycle hooks check ────────────────────────────────

$api->get('/hooks-check', function () {
    return Response::json([
        'request_start' => HookTracker::$requestStartFired ? 'fired' : 'not-fired',
        'request_end' => HookTracker::$requestEndFired ? 'fired' : 'not-fired',
    ]);
});

// ── MySQL routes ─────────────────────────────────────────

$api->get('/mysql/ping', function () use ($api) {
    try {
        $rows = $api->mysql()->query('SELECT 1 AS ok');
        return Response::json(['ok' => (int)$rows[0]['ok']]);
    } catch (\Throwable $e) {
        return Response::error('MySQL error: ' . $e->getMessage(), 500);
    }
});

$api->post('/mysql/insert', function () use ($api) {
    $req = PHAPI::request();
    $body = $req?->body();
    $api->mysql()->execute(
        'INSERT INTO swoole_test (name, value) VALUES (?, ?)',
        [$body['name'] ?? '', $body['value'] ?? '']
    );
    return Response::json(['inserted' => true], 201);
});

$api->get('/mysql/query', function () use ($api) {
    $rows = $api->mysql()->query('SELECT name, value FROM swoole_test ORDER BY name');
    return Response::json(['rows' => $rows]);
});

$api->post('/mysql/transaction', function () use ($api) {
    $req = PHAPI::request();
    $items = $req?->body()['items'] ?? [];
    $api->mysql()->withConnection(function (\PDO $pdo) use ($items) {
        $pdo->beginTransaction();
        foreach ($items as $item) {
            $stmt = $pdo->prepare('INSERT INTO swoole_test (name, value) VALUES (?, ?)');
            $stmt->execute([$item['name'], $item['value']]);
        }
        $pdo->commit();
    });
    return Response::json(['committed' => count($items)]);
});

// ── Redis routes ─────────────────────────────────────────

$api->get('/redis/ping', function () use ($api) {
    try {
        $api->redis()->set('phapi:ping', 'pong', 10);
        $val = $api->redis()->get('phapi:ping');
        return Response::json(['pong' => $val]);
    } catch (\Throwable $e) {
        return Response::error('Redis error: ' . $e->getMessage(), 500);
    }
});

$api->post('/redis/set', function () use ($api) {
    $req = PHAPI::request();
    $body = $req?->body();
    $api->redis()->set($body['key'] ?? '', $body['value'] ?? '', $body['ttl'] ?? null);
    return Response::json(['set' => true]);
});

$api->get('/redis/get', function () use ($api) {
    $req = PHAPI::request();
    $key = $req?->query('key', '');
    $value = $api->redis()->get($key);
    return Response::json(['value' => $value]);
});

$api->post('/redis/hash', function () use ($api) {
    $req = PHAPI::request();
    $body = $req?->body();
    $api->redis()->hMSet($body['key'], $body['data']);
    return Response::json(['ok' => true]);
});

$api->get('/redis/hash', function () use ($api) {
    $req = PHAPI::request();
    $key = $req?->query('key', '');
    $field = $req?->query('field', '');
    $value = $api->redis()->hGet($key, $field);
    return Response::json(['value' => $value === false ? null : $value]);
});

$api->run();
PHP;

        file_put_contents(self::$serverScript, $script);
    }

    private static function startServer(): void
    {
        $cmd = sprintf('nohup php %s > /tmp/phapi_test.log 2>&1 & echo $!', self::$serverScript);
        $pid = (int) trim(shell_exec($cmd));
        self::$pid = $pid;

        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $ch = @curl_init('http://127.0.0.1:' . self::$port . '/ping');
            if ($ch) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200) {
                    $ready = true;
                    break;
                }
            }
            usleep(100_000);
        }

        if (!$ready) {
            self::stopServer();
            self::fail('Swoole server did not start. Log: ' . @file_get_contents('/tmp/phapi_test.log'));
        }
    }

    private static function stopServer(): void
    {
        if (self::$pid !== null) {
            posix_kill(self::$pid, SIGTERM);
            usleep(500_000);
            if (posix_kill(self::$pid, 0)) {
                posix_kill(self::$pid, SIGKILL);
                usleep(500_000);
            }
            self::$pid = null;
        }
    }
}
