<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use PHPUnit\Framework\TestCase;

/**
 * Boots a real Swoole HTTP server with OpenTelemetry tracing enabled and
 * verifies spans are created correctly under concurrent request handling.
 *
 * Requires Docker environment (Swoole extension, ext-curl).
 *
 * @group integration
 */
final class TracingIntegrationTest extends TestCase
{
    private static int $port = 9598;
    private static string $serverScript = '/tmp/phapi_tracing_test_server.php';
    private static string $spansFile = '/tmp/phapi_tracing_test_spans.json';
    private static ?int $pid = null;

    public static function setUpBeforeClass(): void
    {
        @unlink(self::$spansFile);
        self::writeServerScript();
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        @unlink(self::$serverScript);
        @unlink(self::$spansFile);
    }

    public function testRootSpanCreatedForRequest(): void
    {
        $res = self::http('GET', '/ping');
        $this->assertSame(200, $res['status']);

        // Give the SimpleSpanProcessor time to write.
        usleep(100_000);

        $spans = self::readSpans();
        $serverSpans = array_filter($spans, static fn (array $s): bool => ($s['kind'] ?? '') === 'SERVER');
        $this->assertNotEmpty($serverSpans, 'Should have at least one SERVER span');

        $pingSpan = array_filter($serverSpans, static fn (array $s): bool => str_contains($s['name'] ?? '', '/ping'));
        $this->assertNotEmpty($pingSpan, 'Should have a span for GET /ping');
    }

    public function testMySqlSpanCreated(): void
    {
        $res = self::http('GET', '/mysql/ping');

        // MySQL may not be available in all test environments.
        if ($res['status'] !== 200) {
            $this->markTestSkipped('MySQL not available');
        }

        usleep(100_000);

        $spans = self::readSpans();
        $dbSpans = array_filter($spans, static fn (array $s): bool => ($s['attributes']['db.system'] ?? '') === 'mysql');
        $this->assertNotEmpty($dbSpans, 'Should have MySQL spans');
    }

    public function testRedisSpanCreated(): void
    {
        $res = self::http('GET', '/redis/ping');

        if ($res['status'] !== 200) {
            $this->markTestSkipped('Redis not available');
        }

        usleep(100_000);

        $spans = self::readSpans();
        $redisSpans = array_filter($spans, static fn (array $s): bool => ($s['attributes']['db.system'] ?? '') === 'redis');
        $this->assertNotEmpty($redisSpans, 'Should have Redis spans');
    }

