<?php

declare(strict_types=1);

namespace PHAPI\Logging;

/**
 * Channel-specific logger wrapper
 * Provides cleaner API for logging to specific channels
 */
class ChannelLogger
{
    private \PHAPI\Logging\Logger $logger;
    private string $channel;

    /**
     * Create a channel-specific logger.
     *
     * @param Logger $logger
     * @param string $channel
     * @return void
     */
    public function __construct(Logger $logger, string $channel)
    {
        $this->logger = $logger;
        $this->channel = $channel;
    }

    /**
     * Log an info message to the channel.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_INFO, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_INFO, $message, $context, $this->channel);
        }
    }

    /**
     * Log a warning message to the channel.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_WARNING, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_WARNING, $message, $context, $this->channel);
        }
    }

    /**
     * Log an error message to the channel.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_ERROR, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_ERROR, $message, $context, $this->channel);
        }
    }

    /**
     * Log a critical message to the channel.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_CRITICAL, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_CRITICAL, $message, $context, $this->channel);
        }
    }

}
