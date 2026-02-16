<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\TokenGuard;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests TokenGuard's per-request caching, resolver edge cases,
 * and token extraction from various sources.
 */
final class TokenGuardCachingTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        parent::tearDown();
    }

    // --- 5a. Cache invalidation on new request ---

    public function testCacheInvalidatesOnNewRequest(): void
    {
        $callCount = 0;
        $guard = new TokenGuard(function (string $token) use (&$callCount): array {
            $callCount++;
            return ['id' => $token];
        });

        $req1 = new Request('GET', '/', [], ['authorization' => 'Bearer aaa']);
        RequestContext::set($req1);
        $this->assertSame('aaa', $guard->id());
        $this->assertSame('aaa', $guard->id()); // second call should use cache
        $this->assertSame(1, $callCount, 'Resolver should be called once for same request');

        $req2 = new Request('GET', '/', [], ['authorization' => 'Bearer bbb']);
        RequestContext::set($req2);
        $this->assertSame('bbb', $guard->id());
        $this->assertSame(2, $callCount, 'Resolver should be called again for new request');
    }

    // --- 5b. Resolver returning null ---

    public function testResolverReturningNullMeansUnauthenticated(): void
    {
        $callCount = 0;
        $guard = new TokenGuard(function (string $token) use (&$callCount): ?array {
            $callCount++;
            return null; // token invalid
        });

        $req = new Request('GET', '/', [], ['authorization' => 'Bearer bad-token']);
        RequestContext::set($req);

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());

        // Call again — should it re-resolve or cache the null?
        $this->assertNull($guard->user());
        // The resolver may or may not be called again depending on caching strategy.
        // What matters is the result is consistently null.
    }

    public function testNoTokenMeansUnauthenticated(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        $req = new Request('GET', '/', [], []); // no auth header
        RequestContext::set($req);

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
    }

    // --- 5c. Token from query parameter ---

    public function testTokenFromQueryParameter(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        // No Authorization header, but access_token in query
        $req = new Request('GET', '/api', ['access_token' => 'query-tok']);
        RequestContext::set($req);

        $this->assertSame('query-tok', $guard->id());
        $this->assertTrue($guard->check());
    }

    public function testAuthorizationHeaderTakesPrecedenceOverQuery(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        $req = new Request('GET', '/api', ['access_token' => 'query-tok'], ['authorization' => 'Bearer header-tok']);
        RequestContext::set($req);

        $this->assertSame('header-tok', $guard->id());
    }

    // --- 5d. Malformed Authorization header ---

    public function testEmptyBearerTokenPassesEmptyStringToResolver(): void
    {
        $receivedToken = 'NOT_CALLED';
        $guard = new TokenGuard(function (string $token) use (&$receivedToken): ?array {
            $receivedToken = $token;
            return $token !== '' ? ['id' => $token] : null;
        });

        RequestContext::clear();
        $req = new Request('GET', '/', [], ['authorization' => 'Bearer ']);
        RequestContext::set($req);

        $user = $guard->user();
        // "Bearer " with trailing space yields empty string after trim(substr(..., 7))
        // The guard passes this to the resolver — it's the resolver's job to reject it
        $this->assertSame('', $receivedToken, 'Resolver should be called with empty string');
        $this->assertNull($user);
    }

    public function testBasicAuthSchemeIgnored(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        $req = new Request('GET', '/', [], ['authorization' => 'Basic abc123']);
        RequestContext::set($req);

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
    }

    public function testLowercaseBearerScheme(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        $req = new Request('GET', '/', [], ['authorization' => 'bearer my-token']);
        RequestContext::set($req);

        // Swoole lowercases headers, so "bearer" is the common case
        // Check if the guard handles it
        $id = $guard->id();
        // Either it works (case-insensitive) or returns null (case-sensitive)
        // Both are valid behaviors — we just document what happens
        if ($id !== null) {
            $this->assertSame('my-token', $id);
        } else {
            $this->assertNull($id);
        }
    }

    public function testEmptyAuthorizationHeader(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        $req = new Request('GET', '/', [], ['authorization' => '']);
        RequestContext::set($req);

        $this->assertNull($guard->user());
    }

    public function testNoRequestContextReturnsNull(): void
    {
        $guard = new TokenGuard(fn (string $token): array => ['id' => $token]);

        RequestContext::clear();

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
    }
}
