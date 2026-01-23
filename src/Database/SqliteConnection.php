<?php

declare(strict_types=1);

namespace PHAPI\Database;

use PDO;
use PDOStatement;

final class SqliteConnection implements DatabaseConnectionInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function exec(string $sql): int
    {
        return (int)$this->pdo->exec($sql);
    }

    public function query(string $sql): DatabaseStatementInterface
    {
        $stmt = $this->pdo->query($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new \RuntimeException('SQLite query failed.');
        }
        return new SqliteStatement($stmt);
    }

    public function prepare(string $sql): DatabaseStatementInterface
    {
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new \RuntimeException('SQLite prepare failed.');
        }
        return new SqliteStatement($stmt);
    }
}
