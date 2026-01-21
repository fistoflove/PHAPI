<?php

declare(strict_types=1);

namespace PHAPI\Server;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

class MiddlewareManager
{
    /**
     * @var array<int, callable(Request): mixed|callable(Request, callable(Request): Response): mixed>
     */
    private array $globalMiddleware = [];
    /**
     * @var array<int, callable(Request, Response): (Response|void)>
     */
    private array $afterMiddleware = [];
    /**
     * @var array<string, callable(Request, callable(Request): Response, array<string, mixed>=): mixed>
     */
    private array $namedMiddleware = [];

    /**
     * Register a global middleware.
     *
     * @param callable(Request): mixed|callable(Request, callable(Request): Response): (Response|mixed) $middleware
     * @return void
     */
    public function addGlobalMiddleware(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Register an after-middleware.
     *
     * @param callable(Request, Response): (Response|void) $middleware
     * @return void
     */
    public function addAfterMiddleware(callable $middleware): void
    {
        $this->afterMiddleware[] = $middleware;
    }

    /**
     * Register a named middleware handler.
     *
     * @param string $name
     * @param callable(Request, callable(Request): Response, array<string, mixed>=): mixed $handler
     * @return void
     */
    public function registerNamed(string $name, callable $handler): void
    {
        $this->namedMiddleware[$name] = $handler;
    }

    /**
     * Get a named middleware handler.
     *
     * @param string $name
     * @return (callable(Request, callable(Request): Response, array<string, mixed>=): mixed)|null
     */
    public function getNamed(string $name): ?callable
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    /**
     * Get the global middleware stack.
     *
     * @return array<int, callable(Request): mixed|callable(Request, callable(Request): Response): mixed>
     */
    public function globalStack(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Get the after-middleware stack.
     *
     * @return array<int, callable(Request, Response): (Response|void)>
     */
    public function afterStack(): array
    {
        return $this->afterMiddleware;
    }

    /**
     * Resolve route middleware definitions into callables.
     *
     * @param array<int, array<string, mixed>> $middlewareDefs
     * @return array<int, callable(Request): mixed|callable(Request, callable(Request): Response): mixed>
     *
     * @throws \RuntimeException
     */
    public function resolveRouteMiddleware(array $middlewareDefs): array
    {
        $resolved = [];
        foreach ($middlewareDefs as $def) {
            if ($def['type'] === 'named') {
                $middleware = $this->getNamed($def['name']);
                if ($middleware === null) {
                    throw new \RuntimeException("Middleware '{$def['name']}' not found");
                }

                if (($def['args'] ?? []) !== []) {
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

    /**
     * Apply after-middleware to a response.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
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
