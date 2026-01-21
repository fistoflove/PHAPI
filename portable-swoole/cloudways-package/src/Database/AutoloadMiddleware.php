<?php

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
     * @return callable
     */
    public static function create(): callable
    {
        return function ($request, $response, $next) {
            // Load autoload options if database is configured
            if (ConnectionManager::isConfigured()) {
                Options::loadAutoload();
            }

            return $next();
        };
    }
}
