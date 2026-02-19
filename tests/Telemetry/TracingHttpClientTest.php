<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHAPI\Services\HttpClient;
use PHAPI\Telemetry\TracingHttpClient;
use PHPUnit\Framework\TestCase;

final class TracingHttpClientTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private TracingHttpClient $client;
    /** @var HttpClient&\PHPUnit\Framework\MockObject\MockObject */
    private HttpClient $inner;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter)
        );
        $tracer = $this->tracerProvider->getTracer('test');
        $propagator = TraceContextPropagator::getInstance();
        $this->inner = $this->createMock(HttpClient::class);
        $this->client = new TracingHttpClient($this->inner, $tracer, $propagator);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    public function testGetJsonCreatesClientSpan(): void
    {
        $this->inner->method('getJson')
            ->willReturnCallback(function (string $url, array $headers): array {
                // Verify trace headers are injected.
                $this->assertArrayHasKey('traceparent', $headers);
                return ['ok' => true];
            });

        $result = $this->client->getJson('http://example.com/api/test');

        $this->assertSame(['ok' => true], $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame('HTTP GET', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        $this->assertSame('GET', $spans[0]->getAttributes()->get('http.method'));
        $this->assertSame('http://example.com/api/test', $spans[0]->getAttributes()->get('http.url'));
        $this->assertSame('example.com', $spans[0]->getAttributes()->get('net.peer.name'));
    }

    public function testPostJsonWithMetaRecordsStatusCode(): void
    {
        $this->inner->method('postJsonWithMeta')
            ->willReturn(['data' => null, 'status' => 201, 'body' => '']);

        $result = $this->client->postJsonWithMeta('http://api.local/create', ['name' => 'test']);

        $this->assertSame(201, $result['status']);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame('HTTP POST', $spans[0]->getName());
        $this->assertSame(201, $spans[0]->getAttributes()->get('http.status_code'));
    }

    public function testSetsErrorStatusOn500Response(): void
    {
        $this->inner->method('getJsonWithMeta')
            ->willReturn(['data' => null, 'status' => 500, 'body' => 'Internal error']);

        $this->client->getJsonWithMeta('http://api.local/fail');

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testRecordsExceptionFromInnerClient(): void
    {
        $this->inner->method('postJson')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->client->postJson('http://api.local/down', []);
        } finally {
            $this->tracerProvider->forceFlush();
            $spans = $this->exporter->getSpans();
            $this->assertCount(1, $spans);
            $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            $this->assertNotEmpty($spans[0]->getEvents());
        }
    }

    public function testPostFormWithMetaInjectsTraceHeaders(): void
    {
        $this->inner->method('postFormWithMeta')
            ->willReturnCallback(function (string $url, array $form, array $headers): array {
                $this->assertArrayHasKey('traceparent', $headers);
                return ['data' => null, 'status' => 200, 'body' => ''];
            });

        $this->client->postFormWithMeta('http://api.local/form', ['key' => 'val']);

        $this->tracerProvider->forceFlush();
        $this->assertCount(1, $this->exporter->getSpans());
    }
}
