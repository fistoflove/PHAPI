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

    public function testPassesSwooleSettingsToDriver(): void
    {
        $manager = new RuntimeManager([
            'runtime' => 'swoole',
            'swoole_settings' => [
                'worker_num' => 2,
                'task_worker_num' => 4,
                'max_request' => 1000,
            ],
        ]);

        $driver = $manager->driver();
        $this->assertInstanceOf(SwooleDriver::class, $driver);
        $this->assertSame(
            [
                'worker_num' => 2,
                'task_worker_num' => 4,
                'max_request' => 1000,
            ],
            $driver->settings()
        );
    }
}
