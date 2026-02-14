<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Contracts\WebSocketDriverInterface;

final class WebSocketConnection
{
    public function __construct(
        private readonly WebSocketDriverInterface $driver,
        private readonly int $fd
    ) {
    }

    public function fd(): int
    {
        return $this->fd;
    }

    public function isEstablished(): bool
    {
        return $this->driver->isConnectionEstablished($this->fd);
    }

    public function disconnect(int $code = 1000, string $reason = ''): bool
    {
        return $this->driver->disconnect($this->fd, $code, $reason);
    }

    public function subscribe(string $channel): void
    {
        $this->driver->subscribe($this->fd, $channel);
    }

    public function unsubscribe(string $channel): void
    {
        $this->driver->unsubscribe($this->fd, $channel);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    public function send(array|string $payload): bool
    {
        if (is_array($payload)) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                return false;
            }
            return $this->driver->send($this->fd, $encoded);
        }

        return $this->driver->send($this->fd, $payload);
    }
}

