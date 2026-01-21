<?php

declare(strict_types=1);

namespace PHAPI\Services;

/**
 * @phpstan-type JobConfig array{interval: int, handler: callable(mixed ...$args): mixed, log_enabled: bool, log_file: string|null, lock_mode: string}
 * @phpstan-type JobRecord array{job: string, status: string, started_at: string|null, duration_ms: float|null, output: string, result: mixed, error?: string}
 * @phpstan-type JobLock array{handle: resource, path: string}
 */
class JobsManager
{
    /**
     * @var array<string, JobConfig>
     */
    private array $jobs = [];
    private string $logDir;
    private int $logLimit;
    private int $rotateBytes;
    private int $rotateKeep;

    /**
     * Create a jobs manager with log rotation settings.
     *
     * @param string $logDir
     * @param int $logLimit
     * @param int $rotateBytes
     * @param int $rotateKeep
     * @return void
     */
    public function __construct(string $logDir, int $logLimit = 200, int $rotateBytes = 1048576, int $rotateKeep = 5)
    {
        $this->logDir = $logDir;
        $this->logLimit = $logLimit;
        $this->rotateBytes = $rotateBytes;
        $this->rotateKeep = $rotateKeep;
    }

    /**
     * Register a recurring job.
     *
     * @param string $name
     * @param int $intervalSeconds
     * @param callable(mixed ...$args): mixed $handler
     * @param array{log_enabled?: bool, log_file?: string|null, lock_mode?: string} $options
     * @return void
     */
    public function register(string $name, int $intervalSeconds, callable $handler, array $options = []): void
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException('Job interval must be at least 1 second');
        }

        $this->jobs[$name] = [
            'interval' => $intervalSeconds,
            'handler' => $handler,
            'log_enabled' => $options['log_enabled'] ?? true,
            'log_file' => $options['log_file'] ?? null,
            'lock_mode' => $options['lock_mode'] ?? 'skip',
        ];
    }

    /**
     * Get job names.
     *
     * @return array<int, string>
     */
    public function list(): array
    {
        return array_keys($this->jobs);
    }

    /**
     * Get all registered jobs.
     *
     * @return array<string, JobConfig>
     */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * Run due jobs and return results.
     *
     * @param callable(callable(mixed ...$args): mixed, string): mixed $executor
     * @return array<int, JobRecord>
     */
    public function runDue(callable $executor): array
    {
        $now = time();
        $results = [];

        foreach ($this->jobs as $name => $job) {
            $interval = $job['interval'];
            $previous = $this->getLastRunTimestamp($name, $job) ?? 0;
            if ($now - $previous < $interval) {
                continue;
            }

            $results[] = $this->runJob($name, $job, $executor, $now);
        }

        return $results;
    }

    /**
     * Run a scheduled job by name.
     *
     * @param string $name
     * @param callable(callable(mixed ...$args): mixed, string): mixed $executor
     * @return JobRecord|null
     */
    public function runScheduled(string $name, callable $executor): ?array
    {
        if (!isset($this->jobs[$name])) {
            return null;
        }

        $now = time();
        return $this->runJob($name, $this->jobs[$name], $executor, $now);
    }

    /**
     * Get job logs, optionally filtered by job name.
     *
     * @param string|null $name
     * @return array<int, JobRecord>
     */
    public function logs(?string $name = null): array
    {
        if ($name !== null) {
            $job = $this->jobs[$name] ?? null;
            if ($job === null || !$job['log_enabled']) {
                return [];
            }
            return $this->readLogLines($this->logPathFor($name, $job), $name);
        }

        $entries = [];
        foreach ($this->jobs as $jobName => $job) {
            if (!$job['log_enabled']) {
                continue;
            }
            $entries = array_merge($entries, $this->readLogLines($this->logPathFor($jobName, $job), $jobName));
        }

        return $entries;
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @param callable(callable(mixed ...$args): mixed, string): mixed $executor
     * @param int $now
     * @return JobRecord
     * @phpstan-return JobRecord
     */
    private function runJob(string $name, array $job, callable $executor, int $now): array
    {
        $record = [
            'job' => $name,
            'status' => 'ok',
            'started_at' => date('c', $now),
            'duration_ms' => 0.0,
            'output' => '',
            'result' => null,
        ];

        $lock = $this->acquireLock($name, $job);
        if ($lock === null) {
            $record['status'] = 'skipped';
            $record['error'] = 'locked';
            $this->recordRun($name, $job, $record, $now);
            return $record;
        }

        $start = microtime(true);
        try {
            $execution = $executor($job['handler'], $name);
            if (is_array($execution)) {
                $record['output'] = $execution['output'] ?? '';
                $record['result'] = $this->normalizeResult($execution['result'] ?? null);
            } else {
                $record['result'] = $this->normalizeResult($execution);
            }
        } catch (\Throwable $e) {
            $record['status'] = 'error';
            $record['error'] = $e->getMessage();
        } finally {
            $record['duration_ms'] = round((microtime(true) - $start) * 1000, 2);
            $this->recordRun($name, $job, $record, $now);
            $this->releaseLock($lock);
        }

        return $record;
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @param JobRecord $record
     * @phpstan-param JobRecord $record
     * @param int $now
     * @return void
     */
    private function recordRun(string $name, array $job, array $record, int $now): void
    {
        if ($job['log_enabled']) {
            $this->appendLog($name, $job, $record);
            return;
        }

        if ($record['status'] === 'skipped') {
            return;
        }

        $this->writeState($name, $now);
    }

    private function normalizeResult(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @param JobRecord $record
     * @phpstan-param JobRecord $record
     * @return void
     */
    private function appendLog(string $name, array $job, array $record): void
    {
        $path = $this->logPathFor($name, $job);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->rotateIfNeeded($path);

        $line = implode("\t", [
            $record['started_at'],
            $record['status'],
            (string)$record['duration_ms'],
            $this->sanitizeField($record['output']),
            $this->sanitizeField($record['result']),
            $this->sanitizeField($record['error'] ?? ''),
        ]);

        file_put_contents($path, $line . "\n", FILE_APPEND);
        $this->trimLogs($path);
    }

    private function rotateIfNeeded(string $path): void
    {
        if ($this->rotateBytes <= 0 || $this->rotateKeep < 1) {
            return;
        }

        if (!file_exists($path)) {
            return;
        }

        $size = filesize($path);
        if ($size === false || $size < $this->rotateBytes) {
            return;
        }

        for ($i = $this->rotateKeep - 1; $i >= 1; $i--) {
            $from = $path . '.' . $i;
            $to = $path . '.' . ($i + 1);
            if (file_exists($from)) {
                @rename($from, $to);
            }
        }

        @rename($path, $path . '.1');
    }

    private function trimLogs(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) <= $this->logLimit) {
            return;
        }

        $lines = array_slice($lines, -$this->logLimit);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param string $path
     * @param string $jobName
     * @return array<int, JobRecord>
     * @phpstan-return array<int, JobRecord>
     */
    private function readLogLines(string $path, string $jobName): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $status = $parts[1] ?? '';
            $entries[] = [
                'job' => $jobName,
                'started_at' => $parts[0] ?? null,
                'status' => $status,
                'duration_ms' => isset($parts[2]) ? (float)$parts[2] : null,
                'output' => $parts[3] ?? '',
                'result' => $parts[4] ?? '',
                'error' => $parts[5] ?? '',
            ];
        }

        return $entries;
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @return int|null
     */
    private function getLastRunTimestamp(string $name, array $job): ?int
    {
        if ($job['log_enabled']) {
            return $this->getLastLogTimestamp($name, $job);
        }

        return $this->readState($name);
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @return int|null
     */
    private function getLastLogTimestamp(string $name, array $job): ?int
    {
        $path = $this->logPathFor($name, $job);
        if (!file_exists($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return null;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $parts = explode("\t", $lines[$i]);
            $status = $parts[1] ?? '';
            if ($status === 'skipped') {
                continue;
            }
            $timestamp = strtotime($parts[0] ?? '');
            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }

    private function readState(string $name): ?int
    {
        $path = $this->statePathFor($name);
        if (!file_exists($path)) {
            return null;
        }

        $raw = trim((string)file_get_contents($path));
        if ($raw === '') {
            return null;
        }

        return (int)$raw;
    }

    private function writeState(string $name, int $timestamp): void
    {
        $path = $this->statePathFor($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($path, (string)$timestamp . "\n");
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @return JobLock|null
     * @phpstan-return JobLock|null
     */
    private function acquireLock(string $name, array $job): ?array
    {
        $path = $this->lockPathFor($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }

        $mode = $job['lock_mode'];
        $flags = $mode === 'block' ? LOCK_EX : (LOCK_EX | LOCK_NB);

        if (!flock($handle, $flags)) {
            fclose($handle);
            return null;
        }

        return ['handle' => $handle, 'path' => $path];
    }

    /**
     * @param JobLock $lock
     * @phpstan-param JobLock $lock
     * @return void
     */
    private function releaseLock(array $lock): void
    {
        $handle = $lock['handle'];
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param string $name
     * @param JobConfig $job
     * @phpstan-param JobConfig $job
     * @return string
     */
    private function logPathFor(string $name, array $job): string
    {
        $custom = $job['log_file'];
        if ($custom !== null) {
            if ($custom[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $custom) === 1) {
                return $custom;
            }
            return rtrim($this->logDir, '/') . '/' . ltrim($custom, '/');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        return rtrim($this->logDir, '/') . '/' . $safeName . '.log';
    }

    private function statePathFor(string $name): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        return rtrim($this->logDir, '/') . '/' . $safeName . '.state';
    }

    private function lockPathFor(string $name): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        return rtrim($this->logDir, '/') . '/' . $safeName . '.lock';
    }

    private function sanitizeField(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $value = (string)$value;
        $value = str_replace(["\t", "\n", "\r"], ' ', $value);
        return trim($value);
    }
}
