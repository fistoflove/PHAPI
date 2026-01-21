<?php

declare(strict_types=1);

namespace PHAPI\Database;

/**
 * Autoload Middleware
 *
 * Loads autoload options into cache before request processing
 * This middleware should be added early in the middleware stack
 */
class AutoloadMiddleware
{
    /**
     * Create middleware handler
     *
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): mixed
     */
    public static function create(): callable
    {
        return function (\PHAPI\HTTP\Request $request, callable $next) {
            // Load autoload options if database is configured
            if (ConnectionManager::isConfigured()) {
                Options::loadAutoload();
            }

            return $next($request);
        };
    }
}
