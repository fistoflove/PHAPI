<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Middleware;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Supabase\SupabaseContext;

/**
 * Middleware that checks the authenticated user's role from the JWT claims.
 *
 * Register as named middleware 'supabase.role' and use with route args:
 *   ->middleware('supabase.role:admin')
 *
 * @api
 */
final class SupabaseRoleMiddleware
{
    public function __construct(
        private readonly \PHAPI\Core\Container $container,
    ) {
    }

    /**
     * @param callable(Request): Response $next
     * @param array<int|string, string> $args
     */
    public function __invoke(Request $request, callable $next, array $args = []): Response
    {
        try {
            $context = $this->container->get(SupabaseContext::class);
        } catch (\Throwable) {
            return Response::json(['error' => 'Not authenticated'], 401);
        }

        if (!$context instanceof SupabaseContext) {
            return Response::json(['error' => 'Not authenticated'], 401);
        }

        $requiredRole = $args[0] ?? '';
        if ($requiredRole === '') {
            return $next($request);
        }

        try {
            $user = $context->auth()->user();
        } catch (\Throwable) {
            return Response::json(['error' => 'Unable to verify user'], 401);
        }

        $userRole = $user['role'] ?? $user['app_metadata']['role'] ?? '';

        if ($userRole !== $requiredRole) {
            return Response::json(['error' => 'Insufficient permissions'], 403);
        }

        return $next($request);
    }
}
