<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use Closure;
use Generator;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Expression;
use Hyperf\DbConnection\ConnectionResolver;
use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHAPI\Providers\OrmMysqlProvider;

final class OrmMysqlProviderTest extends SwooleTestCase
{
    public function testDatabaseServiceUsesResolver(): void
    {
        $api = new PHAPI([
            'providers' => [OrmMysqlProvider::class],
            'orm' => [
                'mysql' => [
                    'database' => 'app',
                    'username' => 'root',
                ],
            ],
        ]);

        $connection = new FakeConnection();
        $resolver = new FakeResolver($connection);

        $api->container()->singleton(ConnectionResolver::class, static function () use ($resolver) {
            return $resolver;
        });

        $db = $api->database();
        $tableResult = $db->table('users');
        $selectResult = $db->select('select * from users where id = ?', [1]);
        $statementResult = $db->statement('update users set name = ? where id = ?', ['Ada', 1]);
        $transactionResult = $db->transaction(static fn () => 'ok');

        self::assertSame('users', $connection->lastTable);
        self::assertSame('users', $tableResult->table);
        self::assertSame(['select * from users where id = ?', [1]], $connection->lastSelect);
        self::assertSame([['ok' => true]], $selectResult);
        self::assertSame(['update users set name = ? where id = ?', ['Ada', 1]], $connection->lastStatement);
        self::assertTrue($statementResult);
        self::assertSame(1, $connection->transactions);
        self::assertSame('ok', $transactionResult);
        self::assertSame($db, PHAPI::db());
    }

    public function testProviderValidatesConfig(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('PHAPI ORM MySQL config requires a database name.');

        new PHAPI([
            'providers' => [OrmMysqlProvider::class],
            'orm' => [
                'mysql' => [
                    'database' => '',
                    'username' => '',
                ],
            ],
        ]);
    }
}

final class FakeResolver implements ConnectionResolverInterface
{
    public ?string $lastConnection = null;
    private string $defaultConnection = 'default';

    public function __construct(private FakeConnection $connection)
    {
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        $this->lastConnection = $name;
        return $this->connection;
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }
}

final class FakeConnection implements ConnectionInterface
{
    public ?string $lastTable = null;
    /**
     * @var array<int, mixed>
     */
    public array $lastSelect = [];
    /**
     * @var array<int, mixed>
     */
    public array $lastStatement = [];
    public int $transactions = 0;

    public function table($table): Builder
    {
        $this->lastTable = (string)$table;
        return new FakeBuilder((string)$table);
    }

    public function raw($value): Expression
    {
        /** @var Expression $expression */
        $expression = (object)['value' => $value];
        return $expression;
    }

    public function selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
    {
        return $this->select($query, $bindings, $useReadPdo)[0] ?? null;
    }

    /**
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, bool>>
     */
    public function select(string $sql, array $bindings = [], bool $useReadPdo = true): array
    {
        $this->lastSelect = [$sql, $bindings];
        return [['ok' => true]];
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true): Generator
    {
        yield from $this->select($query, $bindings, $useReadPdo);
    }

    public function insert(string $query, array $bindings = []): bool
    {
        return true;
    }

    public function update(string $query, array $bindings = []): int
    {
        return 1;
    }

    public function delete(string $query, array $bindings = []): int
    {
        return 1;
    }

    /**
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return bool
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->lastStatement = [$sql, $bindings];
        return true;
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return 1;
    }

    public function unprepared(string $query): bool
    {
        return true;
    }

    public function prepareBindings(array $bindings): array
    {
        return $bindings;
    }

    /**
     * @param callable(): mixed $fn
     * @return mixed
     */
    public function transaction(Closure $fn, int $attempts = 1)
    {
        $this->transactions++;
        return $fn();
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    public function pretend(Closure $callback): array
    {
        $callback();
        return [];
    }
}

final class FakeBuilder extends Builder
{
    public string $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }
}
