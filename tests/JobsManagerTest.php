<?php

namespace PHAPI\Tests;

use PHAPI\Services\JobsManager;
use PHPUnit\Framework\TestCase;

class JobsManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phapi-jobs-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*') ?: [];
        // Also remove rotated logs like job.log.1, job.log.2
        $files = array_merge($files, glob($this->dir . '/*.*') ?: []);
        $files = array_unique($files);
        array_map('unlink', $files);
        @rmdir($this->dir);
    }

    // ─── register() ──────────────────────────────────────────────────

    public function testRegisterThrowsOnZeroInterval(): void
    {
        $jobs = new JobsManager($this->dir);

        $this->expectException(\InvalidArgumentException::class);
        $jobs->register('bad', 0, fn () => 'ok');
    }

    public function testRegisterThrowsOnNegativeInterval(): void
    {
        $jobs = new JobsManager($this->dir);

        $this->expectException(\InvalidArgumentException::class);
        $jobs->register('bad', -5, fn () => 'ok');
    }

    // ─── list() ──────────────────────────────────────────────────────

    public function testListReturnsEmptyWhenNoJobs(): void
    {
        $jobs = new JobsManager($this->dir);

        $this->assertSame([], $jobs->list());
    }

    public function testListReturnsRegisteredJobNames(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('alpha', 10, fn () => 'a');
        $jobs->register('beta', 20, fn () => 'b');

        $this->assertSame(['alpha', 'beta'], $jobs->list());
    }

    // ─── jobs() ──────────────────────────────────────────────────────

    public function testJobsReturnsEmptyWhenNoJobs(): void
    {
        $jobs = new JobsManager($this->dir);

        $this->assertSame([], $jobs->jobs());
    }

    public function testJobsReturnsFullConfig(): void
    {
        $jobs = new JobsManager($this->dir);
        $handler = fn () => 'ok';
        $jobs->register('sync', 30, $handler, ['log_enabled' => false, 'lock_mode' => 'block']);

        $all = $jobs->jobs();
        $this->assertArrayHasKey('sync', $all);
        $this->assertSame(30, $all['sync']['interval']);
        $this->assertFalse($all['sync']['log_enabled']);
        $this->assertSame('block', $all['sync']['lock_mode']);
    }

    // ─── runDue() ────────────────────────────────────────────────────

    public function testRunDueExecutesDueJob(): void
    {
        $jobs = new JobsManager($this->dir);
        $called = false;
        $jobs->register('tick', 1, function () use (&$called) {
            $called = true;
            return 'done';
        });

        $results = $jobs->runDue(function (callable $handler, string $name) {
            return $handler();
        });

        $this->assertTrue($called);
        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status']);
    }

    public function testRunDueSkipsNotYetDueJob(): void
    {
        $jobs = new JobsManager($this->dir);
        $callCount = 0;
        $jobs->register('slow', 3600, function () use (&$callCount) {
            $callCount++;
            return 'ok';
        });

        // First run should execute (never run before)
        $jobs->runDue(fn (callable $h, string $n) => $h());
        $this->assertSame(1, $callCount);

        // Second run should skip (ran just now, interval is 1 hour)
        $jobs->runDue(fn (callable $h, string $n) => $h());
        $this->assertSame(1, $callCount);
    }

    public function testRunDueHandlesExecutorException(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('fail', 1, fn () => 'ok');

        $results = $jobs->runDue(function (callable $handler, string $name) {
            throw new \RuntimeException('boom');
        });

        $this->assertCount(1, $results);
        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('boom', $results[0]['error']);
    }

    // ─── runScheduled() ──────────────────────────────────────────────

    public function testRunScheduledExecutesNamedJob(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('named', 60, fn () => 'result');

        $result = $jobs->runScheduled('named', fn (callable $h, string $n) => $h());

        $this->assertNotNull($result);
        $this->assertSame('ok', $result['status']);
    }

    public function testRunScheduledReturnsNullForNonexistent(): void
    {
        $jobs = new JobsManager($this->dir);

        $result = $jobs->runScheduled('ghost', fn (callable $h, string $n) => $h());

        $this->assertNull($result);
    }

    // ─── Lock behavior ───────────────────────────────────────────────

    public function testLockSkipIsLogged(): void
    {
        $jobs = new JobsManager($this->dir, 10, 1024 * 1024, 2);
        $jobs->register('locked', 1, function () {
            return 'ok';
        }, ['log_enabled' => true, 'lock_mode' => 'skip']);

        $lockPath = $this->dir . '/locked.lock';
        $handle = fopen($lockPath, 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $result = $jobs->runScheduled('locked', function ($handler) {
            return $handler();
        });

        $this->assertSame('skipped', $result['status']);

        $logPath = $this->dir . '/locked.log';
        $this->assertFileExists($logPath);
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // ─── logs() ──────────────────────────────────────────────────────

    public function testLogsReturnsEmptyWhenNoJobs(): void
    {
        $jobs = new JobsManager($this->dir);

        $this->assertSame([], $jobs->logs());
    }

    public function testLogsReturnsEntriesAfterRun(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('logged', 1, fn () => 'ok');
        $jobs->runDue(fn (callable $h, string $n) => $h());

        $logs = $jobs->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('logged', $logs[0]['job']);
        $this->assertSame('ok', $logs[0]['status']);
    }

    public function testLogsFilteredByName(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('alpha', 1, fn () => 'a');
        $jobs->register('beta', 1, fn () => 'b');
        $jobs->runDue(fn (callable $h, string $n) => $h());

        $alphaLogs = $jobs->logs('alpha');
        $this->assertCount(1, $alphaLogs);
        $this->assertSame('alpha', $alphaLogs[0]['job']);
    }

    public function testLogsReturnsEmptyWhenLogDisabled(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('silent', 1, fn () => 'ok', ['log_enabled' => false]);

        $this->assertSame([], $jobs->logs('silent'));
    }

    // ─── State file ──────────────────────────────────────────────────

    public function testStateFileWrittenWhenLoggingDisabled(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('stateful', 1, fn () => 'ok', ['log_enabled' => false]);
        $jobs->runDue(fn (callable $h, string $n) => $h());

        $statePath = $this->dir . '/stateful.state';
        $this->assertFileExists($statePath);
        $this->assertIsNumeric(trim((string) file_get_contents($statePath)));
    }

    // ─── Log trimming ────────────────────────────────────────────────

    public function testLogTrimming(): void
    {
        $logLimit = 3;
        $jobs = new JobsManager($this->dir, $logLimit);
        $jobs->register('trim', 1, fn () => 'ok');

        // Run 5 times via runScheduled (bypasses interval check)
        for ($i = 0; $i < 5; $i++) {
            $jobs->runScheduled('trim', fn (callable $h, string $n) => $h());
        }

        $logs = $jobs->logs('trim');
        $this->assertCount($logLimit, $logs);
    }

    // ─── sanitizeField ──────────────────────────────────────────────

    public function testSanitizeFieldHandlesTabsNewlinesObjects(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('messy', 1, fn () => ['key' => "val\twith\ttabs"]);

        $jobs->runScheduled('messy', function (callable $h, string $n) {
            $result = $h();
            echo "line1\nline2\t";
            return ['result' => $result, 'output' => ob_get_contents() ?: ''];
        });

        $logs = $jobs->logs('messy');
        $this->assertCount(1, $logs);
        // Tabs and newlines should be replaced with spaces in the log
        $this->assertStringNotContainsString("\t", $logs[0]['output']);
        $this->assertStringNotContainsString("\n", $logs[0]['result']);
    }

    // ─── Custom log_file ────────────────────────────────────────────

    public function testCustomLogFilePath(): void
    {
        $jobs = new JobsManager($this->dir);
        $jobs->register('custom', 1, fn () => 'ok', ['log_file' => 'custom-path.log']);
        $jobs->runScheduled('custom', fn (callable $h, string $n) => $h());

        $this->assertFileExists($this->dir . '/custom-path.log');
    }

    public function testAbsoluteLogFilePath(): void
    {
        $absPath = $this->dir . '/absolute.log';
        $jobs = new JobsManager($this->dir);
        $jobs->register('abs', 1, fn () => 'ok', ['log_file' => $absPath]);
        $jobs->runScheduled('abs', fn (callable $h, string $n) => $h());

        $this->assertFileExists($absPath);
    }
}
