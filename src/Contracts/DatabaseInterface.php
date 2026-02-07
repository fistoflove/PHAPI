<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface DatabaseInterface
{
    /**
     * @param string $table
     * @return object
     */
    public function table(string $table): object;

    /**
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return bool
     */
    public function statement(string $sql, array $bindings = []): bool;

    /**
     * @param callable(): mixed $fn
     * @return mixed
     */
    public function transaction(callable $fn);

    /**
     * @return object
     */
    public function connection(): object;
}
