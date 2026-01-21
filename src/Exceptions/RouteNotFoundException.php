<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class RouteNotFoundException extends PhapiException
{
    protected int $httpStatusCode = 404;

    /**
     * Create a route-not-found exception.
     */
    public function __construct(string $path, string $method = '')
    {
        $message = 'Route not found';
        if ($method !== '' && $path !== '') {
            $message = "Route not found: {$method} {$path}";
        } elseif ($path !== '') {
            $message = "Route not found: {$path}";
        }
        parent::__construct($message);
    }
}
