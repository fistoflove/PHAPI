<?php

declare(strict_types=1);

namespace PHAPI\Auth;

use PHAPI\HTTP\Response;

class AuthMiddleware
{
    /**
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public static function require(AuthManager $auth, ?string $guard = null): callable
    {
        return function (\PHAPI\HTTP\Request $request, callable $next) use ($auth, $guard): Response {
            if (!$auth->check($guard)) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            return $next($request);
        };
    }

    /**
     * @param string|array<int, string> $roles
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public static function requireRole(AuthManager $auth, $roles, ?string $guard = null): callable
    {
        return function (\PHAPI\HTTP\Request $request, callable $next) use ($auth, $roles, $guard): Response {
            if (!$auth->check($guard)) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            if (!$auth->hasRole($roles, $guard)) {
                return Response::json(['error' => 'Forbidden'], 403);
            }

            return $next($request);
        };
    }

    /**
     * @param array<int, string> $roles
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public static function requireAllRoles(AuthManager $auth, array $roles, ?string $guard = null): callable
    {
        return function (\PHAPI\HTTP\Request $request, callable $next) use ($auth, $roles, $guard): Response {
            if (!$auth->check($guard)) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            if (!$auth->hasAllRoles($roles, $guard)) {
                return Response::json(['error' => 'Forbidden'], 403);
            }

            return $next($request);
        };
    }
}
