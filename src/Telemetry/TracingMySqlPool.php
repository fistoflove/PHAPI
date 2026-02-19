<?php

declare(strict_types=1);

namespace PHAPI\Telemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHAPI\Services\MySqlPool;

/**
 * Decorator that wraps MySqlPool to create spans for database operations.
 *
 * MySqlPool is `final`, so this uses composition (delegation) rather
 * than inheritance.  Only `query()` and `execute()` are instrumented;
 * lower-level pool operations (`acquire`, `releaseConnection`,
 * `withConnection`, `current`) delegate directly to avoid double-
 * spanning when called by query/execute internally.
 */
final class TracingMySqlPool
{
    private MySqlPool $inner;
    private TracerInterface $tracer;

    public function __construct(MySqlPool $inner, TracerInterface $tracer)
    {
        $this->inner = $inner;
        $this->tracer = $tracer;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $spanName = $this->spanNameFromSql($sql);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'mysql')
            ->setAttribute('db.statement', $this->truncate($sql))
            ->setAttribute('db.operation', $this->operationFromSql($sql))
            ->startSpan();

        $scope = $span->activate();

        try {
            return $this->inner->query($sql, $params);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param array<int, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        $spanName = $this->spanNameFromSql($sql);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'mysql')
            ->setAttribute('db.statement', $this->truncate($sql))
            ->setAttribute('db.operation', $this->operationFromSql($sql))
            ->startSpan();

        $scope = $span->activate();

        try {
            return $this->inner->execute($sql, $params);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param callable(\PDO): mixed $callback
     */
    public function withConnection(callable $callback): mixed
    {
        return $this->inner->withConnection($callback);
    }

    public function acquire(): \PDO
    {
        return $this->inner->acquire();
    }

    public function releaseConnection(\PDO $pdo): void
    {
        $this->inner->releaseConnection($pdo);
    }

    public function current(): \PDO
    {
        return $this->inner->current();
    }

    /**
     * Parse a span name like "SELECT proposals" from an SQL statement.
     *
     * @return non-empty-string
     */
    private function spanNameFromSql(string $sql): string
    {
        $operation = $this->operationFromSql($sql);
        $table = $this->tableFromSql($sql);

        if ($table !== '') {
            return $operation . ' ' . $table;
        }

        return $operation;
    }

    /**
     * @return non-empty-string
     */
    private function operationFromSql(string $sql): string
    {
        $normalized = ltrim($sql);
        $space = strpos($normalized, ' ');
        if ($space === false) {
            return $normalized !== '' ? strtoupper($normalized) : 'SQL';
        }

        $op = strtoupper(substr($normalized, 0, $space));

        return $op !== '' ? $op : 'SQL';
    }

    private function tableFromSql(string $sql): string
    {
        // SELECT ... FROM table
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $m) === 1) {
            return $m[1];
        }

        // INSERT INTO table
        if (preg_match('/\bINTO\s+`?(\w+)`?/i', $sql, $m) === 1) {
            return $m[1];
        }

        // UPDATE table
        if (preg_match('/\bUPDATE\s+`?(\w+)`?/i', $sql, $m) === 1) {
            return $m[1];
        }

        // DELETE FROM table
        if (preg_match('/\bDELETE\s+FROM\s+`?(\w+)`?/i', $sql, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function truncate(string $sql): string
    {
        if (strlen($sql) <= 1024) {
            return $sql;
        }

        return substr($sql, 0, 1024) . '...';
    }
}
