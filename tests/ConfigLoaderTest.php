<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string|false $originalDebugEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDebugEnv = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        if ($this->originalDebugEnv === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->originalDebugEnv);
            $_ENV['APP_DEBUG'] = $this->originalDebugEnv;
        }

        parent::tearDown();
    }

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
            'runtime' => 'swoole',
            'debug' => true,
            'jobs_log_limit' => 50,
        ]);

        $this->assertSame('swoole', $config['runtime']);
        $this->assertTrue($config['debug']);
        $this->assertSame(50, $config['jobs_log_limit']);
    }

    public function testDebugEnvZeroIsFalse(): void
    {
        putenv('APP_DEBUG=0');
        $_ENV['APP_DEBUG'] = '0';

        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertFalse($defaults['debug']);
    }

    public function testDebugEnvOneIsTrue(): void
    {
        putenv('APP_DEBUG=1');
        $_ENV['APP_DEBUG'] = '1';

        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertTrue($defaults['debug']);
    }

    public function testDebugEnvUnsetIsFalse(): void
    {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);

        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertFalse($defaults['debug']);
    }

    public function testDebugEnvInvalidValueIsFalse(): void
    {
        putenv('APP_DEBUG=banana');
        $_ENV['APP_DEBUG'] = 'banana';

        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertFalse($defaults['debug']);
    }
}
