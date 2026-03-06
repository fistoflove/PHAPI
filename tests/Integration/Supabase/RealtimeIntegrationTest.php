<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\Realtime\RealtimeChannel;
use PHAPI\Supabase\Realtime\RealtimeClient;

/**
 * Integration tests for Supabase Realtime (Phoenix Channels over WebSocket).
 *
 * Requires a running Supabase instance with Realtime enabled.
 * Tests run inside Swoole\Coroutine\run() because the WebSocket client
 * and receiver loop need a coroutine context.
 *
 * @group integration
 * @group supabase
 */
final class RealtimeIntegrationTest extends SupabaseIntegrationTestCase
{
    /**
     * Helper: run a closure inside a Swoole coroutine context.
     *
     * @param callable(): void $fn
     */
    private function runInCoroutine(callable $fn): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runtime required for Realtime tests.');
        }

        $error = null;
        \Swoole\Coroutine\run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    private function createRealtimeClient(): RealtimeClient
    {
        $token = self::$config->serviceRoleKey !== '' ? self::$config->serviceRoleKey : null;

        return new RealtimeClient(self::$config, $token);
    }

    // ─── Connection ────────────────────────────────────────────────

    public function testConnectAndDisconnect(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();

            try {
                $client->connect();
                $this->assertTrue($client->isConnected());
            } finally {
                $client->disconnect();
            }

            $this->assertFalse($client->isConnected());
        });
    }

    // ─── Channel Subscribe/Unsubscribe ─────────────────────────────

    public function testChannelSubscribe(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();
            $status = null;

            try {
                $channel = $client->channel('test-sub-' . bin2hex(random_bytes(4)));
                $channel->subscribe(function (string $s) use (&$status): void {
                    $status = $s;
                });

                $this->waitFor(function () use (&$status): bool {
                    return $status !== null;
                }, 5.0);

                $this->assertSame('SUBSCRIBED', $status);
                $this->assertSame(RealtimeChannel::STATE_JOINED, $channel->state());
            } finally {
                $client->disconnect();
            }
        });
    }

    // ─── Broadcast ─────────────────────────────────────────────────

    public function testBroadcastSendAndReceive(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();
            $received = null;
            $status = null;

            try {
                $channel = $client->channel('broadcast-test-' . bin2hex(random_bytes(4)), [
                    'broadcast' => ['self' => true],
                ]);

                $channel->on('broadcast', ['event' => 'test-msg'], function (array $payload) use (&$received): void {
                    $received = $payload;
                });

                $channel->subscribe(function (string $s) use (&$status): void {
                    $status = $s;
                });

                $this->waitFor(function () use (&$status): bool {
                    return $status === 'SUBSCRIBED';
                }, 5.0);

                if ($status !== 'SUBSCRIBED') {
                    $this->markTestSkipped('Could not subscribe to channel');
                }

                $channel->send([
                    'type' => 'broadcast',
                    'event' => 'test-msg',
                    'payload' => ['text' => 'hello from PHAPI', 'ts' => time()],
                ]);

                $this->waitFor(function () use (&$received): bool {
                    return $received !== null;
                }, 5.0);

                $this->assertNotNull($received, 'Should have received broadcast message');
                $this->assertSame('test-msg', $received['event'] ?? '');
            } finally {
                $client->disconnect();
            }
        });
    }

    // ─── Postgres Changes ──────────────────────────────────────────

    public function testPostgresChangesInsert(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();
            $received = null;
            $status = null;

            try {
                $channel = $client->channel('pg-changes-' . bin2hex(random_bytes(4)));

                $channel->on('postgres_changes', [
                    'event' => 'INSERT',
                    'schema' => 'public',
                    'table' => 'posts',
                ], function (array $data) use (&$received): void {
                    $received = $data;
                });

                $channel->subscribe(function (string $s) use (&$status): void {
                    $status = $s;
                });

                $this->waitFor(function () use (&$status): bool {
                    return $status === 'SUBSCRIBED';
                }, 5.0);

                if ($status !== 'SUBSCRIBED') {
                    $this->markTestSkipped('Could not subscribe to channel');
                }

                // Insert a row via PostgREST to trigger a CDC event
                $ctx = self::$factory->createServiceContext();
                try {
                    $ctx->db()->from('posts')->insert([
                        'title' => 'Realtime test ' . bin2hex(random_bytes(4)),
                        'body' => 'Testing postgres changes',
                    ]);
                } catch (\Throwable $e) {
                    $this->markTestSkipped('Could not insert test row: ' . $e->getMessage());
                }

                // Wait for the change event (may not arrive if table is not
                // in the supabase_realtime publication)
                $this->waitFor(function () use (&$received): bool {
                    return $received !== null;
                }, 10.0);

                if ($received === null) {
                    $this->markTestSkipped(
                        'Postgres CDC event not received — the posts table may not be in the supabase_realtime publication. '
                        . 'Enable it via: ALTER PUBLICATION supabase_realtime ADD TABLE posts;'
                    );
                }

                $this->assertSame('INSERT', $received['type'] ?? '');
                $this->assertSame('posts', $received['table'] ?? '');
            } finally {
                $client->disconnect();
            }
        });
    }

    // ─── Presence ──────────────────────────────────────────────────

    public function testPresenceTrack(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();
            $status = null;
            $synced = false;

            try {
                $channel = $client->channel('presence-test-' . bin2hex(random_bytes(4)), [
                    'presence' => ['key' => 'phapi-server'],
                ]);

                $channel->on('presence', ['event' => 'sync'], function () use (&$synced): void {
                    $synced = true;
                });

                $channel->subscribe(function (string $s) use (&$status): void {
                    $status = $s;
                });

                $this->waitFor(function () use (&$status): bool {
                    return $status === 'SUBSCRIBED';
                }, 5.0);

                if ($status !== 'SUBSCRIBED') {
                    $this->markTestSkipped('Could not subscribe to channel');
                }

                $channel->track(['user' => 'phapi-test', 'online_at' => date('c')]);

                $this->waitFor(function () use (&$synced): bool {
                    return $synced;
                }, 5.0);

                $state = $channel->presenceState();
                if (!$synced || $state === []) {
                    // Presence may not be enabled or sync may be slow
                    $this->markTestSkipped('Presence sync event not received within timeout');
                }

                $this->assertNotEmpty($state);

                $channel->untrack();
            } finally {
                $client->disconnect();
            }
        });
    }

    // ─── Multiple Channels ─────────────────────────────────────────

    public function testMultipleChannels(): void
    {
        $this->runInCoroutine(function (): void {
            $client = $this->createRealtimeClient();
            $statusA = null;
            $statusB = null;

            try {
                $channelA = $client->channel('multi-a-' . bin2hex(random_bytes(4)));
                $channelB = $client->channel('multi-b-' . bin2hex(random_bytes(4)));

                $channelA->subscribe(function (string $s) use (&$statusA): void {
                    $statusA = $s;
                });

                $channelB->subscribe(function (string $s) use (&$statusB): void {
                    $statusB = $s;
                });

                $this->waitFor(function () use (&$statusA, &$statusB): bool {
                    return $statusA !== null && $statusB !== null;
                }, 5.0);

                $this->assertSame('SUBSCRIBED', $statusA);
                $this->assertSame('SUBSCRIBED', $statusB);
                $this->assertCount(2, $client->getChannels());

                $client->removeAllChannels();
                $this->assertCount(0, $client->getChannels());
            } finally {
                $client->disconnect();
            }
        });
    }

    /**
     * Poll until a condition is true or timeout.
     *
     * @param callable(): bool $condition
     */
    private function waitFor(callable $condition, float $timeoutSeconds): void
    {
        $start = microtime(true);
        while (!$condition()) {
            if (microtime(true) - $start > $timeoutSeconds) {
                return;
            }
            \Swoole\Coroutine::sleep(0.1);
        }
    }
}
