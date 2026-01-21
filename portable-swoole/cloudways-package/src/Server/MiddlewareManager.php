<?php

namespace PHAPI\Server;

use PHAPI\Logging\Logger;

/**
 * Manages middleware execution
 */
class MiddlewareManager
{
    private array $globalMiddleware = [];
    private array $afterMiddleware = [];
    private array $namedMiddleware = [];
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Add global middleware (runs before all routes)
     *
     * @param callable $middleware Middleware handler
     */
    public function addGlobalMiddleware(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Add after middleware (runs after route handler)
     *
     * @param callable $middleware Middleware handler
     */
    public function addAfterMiddleware(callable $middleware): void
    {
        $this->afterMiddleware[] = $middleware;
    }

    /**
     * Register a named middleware
     *
     * @param string $name Middleware name
     * @param callable $handler Middleware handler
     */
    public function registerNamed(string $name, callable $handler): void
    {
        $this->namedMiddleware[$name] = $handler;
    }

    /**
     * Get a named middleware
     *
     * @param string $name Middleware name
     * @return callable|null Middleware handler or null if not found
     */
    public function getNamed(string $name): ?callable
    {
        return $this->namedMiddleware[$name] ?? null;
    }

    /**
     * Execute global middleware
     *
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @return bool|null True if middleware handled response, null to continue
     */
    public function executeGlobal($request, $response): ?bool
    {
        foreach ($this->globalMiddleware as $middleware) {
            $result = $this->execute($middleware, $request, $response);
            if ($result !== null) {
                return true;
            }
        }
        return null;
    }

    /**
     * Execute route-specific middleware
     *
     * @param array $middlewareDefs Middleware definitions
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @return bool|null True if middleware handled response, null to continue
     */
    public function executeRoute(array $middlewareDefs, $request, $response): ?bool
    {
        foreach ($middlewareDefs as $middlewareDef) {
            $middleware = null;
            if ($middlewareDef['type'] === 'named') {
                $middleware = $this->getNamed($middlewareDef['name']);
                if ($middleware === null) {
                    throw new \RuntimeException("Middleware '{$middlewareDef['name']}' not found");
                }
            } elseif ($middlewareDef['type'] === 'inline') {
                $middleware = $middlewareDef['handler'];
            }

            if ($middleware !== null) {
                $result = $this->execute($middleware, $request, $response);
                if ($result !== null) {
                    return true;
                }
            }
        }
        return null;
    }

    /**
     * Execute after middleware
     *
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     */
    public function executeAfter($request, $response): void
    {
        foreach ($this->afterMiddleware as $middleware) {
            try {
                $this->execute($middleware, $request, $response);
            } catch (\Throwable $e) {
                $this->logger->errors()->error("After middleware error", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
    }

    /**
     * Execute a single middleware
     *
     * @param callable $middleware Middleware handler
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @return bool|null True if middleware handled response, null to continue
     */
    private function execute(callable $middleware, $request, $response): ?bool
    {
        $next = function() {
            return null;
        };

        $result = $middleware($request, $response, $next);

        if ($result !== null || ($response->status ?? null) !== null) {
            return true;
        }

        return null;
    }

    /**
     * Get all global middleware
     *
     * @return array
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Get all after middleware
     *
     * @return array
     */
    public function getAfterMiddleware(): array
    {
        return $this->afterMiddleware;
    }

    /**
     * Get all named middleware
     *
     * @return array
     */
    public function getNamedMiddleware(): array
    {
        return $this->namedMiddleware;
    }
}

