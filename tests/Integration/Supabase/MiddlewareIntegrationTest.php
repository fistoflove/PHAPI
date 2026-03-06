<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\Supabase\Middleware\SupabaseAuthMiddleware;
use PHAPI\Supabase\Middleware\SupabaseRoleMiddleware;
use PHAPI\Supabase\SupabaseContext;

/**
 * Integration tests for Supabase middleware with live GoTrue.
 *
 * @group integration
 * @group supabase
 */
final class MiddlewareIntegrationTest extends SupabaseIntegrationTestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
    }

    /** @var array<int, string> user IDs to clean up */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        $admin = self::$factory->createServiceContext()->auth()->admin();
        foreach ($this->createdUserIds as $userId) {
            try {
                $admin->deleteUser($userId);
            } catch (\Throwable) {
            }
        }
        $this->createdUserIds = [];
        parent::tearDown();
    }

    /**
     * Create a test user via admin API and return their access token.
     */
    private function createUserToken(): string
    {
        $email = $this->testEmail('middleware');
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $user = $admin->createUser([
            'email' => $email,
            'password' => 'Test1234!',
            'email_confirm' => true,
        ]);
        $this->createdUserIds[] = $user['id'];

        $context = self::$factory->createContext();
        $session = $context->auth()->signInWithPassword($email, 'Test1234!');
        return $session['access_token'];
    }

    private function next(): callable
    {
        return function (Request $request): \PHAPI\HTTP\Response {
            return \PHAPI\HTTP\Response::json(['ok' => true]);
        };
    }

    // ─── SupabaseAuthMiddleware ──────────────────────────────────────

    public function testAuthMiddlewareRejectsMissingToken(): void
    {
        $middleware = new SupabaseAuthMiddleware(self::$factory, $this->container);
        $request = new Request('GET', '/test');

        $response = $middleware($request, $this->next());

        $this->assertSame(401, $response->status());
    }

    public function testAuthMiddlewareRejectsInvalidToken(): void
    {
        $middleware = new SupabaseAuthMiddleware(self::$factory, $this->container);
        $request = new Request('GET', '/test', [], ['authorization' => 'Bearer invalid-jwt-garbage']);

        $response = $middleware($request, $this->next());

        $this->assertSame(401, $response->status());
    }

    public function testAuthMiddlewarePassesValidToken(): void
    {
        $token = $this->createUserToken();
        $middleware = new SupabaseAuthMiddleware(self::$factory, $this->container);
        $request = new Request('GET', '/test', [], ['authorization' => 'Bearer ' . $token]);

        $response = $middleware($request, $this->next());

        $this->assertSame(200, $response->status());

        // Context should be stored in container
        $context = $this->container->get(SupabaseContext::class);
        $this->assertInstanceOf(SupabaseContext::class, $context);
        $this->assertSame($token, $context->accessToken());
    }

    public function testAuthMiddlewareCustomTokenResolver(): void
    {
        $token = $this->createUserToken();

        $resolver = function (Request $request) use ($token): ?string {
            return $request->query('token');
        };

        $middleware = new SupabaseAuthMiddleware(self::$factory, $this->container, $resolver);
        $request = new Request('GET', '/test', ['token' => $token]);

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

    public function testRoleMiddlewareChecksRole(): void
    {
        // Create a user and set up context
        $token = $this->createUserToken();
        $context = self::$factory->createContext($token);
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/admin');

        // GoTrue users get 'authenticated' role by default, not 'admin'
        $response = $middleware($request, $this->next(), ['admin']);

        $this->assertSame(403, $response->status());
    }

    public function testRoleMiddlewarePassesMatchingRole(): void
    {
        $token = $this->createUserToken();
        $context = self::$factory->createContext($token);
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/dashboard');

        // GoTrue gives users 'authenticated' role
        $response = $middleware($request, $this->next(), ['authenticated']);

        $this->assertSame(200, $response->status());
    }

    public function testRoleMiddlewarePassesWithoutRequiredRole(): void
    {
        $token = $this->createUserToken();
        $context = self::$factory->createContext($token);
        $this->container->set(SupabaseContext::class, $context);

        $middleware = new SupabaseRoleMiddleware($this->container);
        $request = new Request('GET', '/any');

        // No role required — should pass
        $response = $middleware($request, $this->next());

        $this->assertSame(200, $response->status());
    }

    // ─── Full Auth + Role Pipeline ───────────────────────────────────

    public function testFullAuthThenRolePipeline(): void
    {
        $token = $this->createUserToken();
        $container = new Container();

        $authMiddleware = new SupabaseAuthMiddleware(self::$factory, $container);
        $roleMiddleware = new SupabaseRoleMiddleware($container);

        $request = new Request('GET', '/protected', [], ['authorization' => 'Bearer ' . $token]);

        // Run auth middleware first
        $response = $authMiddleware($request, function (Request $request) use ($roleMiddleware): \PHAPI\HTTP\Response {
            // Then role middleware (require 'authenticated' — default GoTrue role)
            return $roleMiddleware($request, function (Request $request): \PHAPI\HTTP\Response {
                return \PHAPI\HTTP\Response::json(['access' => 'granted']);
            }, ['authenticated']);
        });

        $this->assertSame(200, $response->status());
    }

    public function testFullAuthThenRolePipelineRejectsWrongRole(): void
    {
        $token = $this->createUserToken();
        $container = new Container();

        $authMiddleware = new SupabaseAuthMiddleware(self::$factory, $container);
        $roleMiddleware = new SupabaseRoleMiddleware($container);

        $request = new Request('GET', '/admin', [], ['authorization' => 'Bearer ' . $token]);

        $response = $authMiddleware($request, function (Request $request) use ($roleMiddleware): \PHAPI\HTTP\Response {
            return $roleMiddleware($request, function (Request $request): \PHAPI\HTTP\Response {
                return \PHAPI\HTTP\Response::json(['access' => 'granted']);
            }, ['admin']);
        });

        $this->assertSame(403, $response->status());
    }
}
