<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Logging\ChannelLogger;
use PHAPI\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests ChannelLogger delegation to Logger with correct channel and level.
 */
final class ChannelLoggerTest extends TestCase
{
    private string $tmpDir;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phapi_chlog_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->logger = Logger::getInstance();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tmpDir);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testInfoDelegatesToLoggerWithChannel(): void
    {
        $file = $this->tmpDir . '/task.log';
        $this->logger->setChannel('task', $file);

        $cl = new ChannelLogger($this->logger, 'task');
        $cl->info('task info');

        $content = file_get_contents($file);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString('task', $content);
        $this->assertStringContainsString('task info', $content);
    }

    public function testWarningDelegatesToLogger(): void
    {
        $file = $this->tmpDir . '/warn.log';
        $this->logger->setChannel('custom', $file);

        $cl = new ChannelLogger($this->logger, 'custom');
        $cl->warning('warn msg');

        $content = file_get_contents($file);
        $this->assertStringContainsString('warning', $content);
        $this->assertStringContainsString('warn msg', $content);
    }

    public function testErrorDelegatesToLogger(): void
    {
        $file = $this->tmpDir . '/err.log';
        $this->logger->setChannel('errors', $file);

        $cl = new ChannelLogger($this->logger, 'errors');
        $cl->error('err msg');

        $content = file_get_contents($file);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString('err msg', $content);
    }

    public function testCriticalDelegatesToLogger(): void
    {
        $file = $this->tmpDir . '/crit.log';
        $this->logger->setChannel('system', $file);

        $cl = new ChannelLogger($this->logger, 'system');
        $cl->critical('crit msg');

        $content = file_get_contents($file);
        $this->assertStringContainsString('critical', $content);
        $this->assertStringContainsString('crit msg', $content);
    }

    public function testAccessChannelUsesLogAccessFormat(): void
    {
        $file = $this->tmpDir . '/access.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);

        $cl = new ChannelLogger($this->logger, Logger::CHANNEL_ACCESS);
        $cl->info('request', ['method' => 'GET', 'uri' => '/test', 'status' => 200]);

        $content = file_get_contents($file);
        $this->assertStringContainsString('access', $content);
        // logAccess format has fixed columns — method and uri appear as positional fields
        $this->assertStringContainsString('GET', $content);
        $this->assertStringContainsString('/test', $content);
    }

    public function testAccessChannelWarningUsesLogAccessFormat(): void
    {
        $file = $this->tmpDir . '/access-warn.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);

        $cl = new ChannelLogger($this->logger, Logger::CHANNEL_ACCESS);
        $cl->warning('slow request', ['duration_ms' => 5000]);

        $content = file_get_contents($file);
        $this->assertStringContainsString('warning', $content);
        $this->assertStringContainsString('5000', $content);
    }

    public function testAccessChannelErrorUsesLogAccessFormat(): void
    {
        $file = $this->tmpDir . '/access-err.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);

        $cl = new ChannelLogger($this->logger, Logger::CHANNEL_ACCESS);
        $cl->error('failed request', ['status' => 500]);

        $content = file_get_contents($file);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString('500', $content);
    }

    public function testAccessChannelCriticalUsesLogAccessFormat(): void
    {
        $file = $this->tmpDir . '/access-crit.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);

        $cl = new ChannelLogger($this->logger, Logger::CHANNEL_ACCESS);
        $cl->critical('server down');

        $content = file_get_contents($file);
        $this->assertStringContainsString('critical', $content);
    }
}
