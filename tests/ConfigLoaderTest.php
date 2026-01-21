<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testDefaultsLoadFromConfigFile(): void
    {
        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertArrayHasKey('runtime', $defaults);
        $this->assertArrayHasKey('debug', $defaults);
        $this->assertArrayHasKey('jobs_log_dir', $defaults);
    }

    public function testOverridesTakePrecedence(): void
    {
        $loader = new ConfigLoader();
        $config = $loader->load([
            'runtime' => 'fpm_amphp',
            'debug' => true,
            'jobs_log_limit' => 50,
        ]);

        $this->assertSame('fpm_amphp', $config['runtime']);
        $this->assertTrue($config['debug']);
        $this->assertSame(50, $config['jobs_log_limit']);
    }
}
