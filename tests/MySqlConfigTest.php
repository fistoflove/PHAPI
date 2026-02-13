<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\PHAPI;
use PHAPI\Services\MySqlPool;

final class MySqlConfigTest extends \PHPUnit\Framework\TestCase
{
    public function testMySqlConfigDerivesConnectionSettingsFromDsn(): void
    {
        $api = new PHAPI([
            'mysql' => [
                'dsn' => 'mysql:host=db.internal;port=3307;dbname=yard;charset=utf8mb4',
                'user' => 'yard_user',
                'password' => 'secret',
                'timeout' => 2.0,
                'pool_size' => 11,
                'pool_timeout' => 1.5,
            ],
        ]);

        $config = $this->readPoolConfig($api->mysql());
        self::assertSame('db.internal', $config['host']);
        self::assertSame(3307, $config['port']);
        self::assertSame('yard', $config['database']);
        self::assertSame('utf8mb4', $config['charset']);
        self::assertSame('yard_user', $config['user']);
        self::assertSame('secret', $config['password']);
        self::assertSame(2.0, $config['timeout']);
        self::assertSame(11, $config['pool_size']);
        self::assertSame(1.5, $config['pool_timeout']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPoolConfig(MySqlPool $pool): array
    {
        $reflection = new \ReflectionClass($pool);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);

        /** @var array<string, mixed> $config */
        $config = $property->getValue($pool);
        return $config;
    }
}
