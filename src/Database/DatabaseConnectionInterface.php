<?php

declare(strict_types=1);

namespace PHAPI\Database;

interface DatabaseConnectionInterface
{
    /**
     * Execute a statement without returning rows.
     *
     * @param string $sql
     * @return int
     */
    public function exec(string $sql): int;

    /**
     * Run a query and return a statement.
     *
     * @param string $sql
     * @return DatabaseStatementInterface
     */
    public function query(string $sql): DatabaseStatementInterface;

    /**
     * Prepare a statement for execution.
     *
     * @param string $sql
     * @return DatabaseStatementInterface
     */
    public function prepare(string $sql): DatabaseStatementInterface;
}
