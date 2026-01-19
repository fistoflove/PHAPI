<?php

namespace PHAPI\Server;

class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $prefixStack = [''];

    public function addRoute(
        string $method,
        string $path,
        $handler,
        array $middleware = [],
        ?array $validation = null,
        string $validationType = 'body',
        ?string $name = null,
        $host = null
    ): int {
        $fullPath = $this->getFullPath($path);
        $segments = $this->parseTemplate($fullPath);
        $regex = $this->compilePath($segments);

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

    public function updateRoute(int $index, array $updates): void
    {
        if (!isset($this->routes[$index])) {
            throw new \RuntimeException("Route index {$index} not found");
        }

        $current = $this->routes[$index];
        $oldName = $current['name'] ?? null;

        $route = array_merge($current, $updates);
        $this->routes[$index] = $route;

        if ($oldName !== null && $oldName !== $route['name']) {
            unset($this->namedRoutes[$oldName]);
        }

        if ($route['name'] !== null) {
            $this->namedRoutes[$route['name']] = $route;
        }
    }

    public function match(string $method, string $path, ?string $host = null): array
    {
        $allowed = [];
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if (!$this->hostMatches($route['host'], $host)) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
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

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function urlFor(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name];
        $path = $this->buildPath($route['segments'], $params);

        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return $path;
    }

    public function pushPrefix(string $prefix): void
    {
        $this->prefixStack[] = rtrim(end($this->prefixStack), '/') . rtrim($prefix, '/');
    }

    public function popPrefix(): void
    {
        array_pop($this->prefixStack);
    }

    public function getFullPath(string $path): string
    {
        $base = end($this->prefixStack);
        $full = rtrim($base, '/') . $path;
        return $full === '' ? '/' : $full;
    }

    private function parseTemplate(string $path): array
    {
        if ($path === '/') {
            return [['type' => 'static', 'value' => '']];
        }

        $segments = [];
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^\{([a-zA-Z0-9_]+)(\?)?\}$/', $segment, $matches)) {
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

    private function compilePath(array $segments): string
    {
        $pattern = '^';
        foreach ($segments as $segment) {
            if ($segment['type'] === 'static') {
                $pattern .= '/' . preg_quote($segment['value'], '#');
                continue;
            }

            $part = '(?P<' . $segment['name'] . '>[^/]+)';
            if ($segment['optional']) {
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
                if ($segment['optional']) {
                    continue;
                }
                throw new \RuntimeException("Missing required route parameter '{$name}'");
            }

            $path .= '/' . rawurlencode((string)$params[$name]);
        }

        return $path === '' ? '/' : $path;
    }

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

        if (is_string($constraint) && strlen($constraint) > 2 && $constraint[0] === '/' && substr($constraint, -1) === '/') {
            return preg_match($constraint, $host) === 1;
        }

        return strtolower((string)$constraint) === $host;
    }
}
