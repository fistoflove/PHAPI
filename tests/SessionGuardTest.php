<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\SessionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests SessionGuard: Swoole context rejection and session lifecycle.
 */
final class SessionGuardTest extends TestCase
{
    // --- 6a. Swoole context rejection ---

    public function testUserReturnsNullWhenSwooleContextDisallowed(): void
    {
        // Default: allowInSwoole = false
        $guard = new SessionGuard();

        // In Swoole context (extension loaded), the guard should detect it
        // and return null rather than accessing $_SESSION
        if (extension_loaded('swoole')) {
            $this->assertNull($guard->user());
            $this->assertFalse($guard->check());
            $this->assertNull($guard->id());
        } else {
            $this->markTestSkipped('Swoole not loaded');
        }
    }

    // --- 6b. Session lifecycle outside Swoole ---

    public function testSetUserAndRetrieve(): void
    {
        $guard = new SessionGuard('auth_user', allowInSwoole: true);

        // Simulate session
        $_SESSION = [];
        $guard->setUser(['id' => '42', 'name' => 'Alice']);

        $this->assertTrue($guard->check());
        $this->assertSame('42', $guard->id());
        $this->assertSame(['id' => '42', 'name' => 'Alice'], $guard->user());
    }

    public function testClearRemovesUser(): void
    {
        $guard = new SessionGuard('auth_user', allowInSwoole: true);

        $_SESSION = [];
        $guard->setUser(['id' => '1', 'name' => 'Bob']);
        $this->assertTrue($guard->check());

        $guard->clear();
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testCustomSessionKey(): void
    {
        $guard = new SessionGuard('custom_key', allowInSwoole: true);

        $_SESSION = [];
        $guard->setUser(['id' => '99']);

        $this->assertArrayHasKey('custom_key', $_SESSION);
        $this->assertSame(['id' => '99'], $_SESSION['custom_key']);
    }

    public function testNoSessionDataReturnsNull(): void
    {
        $guard = new SessionGuard('auth_user', allowInSwoole: true);

        $_SESSION = [];

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
    }

    public function testIdExtractsFromUserArray(): void
    {
        $guard = new SessionGuard('auth_user', allowInSwoole: true);

        $_SESSION = [];
        $guard->setUser(['id' => '7', 'email' => 'test@test.com']);

        $this->assertSame('7', $guard->id());
    }

    public function testUserWithoutIdFieldReturnsNullId(): void
    {
        $guard = new SessionGuard('auth_user', allowInSwoole: true);

        $_SESSION = [];
        $guard->setUser(['name' => 'NoId']);

        $this->assertSame(['name' => 'NoId'], $guard->user());
        $this->assertTrue($guard->check());
        $this->assertNull($guard->id());
    }
}
