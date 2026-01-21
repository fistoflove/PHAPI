<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Services\JobsManager;
use PHPUnit\Framework\TestCase;

final class JobsRotationTest extends TestCase
{
    public function testRotatesJobLogsWhenSizeExceeded(): void
    {
        $dir = sys_get_temp_dir() . '/phapi-jobs-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);

        $jobs = new JobsManager($dir, 200, 50, 2);
        $jobs->register('rotate', 1, static function (): string {
            return 'ok';
        }, ['log_enabled' => true, 'log_file' => 'rotate.log']);

        $executor = static function (callable $handler): array {
            return [
                'result' => $handler(),
                'output' => str_repeat('a', 120),
            ];
        };

        $jobs->runScheduled('rotate', $executor);
        $jobs->runScheduled('rotate', $executor);

        $this->assertFileExists($dir . '/rotate.log.1');

        foreach (glob($dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }
}
