<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHAPI\Services\JobsManager;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class JobsIntegrationTest extends TestCase
{
    public function testJobRunIsLogged(): void
    {
        $logDir = sys_get_temp_dir() . '/phapi_jobs_' . bin2hex(random_bytes(4));
        @mkdir($logDir, 0755, true);

        $jobs = new JobsManager($logDir, 200, 1024 * 1024, 2);
        $jobs->register('heartbeat', 1, static function (): string {
            return 'ok';
        }, ['log_enabled' => true, 'log_file' => 'heartbeat.log']);

        $result = $jobs->runScheduled('heartbeat', static function (callable $handler): mixed {
            return $handler();
        });

        $this->assertNotNull($result);

        $logs = $jobs->logs('heartbeat');
        $this->assertNotEmpty($logs);
        $this->assertSame('ok', $logs[0]['result']);

        foreach (glob($logDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($logDir);
    }
}
