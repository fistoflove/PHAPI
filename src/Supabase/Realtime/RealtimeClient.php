<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Realtime;

use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;
use PHAPI\Supabase\SupabaseConfig;

/**
 * Supabase Realtime client using Phoenix Channels over WebSocket.
 *
 * Maintains a persistent WebSocket connection to Supabase Realtime,
 * with automatic heartbeat keep-alive and coroutine-based message
 * dispatching. Designed as a per-worker singleton in Swoole.
 *
 * API mirrors supabase-js: channel(), removeChannel(), removeAllChannels().
 *
 * @api
 */
final class RealtimeClient
{
    private const HEARTBEAT_INTERVAL_MS = 29000;

    /** @var array<string, RealtimeChannel> */
    private array $channels = [];

    private int $ref = 0;
    private bool $connected = false;
    private ?int $heartbeatTimer = null;
    private ?RealtimeSocket $socket;

    public function __construct(
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
        ?RealtimeSocket $socket = null,
    ) {
        $this->socket = $socket;
    }

    /**
     * Connect to Supabase Realtime WebSocket endpoint.
     *
     * Starts the heartbeat timer and receiver coroutine.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->socket === null) {
            $this->socket = new SwooleRealtimeSocket();
        }

        $parts = parse_url($this->config->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new SupabaseRealtimeException('Invalid Supabase URL: ' . $this->config->url);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ssl = $scheme === 'https';
        $path = '/realtime/v1/websocket?apikey=' . rawurlencode($this->config->anonKey) . '&vsn=1.0.0';

        $this->socket->connect($host, $port, $ssl, $path);
        $this->connected = true;

        $this->startHeartbeat();
        $this->startReceiver();
    }

    /**
     * Disconnect from Supabase Realtime.
     *
     * Unsubscribes all channels, stops the heartbeat, and closes the socket.
     */
    public function disconnect(): void
    {
        $this->connected = false;

        if ($this->heartbeatTimer !== null && class_exists(\Swoole\Timer::class)) {
            \Swoole\Timer::clear($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        // Close the socket first — this interrupts the receiver coroutine's
        // blocked recv() call so it exits cleanly.
        if ($this->socket !== null) {
            $this->socket->close();
        }

        foreach ($this->channels as $channel) {
            $channel->unsubscribe();
        }
    }

    /**
     * Create or retrieve a channel by name.
     *
     * Returns the existing channel instance if one with the same name
     * was already created (idempotent).
     *
     * @param array<string, mixed> $options Channel options (broadcast, presence config)
     */
    public function channel(string $name, array $options = []): RealtimeChannel
    {
        $topic = 'realtime:' . $name;

        if (isset($this->channels[$topic])) {
            return $this->channels[$topic];
        }

        $channel = new RealtimeChannel(
            $topic,
            $this,
            $this->accessToken,
            $options,
        );

        $this->channels[$topic] = $channel;

        return $channel;
    }

    /**
     * Remove and unsubscribe a specific channel.
     */
    public function removeChannel(RealtimeChannel $channel): void
    {
        $channel->unsubscribe();
        unset($this->channels[$channel->topic()]);
    }

    /**
     * Remove and unsubscribe all channels.
     */
    public function removeAllChannels(): void
    {
        foreach ($this->channels as $channel) {
            $channel->unsubscribe();
        }
        $this->channels = [];
    }

    /**
     * @return array<string, RealtimeChannel>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Send a message over the WebSocket connection.
     *
     * @internal Used by RealtimeChannel to send protocol messages.
     *
     * @param array<string, mixed> $message Phoenix Channel protocol message
     */
    public function push(array $message): void
    {
        if (!$this->connected || $this->socket === null) {
            throw new SupabaseRealtimeException('Not connected to Supabase Realtime.');
        }

        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->socket->send($json);
    }

    /**
     * Get the next message reference number.
     *
     * @internal Used by RealtimeChannel for request-response correlation.
     */
    public function nextRef(): string
    {
        return (string) ++$this->ref;
    }

    /**
     * Process an incoming WebSocket message and dispatch to the appropriate channel.
     *
     * @internal Exposed for testing — normally called by the receiver coroutine.
     *
     * @param array<string, mixed> $message Decoded Phoenix Channel message
     */
    public function handleMessage(array $message): void
    {
        $topic = (string) ($message['topic'] ?? '');
        $event = (string) ($message['event'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $ref = isset($message['ref']) ? (string) $message['ref'] : null;

        // System-level: heartbeat replies
        if ($topic === 'phoenix' && $event === 'phx_reply') {
            return;
        }

        // Dispatch to the target channel
        if (isset($this->channels[$topic])) {
            $this->channels[$topic]->handleMessage($event, $payload, $ref);
        }
    }

    private function startHeartbeat(): void
    {
        if (!class_exists(\Swoole\Timer::class) || !class_exists(\Swoole\Coroutine::class)) {
            return;
        }

        // Timer::tick only works inside the Swoole event loop
        if (\Swoole\Coroutine::getCid() < 0) {
            return;
        }

        $this->heartbeatTimer = \Swoole\Timer::tick(self::HEARTBEAT_INTERVAL_MS, function (): void {
            if (!$this->connected || $this->socket === null) {
                return;
            }

            try {
                $this->push([
                    'topic' => 'phoenix',
                    'event' => 'heartbeat',
                    'payload' => (object) [],
                    'ref' => $this->nextRef(),
                ]);
            } catch (\Throwable) {
                $this->connected = false;
            }
        });
    }

    private function startReceiver(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return;
        }

        // Only start the receiver coroutine inside a Swoole coroutine context.
        // Outside of it (e.g., unit tests), Coroutine::create() would execute
        // the callback synchronously and block on the recv() loop.
        if (\Swoole\Coroutine::getCid() < 0) {
            return;
        }

        \Swoole\Coroutine::create(function (): void {
            while ($this->connected && $this->socket !== null) {
                // Short timeout so we can check $this->connected frequently
                // and exit promptly when disconnect() is called.
                $data = $this->socket->recv(1.0);

                if ($data === null) {
                    if (!$this->socket->isConnected()) {
                        $this->connected = false;
                        break;
                    }
                    continue;
                }

                /** @var array<string, mixed>|null $message */
                $message = json_decode($data, true);
                if (is_array($message)) {
                    $this->handleMessage($message);
                }
            }
        });
    }
}
