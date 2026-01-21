<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\RuntimeManager;
use PHAPI\Runtime\FpmDriver;
use PHPUnit\Framework\TestCase;

final class RuntimeManagerTest extends TestCase
{
    public function testSelectsRuntimeDriver(): void
    {
        $manager = new RuntimeManager(['runtime' => 'fpm']);

        $this->assertInstanceOf(FpmDriver::class, $manager->driver());
        $this->assertNotNull($manager->capabilities());
        $this->assertSame('fpm', $manager->driver()->name());
        $this->assertFalse($manager->driver()->isLongRunning());
    }
}
