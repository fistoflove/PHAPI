<?php

declare(strict_types=1);

namespace PHAPI\Concerns;

use PHAPI\HTTP\RouteBuilder;
use PHAPI\Routing\Route as RouteLoader;

/**
 * Provides route registration, grouping, URL generation, and app loading.
 *
 * This trait is used by PHAPI and accesses the following properties via $this:
 * - Router $router
 * - array $config
 * - Container $container
 * - array $groupMiddlewareStack
 * - bool $deferGroupPop
 * - int $deferredGroupCount
 * - array $deferredGroupMarkers
 */
trait RoutesRequests
{
    /**
     * Register a GET route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function get(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function post(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function put(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function patch(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function delete(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('DELETE', $path, $handler);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function options(string $path, $handler): RouteBuilder
    {
        return $this->registerBuilder('OPTIONS', $path, $handler);
    }

    /**
     * Register a route directly with the router.
     *
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @param array<int, array<string, mixed>> $middleware
     * @param array<string, string>|null $validationRules
     * @param string $validationType
     * @param string|null $name
     * @param mixed $host
     * @return int Route index.
     */
    public function registerRoute(
        string $method,
        string $path,
        $handler,
        array $middleware = [],
        ?array $validationRules = null,
        string $validationType = 'body',
        ?string $name = null,
        $host = null
    ): int {
        return $this->router->addRoute($method, $path, $handler, $middleware, $validationRules, $validationType, $name, $host);
    }

    /**
     * Update a registered route by index.
     *
     * @param int $index
     * @param array<string, mixed> $route
     * @return void
     */
    public function updateRoute(int $index, array $route): void
    {
        $this->router->updateRoute($index, $route);
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name
     * @param array<string, string> $params
     * @param array<string, string> $query
     * @return string
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->router->urlFor($name, $params, $query);
    }

    /**
     * Group routes under a prefix.
     *
     * @param string $prefix
     * @param callable(self): void $define
     * @return void
     */
    public function group(string $prefix, callable $define): void
    {
        $this->router->pushPrefix($prefix);
        $this->groupMiddlewareStack[] = [];
        try {
            $define($this);
        } finally {
            if ($this->deferGroupPop) {
                $this->deferredGroupCount++;
            } else {
                array_pop($this->groupMiddlewareStack);
                $this->router->popPrefix();
            }
        }
    }

    /**
     * Begin a deferred group scope used by grouped route loaders.
     *
     * @return void
     */
    public function beginDeferredGroupScope(): void
    {
        $this->deferredGroupMarkers[] = $this->deferredGroupCount;
        $this->deferGroupPop = true;
    }

    /**
     * End a deferred group scope and pop groups opened within it.
     *
     * @return void
     */
    public function endDeferredGroupScope(): void
    {
        $marker = array_pop($this->deferredGroupMarkers);
        if (!is_int($marker)) {
            $this->deferGroupPop = false;
            return;
        }

        while ($this->deferredGroupCount > $marker) {
            if (count($this->groupMiddlewareStack) > 1) {
                array_pop($this->groupMiddlewareStack);
            }
            $this->router->popPrefix();
            $this->deferredGroupCount--;
        }

        if ($this->deferredGroupMarkers === []) {
            $this->deferGroupPop = false;
        }
    }

    /**
     * Register middleware in the current group scope.
     *
     * @param callable(\PHAPI\HTTP\Request): mixed|callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): mixed|string $handler
     * @return self
     */
    public function groupMiddleware($handler): self
    {
        $index = array_key_last($this->groupMiddlewareStack);
        if (!is_int($index)) {
            throw new \RuntimeException('Invalid middleware stack state');
        }
        $this->groupMiddlewareStack[$index][] = $this->normalizeRouteMiddleware($handler);
        return $this;
    }

    /**
     * Load a route file from routes/ by name.
     *
     * @param string $name
     * @return void
     */
    public function load(string $name): void
    {
        RouteLoader::init($this->routesBaseDir(), $this);
        RouteLoader::load($name);
    }

    /**
     * Load a grouped route directory from routes/.
     *
     * @param string $group
     * @return void
     */
    public function loadGroup(string $group): void
    {
        RouteLoader::init($this->routesBaseDir(), $this);
        RouteLoader::loadGroup($group);
    }

