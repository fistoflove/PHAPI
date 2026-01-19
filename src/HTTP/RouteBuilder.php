<?php

namespace PHAPI\HTTP;

use PHAPI\PHAPI;

class RouteBuilder
{
    protected PHAPI $api;
    protected string $method;
    protected string $path;
    protected $handler;
    protected array $middleware = [];
    protected ?array $validationRules = null;
    protected string $validationType = 'body';
    protected ?string $name = null;
    protected $host = null;

    public function __construct(PHAPI $api, string $method, string $path, $handler)
    {
        $this->api = $api;
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
    }

    public function middleware($middleware): self
    {
        if (is_string($middleware)) {
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $args = [];
            if (isset($parts[1])) {
                $args = array_filter(explode('|', $parts[1]), fn($part) => $part !== '');
            }
            $this->middleware[] = ['type' => 'named', 'name' => $name, 'args' => $args];
        } elseif (is_callable($middleware)) {
            $this->middleware[] = ['type' => 'inline', 'handler' => $middleware];
        }
        return $this;
    }

    public function validate(array $rules, string $type = 'body'): self
    {
        $this->validationRules = $rules;
        $this->validationType = $type;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function host($host): self
    {
        $this->host = $host;
        return $this;
    }

    public function register(): void
    {
        $this->api->registerRoute(
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

    public function get(string $path, $handler): RouteBuilder
    {
        return $this->api->get($path, $handler);
    }

    public function post(string $path, $handler): RouteBuilder
    {
        return $this->api->post($path, $handler);
    }

    public function put(string $path, $handler): RouteBuilder
    {
        return $this->api->put($path, $handler);
    }

    public function patch(string $path, $handler): RouteBuilder
    {
        return $this->api->patch($path, $handler);
    }

    public function delete(string $path, $handler): RouteBuilder
    {
        return $this->api->delete($path, $handler);
    }
}
