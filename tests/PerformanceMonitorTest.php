<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Logging\Logger;
use PHAPI\Server\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

/**
 * Tests PerformanceMonitor: enable/disable, processMetrics, cleanHealthCheckLogs.
 */
final class PerformanceMonitorTest extends TestCase
{
    private string $tmpDir;
    private Logger $logger;
    private PerformanceMonitor $monitor;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phapi_perf_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->logger = Logger::getInstance();
        $this->monitor = new PerformanceMonitor($this->logger);
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

    // ── enable / isEnabled ──────────────────────────────────

    public function testDisabledByDefault(): void
    {
        $this->assertFalse($this->monitor->isEnabled());
    }

    public function testEnableTogglesState(): void
    {
        $this->monitor->enable(true);
        $this->assertTrue($this->monitor->isEnabled());

        $this->monitor->enable(false);
        $this->assertFalse($this->monitor->isEnabled());
    }

    // ── processMetrics ──────────────────────────────────────

    public function testProcessMetricsDoesNothingWhenDisabled(): void
    {
        $perfFile = $this->tmpDir . '/perf.log';
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);

        // Don't enable the monitor
        $this->monitor->processMetrics();

        $this->assertFileDoesNotExist($perfFile);
    }

    public function testProcessMetricsDoesNothingWithoutSystemLog(): void
    {
        $perfFile = $this->tmpDir . '/perf.log';
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);
        $this->monitor->enable(true);

        // No system channel configured
        $this->monitor->processMetrics();

        $this->assertFileDoesNotExist($perfFile);
    }

    public function testProcessMetricsDoesNothingWithEmptySystemLog(): void
    {
        $systemFile = $this->tmpDir . '/system.log';
        $perfFile = $this->tmpDir . '/perf.log';
        file_put_contents($systemFile, '');
        $this->logger->setChannel(Logger::CHANNEL_SYSTEM, $systemFile);
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);
        $this->monitor->enable(true);

        $this->monitor->processMetrics();

        $this->assertFileDoesNotExist($perfFile);
    }

    public function testProcessMetricsCalculatesFromHealthChecks(): void
    {
        $systemFile = $this->tmpDir . '/system.log';
        $perfFile = $this->tmpDir . '/perf.log';

        // Write fake health check entries with recent timestamps
        $now = date('Y-m-d H:i:s');
        $lines = [
            "$now\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t10.5\tstatus_code\t200",
            "$now\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t15.3\tstatus_code\t200",
            "$now\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t8.2\tstatus_code\t200",
        ];
        file_put_contents($systemFile, implode("\n", $lines) . "\n");

        $this->logger->setChannel(Logger::CHANNEL_SYSTEM, $systemFile);
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);
        $this->monitor->enable(true);

        $this->monitor->processMetrics();

        $content = file_get_contents($perfFile);
        $this->assertStringContainsString('Performance summary', $content);
        $this->assertStringContainsString('health_checks_count', $content);
        $this->assertStringContainsString('3', $content);
        $this->assertStringContainsString('avg_response_time_ms', $content);
    }

    public function testProcessMetricsIgnoresOldEntries(): void
    {
        $systemFile = $this->tmpDir . '/system.log';
        $perfFile = $this->tmpDir . '/perf.log';

        // Write entries older than 5 minutes
        $old = date('Y-m-d H:i:s', time() - 600);
        $lines = [
            "$old\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t10.0\tstatus_code\t200",
        ];
        file_put_contents($systemFile, implode("\n", $lines) . "\n");

        $this->logger->setChannel(Logger::CHANNEL_SYSTEM, $systemFile);
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);
        $this->monitor->enable(true);

        $this->monitor->processMetrics();

        // No recent health checks → no performance log
        $this->assertFileDoesNotExist($perfFile);
    }

    public function testProcessMetricsIgnoresNonHealthCheckEntries(): void
    {
        $systemFile = $this->tmpDir . '/system.log';
        $perfFile = $this->tmpDir . '/perf.log';

        $now = date('Y-m-d H:i:s');
        $lines = [
            "$now\tinfo\tsystem\tSome other system event",
            "$now\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t5.0\tstatus_code\t200",
        ];
        file_put_contents($systemFile, implode("\n", $lines) . "\n");

        $this->logger->setChannel(Logger::CHANNEL_SYSTEM, $systemFile);
        $this->logger->setChannel(Logger::CHANNEL_PERFORMANCE, $perfFile);
        $this->monitor->enable(true);

        $this->monitor->processMetrics();

        $content = file_get_contents($perfFile);
        $this->assertStringContainsString('1', $content); // Only 1 health check
    }

    // ── cleanHealthCheckLogs ────────────────────────────────

    public function testCleanHealthCheckLogsRemovesOldEntries(): void
    {
        $logFile = $this->tmpDir . '/clean.log';
        $old = date('Y-m-d H:i:s', time() - 600);
        $recent = date('Y-m-d H:i:s');

        $lines = [
            "$old\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t10",
            "$recent\tinfo\tsystem\tKeep-alive health check\tresponse_time_ms\t5",
            "$recent\tinfo\tsystem\tSome other event",
        ];
        file_put_contents($logFile, implode("\n", $lines) . "\n");

        $this->monitor->cleanHealthCheckLogs($logFile, time() - 300);

        $content = file_get_contents($logFile);
        // Old health check removed, recent health check and other event kept
        $this->assertStringNotContainsString('10', $content);
        $this->assertStringContainsString('5', $content);
        $this->assertStringContainsString('Some other event', $content);
    }

    public function testCleanHealthCheckLogsHandlesMissingFile(): void
    {
        // Should not throw
        $this->monitor->cleanHealthCheckLogs('/nonexistent/path.log', time());
        $this->assertTrue(true);
    }

    public function testCleanHealthCheckLogsKeepsNonHealthCheckEntries(): void
    {
        $logFile = $this->tmpDir . '/keep.log';
        $old = date('Y-m-d H:i:s', time() - 600);

        $lines = [
            "$old\tinfo\tsystem\tServer started",
            "$old\twarning\tsystem\tHigh memory usage",
        ];
        file_put_contents($logFile, implode("\n", $lines) . "\n");

        $this->monitor->cleanHealthCheckLogs($logFile, time() - 300);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Server started', $content);
        $this->assertStringContainsString('High memory usage', $content);
    }
}
