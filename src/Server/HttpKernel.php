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
        $ref = new \ReflectionFunction(\Closure::fromCallable($middleware));
        $paramCount = $ref->getNumberOfParameters();
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
        $result = $this->callHandler($callable, $request);

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
     * @return mixed
     */
    private function callHandler(callable $handler, Request $request): mixed
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($handler));
        $params = [];

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === Request::class) {
                    $params[] = $request;
                    continue;
                }
                if ($typeName === Container::class) {
                    $params[] = $this->container;
                    continue;
                }
                if ($typeName === PHAPI::class) {
                    $params[] = $this->container->get(PHAPI::class);
                    continue;
                }
                $params[] = $this->container->get($typeName);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
                continue;
            }

            $params[] = $request;
        }

        return $handler(...$params);
    }
}
