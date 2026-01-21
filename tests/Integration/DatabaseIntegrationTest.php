<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHAPI\Database\DatabaseFacade;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class DatabaseIntegrationTest extends TestCase
{
    public function testSQLiteOptionsRoundTrip(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not available.');
        }

        $dbPath = sys_get_temp_dir() . '/phapi_test_' . bin2hex(random_bytes(4)) . '.sqlite';

        DatabaseFacade::configure($dbPath, ['autoload' => false]);

        $this->assertTrue(DatabaseFacade::isConfigured());
        $this->assertTrue(DatabaseFacade::setOption('foo', 'bar'));
        $this->assertSame('bar', DatabaseFacade::option('foo'));

        if (file_exists($dbPath)) {
            @unlink($dbPath);
        }
    }
}
