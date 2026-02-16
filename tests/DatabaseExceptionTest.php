<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Exceptions\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase
{
    public function testMessageAndDefaults(): void
    {
        $e = new DatabaseException('query failed');

        $this->assertSame('query failed', $e->getMessage());
        $this->assertNull($e->sql());
        $this->assertSame([], $e->bindings());
        $this->assertNull($e->getPrevious());
    }

    public function testWithSqlAndBindings(): void
    {
        $prev = new \RuntimeException('pdo error');
        $e = new DatabaseException(
            'insert failed',
            $prev,
            'INSERT INTO users (name) VALUES (?)',
            ['Alice']
        );

        $this->assertSame('insert failed', $e->getMessage());
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame('INSERT INTO users (name) VALUES (?)', $e->sql());
        $this->assertSame(['Alice'], $e->bindings());
    }

    public function testHttpStatusCodeIs500(): void
    {
        $e = new DatabaseException('db error');

        // httpStatusCode is protected on PhapiException — access via ErrorHandler behavior
        $ref = new \ReflectionClass($e);
        $prop = $ref->getProperty('httpStatusCode');
        $prop->setAccessible(true);

        $this->assertSame(500, $prop->getValue($e));
    }
}
