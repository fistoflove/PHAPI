<?php

declare(strict_types=1);

namespace PHAPI\Database;

use PDOStatement;

final class SqliteStatement implements DatabaseStatementInterface
{
    private PDOStatement $statement;

    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function execute(array $params = []): bool
    {
        return $this->statement->execute($params);
    }

    public function fetch(): array|false
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->statement->fetch();
        return $row;
    }

    public function fetchAll(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->statement->fetchAll();
        return $rows;
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }
}
