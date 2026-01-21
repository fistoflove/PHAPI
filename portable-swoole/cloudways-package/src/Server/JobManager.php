<?php

namespace PHAPI\Server;

use PHAPI\Logging\Logger;
use Swoole\Timer;

/**
 * Manages scheduled jobs (recurring tasks)
 * 
 * Jobs are time-triggered tasks that run automatically at specified intervals
 */
class JobManager
{
    private array $jobs = [];
    private array $timers = [];
    private Logger $logger;
    private bool $debug;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * Register a scheduled job
     * 
     * @param string $name Job name
     * @param int $intervalSeconds Interval in seconds (minimum: 1 second)
     * @param callable $handler Job handler receives: ($logger)
     * @return void
     */
    public function register(string $name, int $intervalSeconds, callable $handler): void
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException("Job interval must be at least 1 second");
        }

        $this->jobs[$name] = [
            'interval' => $intervalSeconds * 1000, // Convert to milliseconds
            'handler' => $handler
        ];
    }

    /**
     * Start all registered jobs
     * 
     * This should be called after the server starts (in onStart callback)
     * 
     * @return void
     */
    public function start(): void
    {
        foreach ($this->jobs as $name => $job) {
            $this->startJob($name, $job);
        }

        if (!empty($this->jobs)) {
            $this->logger->system()->info("Started " . count($this->jobs) . " scheduled job(s)", [
                'job_names' => array_keys($this->jobs)
            ]);
        }
    }

    /**
     * Start a specific job
     * 
     * @param string $name Job name
     * @param array $job Job configuration
     * @return void
     */
    private function startJob(string $name, array $job): void
    {
        $interval = $job['interval'];
        $handler = $job['handler'];

        $timerId = Timer::tick($interval, function () use ($name, $handler) {
            $startTime = microtime(true);

            try {
                // Only log job start in debug mode
                if ($this->debug) {
                    $this->logger->debug()->info("Job started", ['job' => $name]);
                }
                
                // Execute job handler
                $handler($this->logger);
                
                $duration = (microtime(true) - $startTime) * 1000;
                $this->logger->system()->info("Job completed", [
                    'job' => $name,
                    'duration_ms' => round($duration, 2)
                ]);
            } catch (\Throwable $e) {
                $duration = (microtime(true) - $startTime) * 1000;
                $this->logger->error()->error("Job failed", [
                    'job' => $name,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => round($duration, 2),
                    'trace' => $this->debug ? $e->getTraceAsString() : null
                ]);
            }
        });

        $this->timers[$name] = $timerId;
        
        $this->logger->system()->info("Job registered", [
            'job' => $name,
            'interval_seconds' => $job['interval'] / 1000,
            'timer_id' => $timerId
        ]);
    }

    /**
     * Stop a specific job
     * 
     * @param string $name Job name
     * @return bool True if job was stopped, false if not found
     */
    public function stop(string $name): bool
    {
        if (!isset($this->timers[$name])) {
            return false;
        }

        Timer::clear($this->timers[$name]);
        unset($this->timers[$name]);
        
        $this->logger->system()->info("Job stopped", ['job' => $name]);
        
        return true;
    }

    /**
     * Stop all jobs
     * 
     * @return void
     */
    public function stopAll(): void
    {
        foreach ($this->timers as $name => $timerId) {
            Timer::clear($timerId);
        }
        
        $this->timers = [];
        $this->logger->system()->info("All jobs stopped");
    }

    /**
     * Get list of registered jobs
     * 
     * @return array Job names
     */
    public function list(): array
    {
        return array_keys($this->jobs);
    }
}
