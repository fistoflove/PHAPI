<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Auth;

use PHAPI\Supabase\Exceptions\SupabaseAuthException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Supabase GoTrue authentication client.
 *
 * @api
 */
final class AuthClient
{
    private const AUTH_PREFIX = '/auth/v1';

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
    ) {
    }

    /**
     * Get the current user from the access token.
     *
     * @return array<string, mixed>
     */
    public function user(): array
    {
        $this->requireToken();

        $response = $this->transport->request(
            'GET',
            self::AUTH_PREFIX . '/user',
            null,
            $this->config->headers($this->accessToken),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to get user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Alias for user().
     *
     * @return array<string, mixed>
     */
    public function getUser(): array
    {
        return $this->user();
    }

    /**
     * Sign in with email and password.
     *
     * @return array<string, mixed>
     */
    public function signInWithPassword(string $email, string $password): array
    {
        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/token?grant_type=password',
            ['email' => $email, 'password' => $password],
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Sign in failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Sign up with email and password.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function signUp(string $email, string $password, array $options = []): array
    {
        $body = ['email' => $email, 'password' => $password];
        if ($options !== []) {
            $body['data'] = $options;
        }

        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/signup',
            $body,
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Sign up failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Sign in with OTP (magic link).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function signInWithOtp(string $email, array $options = []): array
    {
        $body = array_merge(['email' => $email], $options);

        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/otp',
            $body,
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'OTP request failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Verify OTP token.
     *
     * @return array<string, mixed>
     */
    public function verifyOtp(string $email, string $token, string $type = 'email'): array
    {
        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/verify',
            ['email' => $email, 'token' => $token, 'type' => $type],
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'OTP verification failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Refresh the access token.
     *
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/token?grant_type=refresh_token',
            ['refresh_token' => $refreshToken],
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Token refresh failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Sign out the current user.
     *
     * @return void
     */
    public function signOut(): void
    {
        $this->requireToken();

        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/logout',
            [],
            $this->config->headers($this->accessToken),
        );

        if ($response['status'] >= 400) {
            throw SupabaseAuthException::fromResponse($response, 'Sign out failed');
        }
    }

    /**
     * Update the current user's attributes.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateUser(array $attributes): array
    {
        $this->requireToken();

        $response = $this->transport->request(
            'PUT',
            self::AUTH_PREFIX . '/user',
            $attributes,
            $this->config->headers($this->accessToken),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to update user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Send a password reset email.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function resetPasswordForEmail(string $email, array $options = []): array
    {
        $body = array_merge(['email' => $email], $options);

        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/recover',
            $body,
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Password reset failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Get the OAuth sign-in URL for a provider.
     *
     * Returns the authorization URL that the user should be redirected to.
     * After authentication, the provider redirects back to your redirect URL
     * with an authorization code.
     *
     * @param array<string, mixed> $options  Keys: redirectTo, scopes, queryParams
     * @return array{url: string}
     */
    public function signInWithOAuth(string $provider, array $options = []): array
    {
        $params = ['provider' => $provider];
        if (isset($options['redirectTo'])) {
            $params['redirect_to'] = $options['redirectTo'];
        }
        if (isset($options['scopes'])) {
            $params['scopes'] = $options['scopes'];
        }
        if (isset($options['queryParams']) && is_array($options['queryParams'])) {
            $params = array_merge($params, $options['queryParams']);
        }

        $url = $this->config->url . self::AUTH_PREFIX . '/authorize?' . http_build_query($params);

        return ['url' => $url];
    }

    /**
     * Exchange an ID token from an external provider for a Supabase session.
     *
     * @param array<string, mixed> $credentials  Keys: provider, token, nonce, access_token
     * @return array<string, mixed>
     */
    public function signInWithIdToken(array $credentials): array
    {
        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/token?grant_type=id_token',
            $credentials,
            $this->config->headers(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'ID token sign in failed');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Set the session by exchanging a refresh token.
     *
     * Alias for refreshToken() matching supabase-js API.
     *
     * @return array<string, mixed>
     */
    public function setSession(string $refreshToken): array
    {
        return $this->refreshToken($refreshToken);
    }

    /**
     * Get admin client for service-role operations.
     */
    public function admin(): AdminClient
    {
        return new AdminClient($this->transport, $this->config);
    }

    private function requireToken(): void
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new SupabaseAuthException('No access token available', 401);
        }
    }
}
