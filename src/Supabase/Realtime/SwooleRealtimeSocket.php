<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Realtime;

use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;

/**
 * Swoole coroutine WebSocket client implementation of RealtimeSocket.
 *
 * @api
 */
final class SwooleRealtimeSocket implements RealtimeSocket
{
    private ?\Swoole\Coroutine\Http\Client $client = null;

    public function connect(string $host, int $port, bool $ssl, string $path): void
    {
        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        $client->set(['timeout' => 30.0]);

        /** @var bool $success */
        $success = $client->upgrade($path);

        if (!$success) {
            /** @var string $errMsg */
            $errMsg = $client->errMsg ?? 'unknown error';
            /** @var int $errCode */
            $errCode = $client->errCode ?? 0;
            throw new SupabaseRealtimeException(
                'Failed to connect to Supabase Realtime: ' . $errMsg,
                $errCode,
            );
        }

        $this->client = $client;
    }

    public function send(string $data): bool
    {
        if ($this->client === null) {
            return false;
        }

        /** @var bool $result */
        $result = $this->client->push($data);

        return $result;
    }

    public function recv(float $timeout): ?string
    {
        if ($this->client === null) {
            return null;
        }

        /** @var \Swoole\WebSocket\Frame|false $frame */
        $frame = $this->client->recv($timeout);

        if ($frame === false) {
            return null;
        }

        return $frame->data ?? null;
    }

    public function close(): void
    {
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
    }

    public function isConnected(): bool
    {
        if ($this->client === null) {
            return false;
        }

        /** @var bool $connected */
        $connected = $this->client->connected ?? false;

        return $connected;
    }
}
