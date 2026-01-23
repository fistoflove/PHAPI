<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\PHAPI;

final class SpawnProcessTest extends SwooleTestCase
{
    public function testSpawnProcessThrowsOnInvalidFactory(): void
    {
        $api = new PHAPI(['runtime' => 'swoole']);

        $api->spawnProcess(function () {
            return new \stdClass();
        });

        $driver = $api->runtime();
        $method = new \ReflectionMethod($driver, 'startProcessesForWorker');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke($driver, 0);
    }
}
