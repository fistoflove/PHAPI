<?php

namespace PHAPI\Exceptions;

final class RouteNotFoundException extends PhapiException
{
    protected int $httpStatusCode = 404;

    public function __construct(string $path, string $method = '')
    {
        $message = "Route not found";
        if ($method && $path) {
            $message = "Route not found: {$method} {$path}";
        } elseif ($path) {
            $message = "Route not found: {$path}";
        }
        parent::__construct($message);
    }
}

