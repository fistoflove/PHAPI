<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use Hyperf\DbConnection\ConnectionResolver;
use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHAPI\Providers\OrmMysqlProvider;

final class OrmMysqlProviderTest extends SwooleTestCase
{
    public function testDatabaseServiceUsesResolver(): void
    {
        $api = new PHAPI([
            'runtime' => 'swoole',
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
            'runtime' => 'swoole',
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

final class FakeResolver
{
    public ?string $lastConnection = null;

    public function __construct(private FakeConnection $connection)
    {
    }

    public function connection(?string $name = null): FakeConnection
    {
        $this->lastConnection = $name;
        return $this->connection;
    }
}

final class FakeConnection
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

    public function table(string $table): object
    {
        $this->lastTable = $table;
        return (object)['table' => $table];
    }

    /**
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, bool>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $this->lastSelect = [$sql, $bindings];
        return [['ok' => true]];
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

    /**
     * @param callable(): mixed $fn
     * @return mixed
     */
    public function transaction(callable $fn)
    {
        $this->transactions++;
        return $fn();
    }
}
