<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Logging\ChannelLogger;
use PHAPI\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests Logger: file output, channels, level filtering, stdout, debug mode, access logs.
 */
final class LoggerTest extends TestCase
{
    private string $tmpDir;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phapi_logger_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Reset singleton so each test gets a clean Logger
        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->logger = Logger::getInstance();
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tmpDir);

        // Reset singleton
        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ── Singleton ───────────────────────────────────────────

    public function testGetInstanceReturnsSameObject(): void
    {
        $a = Logger::getInstance();
        $b = Logger::getInstance();
        $this->assertSame($a, $b);
    }

    // ── setLogFile + log levels ──────────────────────────────

    public function testLogWritesToDefaultFile(): void
    {
        $file = $this->tmpDir . '/default.log';
        $this->logger->setLogFile($file);

        $this->logger->info('hello world');

        $content = file_get_contents($file);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString('hello world', $content);
    }

    public function testInfoLevel(): void
    {
        $file = $this->tmpDir . '/info.log';
        $this->logger->setLogFile($file);
        $this->logger->info('info message');

        $this->assertStringContainsString('info', file_get_contents($file));
    }

    public function testWarningLevel(): void
    {
        $file = $this->tmpDir . '/warn.log';
        $this->logger->setLogFile($file);
        $this->logger->warning('warn message');

        $this->assertStringContainsString('warning', file_get_contents($file));
    }

    public function testErrorLevel(): void
    {
        $file = $this->tmpDir . '/err.log';
        $this->logger->setLogFile($file);
        $this->logger->error('error message');

        $this->assertStringContainsString('error', file_get_contents($file));
    }

    public function testCriticalLevel(): void
    {
        $file = $this->tmpDir . '/crit.log';
        $this->logger->setLogFile($file);
        $this->logger->critical('critical message');

        $this->assertStringContainsString('critical', file_get_contents($file));
    }

    // ── Context ─────────────────────────────────────────────

    public function testLogIncludesContext(): void
    {
        $file = $this->tmpDir . '/ctx.log';
        $this->logger->setLogFile($file);
        $this->logger->info('with context', ['user_id' => 42, 'action' => 'login']);

        $content = file_get_contents($file);
        $this->assertStringContainsString('user_id', $content);
        $this->assertStringContainsString('42', $content);
        $this->assertStringContainsString('action', $content);
        $this->assertStringContainsString('login', $content);
    }

    public function testLogContextWithArrayValueSerializesToJson(): void
    {
        $file = $this->tmpDir . '/json.log';
        $this->logger->setLogFile($file);
        $this->logger->info('nested', ['data' => ['a' => 1, 'b' => 2]]);

        $content = file_get_contents($file);
        $this->assertStringContainsString('"a":1', $content);
    }

    // ── Level filtering ─────────────────────────────────────

    public function testSetLevelFiltersLowerLevels(): void
    {
        $file = $this->tmpDir . '/filtered.log';
        $this->logger->setLogFile($file);
        $this->logger->setLevel(Logger::LEVEL_WARNING);

        $this->logger->info('should be skipped');
        $this->logger->warning('should appear');

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('should be skipped', $content);
        $this->assertStringContainsString('should appear', $content);
    }

    public function testSetLevelErrorSkipsWarning(): void
    {
        $file = $this->tmpDir . '/error-only.log';
        $this->logger->setLogFile($file);
        $this->logger->setLevel(Logger::LEVEL_ERROR);

        $this->logger->warning('skip');
        $this->logger->error('keep');

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('skip', $content);
        $this->assertStringContainsString('keep', $content);
    }

    public function testCriticalLevelOnlyLogsCritical(): void
    {
        $file = $this->tmpDir . '/crit-only.log';
        $this->logger->setLogFile($file);
        $this->logger->setLevel(Logger::LEVEL_CRITICAL);

        $this->logger->error('skip error');
        $this->logger->critical('keep critical');

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('skip error', $content);
        $this->assertStringContainsString('keep critical', $content);
    }

    // ── Enabled/disabled ────────────────────────────────────

    public function testDisabledLoggerProducesNoOutput(): void
    {
        $file = $this->tmpDir . '/disabled.log';
        $this->logger->setLogFile($file);
        $this->logger->setEnabled(false);

        $this->logger->info('should not appear');

        $this->assertFileDoesNotExist($file);
    }

    public function testReEnableLogging(): void
    {
        $file = $this->tmpDir . '/reenable.log';
        $this->logger->setLogFile($file);
        $this->logger->setEnabled(false);
        $this->logger->info('hidden');
        $this->logger->setEnabled(true);
        $this->logger->info('visible');

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('hidden', $content);
        $this->assertStringContainsString('visible', $content);
    }

    // ── Stdout output ───────────────────────────────────────

    public function testOutputToStdout(): void
    {
        $this->logger->setOutputToStdout(true);

        ob_start();
        $this->logger->info('stdout test');
        $output = ob_get_clean();

        $this->assertStringContainsString('stdout test', $output);
    }

    public function testStdoutDisabledByDefault(): void
    {
        ob_start();
        $this->logger->info('no stdout');
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    // ── Debug mode ──────────────────────────────────────────

    public function testDebugChannelSuppressedWhenDebugModeOff(): void
    {
        $file = $this->tmpDir . '/debug.log';
        $this->logger->setChannel(Logger::CHANNEL_DEBUG, $file);
        $this->logger->setDebugMode(false);

        $this->logger->log(Logger::LEVEL_INFO, 'debug msg', [], Logger::CHANNEL_DEBUG);

        $this->assertFileDoesNotExist($file);
    }

    public function testDebugChannelWritesWhenDebugModeOn(): void
    {
        $file = $this->tmpDir . '/debug-on.log';
        $this->logger->setChannel(Logger::CHANNEL_DEBUG, $file);
        $this->logger->setDebugMode(true);

        $this->logger->log(Logger::LEVEL_INFO, 'debug msg', [], Logger::CHANNEL_DEBUG);

        $content = file_get_contents($file);
        $this->assertStringContainsString('debug msg', $content);
    }

    // ── Channels ────────────────────────────────────────────

    public function testSetChannelWritesToChannelFile(): void
    {
        $file = $this->tmpDir . '/access.log';
        $this->logger->setChannel('access', $file);

        $this->logger->log(Logger::LEVEL_INFO, 'access entry', [], 'access');

        $content = file_get_contents($file);
        $this->assertStringContainsString('access entry', $content);
        $this->assertStringContainsString('access', $content);
    }

    public function testSetChannelsConfiguresMultiple(): void
    {
        $accessFile = $this->tmpDir . '/ch-access.log';
        $errorFile = $this->tmpDir . '/ch-error.log';
        $this->logger->setChannels([
            'access' => $accessFile,
            'error' => $errorFile,
        ]);

        $this->logger->log(Logger::LEVEL_INFO, 'access log', [], 'access');
        $this->logger->log(Logger::LEVEL_ERROR, 'error log', [], 'error');

        $this->assertStringContainsString('access log', file_get_contents($accessFile));
        $this->assertStringContainsString('error log', file_get_contents($errorFile));
    }

    public function testGetChannelFile(): void
    {
        $file = $this->tmpDir . '/task.log';
        $this->logger->setChannel('task', $file);

        $this->assertSame($file, $this->logger->getChannelFile('task'));
        $this->assertNull($this->logger->getChannelFile('nonexistent'));
    }

    public function testChannelLogDoesNotWriteToDefaultFile(): void
    {
        $defaultFile = $this->tmpDir . '/default.log';
        $channelFile = $this->tmpDir . '/channel.log';
        $this->logger->setLogFile($defaultFile);
        $this->logger->setChannel('custom', $channelFile);

        $this->logger->log(Logger::LEVEL_INFO, 'channel only', [], 'custom');

        $this->assertStringContainsString('channel only', file_get_contents($channelFile));
        // Default file should NOT have the channel message
        if (file_exists($defaultFile)) {
            $this->assertStringNotContainsString('channel only', file_get_contents($defaultFile));
        } else {
            $this->assertFileDoesNotExist($defaultFile);
        }
    }

    // ── Channel shortcuts ───────────────────────────────────

    public function testChannelReturnsChannelLogger(): void
    {
        $cl = $this->logger->channel('test');
        $this->assertInstanceOf(ChannelLogger::class, $cl);
    }

    public function testAccessShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->access());
    }

    public function testErrorsShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->errors());
    }

    public function testTaskShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->task());
    }

    public function testDebugShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->debug());
    }

    public function testSystemShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->system());
    }

    public function testPerformanceShortcut(): void
    {
        $this->assertInstanceOf(ChannelLogger::class, $this->logger->performance());
    }

    // ── TSV format ──────────────────────────────────────────

    public function testLogOutputIsTsvFormat(): void
    {
        $file = $this->tmpDir . '/tsv.log';
        $this->logger->setLogFile($file);
        $this->logger->info('tsv test');

        $line = trim(file_get_contents($file));
        $columns = explode("\t", $line);

        // timestamp, level, channel, message
        $this->assertGreaterThanOrEqual(4, count($columns));
        $this->assertSame('info', $columns[1]);
        $this->assertSame('tsv test', $columns[3]);
    }

    public function testLogEscapesTabsInMessage(): void
    {
        $file = $this->tmpDir . '/escape.log';
        $this->logger->setLogFile($file);
        $this->logger->info("message\twith\ttabs");

        $content = file_get_contents($file);
        // Tabs in message should be replaced with spaces
        $this->assertStringContainsString('message with tabs', $content);
    }

    // ── logAccess ───────────────────────────────────────────

    public function testLogAccessWritesStructuredEntry(): void
    {
        $file = $this->tmpDir . '/access-structured.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);

        $this->logger->logAccess(Logger::LEVEL_INFO, 'request', [
            'request_id' => 'abc-123',
            'method' => 'GET',
            'uri' => '/api/users',
            'ip' => '192.168.1.1',
            'status' => 200,
            'duration_ms' => 12.5,
        ]);

        $content = file_get_contents($file);
        $this->assertStringContainsString('abc-123', $content);
        $this->assertStringContainsString('GET', $content);
        $this->assertStringContainsString('/api/users', $content);
        $this->assertStringContainsString('192.168.1.1', $content);
        $this->assertStringContainsString('200', $content);
        $this->assertStringContainsString('12.5', $content);
    }

    public function testLogAccessRespectedEnabledFlag(): void
    {
        $file = $this->tmpDir . '/access-disabled.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);
        $this->logger->setEnabled(false);

        $this->logger->logAccess(Logger::LEVEL_INFO, 'request', ['method' => 'GET']);

        $this->assertFileDoesNotExist($file);
    }

    public function testLogAccessRespectsLevelFilter(): void
    {
        $file = $this->tmpDir . '/access-level.log';
        $this->logger->setChannel(Logger::CHANNEL_ACCESS, $file);
        $this->logger->setLevel(Logger::LEVEL_ERROR);

        $this->logger->logAccess(Logger::LEVEL_INFO, 'should skip');

        $this->assertFileDoesNotExist($file);
    }

    // ── Fluent API ──────────────────────────────────────────

    public function testFluentApiChaining(): void
    {
        $file = $this->tmpDir . '/fluent.log';
        $result = $this->logger
            ->setLogFile($file)
            ->setLevel(Logger::LEVEL_INFO)
            ->setEnabled(true)
            ->setOutputToStdout(false)
            ->setDebugMode(false);

        $this->assertSame($this->logger, $result);
    }

    // ── No file configured ──────────────────────────────────

    public function testLogWithNoFileConfiguredDoesNotError(): void
    {
        // No setLogFile, no channels — should silently do nothing
        $this->logger->info('nowhere to go');
        $this->assertTrue(true); // No exception thrown
    }

    // ── Channel auto-creates directory ──────────────────────

    public function testSetChannelCreatesDirectory(): void
    {
        $nestedDir = $this->tmpDir . '/sub_' . uniqid() . '/deep';
        $file = $nestedDir . '/auto.log';
        $this->logger->setChannel('auto', $file);

        $this->assertDirectoryExists($nestedDir);

        // Clean up nested dirs
        @unlink($file);
        @rmdir($nestedDir);
        @rmdir(dirname($nestedDir));
    }
}
