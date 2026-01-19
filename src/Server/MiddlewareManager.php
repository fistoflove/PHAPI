<?php

namespace PHAPI\Server;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

class MiddlewareManager
{
    private array $globalMiddleware = [];
    private array $afterMiddleware = [];
    private array $namedMiddleware = [];

    public function addGlobalMiddleware(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function addAfterMiddleware(callable $middleware): void
    {
        $this->afterMiddleware[] = $middleware;
    }

    public function registerNamed(string $name, callable $handler): void
    {
        $this->namedMiddleware[$name] = $handler;
    }

    public function getNamed(string $name): ?callable
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    public function globalStack(): array
    {
        return $this->globalMiddleware;
    }

    public function afterStack(): array
    {
        return $this->afterMiddleware;
    }

    public function resolveRouteMiddleware(array $middlewareDefs): array
    {
        $resolved = [];
        foreach ($middlewareDefs as $def) {
            if ($def['type'] === 'named') {
                $middleware = $this->getNamed($def['name']);
                if ($middleware === null) {
                    throw new \RuntimeException("Middleware '{$def['name']}' not found");
                }

                if (!empty($def['args'])) {
                    $args = $def['args'];
                    $middleware = function ($request, $next) use ($middleware, $args) {
                        return $middleware($request, $next, $args);
                    };
                }

                $resolved[] = $middleware;
            } elseif ($def['type'] === 'inline') {
                $resolved[] = $def['handler'];
            }
        }
        return $resolved;
    }

    public function applyAfter(Request $request, Response $response): Response
    {
        $current = $response;
        foreach ($this->afterMiddleware as $middleware) {
            $result = $middleware($request, $current);
            if ($result instanceof Response) {
                $current = $result;
            }
        }
        return $current;
    }
}
