<?php

declare(strict_types=1);

namespace PHAPI\Server;

use PHAPI\Core\Container;
use PHAPI\Exceptions\MethodNotAllowedException;
use PHAPI\Exceptions\RouteNotFoundException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\HTTP\Response;
use PHAPI\HTTP\Validator;
use PHAPI\PHAPI;

class HttpKernel
{
    private Router $router;
    private MiddlewareManager $middleware;
    private ErrorHandler $errorHandler;
    private Container $container;
    /**
     * @var callable(Request, Response, array<string, mixed>): void|null
     */
    private $accessLogger;
    /**
     * @var array<string, int>
     */
    private array $middlewareArityCache = [];
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $handlerMetadataCache = [];

    /**
     * Create an HTTP kernel instance.
     *
     * @param Router $router
     * @param MiddlewareManager $middleware
     * @param ErrorHandler $errorHandler
     * @param Container $container
     * @param (callable(Request, Response, array<string, mixed>): void)|null $accessLogger
     * @return void
     */
    public function __construct(
        Router $router,
        MiddlewareManager $middleware,
        ErrorHandler $errorHandler,
        Container $container,
        ?callable $accessLogger = null
    ) {
        $this->router = $router;
        $this->middleware = $middleware;
        $this->errorHandler = $errorHandler;
        $this->container = $container;
        $this->accessLogger = $accessLogger;
    }

    /**
     * Handle an incoming request and return a response.
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $this->container->beginRequestScope();
        RequestContext::set($request);
        $start = microtime(true);
        $requestId = $request->header('x-request-id') ?? bin2hex(random_bytes(8));
        try {
            $match = $this->router->match($request->method(), $request->path(), $request->host());
            $route = $match['route'];
            if ($route === null) {
                if ($match['allowed'] !== []) {
                    throw new MethodNotAllowedException($match['allowed']);
                }
                throw new RouteNotFoundException($request->path(), $request->method());
            }

            $request = $request->withParams($route['matchedParams'] ?? []);
            RequestContext::set($request);

            if ($route['validation'] !== null) {
                $this->runValidation($route, $request);
            }

            $middlewareStack = array_merge(
                $this->middleware->globalStack(),
                $this->middleware->resolveRouteMiddleware($route['middleware'])
            );

            $coreHandler = function (Request $req) use ($route): Response {
                return $this->dispatch($route['handler'], $req);
            };

            $response = $this->runMiddlewareStack($middlewareStack, $request, $coreHandler);
            $response = $this->middleware->applyAfter($request, $response);
        } catch (\Throwable $e) {
            $response = $this->errorHandler->handle($e, $request);
        } finally {
            RequestContext::clear();
            $this->container->endRequestScope();
        }

        $response = $response->withHeader('X-Request-Id', $requestId);
        if (is_callable($this->accessLogger)) {
            ($this->accessLogger)($request, $response, [
                'request_id' => $requestId,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }

        return $response;
    }

    /**
     * Expose the container used for handler resolution.
     *
     * @return Container
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * @param array<string, mixed> $route
     * @param Request $request
     * @return void
     *
     * @throws ValidationException
     */
    private function runValidation(array $route, Request $request): void
    {
        $type = $route['validationType'] ?? 'body';
        if ($type === 'query') {
            $data = $request->queryAll();
        } elseif ($type === 'param') {
            $data = $request->params();
        } else {
            $body = $request->body();
            if ($body === null) {
                $data = [];
            } elseif (is_array($body)) {
                $data = $body;
            } else {
                throw new ValidationException('Invalid request body', ['body' => 'Expected JSON or form data']);
            }
        }

        $validator = new Validator($data, $type);
        $validator->rules($route['validation']);
        $validator->validate();
    }

    /**
     * @param array<int, callable(Request): mixed|callable(Request, callable(Request): Response): mixed> $stack
     * @param Request $request
     * @param callable(Request): Response $core
     * @return Response
     */
    private function runMiddlewareStack(array $stack, Request $request, callable $core): Response
    {
        $next = array_reduce(
            array_reverse($stack),
            function ($nextHandler, $middleware) {
                return function (Request $req) use ($middleware, $nextHandler): Response {
                    $result = $this->callMiddleware($middleware, $req, $nextHandler);
                    if ($result instanceof Response) {
                        return $result;
                    }
                    return $nextHandler($req);
                };
            },
            $core
        );

        return $next($request);
    }

