<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\AuthManager;
use PHAPI\Auth\AuthMiddleware;
use PHAPI\Auth\GuardInterface;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private function buildAuth(GuardInterface $guard, string $name = 'test'): AuthManager
    {
        $auth = new AuthManager($name);
        $auth->addGuard($name, $guard);
        return $auth;
    }

    private function passThrough(): callable
    {
        return static fn (Request $r): Response => Response::json(['ok' => true]);
    }

    // --- require() ---

    public function testRequireReturns401WhenUnauthenticated(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->expects($this->once())->method('check')->willReturn(false);

        $middleware = AuthMiddleware::require($this->buildAuth($guard));
        $response = $middleware(new Request('GET', '/secret'), $this->passThrough());

        $this->assertSame(401, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function testRequirePassesThroughWhenAuthenticated(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('check')->willReturn(true);

        $middleware = AuthMiddleware::require($this->buildAuth($guard));
        $response = $middleware(new Request('GET', '/secret'), $this->passThrough());

        $this->assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame(['ok' => true], $body);
    }

    // --- requireRole() ---

    public function testRequireRoleReturns401WhenUnauthenticated(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->expects($this->once())->method('check')->willReturn(false);

        $middleware = AuthMiddleware::requireRole($this->buildAuth($guard), 'admin');
        $response = $middleware(new Request('GET', '/admin'), $this->passThrough());

        $this->assertSame(401, $response->status());
    }

    public function testRequireRoleReturns403WhenMissingRole(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('check')->willReturn(true);
        $guard->method('user')->willReturn(['id' => 1, 'roles' => ['viewer']]);

        $middleware = AuthMiddleware::requireRole($this->buildAuth($guard), 'admin');
        $response = $middleware(new Request('GET', '/admin'), $this->passThrough());

        $this->assertSame(403, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Forbidden', $body['error']);
    }

    public function testRequireRolePassesThroughWithMatchingRole(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('check')->willReturn(true);
        $guard->method('user')->willReturn(['id' => 1, 'roles' => ['admin', 'editor']]);

        $middleware = AuthMiddleware::requireRole($this->buildAuth($guard), 'admin');
        $response = $middleware(new Request('GET', '/admin'), $this->passThrough());

        $this->assertSame(200, $response->status());
    }

    // --- requireAllRoles() ---

    public function testRequireAllRolesReturns403WhenMissingAnyRole(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('check')->willReturn(true);
        $guard->method('user')->willReturn(['id' => 1, 'roles' => ['admin']]);

        $middleware = AuthMiddleware::requireAllRoles($this->buildAuth($guard), ['admin', 'editor']);
        $response = $middleware(new Request('GET', '/manage'), $this->passThrough());

        $this->assertSame(403, $response->status());
    }

    public function testRequireAllRolesPassesThroughWithAllRoles(): void
    {
        $guard = $this->createMock(GuardInterface::class);
        $guard->method('check')->willReturn(true);
        $guard->method('user')->willReturn(['id' => 1, 'roles' => ['admin', 'editor']]);

        $middleware = AuthMiddleware::requireAllRoles($this->buildAuth($guard), ['admin', 'editor']);
        $response = $middleware(new Request('GET', '/manage'), $this->passThrough());

        $this->assertSame(200, $response->status());
    }
}