    /**
     * Load app bootstrap files from the given base directory.
     *
     * @param string|null $baseDir
     * @return void
     */
    public function loadApp(?string $baseDir = null): void
    {
        if ($baseDir === null) {
            $cwd = getcwd();
            $baseDir = $cwd !== false ? $cwd : dirname(__DIR__);
        }
        $api = $this;
        $paths = [
            $baseDir . '/app/middlewares.php',
            $baseDir . '/app/routes.php',
            $baseDir . '/app/tasks.php',
            $baseDir . '/app/jobs.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                require $path;
            }
        }

        $routeEntry = $this->config['route_entry'] ?? null;
        if (is_string($routeEntry) && $routeEntry !== '') {
            $entryPath = $this->resolvePath($baseDir, $routeEntry);
            if (file_exists($entryPath)) {
                require $entryPath;
            }
            return;
        }

        if (!file_exists($baseDir . '/app/routes.php')) {
            $defaultEntry = $baseDir . '/routes/routes.php';
            if (file_exists($defaultEntry)) {
                RouteLoader::init($baseDir . '/routes', $this);
                require $defaultEntry;
            }
        }
    }

    /**
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    private function registerBuilder(string $method, string $path, $handler): RouteBuilder
    {
        $builder = new RouteBuilder($this, $method, $path, $handler, $this->currentGroupMiddleware());
        $builder->register();
        return $builder;
    }

    /**
     * @return RouteBuilder
     */
    private function createRouteBuilderWithMiddleware(string $middlewareName): RouteBuilder
    {
        return new class ($this, $middlewareName) extends RouteBuilder {
            private string $preMiddleware;

            /**
             * Create a middleware-prefixed route builder.
             *
             * @param \PHAPI\PHAPI $api
             * @param string $middleware
             * @return void
             */
            public function __construct(\PHAPI\PHAPI $api, string $middleware)
            {
                $this->preMiddleware = $middleware;
                parent::__construct($api, '', '', function () {
                });
            }

            /**
             * Register a GET route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function get(string $path, $handler): RouteBuilder
            {
                return parent::get($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a POST route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function post(string $path, $handler): RouteBuilder
            {
                return parent::post($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a PUT route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function put(string $path, $handler): RouteBuilder
            {
                return parent::put($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a PATCH route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function patch(string $path, $handler): RouteBuilder
            {
                return parent::patch($path, $handler)->middleware($this->preMiddleware);
            }

            /**
             * Register a DELETE route with the predefined middleware.
             *
             * @param string $path
             * @param mixed $handler
             * @return RouteBuilder
             */
            public function delete(string $path, $handler): RouteBuilder
            {
                return parent::delete($path, $handler)->middleware($this->preMiddleware);
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentGroupMiddleware(): array
    {
        $merged = [];
        foreach ($this->groupMiddlewareStack as $frame) {
            foreach ($frame as $middleware) {
                $merged[] = $middleware;
            }
        }

        return $merged;
    }

    /**
     * @param mixed $middleware
     * @return array<string, mixed>
     */
    private function normalizeRouteMiddleware($middleware): array
    {
        if (is_string($middleware) && class_exists($middleware)) {
            return ['type' => 'inline', 'handler' => $this->classMiddleware($middleware)];
        }

        if (is_string($middleware)) {
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $args = [];
            if (isset($parts[1])) {
                $args = array_filter(explode('|', $parts[1]), fn ($part) => $part !== '');
            }
            return ['type' => 'named', 'name' => $name, 'args' => $args];
        }

        if (is_callable($middleware)) {
            return ['type' => 'inline', 'handler' => $middleware];
        }

        throw new \InvalidArgumentException('Invalid route middleware definition');
    }

    private function routesBaseDir(): string
    {
        $baseDir = $this->config['app_base_dir'] ?? getcwd();
        $configured = $this->config['routes_dir'] ?? 'routes';
        return $this->resolvePath((string)$baseDir, (string)$configured);
    }

    private function resolvePath(string $baseDir, string $path): string
    {
        if ($path === '') {
            return rtrim($baseDir, '/\\');
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{1,2}|\\/)/', $path) === 1) {
            return $path;
        }

        return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
