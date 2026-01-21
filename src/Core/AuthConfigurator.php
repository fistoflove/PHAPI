<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Auth\AuthManager;
use PHAPI\Auth\AuthMiddleware;
use PHAPI\Auth\SessionGuard;
use PHAPI\Auth\TokenGuard;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\MiddlewareManager;

final class AuthConfigurator
{
    /**
     * Configure auth manager and guards.
     *
     * @param array<string, mixed> $config
     * @return AuthManager
     */
    public function configure(array $config): AuthManager
    {
        $authConfig = $config['auth'] ?? [];
        $default = $authConfig['default'] ?? 'token';
        $manager = new AuthManager($default);

        $tokenResolver = $authConfig['token_resolver'] ?? static function () {
            return null;
        };
        $sessionKey = $authConfig['session_key'] ?? 'user';
        $sessionAllowInSwoole = (bool)($authConfig['session_allow_in_swoole'] ?? false);

        $manager->addGuard('token', new TokenGuard($tokenResolver));
        $manager->addGuard('session', new SessionGuard($sessionKey, $sessionAllowInSwoole));

        return $manager;
    }

    /**
     * Register named auth middleware.
     *
     * @param MiddlewareManager $middleware
     * @param AuthManager $auth
     * @return void
     */
    public function registerMiddleware(MiddlewareManager $middleware, AuthManager $auth): void
    {
        $middleware->registerNamed('auth', AuthMiddleware::require($auth));
        $middleware->registerNamed('role', static function (Request $request, callable $next, array $args = []) use ($auth): Response {
            if ($args === []) {
                return $next($request);
            }
            $roles = array_map('strval', array_values($args));
            return AuthMiddleware::requireRole($auth, $roles)($request, $next);
        });

        $middleware->registerNamed('role_all', static function (Request $request, callable $next, array $args = []) use ($auth): Response {
            if ($args === []) {
                return $next($request);
            }
            $roles = array_map('strval', array_values($args));
            return AuthMiddleware::requireAllRoles($auth, $roles)($request, $next);
        });
    }
}
