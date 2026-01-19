<?php

namespace PHAPI\Logging;

/**
 * Channel-specific logger wrapper
 * Provides cleaner API for logging to specific channels
 */
class ChannelLogger
{
    private \PHAPI\Logging\Logger $logger;
    private string $channel;

    public function __construct(Logger $logger, string $channel)
    {
        $this->logger = $logger;
        $this->channel = $channel;
    }

    public function info(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_INFO, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_INFO, $message, $context, $this->channel);
        }
    }

    public function warning(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_WARNING, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_WARNING, $message, $context, $this->channel);
        }
    }

    public function error(string $message, array $context = []): void
    {
        // Use logAccess format for access channel to get proper TSV columns
        if ($this->channel === Logger::CHANNEL_ACCESS) {
            $this->logger->logAccess(Logger::LEVEL_ERROR, $message, $context, $this->channel);
        } else {
            $this->logger->log(Logger::LEVEL_ERROR, $message, $context, $this->channel);
        }
    }

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

