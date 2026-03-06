<?php

declare(strict_types=1);

namespace Tests\Core;

use PHAPI\Core\ServiceAccessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PHAPI\Core\ServiceAccessor
 */
class ServiceAccessorTest extends TestCase
{
    // ─── parseMySqlDsn ──────────────────────────────────

    public function testParseMySqlDsnEmpty(): void
    {
        $result = ServiceAccessor::parseMySqlDsn('');
        $this->assertSame([], $result);
    }

    public function testParseMySqlDsnNonMysql(): void
    {
        $result = ServiceAccessor::parseMySqlDsn('pgsql:host=localhost');
        $this->assertSame([], $result);
    }

    public function testParseMySqlDsnFull(): void
    {
        $dsn = 'mysql:host=db.example.com;port=3307;dbname=myapp;charset=utf8';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('db.example.com', $result['host']);
        $this->assertSame(3307, $result['port']);
        $this->assertSame('myapp', $result['database']);
        $this->assertSame('utf8', $result['charset']);
    }

    public function testParseMySqlDsnPartial(): void
    {
        $dsn = 'mysql:host=localhost;dbname=test_db';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('test_db', $result['database']);
        $this->assertArrayNotHasKey('port', $result);
        $this->assertArrayNotHasKey('charset', $result);
    }

    public function testParseMySqlDsnCaseInsensitive(): void
    {
        $dsn = 'MySQL:Host=myhost;Port=3308';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('myhost', $result['host']);
        $this->assertSame(3308, $result['port']);
    }

    public function testParseMySqlDsnExtraWhitespace(): void
    {
        $dsn = '  mysql: host = myhost ; port = 3309 ; ';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('myhost', $result['host']);
        $this->assertSame(3309, $result['port']);
    }

    public function testParseMySqlDsnInvalidPort(): void
    {
        $dsn = 'mysql:host=localhost;port=abc';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('localhost', $result['host']);
        $this->assertArrayNotHasKey('port', $result);
    }

    public function testParseMySqlDsnEmptySegments(): void
    {
        $dsn = 'mysql:host=localhost;;dbname=test;;';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('test', $result['database']);
    }

    public function testParseMySqlDsnEmptyKey(): void
    {
        $dsn = 'mysql:=value;host=localhost';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('localhost', $result['host']);
    }

    public function testParseMySqlDsnNoEquals(): void
    {
        $dsn = 'mysql:hostlocalhost;dbname=test';
        $result = ServiceAccessor::parseMySqlDsn($dsn);

        $this->assertSame('test', $result['database']);
        $this->assertArrayNotHasKey('host', $result);
    }
}
