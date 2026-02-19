<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHAPI\Services\RedisClient;
use PHAPI\Telemetry\TracingRedisClient;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class TracingRedisClientTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private TracingRedisClient $redis;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $inner = new RedisClient([
                'host' => $host,
                'port' => $port,
                'auth' => null,
                'db' => null,
                'timeout' => 2.0,
            ]);
            // Verify connection.
            $inner->set('__telemetry_ping', 'pong');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: ' . $e->getMessage());
        }

        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter)
        );
        $tracer = $this->tracerProvider->getTracer('test');
        $this->redis = new TracingRedisClient($inner, $tracer);

        // Clear setup spans.
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    public function testGetCreatesRedisSpan(): void
    {
        $this->redis->set('test:key', 'value');
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);

        $result = $this->redis->get('test:key');

        $this->assertSame('value', $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS GET', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        $this->assertSame('redis', $spans[0]->getAttributes()->get('db.system'));
        $this->assertSame('GET test:key', $spans[0]->getAttributes()->get('db.statement'));
    }

    public function testSetCreatesRedisSpan(): void
    {
        $result = $this->redis->set('test:set', 'hello');

        $this->assertTrue($result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS SET', $spans[0]->getName());
    }

    public function testHSetCreatesRedisSpan(): void
    {
        $this->redis->hSet('test:hash', 'field1', 'value1');

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS HSET', $spans[0]->getName());
        $this->assertSame('HSET test:hash', $spans[0]->getAttributes()->get('db.statement'));
    }

    public function testDelCreatesSpanWithMultipleKeys(): void
    {
        $this->redis->set('test:a', '1');
        $this->redis->set('test:b', '2');
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);

        $this->redis->del('test:a', 'test:b');

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS DEL', $spans[0]->getName());
        $this->assertSame('DEL test:a test:b', $spans[0]->getAttributes()->get('db.statement'));
    }

    public function testZAddCreatesSpan(): void
    {
        $this->redis->zAdd('test:sorted', 100, 'member1');

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS ZADD', $spans[0]->getName());
    }

    public function testExistsCreatesSpan(): void
    {
        $this->redis->set('test:exists', '1');
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);

        $result = $this->redis->exists('test:exists');

        $this->assertSame(1, $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS EXISTS', $spans[0]->getName());
    }

    public function testHGetCreatesSpan(): void
    {
        $this->redis->hSet('test:hget', 'field', 'val');
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);

        $result = $this->redis->hGet('test:hget', 'field');

        $this->assertSame('val', $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('REDIS HGET', $spans[0]->getName());
    }
}
