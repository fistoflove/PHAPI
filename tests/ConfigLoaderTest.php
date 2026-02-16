<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\ConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'debug' => true,
            'jobs_log_limit' => 50,
        ]);

        $this->assertSame('swoole', $config['runtime']);
        $this->assertTrue($config['debug']);
        $this->assertSame(50, $config['jobs_log_limit']);
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function debugEnvProvider(): iterable
    {
        yield 'zero' => ['0', false];
        yield 'one' => ['1', true];
        yield 'banana' => ['banana', false];
        yield 'unset' => [null, false];
    }

    #[DataProvider('debugEnvProvider')]
    public function testDebugEnvResolution(?string $envValue, bool $expected): void
    {
        if ($envValue === null) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $envValue);
            $_ENV['APP_DEBUG'] = $envValue;
        }

        $loader = new ConfigLoader();
        $defaults = $loader->defaults();

        $this->assertSame($expected, $defaults['debug']);
    }
}
