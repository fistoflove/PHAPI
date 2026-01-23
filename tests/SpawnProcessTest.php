<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Exceptions\FeatureNotSupportedException;
use PHAPI\PHAPI;
use PHPUnit\Framework\TestCase;

final class SpawnProcessTest extends TestCase
{
    public function testSpawnProcessThrowsOnNonSwooleRuntime(): void
    {
        $api = new PHAPI(['runtime' => 'fpm']);

        $this->expectException(FeatureNotSupportedException::class);

        $api->spawnProcess(function () {
            return new \stdClass();
        });
    }
}
