<?php

declare(strict_types=1);

namespace PHAPI\Tests\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHAPI\Services\MySqlPool;
use PHAPI\Telemetry\TracingMySqlPool;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class TracingMySqlPoolTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private TracingMySqlPool $pool;

    protected function setUp(): void
    {
        $inner = new MySqlPool([
            'host' => getenv('DB_HOST') ?: 'mysql',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'user' => getenv('DB_USERNAME') ?: 'phapi',
            'password' => getenv('DB_PASSWORD') ?: 'phapi',
            'database' => getenv('DB_DATABASE') ?: 'phapi_test',
            'charset' => 'utf8mb4',
            'timeout' => 5.0,
            'pool_size' => 1,
            'pool_timeout' => 5.0,
        ]);

        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($this->exporter)
        );
        $tracer = $this->tracerProvider->getTracer('test');
        $this->pool = new TracingMySqlPool($inner, $tracer);

        try {
            $this->pool->execute('CREATE TABLE IF NOT EXISTS telemetry_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
            $this->pool->execute('TRUNCATE TABLE telemetry_test');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }

        // Clear exporter from setup queries.
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    public function testSelectCreatesSpanWithTableName(): void
    {
        $this->pool->query('SELECT * FROM telemetry_test WHERE id = ?', [1]);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('SELECT telemetry_test', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        $this->assertSame('mysql', $spans[0]->getAttributes()->get('db.system'));
        $this->assertSame('SELECT', $spans[0]->getAttributes()->get('db.operation'));
        $this->assertStringContainsString('SELECT * FROM telemetry_test', $spans[0]->getAttributes()->get('db.statement'));
    }

    public function testInsertCreatesSpan(): void
    {
        $this->pool->execute('INSERT INTO telemetry_test (name) VALUES (?)', ['alice']);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('INSERT telemetry_test', $spans[0]->getName());
        $this->assertSame('INSERT', $spans[0]->getAttributes()->get('db.operation'));
    }

    public function testUpdateCreatesSpan(): void
    {
        $this->pool->execute('INSERT INTO telemetry_test (name) VALUES (?)', ['bob']);
        $this->tracerProvider->forceFlush();
        $this->exporter->getStorage()->exchangeArray([]);

        $this->pool->execute('UPDATE telemetry_test SET name = ? WHERE name = ?', ['robert', 'bob']);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);

        $this->assertCount(1, $spans);
        $this->assertSame('UPDATE telemetry_test', $spans[0]->getName());
    }

    public function testExceptionIsRecordedAndRethrown(): void
    {
        $this->expectException(\Throwable::class);

        try {
            $this->pool->query('SELECT * FROM nonexistent_table_xyz');
        } finally {
            $this->tracerProvider->forceFlush();
            $spans = $this->exporter->getStorage()->exchangeArray([]);
            $this->assertCount(1, $spans);
            $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            $this->assertNotEmpty($spans[0]->getEvents());
        }
    }

    public function testWithConnectionDelegatesWithoutSpan(): void
    {
        $result = $this->pool->withConnection(static function (\PDO $pdo): string {
            return 'direct';
        });

        $this->assertSame('direct', $result);

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);
        $this->assertCount(0, $spans);
    }

    public function testTruncatesLongSqlStatements(): void
    {
        $longCondition = str_repeat('a', 2000);
        try {
            $this->pool->query("SELECT * FROM telemetry_test WHERE name = '{$longCondition}'");
        } catch (\Throwable) {
            // May fail — we only care about the span.
        }

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getStorage()->exchangeArray([]);
        $this->assertCount(1, $spans);
        $stmt = $spans[0]->getAttributes()->get('db.statement');
        $this->assertIsString($stmt);
        $this->assertLessThanOrEqual(1027, strlen($stmt)); // 1024 + '...'
    }
}
