<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Auth\AdminClient;
use PHAPI\Supabase\Exceptions\SupabaseAuthException;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class AdminClientTest extends TestCase
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

    public function testListUsers(): void
    {
        $this->transport->addResponse([
            'data' => ['users' => [['id' => 'u1']]],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->listUsers();

        $this->assertArrayHasKey('users', $result);
        $this->assertSame('GET', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/admin/users', $this->transport->lastRequest()['path']);
        $this->assertSame('Bearer service-key', $this->transport->lastRequest()['headers']['Authorization']);
    }

    public function testGetUser(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'email' => 'admin@test.com'],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->getUser('user-1');

        $this->assertSame('user-1', $result['id']);
        $this->assertStringContainsString('/auth/v1/admin/users/user-1', $this->transport->lastRequest()['path']);
    }

    public function testCreateUser(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'new-user'],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->createUser(['email' => 'new@test.com', 'password' => 'pass']);

        $this->assertSame('new-user', $result['id']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertSame('new@test.com', $this->transport->lastRequest()['body']['email']);
    }

    public function testUpdateUser(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'user-1', 'email' => 'updated@test.com'],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->updateUser('user-1', ['email' => 'updated@test.com']);

        $this->assertSame('updated@test.com', $result['email']);
        $this->assertSame('PUT', $this->transport->lastRequest()['method']);
    }

    public function testDeleteUser(): void
    {
        $this->transport->addResponse([
            'data' => null,
            'status' => 200,
            'body' => '',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $admin->deleteUser('user-1');

        $this->assertSame('DELETE', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/admin/users/user-1', $this->transport->lastRequest()['path']);
    }

    public function testListUsersThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Forbidden'],
            'status' => 403,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);

        $this->expectException(SupabaseAuthException::class);
        $admin->listUsers();
    }

    // ─── New API Methods ────────────────────────────────────────────

    public function testInviteUserByEmail(): void
    {
        $this->transport->addResponse([
            'data' => ['id' => 'invited-user'],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->inviteUserByEmail('invite@test.com');

        $this->assertSame('invited-user', $result['id']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/auth/v1/invite', $this->transport->lastRequest()['path']);
        $this->assertSame('invite@test.com', $this->transport->lastRequest()['body']['email']);
    }

    public function testInviteUserByEmailWithRedirect(): void
    {
        $this->transport->addResponse(['data' => ['id' => 'u1'], 'status' => 200, 'body' => '{}']);

        $admin = new AdminClient($this->transport, $this->config);
        $admin->inviteUserByEmail('invite@test.com', ['redirect_to' => 'https://app.com']);

        $this->assertSame('https://app.com', $this->transport->lastRequest()['body']['redirect_to']);
    }

    public function testGenerateLink(): void
    {
        $this->transport->addResponse([
            'data' => ['action_link' => 'https://test.supabase.co/verify?token=abc'],
            'status' => 200,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);
        $result = $admin->generateLink('signup', 'new@test.com');

        $this->assertArrayHasKey('action_link', $result);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/admin/generate_link', $this->transport->lastRequest()['path']);
        $this->assertSame('signup', $this->transport->lastRequest()['body']['type']);
        $this->assertSame('new@test.com', $this->transport->lastRequest()['body']['email']);
    }

    public function testGenerateLinkThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Invalid type'],
            'status' => 400,
            'body' => '{}',
        ]);

        $admin = new AdminClient($this->transport, $this->config);

        $this->expectException(SupabaseAuthException::class);
        $admin->generateLink('invalid', 'test@test.com');
    }
}
