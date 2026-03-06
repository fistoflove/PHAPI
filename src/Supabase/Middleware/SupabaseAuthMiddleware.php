<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Middleware;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Supabase\SupabaseContext;
use PHAPI\Supabase\SupabaseFactory;

/**
 * Middleware that extracts the bearer token, creates a SupabaseContext,
 * validates the user, and stores the context in the container.
 *
 * @api
 */
final class SupabaseAuthMiddleware
{
    /** @var callable(Request): ?string */
    private $tokenResolver;

    /**
     * @param callable(Request): ?string|null $tokenResolver
     */
    public function __construct(
        private readonly SupabaseFactory $factory,
        private readonly \PHAPI\Core\Container $container,
        ?callable $tokenResolver = null,
    ) {
        $this->tokenResolver = $tokenResolver ?? static function (Request $request): ?string {
            $header = $request->header('authorization');
            if (is_string($header) && stripos($header, 'bearer ') === 0) {
                return trim(substr($header, 7));
            }
            return null;
        };
    }

    /**
     * @param callable(Request): Response $next
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $token = ($this->tokenResolver)($request);

        if ($token === null || $token === '') {
            return Response::json(['error' => 'Missing authentication token'], 401);
        }

        $context = $this->factory->createContext($token);

        try {
            $context->auth()->user();
        } catch (\Throwable) {
            return Response::json(['error' => 'Invalid authentication token'], 401);
        }

        $this->container->set(SupabaseContext::class, $context);

        return $next($request);
    }
}
