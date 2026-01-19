<?php

namespace PHAPI\Tests;

use PHAPI\Services\JobsManager;
use PHPUnit\Framework\TestCase;

class JobsManagerTest extends TestCase
{
    public function testLockSkipIsLogged(): void
    {
        $dir = sys_get_temp_dir() . '/phapi-jobs-' . uniqid();
        @mkdir($dir, 0755, true);

        $jobs = new JobsManager($dir, 10, 1024 * 1024, 2);
        $jobs->register('locked', 1, function () {
            return 'ok';
        }, ['log_enabled' => true, 'lock_mode' => 'skip']);

        $lockPath = $dir . '/locked.lock';
        $handle = fopen($lockPath, 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $result = $jobs->runScheduled('locked', function ($handler) {
            return $handler();
        });

        $this->assertSame('skipped', $result['status']);

        $logPath = $dir . '/locked.log';
        $this->assertFileExists($logPath);
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines);

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
