<?php

declare(strict_types=1);

namespace PHAPI\HTTP;

use PHAPI\PHAPI;

class RouteBuilder
{
    protected PHAPI $api;
    protected string $method;
    protected string $path;
    /**
     * @var mixed
     */
    protected $handler;
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $middleware = [];
    /**
     * @var array<string, string>|null
     */
    protected ?array $validationRules = null;
    protected string $validationType = 'body';
    protected ?string $name = null;
    /**
     * @var array<int, string>|string|null
     */
    protected $host = null;
    private ?int $routeIndex = null;

    /**
     * Create a new route builder.
     *
     * @param PHAPI $api
     * @param string $method
     * @param string $path
     * @param mixed $handler
     * @return void
     */
    public function __construct(PHAPI $api, string $method, string $path, $handler)
    {
        $this->api = $api;
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
    }

    /**
     * Attach middleware to the route.
     *
     * @param (callable(\PHAPI\HTTP\Request): mixed|callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): mixed|string) $middleware
     * @return self
     */
    public function middleware($middleware): self
    {
        if (is_string($middleware) && class_exists($middleware)) {
            $this->middleware[] = ['type' => 'inline', 'handler' => $this->api->classMiddleware($middleware)];
        } elseif (is_string($middleware)) {
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $args = [];
            if (isset($parts[1])) {
                $args = array_filter(explode('|', $parts[1]), fn ($part) => $part !== '');
            }
            $this->middleware[] = ['type' => 'named', 'name' => $name, 'args' => $args];
        } elseif (is_callable($middleware)) {
            $this->middleware[] = ['type' => 'inline', 'handler' => $middleware];
        }
        $this->sync();
        return $this;
    }

    /**
     * Attach validation rules to the route.
     *
     * @param array<string, string> $rules
     * @param string $type
     * @return self
     */
    public function validate(array $rules, string $type = 'body'): self
    {
        $this->validationRules = $rules;
        $this->validationType = $type;
        $this->sync();
        return $this;
    }

    /**
     * Name the route for URL generation.
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self
    {
        $this->name = $name;
        $this->sync();
        return $this;
    }

    /**
     * Apply a host constraint to the route.
     *
     * @param mixed $host
     * @return self
     */
    public function host($host): self
    {
        $this->host = $host;
        $this->sync();
        return $this;
    }

    /**
     * Register the route with the router.
     *
     * @return void
     */
    public function register(): void
    {
        $this->routeIndex = $this->api->registerRoute(
            $this->method,
            $this->path,
            $this->handler,
            $this->middleware,
            $this->validationRules,
            $this->validationType,
            $this->name,
            $this->host
        );
    }

    private function sync(): void
    {
        if ($this->routeIndex === null) {
            return;
        }

        $this->api->updateRoute($this->routeIndex, [
            'handler' => $this->handler,
            'middleware' => $this->middleware,
            'validation' => $this->validationRules,
            'validationType' => $this->validationType,
            'name' => $this->name,
            'host' => $this->host,
        ]);
    }

    /**
     * Register a GET route.
     *
     * @param string $path
     * @param mixed $handler
     * @return RouteBuilder
     */
    public function get(string $path, $handler): RouteBuilder
    {
        return $this->api->get($path, $handler);
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
        return $this->api->post($path, $handler);
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
        return $this->api->put($path, $handler);
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
        return $this->api->patch($path, $handler);
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
        return $this->api->delete($path, $handler);
    }
}
