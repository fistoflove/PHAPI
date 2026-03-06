<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;
use PHAPI\Supabase\Realtime\RealtimeChannel;
use PHAPI\Supabase\Realtime\RealtimeClient;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class RealtimeClientTest extends TestCase
{
    private FakeRealtimeSocket $socket;
    private SupabaseConfig $config;
    private RealtimeClient $client;

    protected function setUp(): void
    {
        $this->socket = new FakeRealtimeSocket();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
            'service_role_key' => 'service-role-key',
        ]);
        $this->client = new RealtimeClient($this->config, 'test-token', $this->socket);
    }

    public function testChannelReturnsRealtimeChannel(): void
    {
        $channel = $this->client->channel('my-room');

        $this->assertInstanceOf(RealtimeChannel::class, $channel);
        $this->assertSame('realtime:my-room', $channel->topic());
    }

    public function testChannelReturnsSameInstanceForSameName(): void
    {
        $channel1 = $this->client->channel('room');
        $channel2 = $this->client->channel('room');

        $this->assertSame($channel1, $channel2);
    }

    public function testChannelReturnsDifferentInstancesForDifferentNames(): void
    {
        $channel1 = $this->client->channel('room-a');
        $channel2 = $this->client->channel('room-b');

        $this->assertNotSame($channel1, $channel2);
    }

    public function testConnectOpensSocket(): void
    {
        $this->assertFalse($this->client->isConnected());

        $this->client->connect();

        $this->assertTrue($this->client->isConnected());
        $this->assertTrue($this->socket->connected);
        $this->assertSame('test.supabase.co', $this->socket->connectHost);
        $this->assertSame(443, $this->socket->connectPort);
        $this->assertTrue($this->socket->connectSsl);
        $this->assertStringContainsString('/realtime/v1/websocket', $this->socket->connectPath);
        $this->assertStringContainsString('apikey=anon-key', $this->socket->connectPath);
        $this->assertStringContainsString('vsn=1.0.0', $this->socket->connectPath);
    }

    public function testConnectIsIdempotent(): void
    {
        $this->client->connect();
        $this->client->connect();

        // connect() on socket should only be called once
        $this->assertTrue($this->socket->connected);
    }

    public function testConnectWithHttpUrl(): void
    {
        $config = new SupabaseConfig([
            'url' => 'http://localhost:54321',
            'anon_key' => 'local-key',
        ]);
        $client = new RealtimeClient($config, null, $this->socket);

        $client->connect();

        $this->assertSame('localhost', $this->socket->connectHost);
        $this->assertSame(54321, $this->socket->connectPort);
        $this->assertFalse($this->socket->connectSsl);
    }

    public function testDisconnectClosesSocket(): void
    {
        $this->client->connect();
        $this->assertTrue($this->client->isConnected());

        $this->client->disconnect();

        $this->assertFalse($this->client->isConnected());
        $this->assertFalse($this->socket->connected);
    }

    public function testDisconnectUnsubscribesChannels(): void
    {
        $this->client->connect();
        $channel = $this->client->channel('test');
        $channel->subscribe();
        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        $this->assertSame(RealtimeChannel::STATE_JOINED, $channel->state());

        $this->client->disconnect();

        $this->assertSame(RealtimeChannel::STATE_CLOSED, $channel->state());
    }

    public function testPushSendsJsonToSocket(): void
    {
        $this->client->connect();

        $this->client->push([
            'topic' => 'realtime:test',
            'event' => 'broadcast',
            'payload' => ['event' => 'msg', 'payload' => ['text' => 'hello']],
            'ref' => '1',
        ]);

        $sent = $this->socket->lastSent();
        $this->assertNotNull($sent);
        $this->assertSame('realtime:test', $sent['topic']);
        $this->assertSame('broadcast', $sent['event']);
    }

    public function testPushThrowsWhenNotConnected(): void
    {
        $this->expectException(SupabaseRealtimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->client->push(['topic' => 'test', 'event' => 'test', 'payload' => []]);
    }

    public function testNextRefIncrementsSequentially(): void
    {
        $ref1 = $this->client->nextRef();
        $ref2 = $this->client->nextRef();
        $ref3 = $this->client->nextRef();

        $this->assertSame('1', $ref1);
        $this->assertSame('2', $ref2);
        $this->assertSame('3', $ref3);
    }

    public function testHandleMessageDispatchesToChannel(): void
    {
        $this->client->connect();
        $received = null;

        $channel = $this->client->channel('test');
        $channel->on('broadcast', ['event' => 'msg'], function (array $payload) use (&$received): void {
            $received = $payload;
        });
        $channel->subscribe();
        $channel->handleMessage('phx_reply', ['status' => 'ok'], '1');

        $this->client->handleMessage([
            'topic' => 'realtime:test',
            'event' => 'broadcast',
            'payload' => ['event' => 'msg', 'payload' => ['text' => 'hello']],
            'ref' => null,
        ]);

        $this->assertNotNull($received);
        $this->assertSame('msg', $received['event']);
    }

    public function testHandleMessageIgnoresUnknownTopic(): void
    {
        // Should not throw
        $this->client->handleMessage([
            'topic' => 'realtime:unknown',
            'event' => 'broadcast',
            'payload' => [],
            'ref' => null,
        ]);

        $this->assertTrue(true);
    }

    public function testHandleHeartbeatReplyIsIgnored(): void
    {
        // Should not throw or dispatch to any channel
        $this->client->handleMessage([
            'topic' => 'phoenix',
            'event' => 'phx_reply',
            'payload' => ['status' => 'ok'],
            'ref' => '1',
        ]);

        $this->assertTrue(true);
    }

    public function testRemoveChannel(): void
    {
        $channel = $this->client->channel('room');

        $this->assertCount(1, $this->client->getChannels());

        $this->client->removeChannel($channel);

        $this->assertCount(0, $this->client->getChannels());
    }

    public function testRemoveAllChannels(): void
    {
        $this->client->channel('room-a');
        $this->client->channel('room-b');
        $this->client->channel('room-c');

        $this->assertCount(3, $this->client->getChannels());

        $this->client->removeAllChannels();

        $this->assertCount(0, $this->client->getChannels());
    }

    public function testGetChannelsReturnsAllChannels(): void
    {
        $this->client->channel('alpha');
        $this->client->channel('beta');

        $channels = $this->client->getChannels();

        $this->assertCount(2, $channels);
        $this->assertArrayHasKey('realtime:alpha', $channels);
        $this->assertArrayHasKey('realtime:beta', $channels);
    }

    public function testConnectWithInvalidUrlThrows(): void
    {
        $config = new SupabaseConfig([
            'url' => '://invalid',
            'anon_key' => 'key',
        ]);
        $client = new RealtimeClient($config, null, $this->socket);

        $this->expectException(SupabaseRealtimeException::class);
        $this->expectExceptionMessage('Invalid Supabase URL');
        $client->connect();
    }
}
