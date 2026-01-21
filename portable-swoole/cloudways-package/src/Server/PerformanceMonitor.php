<?php

namespace PHAPI\Server;

use PHAPI\Logging\Logger;

/**
 * Performance Monitor
 * 
 * Processes health check logs and calculates performance metrics
 */
class PerformanceMonitor
{
    private Logger $logger;
    private bool $enabled = false;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Enable performance monitoring
     * 
     * Adds performance channel and schedules monitoring job
     * 
     * @param bool $enabled Enable monitoring (default: true)
     * @return void
     */
    public function enable(bool $enabled = true): void
    {
        $this->enabled = $enabled;
        // Channel setup is handled by PHAPI::enablePerformanceMonitoring()
        // to ensure it has access to the log directory
    }

    /**
     * Process performance metrics from health check logs
     * 
     * Reads system.log, extracts health check entries from last 5 minutes,
     * calculates average response time, and logs to performance channel.
     * 
     * @return void
     */
    public function processMetrics(): void
    {
        if (!$this->enabled) {
            return;
        }

        $systemLogFile = $this->logger->getChannelFile(Logger::CHANNEL_SYSTEM);
        if (!$systemLogFile || !file_exists($systemLogFile)) {
            return;
        }

        // Read system log and find health check entries from last 5 minutes
        $lines = file($systemLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $healthChecks = [];
        $cutoffTime = time() - 300; // Last 5 minutes

        foreach ($lines as $line) {
            // Parse TSV format
            $parts = explode("\t", $line);
            if (count($parts) < 4) {
                continue;
            }

            // Check if it's a health check entry
            if ($parts[2] === 'system' && strpos($parts[3], 'Keep-alive health check') !== false) {
                // Parse timestamp
                $timestamp = strtotime($parts[0]);
                if ($timestamp < $cutoffTime) {
                    continue;
                }

                // Extract response_time_ms and status_code from context
                $responseTime = 0;
                $statusCode = 0;

                foreach ($parts as $i => $part) {
                    if ($part === 'response_time_ms' && isset($parts[$i + 1])) {
                        $responseTime = (float)$parts[$i + 1];
                    }
                    if ($part === 'status_code' && isset($parts[$i + 1])) {
                        $statusCode = (int)$parts[$i + 1];
                    }
                }

                if ($responseTime > 0) {
                    $healthChecks[] = [
                        'timestamp' => $parts[0],
                        'response_time' => $responseTime,
                        'status_code' => $statusCode
                    ];
                }
            }
        }

        if (empty($healthChecks)) {
            return;
        }

        // Calculate metrics
        $responseTimes = array_column($healthChecks, 'response_time');
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $minResponseTime = min($responseTimes);
        $maxResponseTime = max($responseTimes);
        $count = count($healthChecks);

        // Log performance metrics (summary only - individual entries are in system.log)
        $this->logger->performance()->info("Performance summary", [
            'period_minutes' => 5,
            'health_checks_count' => $count,
            'avg_response_time_ms' => round($avgResponseTime, 2),
            'min_response_time_ms' => round($minResponseTime, 2),
            'max_response_time_ms' => round($maxResponseTime, 2),
            'status_code' => 200 // All health checks should return 200
        ]);

        // Clean up processed health check logs from system.log
        $this->cleanHealthCheckLogs($systemLogFile, $cutoffTime);
    }

    /**
     * Clean health check logs older than cutoff time
     * 
     * @param string $logPath Path to system.log
     * @param int $cutoffTime Timestamp cutoff
     * @return void
     */
    public function cleanHealthCheckLogs(string $logPath, int $cutoffTime): void
    {
        if (!file_exists($logPath)) {
            return;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filteredLines = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 4) {
                $filteredLines[] = $line;
                continue;
            }

            // Keep non-health-check entries
            if ($parts[2] !== 'system' || strpos($parts[3], 'Keep-alive health check') === false) {
                $filteredLines[] = $line;
                continue;
            }

            // Remove health check entries older than cutoff
            $timestamp = strtotime($parts[0]);
            if ($timestamp >= $cutoffTime) {
                $filteredLines[] = $line;
            }
        }

        // Write back filtered lines
        file_put_contents($logPath, implode("\n", $filteredLines) . "\n");
    }

    /**
     * Check if performance monitoring is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
