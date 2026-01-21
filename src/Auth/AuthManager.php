<?php

declare(strict_types=1);

namespace PHAPI\Auth;

class AuthManager
{
    /**
     * @var array<string, GuardInterface>
     */
    private array $guards = [];
    private string $defaultGuard;

    /**
     * Create an auth manager.
     *
     * @param string $defaultGuard
     * @return void
     */
    public function __construct(string $defaultGuard = 'token')
    {
        $this->defaultGuard = $defaultGuard;
    }

    /**
     * Set the default guard name.
     *
     * @param string $name
     * @return void
     */
    public function setDefault(string $name): void
    {
        $this->defaultGuard = $name;
    }

    /**
     * Register a guard instance.
     *
     * @param string $name
     * @param GuardInterface $guard
     * @return void
     */
    public function addGuard(string $name, GuardInterface $guard): void
    {
        $this->guards[$name] = $guard;
    }

    /**
     * Get a guard instance by name.
     *
     * @param string|null $name
     * @return GuardInterface
     */
    public function guard(?string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard '{$name}' is not registered");
        }

        return $this->guards[$name];
    }

    /**
     * Get the authenticated user for a guard.
     *
     * @param string|null $guard
     * @return array<string, mixed>|null
     */
    public function user(?string $guard = null): ?array
    {
        return $this->guard($guard)->user();
    }

    /**
     * Determine if a guard has an authenticated user.
     *
     * @param string|null $guard
     * @return bool
     */
    public function check(?string $guard = null): bool
    {
        return $this->guard($guard)->check();
    }

    /**
     * Get the authenticated user id for a guard.
     *
     * @param string|null $guard
     * @return string|null
     */
    public function id(?string $guard = null): ?string
    {
        return $this->guard($guard)->id();
    }

    /**
     * Check whether the user has any of the given roles.
     *
     * @param string|array<int, string> $roles
     * @param string|null $guard
     * @return bool
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        $user = $this->user($guard);
        if ($user === null) {
            return false;
        }

        $rolesToCheck = is_array($roles) ? $roles : [$roles];
        $userRoles = $user['roles'] ?? $user['role'] ?? [];

        if (is_string($userRoles)) {
            $userRoles = [$userRoles];
        }

        foreach ($rolesToCheck as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the user has all of the given roles.
     *
     * @param array<int, string> $roles
     * @param string|null $guard
     * @return bool
     */
    public function hasAllRoles(array $roles, ?string $guard = null): bool
    {
        $user = $this->user($guard);
        if ($user === null) {
            return false;
        }

        $userRoles = $user['roles'] ?? $user['role'] ?? [];
        if (is_string($userRoles)) {
            $userRoles = [$userRoles];
        }

        foreach ($roles as $role) {
            if (!in_array($role, $userRoles, true)) {
                return false;
            }
        }

        return true;
    }
}
