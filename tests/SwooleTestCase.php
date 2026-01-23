<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHPUnit\Framework\TestCase;

abstract class SwooleTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required for this test.');
        }
    }
}
