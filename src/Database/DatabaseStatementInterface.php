<?php

declare(strict_types=1);

namespace PHAPI\Database;

interface DatabaseStatementInterface
{
    /**
     * Execute the prepared statement.
     *
     * @param array<int|string, mixed> $params
     * @return bool
     */
    public function execute(array $params = []): bool;

    /**
     * Fetch the next row.
     *
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false;

    /**
     * Fetch all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Get the affected row count.
     *
     * @return int
     */
    public function rowCount(): int;
}
