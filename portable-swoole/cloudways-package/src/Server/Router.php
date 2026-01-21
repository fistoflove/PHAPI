<?php

namespace PHAPI\Server;

/**
 * Handles route registration and matching
 */
class Router
{
    private array $routes = [];
    private array $prefixStack = [''];

    /**
     * Add a route to the router
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable $handler Route handler
     * @param array $middleware Route middleware definitions
     * @param array|null $validation Validation rules
     * @param string $validationType Validation type ('body' or 'query')
     */
    public function addRoute(string $method, string $path, callable $handler, array $middleware = [], ?array $validation = null, string $validationType = 'body'): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
            'validation' => $validation,
            'validationType' => $validationType
        ];
    }

    /**
     * Find a matching route for the given method and URI
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array|null Matched route or null if not found
     */
    public function findRoute(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $uri) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Get all registered routes
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Push a prefix onto the prefix stack
     *
     * @param string $prefix Prefix to add
     */
    public function pushPrefix(string $prefix): void
    {
        $this->prefixStack[] = rtrim(end($this->prefixStack), '/') . rtrim($prefix, '/');
    }

    /**
     * Pop a prefix from the prefix stack
     */
    public function popPrefix(): void
    {
        array_pop($this->prefixStack);
    }

    /**
     * Get the full path with current prefix
     *
     * @param string $path Route path
     * @return string Full path with prefix
     */
    public function getFullPath(string $path): string
    {
        $base = end($this->prefixStack);
        $full = rtrim($base, '/') . $path;
        return $full === '' ? '/' : $full;
    }
}