    public function testConcurrentRequestsHaveIsolatedTraces(): void
    {
        // Clear spans file before concurrent test.
        @file_put_contents(self::$spansFile, '');

        // Fire 5 concurrent requests.
        $multiCurl = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init('http://127.0.0.1:' . self::$port . '/slow?id=' . $i);
            \assert($ch !== false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_multi_add_handle($multiCurl, $ch);
            $handles[] = $ch;
        }

        // Execute all concurrently.
        do {
            $status = curl_multi_exec($multiCurl, $active);
            if ($active) {
                curl_multi_select($multiCurl, 1.0);
            }
        } while ($active > 0 && $status === CURLM_OK);

        foreach ($handles as $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->assertSame(200, $code, "Concurrent request failed: {$body}");
            curl_multi_remove_handle($multiCurl, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiCurl);

        usleep(200_000);

        $spans = self::readSpans();
        $serverSpans = array_filter($spans, static fn (array $s): bool => ($s['kind'] ?? '') === 'SERVER' && str_contains($s['name'] ?? '', '/slow'));

        // Each of the 5 requests should produce its own SERVER span.
        $this->assertGreaterThanOrEqual(5, count($serverSpans), 'Each concurrent request should have its own span');

        // Verify all spans have distinct span IDs (no context mixing).
        $spanIds = array_map(static fn (array $s): string => $s['span_id'] ?? '', $serverSpans);
        $this->assertSame(count($spanIds), count(array_unique($spanIds)), 'All span IDs should be unique — no context mixing');
    }

    public function testTraceContextPropagation(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $spanId = 'b7ad6b7169203331';
        $traceparent = "00-{$traceId}-{$spanId}-01";

        @file_put_contents(self::$spansFile, '');

        $res = self::http('GET', '/ping', null, ['traceparent: ' . $traceparent]);
        $this->assertSame(200, $res['status']);

        usleep(100_000);

        $spans = self::readSpans();
        $pingSpans = array_filter($spans, static fn (array $s): bool => str_contains($s['name'] ?? '', '/ping'));
        $this->assertNotEmpty($pingSpans);

        $pingSpan = array_values($pingSpans)[0];
        $this->assertSame($traceId, $pingSpan['trace_id'] ?? '', 'Span should inherit trace ID from traceparent header');
    }

    public function testErrorSpanRecordedFor500(): void
    {
        @file_put_contents(self::$spansFile, '');

        $res = self::http('GET', '/throw');
        $this->assertSame(500, $res['status']);

        usleep(100_000);

        $spans = self::readSpans();
        $errorSpans = array_filter($spans, static fn (array $s): bool => ($s['status_code'] ?? '') === 'ERROR');
        $this->assertNotEmpty($errorSpans, 'Should have an ERROR span for 500 response');
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * @param array<int, string> $extraHeaders
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    private static function http(string $method, string $path, mixed $body = null, array $extraHeaders = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        \assert($ch !== false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = $extraHeaders;
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'body' => $decoded ?? (string) $raw,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readSpans(): array
    {
        if (!file_exists(self::$spansFile)) {
            return [];
        }

        $content = file_get_contents(self::$spansFile);
        if ($content === false || $content === '') {
            return [];
        }

        $spans = [];
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $spans[] = $decoded;
            }
        }

        return $spans;
    }

    private static function writeServerScript(): void
    {
        $spansFile = self::$spansFile;
        $port = self::$port;
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

        $script = <<<PHP
<?php
require '{$autoloadPath}';

use PHAPI\\HTTP\\Request;
use PHAPI\\HTTP\\Response;
use PHAPI\\PHAPI;
use OpenTelemetry\\SDK\\Trace\\SpanExporter\\InMemoryExporter;
use OpenTelemetry\\SDK\\Trace\\SpanProcessor\\SimpleSpanProcessor;
use OpenTelemetry\\SDK\\Trace\\TracerProvider;
use OpenTelemetry\\API\\Trace\\TracerInterface;
use OpenTelemetry\\API\\Trace\\Propagation\\TraceContextPropagator;
use PHAPI\\Telemetry\\TracingMiddleware;
use PHAPI\\Telemetry\\TracingHttpClient;
use PHAPI\\Telemetry\\TracingMySqlPool;
use PHAPI\\Telemetry\\TracingRedisClient;

// Use a file-based span exporter for assertions.
// Each span is written as a JSON line to the spans file.
class FileSpanExporter implements \\OpenTelemetry\\SDK\\Trace\\SpanExporterInterface
{
    private string \$path;

    public function __construct(string \$path) { \$this->path = \$path; }

    public static function fromConnectionString(string \$endpointUrl, string \$name, string \$args): static
    {
        return new static(\$endpointUrl);
    }

    public function export(iterable \$batch, ?\\OpenTelemetry\\SDK\\Common\\Future\\CancellationInterface \$cancellation = null): \\OpenTelemetry\\SDK\\Common\\Future\\FutureInterface
    {
        \$lines = '';
        foreach (\$batch as \$span) {
            \$lines .= json_encode([
                'name' => \$span->getName(),
                'kind' => match(\$span->getKind()) {
                    \\OpenTelemetry\\API\\Trace\\SpanKind::KIND_SERVER => 'SERVER',
                    \\OpenTelemetry\\API\\Trace\\SpanKind::KIND_CLIENT => 'CLIENT',
                    default => 'INTERNAL',
                },
                'trace_id' => \$span->getContext()->getTraceId(),
                'span_id' => \$span->getContext()->getSpanId(),
                'parent_span_id' => \$span->getParentContext()->getSpanId(),
                'status_code' => match(\$span->getStatus()->getCode()) {
                    \\OpenTelemetry\\API\\Trace\\StatusCode::STATUS_ERROR => 'ERROR',
                    \\OpenTelemetry\\API\\Trace\\StatusCode::STATUS_OK => 'OK',
                    default => 'UNSET',
                },
                'attributes' => iterator_to_array(\$span->getAttributes()),
            ]) . "\\n";
        }
        file_put_contents(\$this->path, \$lines, FILE_APPEND | LOCK_EX);

        return new \\OpenTelemetry\\SDK\\Common\\Future\\CompletedFuture(
            \\OpenTelemetry\\SDK\\Trace\\SpanExporterInterface::STATUS_SUCCESS
        );
    }

    public function shutdown(?\\OpenTelemetry\\SDK\\Common\\Future\\CancellationInterface \$cancellation = null): bool { return true; }
    public function forceFlush(?\\OpenTelemetry\\SDK\\Common\\Future\\CancellationInterface \$cancellation = null): bool { return true; }
}

// Install Swoole context storage before anything else.
\\OpenTelemetry\\Context\\Context::setStorage(
    new \\OpenTelemetry\\Contrib\\Context\\Swoole\\SwooleContextStorage(
        \\OpenTelemetry\\Context\\Context::storage()
    )
);

\$exporter = new FileSpanExporter('{$spansFile}');
\$tracerProvider = new TracerProvider(new SimpleSpanProcessor(\$exporter));
\$tracer = \$tracerProvider->getTracer('integration-test', '1.0.0');
\$propagator = TraceContextPropagator::getInstance();

\$api = new PHAPI([
    'host' => '127.0.0.1',
    'port' => {$port},
    'debug' => true,
    'swoole' => ['worker_num' => 2, 'log_level' => SWOOLE_LOG_ERROR],
    'mysql' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'user' => getenv('DB_USERNAME') ?: 'phapi',
        'password' => getenv('DB_PASSWORD') ?: 'phapi',
        'database' => getenv('DB_DATABASE') ?: 'phapi_test',
        'pool_size' => 2,
    ],
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'db' => 2,
    ],
]);

// Install tracing middleware manually (no service provider in this test —
// we control the TracerProvider ourselves for file-based export).
\$api->middleware(new TracingMiddleware(\$tracer, \$propagator));

// Wrap HttpClient.
\$innerHttp = \$api->container()->get(\\PHAPI\\Services\\HttpClient::class);
\$api->container()->set(\\PHAPI\\Services\\HttpClient::class, new TracingHttpClient(\$innerHttp, \$tracer, \$propagator));

// Routes.
\$api->get('/ping', fn() => Response::json(['pong' => true]));

\$api->get('/slow', function () {
    \$req = PHAPI::request();
    \\Swoole\\Coroutine::sleep(0.05); // 50ms delay to test concurrency
    return Response::json(['id' => \$req?->query('id')]);
});

\$api->get('/throw', fn() => throw new \\RuntimeException('Intentional test error'));

\$api->get('/mysql/ping', function () use (\$api, \$tracer) {
    try {
        \$pool = new TracingMySqlPool(\$api->services()->mysql(), \$tracer);
        \$rows = \$pool->query('SELECT 1 AS ok');
        return Response::json(['ok' => (int)\$rows[0]['ok']]);
    } catch (\\Throwable \$e) {
        return Response::error('MySQL error: ' . \$e->getMessage(), 500);
    }
});

\$api->get('/redis/ping', function () use (\$api, \$tracer) {
    try {
        \$client = new TracingRedisClient(\$api->services()->redis(), \$tracer);
        \$client->set('otel:test', 'pong', 10);
        \$val = \$client->get('otel:test');
        return Response::json(['pong' => \$val]);
    } catch (\\Throwable \$e) {
        return Response::error('Redis error: ' . \$e->getMessage(), 500);
    }
});

\$api->onShutdown(static function () use (\$tracerProvider): void {
    \$tracerProvider->shutdown();
});

\$api->run();
PHP;

        file_put_contents(self::$serverScript, $script);
    }

    private static function startServer(): void
    {
        $cmd = sprintf('nohup php %s > /tmp/phapi_tracing_test.log 2>&1 & echo $!', self::$serverScript);
        $pid = (int) trim((string) shell_exec($cmd));
        self::$pid = $pid;

        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $ch = @curl_init('http://127.0.0.1:' . self::$port . '/ping');
            if ($ch !== false) {
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
            self::fail('Tracing test server did not start. Log: ' . @file_get_contents('/tmp/phapi_tracing_test.log'));
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
