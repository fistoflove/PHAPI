<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Contracts\WebSocketDriverInterface;
use PHAPI\PHAPI;
use PHAPI\Services\WebSocketConnection;
use PHAPI\Services\WebSocketMessage;

final class WebSocketAbstractionTest extends SwooleTestCase
{
    public function testWebSocketConnectionDelegatesToDriver(): void
    {
        $driver = new TestWebSocketDriver();
        $connection = new WebSocketConnection($driver, 42);

        $this->assertSame(42, $connection->fd());
        $this->assertTrue($connection->isEstablished());
        $this->assertTrue($connection->send(['ok' => true]));
        $this->assertTrue($connection->send('raw'));
        $connection->subscribe('deployments');
        $connection->unsubscribe('deployments');
        $this->assertTrue($connection->disconnect(1000, 'done'));

        $this->assertSame(
            [
                ['fd' => 42, 'payload' => '{"ok":true}'],
                ['fd' => 42, 'payload' => 'raw'],
            ],
            $driver->sent
        );
        $this->assertSame([['fd' => 42, 'channel' => 'deployments']], $driver->subscribed);
        $this->assertSame([['fd' => 42, 'channel' => 'deployments']], $driver->unsubscribed);
        $this->assertSame([['fd' => 42, 'code' => 1000, 'reason' => 'done']], $driver->disconnected);
    }

    public function testOnWebSocketMessageProvidesAbstractMessageAndConnection(): void
    {
        $api = new PHAPI([
            'enable_websockets' => true,
            'default_endpoints' => false,
            'debug' => false,
        ]);

        $driver = new TestWebSocketDriver();
        $captured = [];

        $api->onWebSocketMessage(function (WebSocketMessage $message, WebSocketConnection $connection) use (&$captured): void {
            $captured['fd'] = $message->fd();
            $captured['data'] = $message->data();
            $captured['json'] = $message->json();
            $captured['isEstablished'] = $connection->isEstablished();
            $connection->subscribe('heartbeat');
            $connection->send(['event' => 'subscribed']);
        });

        $property = new \ReflectionProperty(PHAPI::class, 'webSocketHandler');
        $property->setAccessible(true);
        $handler = $property->getValue($api);

        $this->assertIsCallable($handler);

        $frame = (object) [
            'fd' => 77,
            'data' => '{"action":"subscribe","channel":"heartbeat"}',
        ];
        $handler(new \stdClass(), $frame, $driver);

        $this->assertSame(77, $captured['fd'] ?? null);
        $this->assertSame('{"action":"subscribe","channel":"heartbeat"}', $captured['data'] ?? null);
        $this->assertSame('subscribe', $captured['json']['action'] ?? null);
        $this->assertTrue((bool) ($captured['isEstablished'] ?? false));
        $this->assertSame([['fd' => 77, 'channel' => 'heartbeat']], $driver->subscribed);
        $this->assertSame(
            [['fd' => 77, 'payload' => '{"event":"subscribed"}']],
            $driver->sent
        );
    }
}

final class TestWebSocketDriver implements WebSocketDriverInterface
{
    /** @var array<int, array{fd: int, payload: string}> */
    public array $sent = [];
    /** @var array<int, array{fd: int, channel: string}> */
    public array $subscribed = [];
    /** @var array<int, array{fd: int, channel: string}> */
    public array $unsubscribed = [];
    /** @var array<int, array{fd: int, code: int, reason: string}> */
    public array $disconnected = [];

    public function send(int $fd, string $payload): bool
    {
        $this->sent[] = ['fd' => $fd, 'payload' => $payload];
        return true;
    }

    public function subscribe(int $fd, string $channel): void
    {
        $this->subscribed[] = ['fd' => $fd, 'channel' => $channel];
    }

    public function unsubscribe(int $fd, string $channel): void
    {
        $this->unsubscribed[] = ['fd' => $fd, 'channel' => $channel];
    }

    public function isConnectionEstablished(int $fd): bool
    {
        return $fd > 0;
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        $this->disconnected[] = [
            'fd' => $fd,
            'code' => $code,
            'reason' => $reason,
        ];
        return true;
    }

    public function connections(): array
    {
        return [];
    }
}

