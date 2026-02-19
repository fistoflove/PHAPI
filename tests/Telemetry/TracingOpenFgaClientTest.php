<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Telemetry\TracingOpenFgaClient;
use PHPUnit\Framework\TestCase;

final class TracingOpenFgaClientTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private TracingOpenFgaClient $client;
    /** @var OpenFgaClient&\PHPUnit\Framework\MockObject\MockObject */
    private OpenFgaClient $inner;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter)
        );
        $tracer = $this->tracerProvider->getTracer('test');
        $this->inner = $this->createMock(OpenFgaClient::class);
        $this->client = new TracingOpenFgaClient($this->inner, $tracer);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    public function testCheckCreatesFgaSpanWithAttributes(): void
    {
        $this->inner->method('check')->willReturn(true);

        $result = $this->client->check('user:alice', 'viewer', 'document:readme');

        $this->assertTrue($result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertCount(1, $spans);
        $this->assertSame('FGA check', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        $this->assertSame('user:alice', $spans[0]->getAttributes()->get('fga.user'));
        $this->assertSame('viewer', $spans[0]->getAttributes()->get('fga.relation'));
        $this->assertSame('document:readme', $spans[0]->getAttributes()->get('fga.object'));
    }

    public function testListObjectsCreatesFgaSpan(): void
    {
        $this->inner->method('listObjects')->willReturn(['doc:1', 'doc:2']);

        $result = $this->client->listObjects('user:bob', 'editor', 'document');

        $this->assertSame(['doc:1', 'doc:2'], $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('FGA listObjects', $spans[0]->getName());
        $this->assertSame('user:bob', $spans[0]->getAttributes()->get('fga.user'));
        $this->assertSame('editor', $spans[0]->getAttributes()->get('fga.relation'));
        $this->assertSame('document', $spans[0]->getAttributes()->get('fga.object_type'));
    }

    public function testWriteTuplesCreatesFgaSpan(): void
    {
        $this->inner->expects($this->once())
            ->method('writeTuples');

        $tuples = [
            ['user' => 'user:alice', 'relation' => 'viewer', 'object' => 'doc:1'],
        ];

        $this->client->writeTuples($tuples);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('FGA writeTuples', $spans[0]->getName());
        $this->assertSame(1, $spans[0]->getAttributes()->get('fga.tuple_count'));
    }

    public function testBatchCheckCreatesFgaSpan(): void
    {
        $this->inner->method('batchCheck')->willReturn(['c1' => true, 'c2' => false]);

        $checks = [
            ['user' => 'user:a', 'relation' => 'viewer', 'object' => 'doc:1', 'correlation_id' => 'c1'],
            ['user' => 'user:b', 'relation' => 'viewer', 'object' => 'doc:1', 'correlation_id' => 'c2'],
        ];

        $result = $this->client->batchCheck($checks);

        $this->assertSame(['c1' => true, 'c2' => false], $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('FGA batchCheck', $spans[0]->getName());
        $this->assertSame(2, $spans[0]->getAttributes()->get('fga.batch_size'));
    }

    public function testRecordsExceptionFromFga(): void
    {
        $this->inner->method('check')
            ->willThrowException(new \RuntimeException('FGA unavailable'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->client->check('user:x', 'rel', 'obj:1');
        } finally {
            $this->tracerProvider->forceFlush();
            $spans = $this->exporter->getSpans();
            $this->assertCount(1, $spans);
            $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        }
    }

    public function testListUsersCreatesFgaSpan(): void
    {
        $this->inner->method('listUsers')->willReturn(['user:a', 'user:b']);

        $this->client->listUsers('doc:1', 'viewer', 'user');

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('FGA listUsers', $spans[0]->getName());
        $this->assertSame('doc:1', $spans[0]->getAttributes()->get('fga.object'));
    }

    public function testReadTuplesCreatesSpan(): void
    {
        $this->inner->method('readTuples')->willReturn([]);

        $this->client->readTuples('user:x', 'viewer', null);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();

        $this->assertSame('FGA readTuples', $spans[0]->getName());
        $this->assertSame('user:x', $spans[0]->getAttributes()->get('fga.user'));
        $this->assertSame('viewer', $spans[0]->getAttributes()->get('fga.relation'));
    }
}
