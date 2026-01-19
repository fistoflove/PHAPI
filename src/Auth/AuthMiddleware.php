<?php

namespace PHAPI\Auth;

use PHAPI\HTTP\Response;

class AuthMiddleware
{
    public static function require(AuthManager $auth, ?string $guard = null): callable
    {
        return function ($request, $next) use ($auth, $guard) {
            if (!$auth->check($guard)) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            return $next($request);
        };
    }

    public static function requireRole(AuthManager $auth, $roles, ?string $guard = null): callable
    {
        return function ($request, $next) use ($auth, $roles, $guard) {
            if (!$auth->check($guard)) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            if (!$auth->hasRole($roles, $guard)) {
                return Response::json(['error' => 'Forbidden'], 403);
            }

            return $next($request);
        };
    }

    public static function requireAllRoles(AuthManager $auth, array $roles, ?string $guard = null): callable
    {
        return function ($request, $next) use ($auth, $roles, $guard) {
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
