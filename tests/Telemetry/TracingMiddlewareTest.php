<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Telemetry\TracingMiddleware;
use PHPUnit\Framework\TestCase;

final class TracingMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private TracingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter)
        );
        $tracer = $this->tracerProvider->getTracer('test');
        $propagator = TraceContextPropagator::getInstance();
        $this->middleware = new TracingMiddleware($tracer, $propagator);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    public function testCreatesServerSpanForRequest(): void
    {
        $request = new Request('GET', '/users/42', [], [], [], null, []);

        $response = ($this->middleware)($request, static fn () => Response::json(['ok' => true]));

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        $span = $spans[0];

        $this->assertSame('GET /users/42', $span->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame('GET', $span->getAttributes()->get('http.method'));
        $this->assertSame('/users/42', $span->getAttributes()->get('http.target'));
        $this->assertSame(200, $span->getAttributes()->get('http.status_code'));
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function testSetsErrorStatusOn500(): void
    {
        $request = new Request('POST', '/fail', [], [], [], null, []);

        $response = ($this->middleware)($request, static fn () => Response::error('fail', 500));

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame(500, $spans[0]->getAttributes()->get('http.status_code'));
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testRecordsExceptionAndRethrows(): void
    {
        $request = new Request('GET', '/throw', [], [], [], null, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        try {
            ($this->middleware)($request, static function (): never {
                throw new \RuntimeException('test error');
            });
        } finally {
            $this->tracerProvider->forceFlush();
            $spans = $this->exporter->getSpans();
            $this->assertCount(1, $spans);
            $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            $this->assertNotEmpty($spans[0]->getEvents());
        }
    }

    public function testExtractsTraceContextFromHeaders(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $spanId = 'b7ad6b7169203331';
        $traceparent = "00-{$traceId}-{$spanId}-01";

        $request = new Request('GET', '/traced', [], ['traceparent' => $traceparent], [], null, []);

        ($this->middleware)($request, static fn () => Response::json(['ok' => true]));

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        // The span should have a parent context with the injected trace ID.
        $this->assertSame($traceId, $spans[0]->getContext()->getTraceId());
    }

    public function testSetsRequestIdAttribute(): void
    {
        $request = new Request('GET', '/with-id', [], ['x-request-id' => 'req-123'], [], null, []);

        ($this->middleware)($request, static fn () => Response::json(['ok' => true]));

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('req-123', $spans[0]->getAttributes()->get('http.request_id'));
    }

    public function testSetsUserAgentAttribute(): void
    {
        $request = new Request('GET', '/ua', [], ['user-agent' => 'TestAgent/1.0'], [], null, []);

        ($this->middleware)($request, static fn () => Response::json(['ok' => true]));

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('TestAgent/1.0', $spans[0]->getAttributes()->get('http.user_agent'));
    }
}
