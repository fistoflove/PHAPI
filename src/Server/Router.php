<?php

declare(strict_types=1);

namespace PHAPI\Server;

/**
 * @phpstan-type RouteSegment array{type: 'static', value: string}|array{type: 'param', name: string, optional: bool}
 * @phpstan-type RouteDefinition array{
 *   method: string,
 *   path: string,
 *   segments: array<int, RouteSegment>,
 *   regex: string,
 *   handler: mixed,
 *   middleware: array<int, array<string, mixed>>,
 *   validation: array<string, string>|null,
 *   validationType: string,
 *   name: string|null,
 *   host: array<int, string>|string|null,
 *   matchedParams?: array<string, string>
 * }
 */
class Router
{
    /**
     * @var array<int, RouteDefinition>
     */
    private array $routes = [];
    /**
     * @var array<string, RouteDefinition>
     */
    private array $namedRoutes = [];
    /**
     * @var array<int, string>
     */
    private array $prefixStack = [''];

    /**
     * Add a route to the router.
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @param array<int, array<string, mixed>> $middleware
     * @param array<string, string>|null $validation
     * @param string $validationType
     * @param string|null $name
     * @param array<int, string>|string|null $host
     * @return int Route index.
     */
    public function addRoute(
        string $method,
        string $path,
        $handler,
        array $middleware = [],
        ?array $validation = null,
        string $validationType = 'body',
        ?string $name = null,
        array|string|null $host = null
    ): int {
        $fullPath = $this->getFullPath($path);
        $segments = $this->parseTemplate($fullPath);
        $regex = $this->compilePath($segments);

        /** @var RouteDefinition $route */
        $route = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'segments' => $segments,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
            'validation' => $validation,
            'validationType' => $validationType,
            'name' => $name,
            'host' => $host,
        ];

        $this->routes[] = $route;
        $index = array_key_last($this->routes);

        if ($name !== null) {
            $this->namedRoutes[$name] = $route;
        }

        return $index;
    }

    /**
     * Update a route by index.
     *
     * @param int $index
     * @param array<string, mixed> $updates
     * @return void
     */
    public function updateRoute(int $index, array $updates): void
    {
        if (!isset($this->routes[$index])) {
            throw new \RuntimeException("Route index {$index} not found");
        }

        $current = $this->routes[$index];
        $oldName = $current['name'] ?? null;

        /** @var RouteDefinition $route */
        $route = array_merge($current, $updates);
        $this->routes[$index] = $route;

        if ($oldName !== null && $oldName !== $route['name']) {
            unset($this->namedRoutes[$oldName]);
        }

        if ($route['name'] !== null) {
            $this->namedRoutes[$route['name']] = $route;
        }
    }

    /**
     * Match a route by method, path, and host.
     *
     * @param string $method
     * @param string $path
     * @param string|null $host
     * @return array{route: RouteDefinition|null, allowed: array<int, string>}
     */
    public function match(string $method, string $path, ?string $host = null): array
    {
        $allowed = [];
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if (!$this->hostMatches($route['host'], $host)) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                if ($route['method'] !== $method) {
                    $allowed[$route['method']] = true;
                    continue;
                }

                $params = [];
                foreach ($route['segments'] as $segment) {
                    if ($segment['type'] === 'param') {
                        $name = $segment['name'];
                        if (isset($matches[$name])) {
                            $params[$name] = $matches[$name];
                        }
                    }
                }
                $route['matchedParams'] = $params;
                return ['route' => $route, 'allowed' => array_keys($allowed)];
            }
        }

        return ['route' => null, 'allowed' => array_keys($allowed)];
    }

    /**
     * Get all registered routes.
     *
     * @return array<int, RouteDefinition>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name
     * @param array<string, string> $params
     * @param array<string, string> $query
     * @return string
     *
     * @throws \RuntimeException When the route name is not found.
     */
    public function urlFor(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name];
        $path = $this->buildPath($route['segments'], $params);

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $path;
    }

    /**
     * Push a route prefix onto the stack.
     *
     * @param string $prefix
     * @return void
     */
    public function pushPrefix(string $prefix): void
    {
        $base = end($this->prefixStack);
        if ($base === false) {
            $base = '';
        }
        $this->prefixStack[] = rtrim($base, '/') . rtrim($prefix, '/');
    }

    /**
     * Pop the last route prefix from the stack.
     *
     * @return void
     */
    public function popPrefix(): void
    {
        array_pop($this->prefixStack);
    }

    /**
     * Get the full path with the current prefix applied.
     *
     * @param string $path
     * @return string
     */
    public function getFullPath(string $path): string
    {
        $base = end($this->prefixStack);
        if ($base === false) {
            $base = '';
        }
        $full = rtrim($base, '/') . $path;
        return $full === '' ? '/' : $full;
    }

    /**
     * @param string $path
     * @return array<int, RouteSegment>
     */
    private function parseTemplate(string $path): array
    {
        if ($path === '/') {
            return [['type' => 'static', 'value' => '']];
        }

        /** @var array<int, RouteSegment> $segments */
        $segments = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^\{([a-zA-Z0-9_]+)(\?)?\}$/', $segment, $matches) === 1) {
                $segments[] = [
                    'type' => 'param',
                    'name' => $matches[1],
                    'optional' => ($matches[2] ?? '') === '?',
                ];
            } else {
                $segments[] = [
                    'type' => 'static',
                    'value' => $segment,
                ];
            }
        }

        return $segments;
    }

    /**
     * @param array<int, RouteSegment> $segments
     * @return string
     */
    private function compilePath(array $segments): string
    {
        $pattern = '^';
        foreach ($segments as $segment) {
            if ($segment['type'] === 'static') {
                $pattern .= '/' . preg_quote($segment['value'], '#');
                continue;
            }

            $part = '(?P<' . $segment['name'] . '>[^/]+)';
            if ($segment['optional'] === true) {
                $pattern .= '(?:/' . $part . ')?';
            } else {
                $pattern .= '/' . $part;
            }
        }

        if ($pattern === '^') {
            $pattern .= '/';
        }

        return '#'.$pattern.'$#';
    }

    /**
     * @param array<int, RouteSegment> $segments
     * @param array<string, scalar> $params
     * @return string
     */
    private function buildPath(array $segments, array $params): string
    {
        $path = '';
        foreach ($segments as $segment) {
            if ($segment['type'] === 'static') {
                $path .= '/' . $segment['value'];
                continue;
            }

            $name = $segment['name'];
            if (!array_key_exists($name, $params)) {
                if ($segment['optional'] === true) {
                    continue;
                }
                throw new \RuntimeException("Missing required route parameter '{$name}'");
            }

            $path .= '/' . rawurlencode((string)$params[$name]);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @param array<int, string>|string|null $constraint
     * @param string|null $host
     * @return bool
     */
    private function hostMatches($constraint, ?string $host): bool
    {
        if ($constraint === null || $constraint === '') {
            return true;
        }

        if ($host === null || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (is_array($constraint)) {
            return in_array($host, array_map('strtolower', $constraint), true);
        }

        if (strlen($constraint) > 2 && $constraint[0] === '/' && substr($constraint, -1) === '/') {
            return preg_match($constraint, $host) === 1;
        }

        return strtolower($constraint) === $host;
    }
}
