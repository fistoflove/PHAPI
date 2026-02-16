<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Logging\Logger;
use PHAPI\Server\JobManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests JobManager: register, start, stop, list.
 * Timer-based tests run inside Swoole\Coroutine\run().
 */
final class JobManagerTest extends TestCase
{
    private string $tmpDir;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phapi_jobmgr_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $ref = new \ReflectionClass(Logger::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->logger = Logger::getInstance();
        $this->logger->setChannel('system', $this->tmpDir . '/system.log');
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

    // ── register ────────────────────────────────────────────

    public function testRegisterAddsJobToList(): void
    {
        $jm = new JobManager($this->logger);
        $jm->register('cleanup', 60, fn (Logger $l) => null);

        $this->assertSame(['cleanup'], $jm->list());
    }

    public function testRegisterMultipleJobs(): void
    {
        $jm = new JobManager($this->logger);
        $jm->register('job1', 10, fn (Logger $l) => null);
        $jm->register('job2', 20, fn (Logger $l) => null);
        $jm->register('job3', 30, fn (Logger $l) => null);

        $this->assertSame(['job1', 'job2', 'job3'], $jm->list());
    }

    public function testRegisterThrowsOnZeroInterval(): void
    {
        $jm = new JobManager($this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 second');
        $jm->register('bad', 0, fn (Logger $l) => null);
    }

    public function testRegisterThrowsOnNegativeInterval(): void
    {
        $jm = new JobManager($this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $jm->register('bad', -5, fn (Logger $l) => null);
    }

    // ── list ────────────────────────────────────────────────

    public function testListEmptyByDefault(): void
    {
        $jm = new JobManager($this->logger);
        $this->assertSame([], $jm->list());
    }

    // ── start / stop / stopAll (require Swoole event loop) ──

    public function testStartAndStopJobInCoroutine(): void
    {
        $counter = 0;

        \Swoole\Coroutine\run(function () use (&$counter) {
            $logger = Logger::getInstance();
            $logger->setChannel('system', $this->tmpDir . '/system.log');
            $logger->setDebugMode(true);
            $logger->setChannel('debug', $this->tmpDir . '/debug.log');

            $jm = new JobManager($logger, true);
            $jm->register('tick', 1, function (Logger $l) use (&$counter) {
                $counter++;
            });

            $jm->start();

            // Wait 1.5 seconds — timer should fire at least once (interval = 1s = 1000ms)
            \Swoole\Coroutine::sleep(1.5);

            $stopped = $jm->stop('tick');
            $this->assertTrue($stopped);

            $counterAfterStop = $counter;
            \Swoole\Coroutine::sleep(1.5);

            // Counter should not increase after stop
            $this->assertSame($counterAfterStop, $counter);
        });

        $this->assertGreaterThanOrEqual(1, $counter);
    }

    public function testStopNonexistentJobReturnsFalse(): void
    {
        \Swoole\Coroutine\run(function () {
            $jm = new JobManager($this->logger);
            $this->assertFalse($jm->stop('nonexistent'));
        });
    }

    public function testStopAllClearsAllTimers(): void
    {
        $counter1 = 0;
        $counter2 = 0;

        \Swoole\Coroutine\run(function () use (&$counter1, &$counter2) {
            $logger = Logger::getInstance();
            $logger->setChannel('system', $this->tmpDir . '/system.log');

            $jm = new JobManager($logger);
            $jm->register('a', 1, function () use (&$counter1) { $counter1++; });
            $jm->register('b', 1, function () use (&$counter2) { $counter2++; });

            $jm->start();
            \Swoole\Coroutine::sleep(1.5);

            $jm->stopAll();
            $c1 = $counter1;
            $c2 = $counter2;

            \Swoole\Coroutine::sleep(1.5);

            // Counters should not increase after stopAll
            $this->assertSame($c1, $counter1);
            $this->assertSame($c2, $counter2);
        });

        $this->assertGreaterThanOrEqual(1, $counter1);
        $this->assertGreaterThanOrEqual(1, $counter2);
    }

    public function testJobFailureIsLoggedNotThrown(): void
    {
        $ran = false;

        \Swoole\Coroutine\run(function () use (&$ran) {
            $logger = Logger::getInstance();
            $errFile = $this->tmpDir . '/error.log';
            $logger->setLogFile($errFile);
            $logger->setChannel('system', $this->tmpDir . '/system.log');

            $jm = new JobManager($logger, true);
            $jm->register('failing', 1, function () use (&$ran) {
                $ran = true;
                throw new \RuntimeException('job exploded');
            });

            $jm->start();
            \Swoole\Coroutine::sleep(1.5);
            $jm->stopAll();
        });

        $this->assertTrue($ran);
        // Error should be logged, not thrown
        $errorLog = file_get_contents($this->tmpDir . '/error.log');
        $this->assertStringContainsString('job exploded', $errorLog);
    }
}
