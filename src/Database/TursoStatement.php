<?php

declare(strict_types=1);

namespace PHAPI\Database;

final class TursoStatement implements DatabaseStatementInterface
{
    private TursoConnection $connection;
    private string $sql;
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $rows = [];
    private int $index = 0;

    public function __construct(TursoConnection $connection, string $sql)
    {
        $this->connection = $connection;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        $normalized = array_is_list($params) ? $params : array_values($params);
        $this->rows = $this->connection->execute($this->sql, $normalized);
        $this->index = 0;
        return true;
    }

    public function fetch(): array|false
    {
        if (!isset($this->rows[$this->index])) {
            return false;
        }

        return $this->rows[$this->index++];
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
