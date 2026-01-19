<?php

namespace PHAPI\Auth;

class AuthManager
{
    private array $guards = [];
    private string $defaultGuard;

    public function __construct(string $defaultGuard = 'token')
    {
        $this->defaultGuard = $defaultGuard;
    }

    public function setDefault(string $name): void
    {
        $this->defaultGuard = $name;
    }

    public function addGuard(string $name, GuardInterface $guard): void
    {
        $this->guards[$name] = $guard;
    }

    public function guard(?string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard '{$name}' is not registered");
        }

        return $this->guards[$name];
    }

    public function user(?string $guard = null): ?array
    {
        return $this->guard($guard)->user();
    }

    public function check(?string $guard = null): bool
    {
        return $this->guard($guard)->check();
    }

    public function id(?string $guard = null): ?string
    {
        return $this->guard($guard)->id();
    }

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
