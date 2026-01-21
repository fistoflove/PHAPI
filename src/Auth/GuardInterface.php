<?php

declare(strict_types=1);

namespace PHAPI\Auth;

interface GuardInterface
{
    /**
     * Get the authenticated user.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array;
    /**
     * Determine if a user is authenticated.
     *
     * @return bool
     */
    public function check(): bool;
    /**
     * Get the authenticated user id.
     *
     * @return string|null
     */
    public function id(): ?string;
}
