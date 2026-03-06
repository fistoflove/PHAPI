<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Realtime;

use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;

/**
 * A Supabase Realtime channel subscription.
 *
 * Mirrors the supabase-js RealtimeChannel API: register listeners with on(),
 * join with subscribe(), send broadcasts, and track presence.
 *
 * @api
 */
final class RealtimeChannel
{
    public const BROADCAST = 'broadcast';
    public const POSTGRES_CHANGES = 'postgres_changes';
    public const PRESENCE = 'presence';
    public const SYSTEM = 'system';

    public const STATE_CLOSED = 'closed';
    public const STATE_JOINING = 'joining';
    public const STATE_JOINED = 'joined';
    public const STATE_LEAVING = 'leaving';

    private string $state = self::STATE_CLOSED;

    /** @var array<string, array<int, array{filter: array<string, mixed>, callback: callable(array<string, mixed>): void}>> */
    private array $listeners = [];

    /** @var array<int, array<string, mixed>> */
    private array $postgresChanges = [];

    /** @var (callable(string): void)|null */
    private $subscribeCallback = null;

    private RealtimePresence $presence;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $topic,
        private readonly RealtimeClient $client,
        private readonly ?string $accessToken,
        array $options = [],
    ) {
        $this->options = $options;
        $this->presence = new RealtimePresence();
    }

    public function topic(): string
    {
        return $this->topic;
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * Register a listener for channel events.
     *
     * @param string $type One of BROADCAST, POSTGRES_CHANGES, PRESENCE, SYSTEM
     * @param array<string, mixed> $filter Event filter (e.g., ['event' => 'INSERT', 'table' => 'posts'])
     * @param callable(array<string, mixed>): void $callback
     */
    public function on(string $type, array $filter, callable $callback): self
    {
        if ($type === self::POSTGRES_CHANGES) {
            $this->postgresChanges[] = array_filter([
                'event' => $filter['event'] ?? '*',
                'schema' => $filter['schema'] ?? 'public',
                'table' => $filter['table'] ?? '*',
                'filter' => $filter['filter'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $this->listeners[$type][] = [
            'filter' => $filter,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Subscribe (join) the channel.
     *
     * Automatically connects the RealtimeClient if not yet connected.
     * The optional callback receives status strings: 'SUBSCRIBED', 'CHANNEL_ERROR', 'CLOSED'.
     *
     * @param (callable(string): void)|null $onStatus
     */
    public function subscribe(?callable $onStatus = null): self
    {
        if ($this->state !== self::STATE_CLOSED) {
            return $this;
        }

        $this->subscribeCallback = $onStatus;
        $this->state = self::STATE_JOINING;

        if (!$this->client->isConnected()) {
            $this->client->connect();
        }

        $broadcastOpts = $this->options['broadcast'] ?? [];
        $presenceOpts = $this->options['presence'] ?? [];

        $config = [
            'broadcast' => [
                'ack' => (bool) ($broadcastOpts['ack'] ?? false),
                'self' => (bool) ($broadcastOpts['self'] ?? false),
            ],
            'presence' => [
                'key' => (string) ($presenceOpts['key'] ?? ''),
            ],
            'postgres_changes' => $this->postgresChanges,
        ];

        /** @var array<string, mixed> $payload */
        $payload = ['config' => $config];
        if ($this->accessToken !== null) {
            $payload['access_token'] = $this->accessToken;
        }

        $this->client->push([
            'topic' => $this->topic,
            'event' => 'phx_join',
            'payload' => $payload,
            'ref' => $this->client->nextRef(),
        ]);

        return $this;
    }

    /**
     * Unsubscribe (leave) the channel.
     */
    public function unsubscribe(): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $this->state = self::STATE_LEAVING;

        if ($this->client->isConnected()) {
            try {
                $this->client->push([
                    'topic' => $this->topic,
                    'event' => 'phx_leave',
                    'payload' => (object) [],
                    'ref' => $this->client->nextRef(),
                ]);
            } catch (\Throwable) {
                // Ignore send errors during leave
            }
        }

        $this->state = self::STATE_CLOSED;
    }

    /**
     * Send a broadcast message.
     *
     * @param array<string, mixed> $message Must include 'event' and 'payload' keys
     */
    public function send(array $message): self
    {
        $this->requireJoined();

        $this->client->push([
            'topic' => $this->topic,
            'event' => 'broadcast',
            'payload' => $message,
            'ref' => $this->client->nextRef(),
        ]);

        return $this;
    }

    /**
     * Track presence for this channel.
     *
     * @param array<string, mixed> $payload User-defined presence data
     */
    public function track(array $payload): self
    {
        $this->requireJoined();

        $this->client->push([
            'topic' => $this->topic,
            'event' => 'presence',
            'payload' => [
                'type' => 'presence',
                'event' => 'track',
                'payload' => $payload,
            ],
            'ref' => $this->client->nextRef(),
        ]);

        return $this;
    }

    /**
     * Stop tracking presence for this channel.
     */
    public function untrack(): self
    {
        $this->requireJoined();

        $this->client->push([
            'topic' => $this->topic,
            'event' => 'presence',
            'payload' => [
                'type' => 'presence',
                'event' => 'untrack',
                'payload' => (object) [],
            ],
            'ref' => $this->client->nextRef(),
        ]);

        return $this;
    }

    /**
     * Get the current presence state.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function presenceState(): array
    {
        return $this->presence->state();
    }

    /**
     * @internal Called by RealtimeClient when a message arrives for this channel.
     *
     * @param array<string, mixed> $payload
     */
    public function handleMessage(string $event, array $payload, ?string $ref): void
    {
        switch ($event) {
            case 'phx_reply':
                $this->handleReply($payload);
                break;
            case 'phx_error':
                $this->state = self::STATE_CLOSED;
                $this->notifyStatus('CHANNEL_ERROR');
                break;
            case 'phx_close':
                $this->state = self::STATE_CLOSED;
                $this->notifyStatus('CLOSED');
                break;
            case 'postgres_changes':
                $this->dispatchPostgresChanges($payload);
                break;
            case 'broadcast':
                $this->dispatchBroadcast($payload);
                break;
            case 'presence_state':
                $this->presence->syncState($payload);
                $this->dispatchPresence('sync', $payload);
                break;
            case 'presence_diff':
                $this->presence->syncDiff($payload);
                $this->dispatchPresence('sync', $payload);
                break;
            case 'system':
                $this->dispatchSystem($payload);
                break;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleReply(array $payload): void
    {
        $status = (string) ($payload['status'] ?? '');

        if ($this->state === self::STATE_JOINING) {
            if ($status === 'ok') {
                $this->state = self::STATE_JOINED;
                $this->notifyStatus('SUBSCRIBED');
            } else {
                $this->state = self::STATE_CLOSED;
                $this->notifyStatus('CHANNEL_ERROR');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchPostgresChanges(array $payload): void
    {
        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? $payload;
        $changeEvent = (string) ($data['type'] ?? '*');
        $table = (string) ($data['table'] ?? '');
        $schema = (string) ($data['schema'] ?? '');

        foreach ($this->listeners[self::POSTGRES_CHANGES] ?? [] as $listener) {
            $filter = $listener['filter'];
            $filterEvent = (string) ($filter['event'] ?? '*');
            $filterTable = (string) ($filter['table'] ?? '*');
            $filterSchema = (string) ($filter['schema'] ?? 'public');

            if (($filterEvent === '*' || strtoupper($filterEvent) === strtoupper($changeEvent))
                && ($filterTable === '*' || $filterTable === $table)
                && ($filterSchema === '*' || $filterSchema === $schema)) {
                ($listener['callback'])($data);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchBroadcast(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');

        foreach ($this->listeners[self::BROADCAST] ?? [] as $listener) {
            $filterEvent = (string) ($listener['filter']['event'] ?? '*');

            if ($filterEvent === '*' || $filterEvent === $event) {
                ($listener['callback'])($payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchPresence(string $presenceEvent, array $payload): void
    {
        foreach ($this->listeners[self::PRESENCE] ?? [] as $listener) {
            $filterEvent = (string) ($listener['filter']['event'] ?? '*');

            if ($filterEvent === '*' || $filterEvent === $presenceEvent || $filterEvent === 'sync') {
                ($listener['callback'])($payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchSystem(array $payload): void
    {
        foreach ($this->listeners[self::SYSTEM] ?? [] as $listener) {
            ($listener['callback'])($payload);
        }
    }

    private function notifyStatus(string $status): void
    {
        if ($this->subscribeCallback !== null) {
            ($this->subscribeCallback)($status);
        }
    }

    private function requireJoined(): void
    {
        if ($this->state !== self::STATE_JOINED) {
            throw new SupabaseRealtimeException(
                'Channel not subscribed. Call subscribe() first.',
                400,
            );
        }
    }
}
