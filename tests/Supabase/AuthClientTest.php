<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Auth\AuthClient;
use PHAPI\Supabase\Exceptions\SupabaseAuthException;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class AuthClientTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
            'service_role_key' => 'service-key',
        ]);
    }

    public function testUserReturnsData(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'email' => 'test@example.com'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config, 'valid-token');
        $user = $client->user();

        $this->assertSame('user-1', $user['id']);
        $this->assertSame('test@example.com', $user['email']);
        $this->assertSame('GET', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/user', $this->transport->lastRequest()['path']);
    }

    public function testUserThrowsWithoutToken(): void
    {
        $client = new AuthClient($this->transport, $this->config, null);

        $this->expectException(SupabaseAuthException::class);
        $client->user();
    }

    public function testUserThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'invalid token'],
            'status' => 401,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config, 'bad-token');

        $this->expectException(SupabaseAuthException::class);
        $client->user();
    }

    public function testGetUserIsAlias(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config, 'token');
        $user = $client->getUser();

        $this->assertSame('user-1', $user['id']);
    }

    public function testSignInWithPassword(): void
    {
        $this->transport->addResponse([
            'data' => ['access_token' => 'jwt', 'refresh_token' => 'refresh'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->signInWithPassword('user@test.com', 'pass');

        $this->assertSame('jwt', $result['access_token']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('grant_type=password', $this->transport->lastRequest()['path']);
        $this->assertSame('user@test.com', $this->transport->lastRequest()['body']['email']);
    }

    public function testSignInWithPasswordThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['error_description' => 'Invalid credentials'],
            'status' => 400,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);

        $this->expectException(SupabaseAuthException::class);
        $client->signInWithPassword('user@test.com', 'wrong');
    }

    public function testSignUp(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'new-user'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->signUp('new@test.com', 'password123');

        $this->assertSame('new-user', $result['id']);
        $this->assertStringContainsString('/auth/v1/signup', $this->transport->lastRequest()['path']);
    }

    public function testSignInWithOtp(): void
    {
        $this->transport->addResponse([
            'data' => [],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $client->signInWithOtp('user@test.com');

        $this->assertStringContainsString('/auth/v1/otp', $this->transport->lastRequest()['path']);
    }

    public function testVerifyOtp(): void
    {
        $this->transport->addResponse([
            'data' => ['access_token' => 'jwt'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->verifyOtp('user@test.com', '123456');

        $this->assertSame('jwt', $result['access_token']);
        $this->assertStringContainsString('/auth/v1/verify', $this->transport->lastRequest()['path']);
    }

    public function testRefreshToken(): void
    {
        $this->transport->addResponse([
            'data' => ['access_token' => 'new-jwt'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->refreshToken('old-refresh');

        $this->assertSame('new-jwt', $result['access_token']);
        $this->assertStringContainsString('grant_type=refresh_token', $this->transport->lastRequest()['path']);
    }

    public function testSignOut(): void
    {
        $this->transport->addResponse([
            'data' => null,
            'status' => 204,
            'body' => '',
        ]);

        $client = new AuthClient($this->transport, $this->config, 'token');
        $client->signOut();

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/logout', $this->transport->lastRequest()['path']);
    }

    public function testAdminReturnsAdminClient(): void
    {
        $client = new AuthClient($this->transport, $this->config);
        $admin = $client->admin();

        $this->assertInstanceOf(\PHAPI\Supabase\Auth\AdminClient::class, $admin);
    }

    // ─── New API Methods ────────────────────────────────────────────

    public function testUpdateUser(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'user_metadata' => ['name' => 'Updated']],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config, 'valid-token');
        $result = $client->updateUser(['data' => ['name' => 'Updated']]);

        $this->assertSame('user-1', $result['id']);
        $this->assertSame('PUT', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/user', $this->transport->lastRequest()['path']);
    }

    public function testUpdateUserRequiresToken(): void
    {
        $client = new AuthClient($this->transport, $this->config, null);

        $this->expectException(SupabaseAuthException::class);
        $client->updateUser(['data' => ['name' => 'Test']]);
    }

    public function testResetPasswordForEmail(): void
    {
        $this->transport->addResponse([
            'data' => [],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $client->resetPasswordForEmail('user@test.com');

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/recover', $this->transport->lastRequest()['path']);
        $this->assertSame('user@test.com', $this->transport->lastRequest()['body']['email']);
    }

    public function testResetPasswordForEmailWithRedirectTo(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '{}']);

        $client = new AuthClient($this->transport, $this->config);
        $client->resetPasswordForEmail('user@test.com', ['redirectTo' => 'https://app.com/reset']);

        $this->assertSame('https://app.com/reset', $this->transport->lastRequest()['body']['redirectTo']);
    }

    public function testSignInWithOAuth(): void
    {
        $client = new AuthClient($this->transport, $this->config);
        $result = $client->signInWithOAuth('google', [
            'redirectTo' => 'https://app.com/callback',
            'scopes' => 'email profile',
        ]);

        $this->assertArrayHasKey('url', $result);
        $this->assertStringContainsString('/auth/v1/authorize', $result['url']);
        $this->assertStringContainsString('provider=google', $result['url']);
        $this->assertStringContainsString('redirect_to=', $result['url']);
        $this->assertStringContainsString('scopes=', $result['url']);
    }

    public function testSignInWithIdToken(): void
    {
        $this->transport->addResponse([
            'data' => ['access_token' => 'jwt', 'user' => ['id' => 'user-1']],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->signInWithIdToken([
            'provider' => 'google',
            'token' => 'google-id-token',
        ]);

        $this->assertSame('jwt', $result['access_token']);
        $this->assertStringContainsString('grant_type=id_token', $this->transport->lastRequest()['path']);
        $this->assertSame('google', $this->transport->lastRequest()['body']['provider']);
    }

    public function testSetSessionIsRefreshAlias(): void
    {
        $this->transport->addResponse([
            'data' => ['access_token' => 'new-jwt'],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new AuthClient($this->transport, $this->config);
        $result = $client->setSession('refresh-token');

        $this->assertSame('new-jwt', $result['access_token']);
        $this->assertStringContainsString('grant_type=refresh_token', $this->transport->lastRequest()['path']);
    }
}
