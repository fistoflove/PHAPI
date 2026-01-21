<?php

namespace PHAPI\HTTP;

use PHAPI\PHAPI;

/**
 * Route builder for fluent middleware and validation chains
 */
class RouteBuilder
{
    protected PHAPI $api;
    protected string $method;
    protected string $path;
    protected $handler;
    protected array $middleware = [];
    protected ?array $validationRules = null;
    protected string $validationType = 'body';

    /**
     * Create a new route builder
     *
     * @param PHAPI $api PHAPI instance
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable $handler Route handler
     */
    public function __construct(PHAPI $api, string $method, string $path, callable $handler)
    {
        $this->api = $api;
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
    }

    /**
     * Add middleware to this route
     *
     * @param string|callable $middleware Named middleware (string) or inline middleware (callable)
     * @return self
     */
    public function middleware($middleware): self
    {
        if (is_string($middleware)) {
            $this->middleware[] = ['type' => 'named', 'name' => $middleware];
        } elseif (is_callable($middleware)) {
            $this->middleware[] = ['type' => 'inline', 'handler' => $middleware];
        }
        return $this;
    }

    /**
     * Add validation rules to this route
     *
     * @param array $rules Validation rules
     * @param string $type Validation type ('body' or 'query')
     * @return self
     */
    public function validate(array $rules, string $type = 'body'): self
    {
        $this->validationRules = $rules;
        $this->validationType = $type;
        return $this;
    }

    /**
     * Register the route with PHAPI
     *
     * @return void
     */
    public function register(): void
    {
        $this->api->registerRoute(
            $this->method,
            $this->path,
            $this->handler,
            $this->middleware,
            $this->validationRules,
            $this->validationType
        );
    }

    /**
     * Get method for route builder chaining
     *
     * @param string $path Route path
     * @param callable $handler Route handler
     * @return RouteBuilder
     */
    public function get(string $path, callable $handler): RouteBuilder
    {
        return $this->api->get($path, $handler);
    }

    /**
     * Post method for route builder chaining
     *
     * @param string $path Route path
     * @param callable $handler Route handler
     * @return RouteBuilder
     */
    public function post(string $path, callable $handler): RouteBuilder
    {
        return $this->api->post($path, $handler);
    }
}

