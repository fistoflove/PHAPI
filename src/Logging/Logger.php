<?php

declare(strict_types=1);

namespace PHAPI\Logging;

/**
 * Simple logger implementation
 * Can be extended to support PSR-3 or other logging backends
 */
class Logger
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    public const CHANNEL_DEFAULT = 'default';
    public const CHANNEL_ACCESS = 'access';
    public const CHANNEL_ERROR = 'error';
    public const CHANNEL_TASK = 'task';
    public const CHANNEL_DEBUG = 'debug';
    public const CHANNEL_SYSTEM = 'system';
    public const CHANNEL_PERFORMANCE = 'performance';

    private static ?Logger $instance = null;
    private ?string $logFile = null;
    /**
     * @var array<string, string> channel => log file
     */
    private array $channels = [];
    private string $level = self::LEVEL_INFO;
    private bool $enabled = true;
    private bool $outputToStdout = false;
    private bool $debugMode = false; // Controls debug channel logging

    private function __construct()
    {
    }

    /**
     * Get the singleton logger instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set the default log file.
     */
    public function setLogFile(?string $logFile): self
    {
        $this->logFile = $logFile;
        return $this;
    }

    /**
     * Configure a logging channel with its own log file
     *
     * @param string $channel Channel name
     * @param string $logFile Path to log file for this channel
     * @return self
     */
    public function setChannel(string $channel, string $logFile): self
    {
        $this->channels[$channel] = $logFile;
        // Auto-create log directory if needed
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $this;
    }

    /**
     * Configure multiple channels at once
     *
     * @param array<string, string> $channels ['channel_name' => 'log_file_path', ...]
     * @return self
     */
    public function setChannels(array $channels): self
    {
        foreach ($channels as $channel => $logFile) {
            $this->setChannel($channel, $logFile);
        }
        return $this;
    }

    /**
     * Get log file for a specific channel
     */
    public function getChannelFile(string $channel): ?string
    {
        return $this->channels[$channel] ?? null;
    }

    /**
     * Set the minimum log level.
     */
    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Enable or disable logging.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Enable or disable stdout output.
     */
    public function setOutputToStdout(bool $output): self
    {
        $this->outputToStdout = $output;
        return $this;
    }

    /**
     * Enable or disable debug mode (controls debug channel)
     */
    public function setDebugMode(bool $enabled): self
    {
        $this->debugMode = $enabled;
        return $this;
    }

    /**
     * Log an info message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log a critical message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Get a channel-specific logger instance
     *
     * @param string $channel Channel name
     * @return ChannelLogger Logger scoped to the specified channel
     */
    public function channel(string $channel): ChannelLogger
    {
        return new ChannelLogger($this, $channel);
    }

    /**
     * Convenience methods for built-in channels
     * Use these instead of channel('access'), channel('error'), etc.
     *
     * @return ChannelLogger
     */
    public function access(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_ACCESS);
    }


    /**
     * Shortcut for the error channel.
     *
     * @return ChannelLogger
     */
    public function errors(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_ERROR);
    }

    /**
     * Shortcut for the task channel.
     *
     * @return ChannelLogger
     */
    public function task(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_TASK);
    }

    /**
     * Debug channel - only logs when debug mode is enabled
     *
     * @return ChannelLogger
     */
    public function debug(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_DEBUG);
    }

    /**
     * System channel - for internal framework logs (not for user code)
     * @internal
     *
     * @return ChannelLogger
     */
    public function system(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_SYSTEM);
    }

    /**
     * Performance channel - for performance monitoring metrics
     *
     * @return ChannelLogger
     */
    public function performance(): ChannelLogger
    {
        return $this->channel(self::CHANNEL_PERFORMANCE);
    }

    /**
     * Internal log method - automatically uses appropriate format based on channel
     * Access channel uses fixed-column format, other channels use flattened format
     * Used internally by ChannelLogger
     * @internal
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     * @param string|null $channel
     * @return void
     */
    public function log(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        if (!$this->enabled) {
            return;
        }

        // Debug channel only logs when debug mode is enabled
        if ($channel === self::CHANNEL_DEBUG && !$this->debugMode) {
            return;
        }

        if (!$this->shouldLog($level)) {
            return;
        }

        // Get timestamp with microseconds
        $microtime = microtime(true);
        $timestamp = date('Y-m-d H:i:s', (int)$microtime) . '.' . sprintf('%06d', (int)(($microtime - (int)$microtime) * 1000000));
        $channelStr = $channel ?? '';

        // Escape tabs in all fields to preserve TSV structure
        $escape = fn ($val) => str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], (string)$val);
        $safeMessage = $escape($message);

        // Flatten context array into TSV columns
        // Format: timestamp	level	channel	message	field1	value1	field2	value2	...
        $columns = [
            $timestamp,
            $level,
            $channelStr,
            $safeMessage,
        ];

        // Flatten context into key-value pairs as separate columns
        if ($context !== []) {
            foreach ($context as $key => $value) {
                $columns[] = $escape($key);
                // Convert arrays/objects to JSON string for TSV compatibility
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $columns[] = $escape($value);
            }
        }

        // TSV Format: timestamp	level	channel	message	[key1	value1	key2	value2	...]
        $logMessage = implode("\t", $columns) . PHP_EOL;

        $this->writeLog($logMessage, $channel);
    }

    /**
     * Log access requests with full TSV columns for optimal performance
     * Non-blocking async logging using coroutines
     * Used internally by ChannelLogger for access channel
     *
     * TSV Format: timestamp	level	channel	message	request_id	method	uri	ip	user_agent	referer	host	protocol	content_type	content_length	query_string	status	duration_ms	middleware_ms	handler_ms	validation_ms	after_middleware_ms
     * @internal
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $fields
     * @param string|null $channel
     * @return void
     */
    public function logAccess(string $level, string $message, array $fields = [], ?string $channel = null): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->shouldLog($level)) {
            return;
        }

        // Get timestamp with microseconds
        $microtime = microtime(true);
        $timestamp = date('Y-m-d H:i:s', (int)$microtime) . '.' . sprintf('%06d', (int)(($microtime - (int)$microtime) * 1000000));
        $channelStr = $channel ?? self::CHANNEL_ACCESS;

        // Escape tabs and newlines in all fields
        $safeMessage = str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], $message);

        // Extract fields with defaults
        $requestId = $fields['request_id'] ?? '';
        $method = $fields['method'] ?? '';
        $uri = $fields['uri'] ?? '';
        $ip = $fields['ip'] ?? 'unknown';
        $userAgent = $fields['user_agent'] ?? '';
        $referer = $fields['referer'] ?? '';
        $host = $fields['host'] ?? '';
        $protocol = $fields['protocol'] ?? '';
        $contentType = $fields['content_type'] ?? '';
        $contentLength = $fields['content_length'] ?? 0;
        $queryString = $fields['query_string'] ?? '';
        $status = $fields['status'] ?? '';
        $durationMs = $fields['duration_ms'] ?? '';
        $middlewareMs = $fields['middleware_ms'] ?? '';
        $handlerMs = $fields['handler_ms'] ?? '';
        $validationMs = $fields['validation_ms'] ?? '';
        $afterMiddlewareMs = $fields['after_middleware_ms'] ?? '';

        // Escape all fields
        $escape = fn ($val) => str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], (string)$val);

        // TSV Format: timestamp	level	channel	message	request_id	method	uri	ip	user_agent	referer	host	protocol	content_type	content_length	query_string	status	duration_ms	middleware_ms	handler_ms	validation_ms	after_middleware_ms
        $logMessage = implode("\t", [
            $timestamp,
            $level,
            $channelStr,
            $safeMessage,
            $escape($requestId),
            $escape($method),
            $escape($uri),
            $escape($ip),
            $escape($userAgent),
            $escape($referer),
            $escape($host),
            $escape($protocol),
            $escape($contentType),
            $escape($contentLength),
            $escape($queryString),
            $escape($status),
            $escape($durationMs),
            $escape($middlewareMs),
            $escape($handlerMs),
            $escape($validationMs),
            $escape($afterMiddlewareMs),
        ]) . PHP_EOL;

        $this->writeLog($logMessage, $channelStr);
    }

    /**
     * Non-blocking log write using coroutines
     */
    private function writeLog(string $logMessage, ?string $channel = null): void
    {
        if ($this->outputToStdout) {
            // Non-blocking stdout via coroutine
            if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() >= 0) {
                \Swoole\Coroutine::create(function () use ($logMessage) {
                    echo $logMessage;
                });
            } else {
                echo $logMessage;
            }
        }

        // Determine which log file(s) to write to
        $logFiles = [];

        // If channel is specified and has its own log file, use it
        if ($channel !== null && isset($this->channels[$channel])) {
            $logFiles[] = $this->channels[$channel];
        }

        // Also write to default log file if set (only when no channel is specified)
        if ($this->logFile !== null && $channel === null) {
            $logFiles[] = $this->logFile;
        }

        // If no channel-specific file and no default file, nothing to log
        if ($logFiles === []) {
            return;
        }

        // Remove duplicates
        $logFiles = array_unique($logFiles);

        // Non-blocking async file write using coroutines
        foreach ($logFiles as $logFile) {
            $this->writeToFile($logMessage, $logFile);
        }
    }

    private function writeToFile(string $logMessage, string $logFile): void
    {
        // Use async file I/O if in coroutine context (non-blocking)
        // If not in coroutine context, use blocking write
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() >= 0) {
            // We're in a coroutine, but writeFile still blocks this coroutine
            // So we spawn a new coroutine to make it truly non-blocking
            \Swoole\Coroutine::create(function () use ($logMessage, $logFile) {
                try {
                    // This will yield and allow other coroutines to run
                    \Swoole\Coroutine\System::writeFile(
                        $logFile,
                        $logMessage,
                        FILE_APPEND | LOCK_EX
                    );
                } catch (\Throwable $e) {
                    // Fallback to blocking write if async fails
                    @file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            });
        } else {
            // Not in coroutine context, use blocking write
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            self::LEVEL_INFO => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_CRITICAL => 4,
        ];

        return ($levels[$level] ?? 0) >= ($levels[$this->level] ?? 0);
    }
}
