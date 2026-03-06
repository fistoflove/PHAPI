<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Realtime\RealtimePresence;
use PHPUnit\Framework\TestCase;

final class RealtimePresenceTest extends TestCase
{
    private RealtimePresence $presence;

    protected function setUp(): void
    {
        $this->presence = new RealtimePresence();
    }

    public function testInitialStateIsEmpty(): void
    {
        $this->assertSame([], $this->presence->state());
        $this->assertSame([], $this->presence->keys());
    }

    public function testSyncStateWithMetas(): void
    {
        $this->presence->syncState([
            'user1' => ['metas' => [['phx_ref' => 'abc', 'online_at' => '2024-01-01']]],
            'user2' => ['metas' => [['phx_ref' => 'def', 'status' => 'active']]],
        ]);

        $state = $this->presence->state();
        $this->assertCount(2, $state);
        $this->assertArrayHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
        $this->assertSame('abc', $state['user1'][0]['phx_ref']);
        $this->assertSame('active', $state['user2'][0]['status']);
    }

    public function testSyncStateReplacesExisting(): void
    {
        $this->presence->syncState([
            'user1' => ['metas' => [['phx_ref' => 'abc']]],
        ]);

        $this->presence->syncState([
            'user2' => ['metas' => [['phx_ref' => 'def']]],
        ]);

        $state = $this->presence->state();
        $this->assertCount(1, $state);
        $this->assertArrayNotHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
    }

    public function testSyncDiffJoins(): void
    {
        $this->presence->syncDiff([
            'joins' => [
                'user1' => ['metas' => [['phx_ref' => 'abc', 'name' => 'Alice']]],
                'user2' => ['metas' => [['phx_ref' => 'def', 'name' => 'Bob']]],
            ],
            'leaves' => [],
        ]);

        $state = $this->presence->state();
        $this->assertCount(2, $state);
        $this->assertSame('Alice', $state['user1'][0]['name']);
        $this->assertSame('Bob', $state['user2'][0]['name']);
    }

    public function testSyncDiffLeaves(): void
    {
        $this->presence->syncState([
            'user1' => ['metas' => [['phx_ref' => 'abc']]],
            'user2' => ['metas' => [['phx_ref' => 'def']]],
        ]);

        $this->presence->syncDiff([
            'joins' => [],
            'leaves' => [
                'user1' => ['metas' => [['phx_ref' => 'abc']]],
            ],
        ]);

        $state = $this->presence->state();
        $this->assertCount(1, $state);
        $this->assertArrayNotHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
    }

    public function testSyncDiffPartialLeave(): void
    {
        // User with multiple metas (e.g., multiple tabs)
        $this->presence->syncState([
            'user1' => ['metas' => [
                ['phx_ref' => 'tab1', 'device' => 'desktop'],
                ['phx_ref' => 'tab2', 'device' => 'mobile'],
            ]],
        ]);

        // Only one tab leaves
        $this->presence->syncDiff([
            'joins' => [],
            'leaves' => [
                'user1' => ['metas' => [['phx_ref' => 'tab1']]],
            ],
        ]);

        $state = $this->presence->state();
        $this->assertArrayHasKey('user1', $state);
        $this->assertCount(1, $state['user1']);
        $this->assertSame('tab2', $state['user1'][0]['phx_ref']);
        $this->assertSame('mobile', $state['user1'][0]['device']);
    }

    public function testSyncDiffJoinAndLeaveSimultaneously(): void
    {
        $this->presence->syncState([
            'user1' => ['metas' => [['phx_ref' => 'abc']]],
        ]);

        $this->presence->syncDiff([
            'joins' => [
                'user2' => ['metas' => [['phx_ref' => 'ghi']]],
            ],
            'leaves' => [
                'user1' => ['metas' => [['phx_ref' => 'abc']]],
            ],
        ]);

        $state = $this->presence->state();
        $this->assertCount(1, $state);
        $this->assertArrayNotHasKey('user1', $state);
        $this->assertArrayHasKey('user2', $state);
    }

    public function testSyncDiffJoinAddsToExistingMetas(): void
    {
        $this->presence->syncState([
            'user1' => ['metas' => [['phx_ref' => 'tab1']]],
        ]);

        $this->presence->syncDiff([
            'joins' => [
                'user1' => ['metas' => [['phx_ref' => 'tab2']]],
            ],
            'leaves' => [],
        ]);

        $state = $this->presence->state();
        $this->assertCount(2, $state['user1']);
    }

    public function testKeys(): void
    {
        $this->presence->syncState([
            'alice' => ['metas' => [['phx_ref' => 'a']]],
            'bob' => ['metas' => [['phx_ref' => 'b']]],
            'charlie' => ['metas' => [['phx_ref' => 'c']]],
        ]);

        $keys = $this->presence->keys();
        $this->assertCount(3, $keys);
        $this->assertContains('alice', $keys);
        $this->assertContains('bob', $keys);
        $this->assertContains('charlie', $keys);
    }

    public function testLeaveNonexistentKeyIsIgnored(): void
    {
        $this->presence->syncDiff([
            'joins' => [],
            'leaves' => [
                'ghost' => ['metas' => [['phx_ref' => 'xyz']]],
            ],
        ]);

        $this->assertSame([], $this->presence->state());
    }
}
