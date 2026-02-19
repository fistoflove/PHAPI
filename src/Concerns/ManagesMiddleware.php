<?php

declare(strict_types=1);

namespace PHAPI\Concerns;

use PHAPI\Auth\AuthMiddleware;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RouteBuilder;

/**
 * Provides middleware registration, CORS, security headers, and auth middleware helpers.
 *
 * This trait is used by PHAPI and accesses the following properties via $this:
 * - MiddlewareManager $middleware
 * - Container $container
 * - AuthManager $auth
 *
 * It also calls $this->createRouteBuilderWithMiddleware() from RoutesRequests trait.
 */
trait ManagesMiddleware
{
    /**
     * Register global middleware or return a route builder for named middleware.
     *
     * @param mixed $handler
     * @return self|RouteBuilder
     *
     * @throws \InvalidArgumentException
     */
    public function middleware($handler)
    {
        if (is_string($handler) && class_exists($handler)) {
            $this->middleware->addGlobalMiddleware($this->classMiddleware($handler));
            return $this;
        }

        if (is_string($handler)) {
            return $this->createRouteBuilderWithMiddleware($handler);
        }

        if (is_callable($handler)) {
            $this->middleware->addGlobalMiddleware($handler);
            return $this;
        }

        throw new \InvalidArgumentException('middleware() expects a callable (global middleware) or string (named middleware)');
    }

    /**
     * Build a middleware callable from an invokable class.
     *
     * @param class-string $class
     * @return callable(Request): mixed|callable(Request, callable(Request): \PHAPI\HTTP\Response): mixed
     */
    public function classMiddleware(string $class): callable
    {
        return function (Request $request, callable $next) use ($class) {
            $instance = $this->container->get($class);
            if (!is_callable($instance)) {
                throw new \RuntimeException("Middleware class '{$class}' is not invokable.");
            }
            $callable = \Closure::fromCallable($instance);
            $paramCount = (new \ReflectionFunction($callable))->getNumberOfParameters();
            if ($paramCount <= 1) {
                return $instance($request);
            }
            return $instance($request, $next);
        };
    }

    /**
     * Register after-middleware to run after the handler.
     *
     * @param callable(Request, \PHAPI\HTTP\Response): (\PHAPI\HTTP\Response|void) $handler
     * @return self
     */
    public function afterMiddleware(callable $handler): self
    {
        $this->middleware->addAfterMiddleware($handler);
        return $this;
    }

    /**
     * Register a named middleware handler.
     *
     * @param string $name
     * @param callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response, array<string, mixed>=): mixed $handler
     * @return self
     */
    public function addMiddleware(string $name, callable $handler): self
    {
        $this->middleware->registerNamed($name, $handler);
        return $this;
    }

    /**
     * Enable CORS handling using a global middleware.
     *
     * @param mixed $origins
     * @param array<int, string> $methods
     * @param array<int, string> $headers
     * @param bool $credentials
     * @param int $maxAge
     * @return self
     */
    public function enableCORS(
        $origins = '*',
        array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type'],
        bool $credentials = false,
        int $maxAge = 3600
    ): self {
        $this->middleware->addGlobalMiddleware(function ($request, $next) use ($origins, $methods, $headers, $credentials, $maxAge) {
            $origin = $request->header('origin');
            $allowedOrigin = $this->resolveOrigin($origins, $origin, $credentials);

            if ($request->method() === 'OPTIONS') {
                return \PHAPI\HTTP\Response::empty(204)
                    ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                    ->withHeader('Access-Control-Max-Age', (string)$maxAge)
                    ->withHeader('Access-Control-Allow-Credentials', $credentials ? 'true' : 'false');
            }

            $response = $next($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                ->withHeader('Access-Control-Max-Age', (string)$maxAge)
                ->withHeader('Access-Control-Allow-Credentials', $credentials ? 'true' : 'false');
        });

        return $this;
    }

    /**
     * Enable default security headers with optional overrides.
     *
     * @param array<string, string> $headers
     * @return self
     */
    public function enableSecurityHeaders(array $headers = []): self
    {
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-XSS-Protection' => '0',
        ];

        $final = array_merge($defaults, $headers);

        $this->middleware->addGlobalMiddleware(function ($request, $next) use ($final) {
            $response = $next($request);
            foreach ($final as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        });

        return $this;
    }

    /**
     * Return auth-required middleware for the given guard.
     *
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireAuth(?string $guard = null): callable
    {
        return AuthMiddleware::require($this->auth, $guard);
    }

    /**
     * Return role-required middleware for the given guard.
     *
     * @param string|array<int, string> $roles
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireRole($roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireRole($this->auth, $roles, $guard);
    }

    /**
     * Return middleware requiring all roles.
     *
     * @param array<int, string> $roles
     * @param string|null $guard
     * @return callable(\PHAPI\HTTP\Request, callable(\PHAPI\HTTP\Request): \PHAPI\HTTP\Response): \PHAPI\HTTP\Response
     */
    public function requireAllRoles(array $roles, ?string $guard = null): callable
    {
        return AuthMiddleware::requireAllRoles($this->auth, $roles, $guard);
    }

    /**
     * @param array<int, string>|string $origins
     * @param string|null $requestOrigin
     * @param bool $credentials
     * @return string
     */
    private function resolveOrigin($origins, ?string $requestOrigin, bool $credentials): string
    {
        if ($origins === '*') {
            return ($credentials && $requestOrigin !== null && $requestOrigin !== '') ? $requestOrigin : '*';
        }

        if (is_array($origins)) {
            if ($requestOrigin !== null && in_array($requestOrigin, $origins, true)) {
                return $requestOrigin;
            }
            return $origins[0] ?? '*';
        }

        return $origins;
    }
}
