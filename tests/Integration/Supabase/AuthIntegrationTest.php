<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseAuthException;

/**
 * Integration tests for Supabase Auth (GoTrue).
 *
 * Uses the admin API (service_role) to create test users, which:
 *   - bypasses email domain validation (Supabase Cloud rejects fake domains)
 *   - avoids signUp rate limits
 *   - auto-confirms emails (email_confirm: true)
 *
 * @group integration
 * @group supabase
 */
final class AuthIntegrationTest extends SupabaseIntegrationTestCase
{
    /** @var array<int, string> user IDs to clean up */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        // Clean up test users via admin API
        $admin = self::$factory->createServiceContext()->auth()->admin();
        foreach ($this->createdUserIds as $userId) {
            try {
                $admin->deleteUser($userId);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }
        $this->createdUserIds = [];
    }

    /**
     * Create a confirmed test user via admin API and return [email, password, user].
     *
     * @return array{email: string, password: string, user: array<string, mixed>}
     */
    private function createTestUser(string $prefix, string $password = 'Test1234!'): array
    {
        $email = $this->testEmail($prefix);
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $user = $admin->createUser([
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
        ]);
        $this->createdUserIds[] = $user['id'];
        return ['email' => $email, 'password' => $password, 'user' => $user];
    }

    /**
     * Create a user and return an active session.
     *
     * @return array{email: string, session: array<string, mixed>}
     */
    private function createAuthenticatedUser(string $prefix): array
    {
        $data = $this->createTestUser($prefix);
        $context = self::$factory->createContext();
        $session = $context->auth()->signInWithPassword($data['email'], $data['password']);
        return ['email' => $data['email'], 'session' => $session];
    }

    // ─── Sign Up (via admin) ─────────────────────────────────────────

    public function testSignUpCreatesUser(): void
    {
        $data = $this->createTestUser('signup');

        $this->assertArrayHasKey('id', $data['user']);
        $this->assertSame($data['email'], $data['user']['email'] ?? '');
    }

    public function testSignUpWithMetadata(): void
    {
        $email = $this->testEmail('meta');
        $admin = self::$factory->createServiceContext()->auth()->admin();
        $user = $admin->createUser([
            'email' => $email,
            'password' => 'Test1234!',
            'email_confirm' => true,
            'user_metadata' => ['display_name' => 'Test User'],
        ]);
        $this->createdUserIds[] = $user['id'];

        $this->assertArrayHasKey('id', $user);
        $this->assertSame('Test User', $user['user_metadata']['display_name'] ?? '');
    }

    // ─── Sign In ──────────────────────────────────────────────────────

    public function testSignInWithPassword(): void
    {
        $data = $this->createTestUser('signin');
        $context = self::$factory->createContext();

        $session = $context->auth()->signInWithPassword($data['email'], $data['password']);

        $this->assertArrayHasKey('access_token', $session);
        $this->assertArrayHasKey('refresh_token', $session);
        $this->assertNotEmpty($session['access_token']);
    }

    public function testSignInWithWrongPasswordFails(): void
    {
        $data = $this->createTestUser('wrongpw');
        $context = self::$factory->createContext();

        $this->expectException(SupabaseAuthException::class);
        $context->auth()->signInWithPassword($data['email'], 'WrongPassword');
    }

    public function testSignInWithNonexistentUserFails(): void
    {
        $context = self::$factory->createContext();

        $this->expectException(SupabaseAuthException::class);
        $context->auth()->signInWithPassword('nonexistent-' . bin2hex(random_bytes(4)) . '@example.com', 'Test1234!');
    }

    // ─── Get User ─────────────────────────────────────────────────────

    public function testGetUserWithValidToken(): void
    {
        $auth = $this->createAuthenticatedUser('getuser');

        $authed = self::$factory->createContext($auth['session']['access_token']);
        $user = $authed->auth()->user();

        $this->assertSame($auth['email'], $user['email'] ?? '');
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('role', $user);
    }

    public function testGetUserAlias(): void
    {
        $auth = $this->createAuthenticatedUser('alias');

        $authed = self::$factory->createContext($auth['session']['access_token']);
        $user = $authed->auth()->getUser();

        $this->assertSame($auth['email'], $user['email'] ?? '');
    }

    public function testGetUserWithInvalidTokenFails(): void
    {
        $authed = self::$factory->createContext('invalid-jwt-token');

        $this->expectException(SupabaseAuthException::class);
        $authed->auth()->user();
    }

    public function testGetUserWithoutTokenFails(): void
    {
        $context = self::$factory->createContext();

        $this->expectException(SupabaseAuthException::class);
        $context->auth()->user();
    }

    // ─── Refresh Token ────────────────────────────────────────────────

    public function testRefreshToken(): void
    {
        $auth = $this->createAuthenticatedUser('refresh');

        $context = self::$factory->createContext();
        $refreshed = $context->auth()->refreshToken($auth['session']['refresh_token']);

        $this->assertArrayHasKey('access_token', $refreshed);
        $this->assertNotEmpty($refreshed['access_token']);
        $this->assertNotSame($auth['session']['access_token'], $refreshed['access_token']);
    }

