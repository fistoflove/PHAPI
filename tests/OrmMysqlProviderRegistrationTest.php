<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connectors\ConnectionFactory;
use Hyperf\DbConnection\ConnectionResolver;
use Hyperf\DbConnection\Pool\PoolFactory;
use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Core\Container;
use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHAPI\Providers\OrmMysqlProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests OrmMysqlProvider: config validation, service registration, boot.
 */
final class OrmMysqlProviderRegistrationTest extends TestCase
{
    // ── Config validation ───────────────────────────────────

    public function testValidConfigRegistersServices(): void
    {
        $api = new PHAPI([
            'mysql' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'phapi_test',
                'user' => 'phapi',
                'password' => 'phapi_pass',
            ],
        ]);

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);

        $this->assertTrue($api->container()->has(ConfigInterface::class));
        $this->assertTrue($api->container()->has(PoolFactory::class));
        $this->assertTrue($api->container()->has(ConnectionFactory::class));
        $this->assertTrue($api->container()->has(ConnectionResolver::class));
        $this->assertTrue($api->container()->has(DatabaseInterface::class));
    }

    public function testConfigFromMysqlKeyFallback(): void
    {
        $api = new PHAPI([
            'mysql' => [
                'host' => '127.0.0.1',
                'database' => 'testdb',
                'user' => 'root',
                'password' => '',
                'pool_size' => 8,
                'pool_timeout' => 5.0,
                'timeout' => 3.0,
            ],
        ]);

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);

        // Verify Hyperf config was built correctly
        $config = $api->container()->get(ConfigInterface::class);
        $dbConfig = $config->get('db.connections.default');
        $this->assertSame('127.0.0.1', $dbConfig['host']);
        $this->assertSame('testdb', $dbConfig['database']);
        $this->assertSame('root', $dbConfig['username']);
    }

    public function testConfigFromOrmMysqlKey(): void
    {
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '10.0.0.1',
                    'port' => 3307,
                    'database' => 'ormdb',
                    'username' => 'ormuser',
                    'password' => 'ormpass',
                    'charset' => 'utf8',
                    'collation' => 'utf8_general_ci',
                    'prefix' => 'app_',
                    'options' => [\PDO::ATTR_EMULATE_PREPARES => false],
                    'pool' => [
                        'min_connections' => 2,
                        'max_connections' => 10,
                        'connect_timeout' => 5.0,
                        'wait_timeout' => 3.0,
                        'max_idle_time' => 30.0,
                    ],
                ],
            ],
        ]);

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);

        $config = $api->container()->get(ConfigInterface::class);
        $dbConfig = $config->get('db.connections.default');
        $this->assertSame('10.0.0.1', $dbConfig['host']);
        $this->assertSame(3307, $dbConfig['port']);
        $this->assertSame('ormdb', $dbConfig['database']);
        $this->assertSame('ormuser', $dbConfig['username']);
        $this->assertSame('app_', $dbConfig['prefix']);
    }

    // ── Validation errors ───────────────────────────────────

    public function testMissingDatabaseThrows(): void
    {
        $api = new PHAPI([
            'mysql' => ['host' => '127.0.0.1', 'user' => 'root', 'password' => ''],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('database name');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    public function testMissingUsernameThrows(): void
    {
        // Use orm.mysql directly (bypassing mysql fallback) to test validation
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '127.0.0.1',
                    'database' => 'db',
                    'username' => '',
                    'password' => '',
                    'pool' => [
                        'min_connections' => 1,
                        'max_connections' => 5,
                        'connect_timeout' => 1.0,
                        'wait_timeout' => 1.0,
                        'max_idle_time' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('username');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    public function testMissingHostThrows(): void
    {
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '',
                    'database' => 'db',
                    'username' => 'user',
                    'password' => '',
                    'pool' => [
                        'min_connections' => 1,
                        'max_connections' => 1,
                        'connect_timeout' => 1.0,
                        'wait_timeout' => 1.0,
                        'max_idle_time' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('host');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    public function testInvalidPoolMinMaxThrows(): void
    {
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '127.0.0.1',
                    'database' => 'db',
                    'username' => 'user',
                    'password' => '',
                    'pool' => [
                        'min_connections' => 10,
                        'max_connections' => 5, // max < min
                        'connect_timeout' => 1.0,
                        'wait_timeout' => 1.0,
                        'max_idle_time' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('max_connections >= min_connections');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    public function testNonNumericPoolTimeoutThrows(): void
    {
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '127.0.0.1',
                    'database' => 'db',
                    'username' => 'user',
                    'password' => '',
                    'pool' => [
                        'min_connections' => 1,
                        'max_connections' => 5,
                        'connect_timeout' => 'not-a-number',
                        'wait_timeout' => 1.0,
                        'max_idle_time' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('connect_timeout');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    public function testNonArrayOptionsThrows(): void
    {
        $api = new PHAPI([
            'orm' => [
                'mysql' => [
                    'host' => '127.0.0.1',
                    'database' => 'db',
                    'username' => 'user',
                    'password' => '',
                    'options' => 'not-an-array',
                    'pool' => [
                        'min_connections' => 1,
                        'max_connections' => 5,
                        'connect_timeout' => 1.0,
                        'wait_timeout' => 1.0,
                        'max_idle_time' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('options must be an array');

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);
    }

    // ── Boot ────────────────────────────────────────────────

    public function testBootSetsModelConnectionResolver(): void
    {
        $api = new PHAPI([
            'mysql' => [
                'host' => '127.0.0.1',
                'database' => 'phapi_test',
                'user' => 'phapi',
                'password' => 'phapi_pass',
            ],
        ]);

        $provider = new OrmMysqlProvider();
        $provider->register($api->container(), $api);

        // boot() should not throw
        $provider->boot($api);

        // Verify the resolver was set on the model register
        $resolver = \Hyperf\Database\Model\Register::getConnectionResolver();
        $this->assertInstanceOf(ConnectionResolver::class, $resolver);
    }
}
