<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;
use PHAPI\Supabase\Realtime\RealtimeChannel;
use PHAPI\Supabase\Realtime\RealtimeClient;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class RealtimeChannelTest extends TestCase
{
    private FakeRealtimeSocket $socket;
    private RealtimeClient $client;

    protected function setUp(): void
    {
        $this->socket = new FakeRealtimeSocket();
        $config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
        ]);
        $this->client = new RealtimeClient($config, 'jwt-token', $this->socket);
    }

    private function createJoinedChannel(string $name = 'test', array $options = []): RealtimeChannel
    {
        $channel = $this->client->channel($name, $options);
        $channel->subscribe();
        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        return $channel;
    }

    // ─── State ──────────────────────────────────────────────────────

    public function testInitialStateIsClosed(): void
    {
        $channel = $this->client->channel('test');

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
    }

    public function testSubscribeChangesStateToJoining(): void
    {
        $channel = $this->client->channel('test');
        $channel->subscribe();

        $this->assertSame(RealtimeChannel::STATE_JOINING, $channel->state());
    }

    public function testJoinReplyOkChangesStateToJoined(): void
    {
        $channel = $this->createJoinedChannel();

        $this->assertSame(RealtimeChannel::STATE_JOINED, $channel->state());
    }

    public function testJoinReplyErrorChangesStateToClosed(): void
    {
        $channel = $this->client->channel('test');
        $channel->subscribe();
        $channel->handleMessage('phx_reply', ['status' => 'error'], '1');

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
    }

    public function testUnsubscribeChangesStateToClosed(): void
    {
        $channel = $this->createJoinedChannel();

        $channel->unsubscribe();

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
    }

    public function testSubscribeIsIdempotentWhenNotClosed(): void
    {
        $channel = $this->client->channel('test');
        $channel->subscribe();
        $sentCount = count($this->socket->sent);

        $channel->subscribe(); // Should be a no-op

        $this->assertCount($sentCount, $this->socket->sent);
    }

    public function testUnsubscribeIsIdempotentWhenClosed(): void
    {
        $channel = $this->client->channel('test');
        $sentBefore = count($this->socket->sent);

        $channel->unsubscribe();

        $this->assertCount($sentBefore, $this->socket->sent);
    }

    // ─── Subscribe (join) message ──────────────────────────────────

    public function testSubscribeSendsJoinMessage(): void
    {
        $channel = $this->client->channel('my-room');
        $channel->subscribe();

        $sent = $this->socket->lastSent();

        $this->assertNotNull($sent);
        $this->assertSame('realtime:my-room', $sent['topic']);
        $this->assertSame('phx_join', $sent['event']);
        $this->assertSame('jwt-token', $sent['payload']['access_token']);
        $this->assertArrayHasKey('config', $sent['payload']);
    }

    public function testSubscribeAutoConnects(): void
    {
        $this->assertFalse($this->client->isConnected());

        $this->client->channel('test')->subscribe();

        $this->assertTrue($this->client->isConnected());
    }

    public function testSubscribeBuildsPostgresChangesConfig(): void
    {
        $channel = $this->client->channel('db-changes');
        $channel->on('postgres_changes', [
            'event' => 'INSERT',
            'schema' => 'public',
            'table' => 'posts',
        ], function (): void {});
        $channel->on('postgres_changes', [
            'event' => '*',
            'table' => 'comments',
            'filter' => 'post_id=eq.1',
        ], function (): void {});

        $channel->subscribe();

        $sent = $this->socket->lastSent();
        $pgChanges = $sent['payload']['config']['postgres_changes'] ?? [];

        $this->assertCount(2, $pgChanges);
        $this->assertSame('INSERT', $pgChanges[0]['event']);
        $this->assertSame('posts', $pgChanges[0]['table']);
        $this->assertSame('*', $pgChanges[1]['event']);
        $this->assertSame('comments', $pgChanges[1]['table']);
        $this->assertSame('post_id=eq.1', $pgChanges[1]['filter']);
    }

    public function testSubscribeSendsBroadcastOptions(): void
    {
        $channel = $this->client->channel('room', [
            'broadcast' => ['ack' => true, 'self' => true],
        ]);
        $channel->subscribe();

        $sent = $this->socket->lastSent();
        $broadcast = $sent['payload']['config']['broadcast'] ?? [];

        $this->assertTrue($broadcast['ack']);
        $this->assertTrue($broadcast['self']);
    }

    public function testSubscribeSendsPresenceKey(): void
    {
        $channel = $this->client->channel('room', [
            'presence' => ['key' => 'user-123'],
        ]);
        $channel->subscribe();

        $sent = $this->socket->lastSent();
        $presence = $sent['payload']['config']['presence'] ?? [];

        $this->assertSame('user-123', $presence['key']);
    }

    public function testSubscribeCallbackReceivesStatus(): void
    {
        $statuses = [];
        $channel = $this->client->channel('test');
        $channel->subscribe(function (string $status) use (&$statuses): void {
            $statuses[] = $status;
        });

        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        $this->assertSame(['SUBSCRIBED'], $statuses);
    }

    public function testSubscribeCallbackReceivesErrorStatus(): void
    {
        $statuses = [];
        $channel = $this->client->channel('test');
        $channel->subscribe(function (string $status) use (&$statuses): void {
            $statuses[] = $status;
        });

        $channel->handleMessage('phx_reply', ['status' => 'error'], '1');

        $this->assertSame(['CHANNEL_ERROR'], $statuses);
    }

    // ─── Unsubscribe (leave) message ───────────────────────────────

    public function testUnsubscribeSendsLeaveMessage(): void
    {
        $channel = $this->createJoinedChannel();

        $channel->unsubscribe();

        $sent = $this->socket->lastSent();
        $this->assertNotNull($sent);
        $this->assertSame('realtime:test', $sent['topic']);
        $this->assertSame('phx_leave', $sent['event']);
    }

    // ─── Broadcast ─────────────────────────────────────────────────

    public function testSendBroadcast(): void
    {
        $channel = $this->createJoinedChannel();

        $channel->send([
            'event' => 'cursor-pos',
            'payload' => ['x' => 100, 'y' => 200],
        ]);

        $sent = $this->socket->lastSent();
        $this->assertNotNull($sent);
        $this->assertSame('realtime:test', $sent['topic']);
        $this->assertSame('broadcast', $sent['event']);
        $this->assertSame('cursor-pos', $sent['payload']['event']);
        $this->assertSame(100, $sent['payload']['payload']['x']);
    }

    public function testSendRequiresJoinedState(): void
    {
        $channel = $this->client->channel('test');

        $this->expectException(SupabaseRealtimeException::class);
        $this->expectExceptionMessage('not subscribed');
        $channel->send(['event' => 'test', 'payload' => []]);
    }

    public function testReceiveBroadcast(): void
    {
        $received = null;
        $channel = $this->createJoinedChannel();
        $channel->on('broadcast', ['event' => 'msg'], function (array $payload) use (&$received): void {
            $received = $payload;
        });

        $channel->handleMessage('broadcast', [
            'event' => 'msg',
            'payload' => ['text' => 'hello'],
        ], null);

        $this->assertNotNull($received);
        $this->assertSame('msg', $received['event']);
        $this->assertSame('hello', $received['payload']['text']);
    }

    public function testBroadcastWildcardListener(): void
    {
        $received = null;
        $channel = $this->createJoinedChannel();
        $channel->on('broadcast', ['event' => '*'], function (array $payload) use (&$received): void {
            $received = $payload;
        });

        $channel->handleMessage('broadcast', [
            'event' => 'any-event',
            'payload' => ['data' => true],
        ], null);

        $this->assertNotNull($received);
        $this->assertSame('any-event', $received['event']);
    }

    public function testBroadcastFilterMismatchIsIgnored(): void
    {
        $called = false;
        $channel = $this->createJoinedChannel();
        $channel->on('broadcast', ['event' => 'specific'], function () use (&$called): void {
            $called = true;
        });

        $channel->handleMessage('broadcast', [
            'event' => 'other-event',
            'payload' => [],
        ], null);

        $this->assertFalse($called);
    }

    // ─── Postgres Changes ──────────────────────────────────────────

    public function testReceivePostgresInsert(): void
    {
        $received = null;
        $channel = $this->createJoinedChannel();
        $channel->on('postgres_changes', [
            'event' => 'INSERT',
            'schema' => 'public',
            'table' => 'posts',
        ], function (array $data) use (&$received): void {
            $received = $data;
        });

        $channel->handleMessage('postgres_changes', [
            'data' => [
                'type' => 'INSERT',
                'table' => 'posts',
                'schema' => 'public',
                'record' => ['id' => 1, 'title' => 'Hello'],
                'old_record' => null,
            ],
        ], null);

        $this->assertNotNull($received);
        $this->assertSame('INSERT', $received['type']);
        $this->assertSame('Hello', $received['record']['title']);
    }

    public function testPostgresChangesWildcardEvent(): void
    {
        $received = [];
        $channel = $this->createJoinedChannel();
        $channel->on('postgres_changes', [
            'event' => '*',
            'table' => 'posts',
        ], function (array $data) use (&$received): void {
            $received[] = $data;
        });

        $channel->handleMessage('postgres_changes', [
            'data' => ['type' => 'INSERT', 'table' => 'posts', 'schema' => 'public'],
        ], null);
        $channel->handleMessage('postgres_changes', [
            'data' => ['type' => 'UPDATE', 'table' => 'posts', 'schema' => 'public'],
        ], null);
        $channel->handleMessage('postgres_changes', [
            'data' => ['type' => 'DELETE', 'table' => 'posts', 'schema' => 'public'],
        ], null);

        $this->assertCount(3, $received);
    }

    public function testPostgresChangesTableMismatchIsIgnored(): void
    {
        $called = false;
        $channel = $this->createJoinedChannel();
        $channel->on('postgres_changes', [
            'event' => '*',
            'table' => 'posts',
        ], function () use (&$called): void {
            $called = true;
        });

        $channel->handleMessage('postgres_changes', [
            'data' => ['type' => 'INSERT', 'table' => 'comments', 'schema' => 'public'],
        ], null);

        $this->assertFalse($called);
    }

    // ─── Presence ──────────────────────────────────────────────────

    public function testTrackPresence(): void
    {
        $channel = $this->createJoinedChannel();

        $channel->track(['user' => 'alice', 'online_at' => '2024-01-01']);

        $sent = $this->socket->lastSent();
        $this->assertNotNull($sent);
        $this->assertSame('presence', $sent['event']);
        $this->assertSame('track', $sent['payload']['event']);
        $this->assertSame('alice', $sent['payload']['payload']['user']);
    }

    public function testTrackRequiresJoinedState(): void
    {
        $channel = $this->client->channel('test');

        $this->expectException(SupabaseRealtimeException::class);
        $channel->track(['user' => 'alice']);
    }

    public function testUntrackPresence(): void
    {
        $channel = $this->createJoinedChannel();

        $channel->untrack();

        $sent = $this->socket->lastSent();
        $this->assertNotNull($sent);
        $this->assertSame('presence', $sent['event']);
        $this->assertSame('untrack', $sent['payload']['event']);
    }

    public function testUntrackRequiresJoinedState(): void
    {
        $channel = $this->client->channel('test');

        $this->expectException(SupabaseRealtimeException::class);
        $channel->untrack();
    }

    public function testPresenceStateSync(): void
    {
        $synced = false;
        $channel = $this->createJoinedChannel();
        $channel->on('presence', ['event' => 'sync'], function () use (&$synced): void {
            $synced = true;
        });

        $channel->handleMessage('presence_state', [
            'user1' => ['metas' => [['phx_ref' => 'abc', 'online_at' => '2024-01-01']]],
            'user2' => ['metas' => [['phx_ref' => 'def', 'status' => 'active']]],
        ], null);

        $this->assertTrue($synced);
        $state = $channel->presenceState();
        $this->assertArrayHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
    }

    public function testPresenceDiffSync(): void
    {
        $channel = $this->createJoinedChannel();

        // Initial state
        $channel->handleMessage('presence_state', [
            'user1' => ['metas' => [['phx_ref' => 'abc']]],
        ], null);

        // Diff: user2 joins, user1 leaves
        $channel->handleMessage('presence_diff', [
            'joins' => [
                'user2' => ['metas' => [['phx_ref' => 'def']]],
            ],
            'leaves' => [
                'user1' => ['metas' => [['phx_ref' => 'abc']]],
            ],
        ], null);

        $state = $channel->presenceState();
        $this->assertArrayNotHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
    }

    // ─── System & Error Events ─────────────────────────────────────

    public function testPhxErrorClosesChannel(): void
    {
        $status = null;
        $channel = $this->client->channel('test');
        $channel->subscribe(function (string $s) use (&$status): void {
            $status = $s;
        });
        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        $channel->handleMessage('phx_error', [], null);

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
        $this->assertSame('CHANNEL_ERROR', $status);
    }

    public function testPhxCloseClosesChannel(): void
    {
        $status = null;
        $channel = $this->client->channel('test');
        $channel->subscribe(function (string $s) use (&$status): void {
            $status = $s;
        });
        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        $channel->handleMessage('phx_close', [], null);

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
        $this->assertSame('CLOSED', $status);
    }

    public function testSystemEventDispatches(): void
    {
        $received = null;
        $channel = $this->createJoinedChannel();
        $channel->on('system', [], function (array $payload) use (&$received): void {
            $received = $payload;
        });

        $channel->handleMessage('system', ['message' => 'token expired'], null);

        $this->assertNotNull($received);
        $this->assertSame('token expired', $received['message']);
    }

    // ─── on() fluent chaining ──────────────────────────────────────

    public function testOnReturnsSelf(): void
    {
        $channel = $this->client->channel('test');

        $result = $channel
            ->on('broadcast', ['event' => 'a'], function (): void {})
            ->on('broadcast', ['event' => 'b'], function (): void {})
            ->on('postgres_changes', ['event' => '*', 'table' => 'posts'], function (): void {});

        $this->assertSame($channel, $result);
    }

    public function testMultipleListenersForSameEvent(): void
    {
        $calls = 0;
        $channel = $this->createJoinedChannel();
        $channel->on('broadcast', ['event' => 'msg'], function () use (&$calls): void {
            $calls++;
        });
        $channel->on('broadcast', ['event' => 'msg'], function () use (&$calls): void {
            $calls++;
        });

        $channel->handleMessage('broadcast', ['event' => 'msg', 'payload' => []], null);

        $this->assertSame(2, $calls);
    }
}
