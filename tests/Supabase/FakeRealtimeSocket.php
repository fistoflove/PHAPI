<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Realtime\RealtimeSocket;

/**
 * Test double for RealtimeSocket that records sent messages
 * and returns queued responses without requiring Swoole.
 */
class FakeRealtimeSocket implements RealtimeSocket
{
    public bool $connected = false;

    /** @var array<int, string> */
    public array $sent = [];

    /** @var array<int, string> */
    private array $receiveQueue = [];

    public string $connectHost = '';
    public int $connectPort = 0;
    public bool $connectSsl = false;
    public string $connectPath = '';

    public function connect(string $host, int $port, bool $ssl, string $path): void
    {
        $this->connectHost = $host;
        $this->connectPort = $port;
        $this->connectSsl = $ssl;
        $this->connectPath = $path;
        $this->connected = true;
    }

    public function send(string $data): bool
    {
        $this->sent[] = $data;

        return true;
    }

    public function recv(float $timeout): ?string
    {
        if ($this->receiveQueue !== []) {
            return array_shift($this->receiveQueue);
        }

        return null;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function addMessage(string $json): void
    {
        $this->receiveQueue[] = $json;
    }

    /**
     * Get the last sent message as a decoded array.
     *
     * @return array<string, mixed>|null
     */
    public function lastSent(): ?array
    {
        if ($this->sent === []) {
            return null;
        }

        $decoded = json_decode($this->sent[count($this->sent) - 1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get all sent messages as decoded arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allSent(): array
    {
        $result = [];
        foreach ($this->sent as $data) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $result[] = $decoded;
            }
        }

        return $result;
    }
}
