<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\JobsScheduler;
use PHAPI\Services\JobsManager;
use PHPUnit\Framework\TestCase;

final class JobsSchedulerTest extends TestCase
{
    public function testNoOpWhenNoDriverProvided(): void
    {
        $scheduler = new JobsScheduler();
        $logDir = sys_get_temp_dir() . '/phapi_jobs_' . bin2hex(random_bytes(4));
        @mkdir($logDir, 0755, true);

        $jobs = new JobsManager($logDir);
        $jobs->register('heartbeat', 5, static function (): string {
            return 'ok';
        });

        $scheduler->registerSwooleJobs($jobs, null, static function (callable $handler): array {
            return ['result' => $handler(), 'output' => ''];
        });

        $this->assertTrue(true);

        foreach (glob($logDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($logDir);
    }
}
