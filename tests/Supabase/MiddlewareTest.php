<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Supabase\Middleware\SupabaseAuthMiddleware;
use PHAPI\Supabase\Middleware\SupabaseRoleMiddleware;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseContext;
use PHAPI\Supabase\SupabaseFactory;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;
    private SupabaseFactory $factory;
    private Container $container;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
        ]);
        $this->factory = new SupabaseFactory($this->transport, $this->config);
        $this->container = new Container();
    }

    private function next(): callable
    {
        return function (Request $request): Response {
            return Response::json(['ok' => true]);
        };
    }

    // ─── SupabaseAuthMiddleware ──────────────────────────────────────

    public function testAuthMiddlewareRejectsMissingToken(): void
    {
        $middleware = new SupabaseAuthMiddleware($this->factory, $this->container);
        $request = new Request('GET', '/test');

        $response = $middleware($request, $this->next());

        $this->assertSame(401, $response->status());
    }

    public function testAuthMiddlewareRejectsInvalidToken(): void
    {
        // GoTrue returns error for invalid token
        $this->transport->addResponse([
            'data' => ['message' => 'invalid token'],
            'status' => 401,
            'body' => '{}',
        ]);

        $middleware = new SupabaseAuthMiddleware($this->factory, $this->container);
        $request = new Request('GET', '/test', [], ['authorization' => 'Bearer bad-token']);

        $response = $middleware($request, $this->next());

        $this->assertSame(401, $response->status());
    }

    public function testAuthMiddlewarePassesValidToken(): void
    {
        // GoTrue returns user for valid token
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'email' => 'user@test.com'],
            'status' => 200,
            'body' => '{}',
        ]);

        $middleware = new SupabaseAuthMiddleware($this->factory, $this->container);
        $request = new Request('GET', '/test', [], ['authorization' => 'Bearer valid-jwt']);

        $response = $middleware($request, $this->next());

        $this->assertSame(200, $response->status());
        $this->assertTrue($this->container->has(SupabaseContext::class));
    }

    public function testAuthMiddlewareCustomTokenResolver(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1'],
            'status' => 200,
            'body' => '{}',
        ]);

        $resolver = function (Request $request): ?string {
            return $request->cookie('sb-token');
        };

        $middleware = new SupabaseAuthMiddleware($this->factory, $this->container, $resolver);
        $request = new Request('GET', '/test', [], [], ['sb-token' => 'cookie-jwt']);

        $response = $middleware($request, $this->next());

        $this->assertSame(200, $response->status());
    }

    // ─── SupabaseRoleMiddleware ──────────────────────────────────────

    public function testRoleMiddlewareRejectsUnauthenticated(): void
    {
        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/admin');

        $response = $middleware($request, $this->next(), ['admin']);

        $this->assertSame(401, $response->status());
    }

    public function testRoleMiddlewareRejectsInsufficientRole(): void
    {
        // User has 'user' role, not 'admin'
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'role' => 'user'],
            'status' => 200,
            'body' => '{}',
        ]);

        $context = $this->factory->createContext('token');
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/admin');

        $response = $middleware($request, $this->next(), ['admin']);

        $this->assertSame(403, $response->status());
    }

    public function testRoleMiddlewareAllowsMatchingRole(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'admin-1', 'role' => 'admin'],
            'status' => 200,
            'body' => '{}',
        ]);

        $context = $this->factory->createContext('token');
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/admin');

        $response = $middleware($request, $this->next(), ['admin']);

        $this->assertSame(200, $response->status());
    }

    public function testRoleMiddlewarePassesWithoutRequiredRole(): void
    {
        $context = $this->factory->createContext('token');
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/any');

        $response = $middleware($request, $this->next());

        $this->assertSame(200, $response->status());
    }
}
