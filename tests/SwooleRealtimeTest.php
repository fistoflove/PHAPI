<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Services\SwooleRealtime;
use PHPUnit\Framework\TestCase;

/**
 * Tests SwooleRealtime broadcast with mock WebSocket server.
 */
final class SwooleRealtimeTest extends TestCase
{
    public function testBroadcastToSubscribedConnections(): void
    {
        $server = $this->createMock(\Swoole\WebSocket\Server::class);
        $connections = [
            1 => ['channels' => ['chat' => true, 'news' => false]],
            2 => ['channels' => ['chat' => true]],
            3 => ['channels' => ['news' => true]],
        ];

        // Only fd 1 and 2 are subscribed to 'chat'
        $pushed = [];
        $server->expects($this->exactly(2))
            ->method('push')
            ->willReturnCallback(function (int $fd, string $data) use (&$pushed): bool {
                $pushed[] = $fd;
                return true;
            });

        $rt = new SwooleRealtime($server, $connections);
        $rt->broadcast('chat', ['text' => 'hello']);

        $this->assertSame([1, 2], $pushed);
    }

    public function testBroadcastEmptyChannelSendsToAll(): void
    {
        $server = $this->createMock(\Swoole\WebSocket\Server::class);
        $connections = [
            10 => ['channels' => ['a' => true]],
            20 => ['channels' => []],
        ];

        $pushed = [];
        $server->expects($this->exactly(2))
            ->method('push')
            ->willReturnCallback(function (int $fd, string $data) use (&$pushed): bool {
                $pushed[] = $fd;
                return true;
            });

        $rt = new SwooleRealtime($server, $connections);
        $rt->broadcast('', ['type' => 'global']);

        $this->assertSame([10, 20], $pushed);
    }

    public function testBroadcastNoSubscribersDoesNotPush(): void
    {
        $server = $this->createMock(\Swoole\WebSocket\Server::class);
        $connections = [
            1 => ['channels' => ['other' => true]],
        ];

        $server->expects($this->never())->method('push');

        $rt = new SwooleRealtime($server, $connections);
        $rt->broadcast('chat', ['text' => 'nobody here']);
    }

    public function testBroadcastPayloadIsValidJson(): void
    {
        $server = $this->createMock(\Swoole\WebSocket\Server::class);
        $connections = [
            1 => ['channels' => ['test' => true]],
        ];

        $capturedPayload = null;
        $server->expects($this->once())
            ->method('push')
            ->willReturnCallback(function (int $fd, string $data) use (&$capturedPayload): bool {
                $capturedPayload = $data;
                return true;
            });

        $rt = new SwooleRealtime($server, $connections);
        $rt->broadcast('test', ['key' => 'value']);

        $decoded = json_decode($capturedPayload, true);
        $this->assertSame('test', $decoded['channel']);
        $this->assertSame(['key' => 'value'], $decoded['message']);
    }

    public function testBroadcastWithEmptyConnections(): void
    {
        $server = $this->createMock(\Swoole\WebSocket\Server::class);
        $connections = [];

        $server->expects($this->never())->method('push');

        $rt = new SwooleRealtime($server, $connections);
        $rt->broadcast('chat', ['text' => 'empty']);
    }
}
