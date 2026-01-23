<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\RuntimeManager;
use PHAPI\Runtime\SwooleDriver;

final class RuntimeManagerTest extends SwooleTestCase
{
    public function testSelectsRuntimeDriver(): void
    {
        $manager = new RuntimeManager(['runtime' => 'swoole']);

        $this->assertInstanceOf(SwooleDriver::class, $manager->driver());
        $this->assertNotNull($manager->capabilities());
        $this->assertSame('swoole', $manager->driver()->name());
        $this->assertTrue($manager->driver()->isLongRunning());
    }
}