    public function testRefreshTokenWithInvalidTokenFails(): void
    {
        $context = self::$factory->createContext();

        $this->expectException(SupabaseAuthException::class);
        $context->auth()->refreshToken('invalid-refresh-token');
    }

    // ─── Sign Out ─────────────────────────────────────────────────────

    public function testSignOut(): void
    {
        $auth = $this->createAuthenticatedUser('signout');

        $authed = self::$factory->createContext($auth['session']['access_token']);
        $authed->auth()->signOut();

        // After sign out the token should be invalidated
        $this->expectException(SupabaseAuthException::class);
        $authed->auth()->user();
    }

    // ─── OTP (Magic Link) ──────────────────────────────────────────

    public function testSignInWithOtpSendsEmail(): void
    {
        $data = $this->createTestUser('otp');
        $context = self::$factory->createContext();

        try {
            $result = $context->auth()->signInWithOtp($data['email']);
            $this->assertIsArray($result);
        } catch (SupabaseAuthException $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markTestSkipped('Email rate limit exceeded (Supabase Cloud)');
            }
            throw $e;
        }

        // Verify email was captured by Inbucket (Docker only)
        $otp = $this->extractOtpFromInbucket($data['email']);
        if ($otp === null) {
            $this->assertTrue(true);
        } else {
            $this->assertNotEmpty($otp);
        }
    }

    public function testVerifyOtpCompletesSignIn(): void
    {
        $data = $this->createTestUser('verify-otp');
        $context = self::$factory->createContext();

        try {
            $context->auth()->signInWithOtp($data['email']);
        } catch (SupabaseAuthException $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->markTestSkipped('Email rate limit exceeded (Supabase Cloud)');
            }
            throw $e;
        }

        $otp = $this->extractOtpFromInbucket($data['email']);
        if ($otp === null) {
            $this->markTestSkipped('OTP extraction requires Inbucket (Docker stack)');
        }

        $session = $context->auth()->verifyOtp($data['email'], $otp, 'email');
        $this->assertArrayHasKey('access_token', $session);
        $this->assertNotEmpty($session['access_token']);
    }

    /**
     * Extract the OTP token from an email captured by Inbucket.
     */
    private function extractOtpFromInbucket(string $email): ?string
    {
        $inbucketUrl = getenv('INBUCKET_URL') ?: 'http://localhost:1100';
        $mailbox = explode('@', $email)[0];

        // Quick check if Inbucket is reachable
        $ch = curl_init($inbucketUrl . '/api/v1/mailbox/' . rawurlencode($mailbox));
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 0) {
            return null; // Inbucket not running (real Supabase mode)
        }

        // Poll for up to 10 seconds
        for ($i = 0; $i < 20; $i++) {
            usleep(500_000);

            $ch = curl_init($inbucketUrl . '/api/v1/mailbox/' . rawurlencode($mailbox));
            if ($ch === false) {
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                continue;
            }

            /** @var array<int, array{id: string}> $messages */
            $messages = json_decode($response, true);
            if (!is_array($messages) || $messages === []) {
                continue;
            }

            $latestId = $messages[count($messages) - 1]['id'] ?? '';
            if ($latestId === '') {
                continue;
            }

            $ch = curl_init($inbucketUrl . '/api/v1/mailbox/' . rawurlencode($mailbox) . '/' . $latestId);
            if ($ch === false) {
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $msgResponse = curl_exec($ch);
            curl_close($ch);

            if ($msgResponse === false) {
                continue;
            }

            /** @var array{body?: array{text?: string, html?: string}} $msg */
            $msg = json_decode($msgResponse, true);
            if (!is_array($msg)) {
                continue;
            }

            $body = $msg['body']['text'] ?? $msg['body']['html'] ?? '';

            if (preg_match('/\b(\d{6})\b/', $body, $matches)) {
                return $matches[1];
            }
            if (preg_match('/token=([a-zA-Z0-9_-]+)/', $body, $matches)) {
                return $matches[1];
            }

            $trimmed = trim($body);
            if (strlen($trimmed) > 0 && strlen($trimmed) < 100) {
                return $trimmed;
            }
        }

        return null;
    }

    // ─── Full Lifecycle ───────────────────────────────────────────────

    public function testFullAuthLifecycle(): void
    {
        // 1. Create user (via admin — works on both Docker and real Supabase)
        $data = $this->createTestUser('lifecycle');

        $context = self::$factory->createContext();

        // 2. Sign in
        $session = $context->auth()->signInWithPassword($data['email'], $data['password']);
        $this->assertNotEmpty($session['access_token']);

        // 3. Get user
        $authed = self::$factory->createContext($session['access_token']);
        $user = $authed->auth()->user();
        $this->assertSame($data['email'], $user['email']);

        // 4. Refresh
        $refreshed = $context->auth()->refreshToken($session['refresh_token']);
        $this->assertNotEmpty($refreshed['access_token']);

        // 5. Get user with refreshed token
        $reauthed = self::$factory->createContext($refreshed['access_token']);
        $user2 = $reauthed->auth()->user();
        $this->assertSame($data['email'], $user2['email']);

        // 6. Sign out
        $reauthed->auth()->signOut();
    }
}
