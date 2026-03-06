<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseAuthException;

/**
 * Integration tests for Supabase Admin Auth (service_role).
 *
 * @group integration
 * @group supabase
 */
final class AdminIntegrationTest extends SupabaseIntegrationTestCase
{
    public function testListUsers(): void
    {
        $admin = self::$factory->createServiceContext()->auth()->admin();

        $result = $admin->listUsers();

        $this->assertArrayHasKey('users', $result);
        $this->assertIsArray($result['users']);
    }

    public function testCreateUser(): void
    {
        $email = $this->testEmail('admin-create');
        $admin = self::$factory->createServiceContext()->auth()->admin();

        $user = $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);

        $this->assertArrayHasKey('id', $user);
        $this->assertSame($email, $user['email'] ?? '');
    }

    public function testGetUser(): void
    {
        $email = $this->testEmail('admin-get');
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $created = $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);

        $user = $admin->getUser($created['id']);

        $this->assertSame($created['id'], $user['id']);
        $this->assertSame($email, $user['email']);
    }

    public function testUpdateUser(): void
    {
        $email = $this->testEmail('admin-update');
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $created = $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);

        $updated = $admin->updateUser($created['id'], [
            'user_metadata' => ['role' => 'editor'],
        ]);

        $this->assertSame('editor', $updated['user_metadata']['role'] ?? '');
    }

    public function testDeleteUser(): void
    {
        $email = $this->testEmail('admin-delete');
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $created = $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);

        $admin->deleteUser($created['id']);

        // Verify user is gone — getUser should fail
        $this->expectException(SupabaseAuthException::class);
        $admin->getUser($created['id']);
    }

    public function testCreateAndSignIn(): void
    {
        $email = $this->testEmail('admin-signin');
        $admin = self::$factory->createServiceContext()->auth()->admin();

        $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);

        // Created user should be able to sign in
        $context = self::$factory->createContext();
        $session = $context->auth()->signInWithPassword($email, 'Admin1234!');

        $this->assertNotEmpty($session['access_token']);
    }

    public function testListUsersWithPagination(): void
    {
        $admin = self::$factory->createServiceContext()->auth()->admin();

        // Create a few users to ensure there's data
        for ($i = 0; $i < 3; $i++) {
            $admin->createUser([
                'email' => $this->testEmail('page-' . $i),
                'password' => 'Admin1234!',
                'email_confirm' => true,
            ]);
        }

        $page1 = $admin->listUsers(1, 2);
        $this->assertArrayHasKey('users', $page1);
        $this->assertLessThanOrEqual(2, count($page1['users']));
    }

    public function testFullAdminLifecycle(): void
    {
        $email = $this->testEmail('admin-lifecycle');
        $admin = self::$factory->createServiceContext()->auth()->admin();

        // Create
        $user = $admin->createUser([
            'email' => $email,
            'password' => 'Admin1234!',
            'email_confirm' => true,
        ]);
        $userId = $user['id'];

        // Read
        $fetched = $admin->getUser($userId);
        $this->assertSame($email, $fetched['email']);

        // Update
        $updated = $admin->updateUser($userId, [
            'user_metadata' => ['verified' => true],
        ]);
        $this->assertTrue($updated['user_metadata']['verified'] ?? false);

        // Verify in list
        $list = $admin->listUsers();
        $emails = array_column($list['users'] ?? [], 'email');
        $this->assertContains($email, $emails);

        // Delete
        $admin->deleteUser($userId);
    }
}
