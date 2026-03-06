<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Auth;

use PHAPI\Supabase\Exceptions\SupabaseAuthException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Supabase Auth admin client using service role key.
 *
 * @api
 */
final class AdminClient
{
    private const AUTH_PREFIX = '/auth/v1/admin';

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
    ) {
    }

    /**
     * List users.
     *
     * @param int $page
     * @param int $perPage
     * @return array<string, mixed>
     */
    public function listUsers(int $page = 1, int $perPage = 50): array
    {
        $response = $this->transport->request(
            'GET',
            self::AUTH_PREFIX . '/users?page=' . $page . '&per_page=' . $perPage,
            null,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to list users');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Get a user by ID.
     *
     * @return array<string, mixed>
     */
    public function getUser(string $userId): array
    {
        $response = $this->transport->request(
            'GET',
            self::AUTH_PREFIX . '/users/' . rawurlencode($userId),
            null,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to get user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Create a user.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createUser(array $attributes): array
    {
        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/users',
            $attributes,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to create user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Update a user.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateUser(string $userId, array $attributes): array
    {
        $response = $this->transport->request(
            'PUT',
            self::AUTH_PREFIX . '/users/' . rawurlencode($userId),
            $attributes,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to update user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Delete a user.
     *
     * @return void
     */
    public function deleteUser(string $userId): void
    {
        $response = $this->transport->request(
            'DELETE',
            self::AUTH_PREFIX . '/users/' . rawurlencode($userId),
            null,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to delete user');
        }
    }

    /**
     * Invite a user by email.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function inviteUserByEmail(string $email, array $options = []): array
    {
        $body = array_merge(['email' => $email], $options);

        $response = $this->transport->request(
            'POST',
            '/auth/v1/invite',
            $body,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to invite user');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Generate an email link for various auth actions.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function generateLink(string $type, string $email, array $options = []): array
    {
        $body = array_merge(['type' => $type, 'email' => $email], $options);

        $response = $this->transport->request(
            'POST',
            self::AUTH_PREFIX . '/generate_link',
            $body,
            $this->config->serviceRoleHeaders(),
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw SupabaseAuthException::fromResponse($response, 'Failed to generate link');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }
}
