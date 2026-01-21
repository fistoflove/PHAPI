<?php

declare(strict_types=1);

namespace PHAPI\Auth;

class SessionGuard implements GuardInterface
{
    private string $key;
    private bool $allowInSwoole;

    /**
     * Create a session guard.
     *
     * @param string $key
     * @param bool $allowInSwoole
     * @return void
     */
    public function __construct(string $key = 'user', bool $allowInSwoole = false)
    {
        $this->key = $key;
        $this->allowInSwoole = $allowInSwoole;
    }

    /**
     * Get the current user from the session.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if (!$this->allowInSwoole && $this->isSwooleContext()) {
            return null;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $value = $_SESSION[$this->key] ?? null;
        return is_array($value) ? $value : null;
    }

    /**
     * Determine if a user is present in the session.
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the current user id from the session.
     *
     * @return string|null
     */
    public function id(): ?string
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }
        $id = $user['id'] ?? $user['user_id'] ?? null;
        return $id === null ? null : (string)$id;
    }

    /**
     * Store a user in the session.
     *
     * @param array<string, mixed> $user
     * @return void
     */
    public function setUser(array $user): void
    {
        if (!$this->allowInSwoole && $this->isSwooleContext()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION[$this->key] = $user;
    }

    /**
     * Clear the user from the session.
     *
     * @return void
     */
    public function clear(): void
    {
        if (!$this->allowInSwoole && $this->isSwooleContext()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        unset($_SESSION[$this->key]);
    }

    private function isSwooleContext(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }

        if (!class_exists('Swoole\\\\Coroutine')) {
            return false;
        }

        return \Swoole\Coroutine::getCid() >= 0;
    }
}
