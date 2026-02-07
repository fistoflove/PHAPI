<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Exceptions\DatabaseException;

final class Database implements DatabaseInterface
{
    private object $resolver;
    private bool $debug;

    public function __construct(object $resolver, bool $debug = false)
    {
        $this->resolver = $resolver;
        $this->debug = $debug;
    }

    public function table(string $table): object
    {
        try {
            return $this->resolver->connection('default')->table($table);
        } catch (\Throwable $e) {
            throw $this->wrapException($e, 'table:' . $table, []);
        }
    }

    public function select(string $sql, array $bindings = []): array
    {
        try {
            return $this->resolver->connection()->select($sql, $bindings);
        } catch (\Throwable $e) {
            throw $this->wrapException($e, $sql, $bindings);
        }
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        try {
            return $this->resolver->connection()->statement($sql, $bindings);
        } catch (\Throwable $e) {
            throw $this->wrapException($e, $sql, $bindings);
        }
    }

    public function transaction(callable $fn)
    {
        try {
            return $this->resolver->connection()->transaction($fn);
        } catch (\Throwable $e) {
            throw $this->wrapException($e, 'transaction', []);
        }
    }

    public function connection(): object
    {
        return $this->resolver->connection();
    }

    /**
     * @param \Throwable $exception
     * @param string|null $sql
     * @param array<int, mixed> $bindings
     * @return DatabaseException
     */
    private function wrapException(\Throwable $exception, ?string $sql, array $bindings): DatabaseException
    {
        $message = 'Database query failed.';
        if ($this->debug && $sql !== null) {
            $bindingInfo = $bindings === [] ? '' : ' Bindings: ' . json_encode($bindings);
            $message = sprintf('Database query failed. SQL: %s%s', $sql, $bindingInfo);
        }

        return new DatabaseException($message, $exception, $sql, $bindings);
    }
}