    /**
     * @param callable(Request): mixed|callable(Request, callable(Request): Response): mixed $middleware
     * @param Request $request
     * @param callable(Request): Response $next
     * @return mixed
     */
    private function callMiddleware(callable $middleware, Request $request, callable $next): mixed
    {
        $cacheKey = $this->callableCacheKey($middleware);
        if (!isset($this->middlewareArityCache[$cacheKey])) {
            $ref = new \ReflectionFunction(\Closure::fromCallable($middleware));
            $this->middlewareArityCache[$cacheKey] = $ref->getNumberOfParameters();
        }

        $paramCount = $this->middlewareArityCache[$cacheKey];
        if ($paramCount <= 1) {
            return $middleware($request);
        }
        return $middleware($request, $next);
    }

    /**
     * @param mixed $handler
     * @param Request $request
     * @return Response
     */
    private function dispatch($handler, Request $request): Response
    {
        $callable = $this->resolveHandler($handler);
        $result = $this->callHandler($callable, $request, $handler);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::text($result);
        }

        if ($result === null) {
            return Response::empty();
        }

        return Response::error('Handler returned unsupported response type', 500, [
            'type' => gettype($result),
        ]);
    }

    /**
     * @param mixed $handler
     * @return callable(mixed ...$args): mixed
     */
    private function resolveHandler($handler): callable
    {
        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$class, $method] = explode('@', $handler, 2);
            $instance = $this->container->get($class);
            /** @var callable $callable */
            $callable = [$instance, $method];
            return \Closure::fromCallable($callable);
        }

        if (is_array($handler) && is_string($handler[0])) {
            $instance = $this->container->get($handler[0]);
            /** @var callable $callable */
            $callable = [$instance, $handler[1]];
            return \Closure::fromCallable($callable);
        }

        if (!is_callable($handler)) {
            throw new \RuntimeException('Route handler is not callable');
        }

        return $handler;
    }

    /**
     * @param callable(mixed ...$args): mixed $handler
     * @param Request $request
     * @param mixed $originalHandler
     * @return mixed
     */
    private function callHandler(callable $handler, Request $request, $originalHandler = null): mixed
    {
        $cacheKey = $this->handlerCacheKey($handler, $originalHandler);
        if (!isset($this->handlerMetadataCache[$cacheKey])) {
            $this->handlerMetadataCache[$cacheKey] = $this->buildHandlerMetadata($handler);
        }

        $params = [];
        foreach ($this->handlerMetadataCache[$cacheKey] as $meta) {
            $kind = $meta['kind'];
            if ($kind === 'request') {
                $params[] = $request;
                continue;
            }
            if ($kind === 'container') {
                $params[] = $this->container;
                continue;
            }
            if ($kind === 'phapi') {
                $params[] = $this->container->get(PHAPI::class);
                continue;
            }
            if ($kind === 'service') {
                $params[] = $this->container->get($meta['type']);
                continue;
            }
            if ($kind === 'default') {
                $params[] = $meta['value'];
                continue;
            }
        }

        return $handler(...$params);
    }

    /**
     * @param callable(mixed ...$args): mixed $handler
     * @return array<int, array<string, mixed>>
     */
    private function buildHandlerMetadata(callable $handler): array
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $metadata = [];

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === Request::class) {
                    $metadata[] = ['kind' => 'request'];
                    continue;
                }
                if ($typeName === Container::class) {
                    $metadata[] = ['kind' => 'container'];
                    continue;
                }
                if ($typeName === PHAPI::class) {
                    $metadata[] = ['kind' => 'phapi'];
                    continue;
                }
                $metadata[] = ['kind' => 'service', 'type' => $typeName];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $metadata[] = ['kind' => 'default', 'value' => $param->getDefaultValue()];
                continue;
            }

            $metadata[] = ['kind' => 'request'];
        }

        return $metadata;
    }

    /**
     * @param mixed $callable
     */
    private function callableCacheKey($callable): string
    {
        if (!is_callable($callable)) {
            return 'other:' . md5(serialize($callable));
        }

        if (is_string($callable)) {
            return 'string:' . $callable;
        }

        if (is_array($callable)) {
            $target = is_object($callable[0]) ? get_class($callable[0]) : (string)$callable[0];
            return 'array:' . $target . '::' . (string)$callable[1];
        }

        if ($callable instanceof \Closure) {
            return 'closure:' . spl_object_hash($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return 'invokable:' . get_class($callable);
        }

        return 'other:' . md5(serialize($callable));
    }

    /**
     * @param callable(mixed ...$args): mixed $handler
     * @param mixed $originalHandler
     */
    private function handlerCacheKey(callable $handler, $originalHandler = null): string
    {
        if (is_string($originalHandler) && strpos($originalHandler, '@') !== false) {
            return 'handler:' . $originalHandler;
        }

        if (
            is_array($originalHandler)
            && count($originalHandler) === 2
            && is_string($originalHandler[0])
        ) {
            return 'handler:' . $originalHandler[0] . '::' . (string)$originalHandler[1];
        }

        return $this->callableCacheKey($handler);
    }
}
