<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests middleware pipeline edge cases: short-circuiting, request mutation,
 * route-specific middleware, named middleware with arguments.
 */
final class MiddlewarePipelineEdgeTest extends TestCase
{
    private function buildKernel(Router $router, MiddlewareManager $mm): HttpKernel
    {
        return new HttpKernel($router, $mm, new ErrorHandler(false), new Container());
    }

    // --- 7a. Middleware that doesn't call $next ---

    public function testMiddlewareShortCircuitsWithoutCallingNext(): void
    {
        $handlerCalled = false;

        $router = new Router();
        $router->addRoute('GET', '/blocked', static function () use (&$handlerCalled): Response {
            $handlerCalled = true;
            return Response::json(['reached' => true]);
        });

        $mm = new MiddlewareManager();
        $mm->addGlobalMiddleware(static function (Request $req, callable $next): Response {
            return Response::json(['blocked' => true], 403);
            // $next is never called
        });

        $kernel = $this->buildKernel($router, $mm);
        $response = $kernel->handle(new Request('GET', '/blocked'));

        $this->assertSame(403, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertTrue($body['blocked']);
        $this->assertFalse($handlerCalled, 'Handler should not be called when middleware short-circuits');
    }

    // --- 7b. Middleware that modifies the request ---

    public function testMiddlewareModifiesRequest(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/check', static function (Request $req): Response {
            return Response::json(['custom' => $req->header('x-injected')]);
        });

        $mm = new MiddlewareManager();
        // Since Request has no withHeader, create a new Request with the header injected
        $mm->addGlobalMiddleware(static function (Request $req, callable $next): Response {
            $modified = new Request(
                $req->method(),
                $req->path(),
                $req->queryAll(),
                array_merge($req->headers(), ['x-injected' => 'from-middleware']),
                $req->cookies(),
                $req->body(),
                $req->server()
            );
            return $next($modified);
        });

        $kernel = $this->buildKernel($router, $mm);
        $response = $kernel->handle(new Request('GET', '/check'));

        $body = json_decode($response->body(), true);
        $this->assertSame('from-middleware', $body['custom']);
    }

    // --- 7c. Route-specific inline middleware ---

    public function testRouteSpecificMiddlewareOnlyRunsForThatRoute(): void
    {
        $middlewareRan = false;

        $inlineMiddleware = static function (Request $req, callable $next) use (&$middlewareRan): Response {
            $middlewareRan = true;
            return $next($req);
        };

        $router = new Router();
        // Protected route with inline middleware
        $router->addRoute(
            'GET',
            '/protected',
            static fn (): Response => Response::json(['ok' => true]),
            [['type' => 'inline', 'handler' => $inlineMiddleware]]
        );
        // Public route without middleware
        $router->addRoute('GET', '/public', static fn (): Response => Response::json(['ok' => true]));

        $mm = new MiddlewareManager();
        $kernel = $this->buildKernel($router, $mm);

        // Hit the public route — middleware should NOT run
        $middlewareRan = false;
        $kernel->handle(new Request('GET', '/public'));
        $this->assertFalse($middlewareRan, 'Route middleware should not run for other routes');

        // Hit the protected route — middleware SHOULD run
        $middlewareRan = false;
        $kernel->handle(new Request('GET', '/protected'));
        $this->assertTrue($middlewareRan, 'Route middleware should run for its route');
    }

    // --- 7d. Named middleware with arguments ---

    public function testNamedMiddlewareReceivesArguments(): void
    {
        $receivedArgs = null;

        $mm = new MiddlewareManager();
        $mm->registerNamed('role', static function (Request $req, callable $next, array $args = []) use (&$receivedArgs): Response {
            $receivedArgs = $args;
            return $next($req);
        });

        $router = new Router();
        // Attach named middleware with args via the route definition format
        $router->addRoute(
            'GET',
            '/admin',
            static fn (): Response => Response::json(['ok' => true]),
            [['type' => 'named', 'name' => 'role', 'args' => ['admin', 'editor']]]
        );

        $kernel = $this->buildKernel($router, $mm);
        $response = $kernel->handle(new Request('GET', '/admin'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['admin', 'editor'], $receivedArgs);
    }

    // --- Multiple middleware ordering ---

    public function testMiddlewareExecutesInOrder(): void
    {
        $order = [];

        $router = new Router();
        $router->addRoute('GET', '/order', static function () use (&$order): Response {
            $order[] = 'handler';
            return Response::json(['ok' => true]);
        });

        $mm = new MiddlewareManager();
        $mm->addGlobalMiddleware(static function (Request $req, callable $next) use (&$order): Response {
            $order[] = 'first-before';
            $response = $next($req);
            $order[] = 'first-after';
            return $response;
        });
        $mm->addGlobalMiddleware(static function (Request $req, callable $next) use (&$order): Response {
            $order[] = 'second-before';
            $response = $next($req);
            $order[] = 'second-after';
            return $response;
        });

        $kernel = $this->buildKernel($router, $mm);
        $kernel->handle(new Request('GET', '/order'));

        $this->assertSame(
            ['first-before', 'second-before', 'handler', 'second-after', 'first-after'],
            $order
        );
    }

    // --- After-middleware ---

    public function testAfterMiddlewareModifiesResponse(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/after', static fn (): Response => Response::json(['ok' => true]));

        $mm = new MiddlewareManager();
        $mm->addAfterMiddleware(static function (Request $req, Response $res): Response {
            return $res->withHeader('X-After', 'applied');
        });

        $kernel = $this->buildKernel($router, $mm);
        $response = $kernel->handle(new Request('GET', '/after'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['applied'], $response->headerValues('X-After'));
    }

    // --- resolveRouteMiddleware throws on unknown named middleware ---

    public function testResolveRouteMiddlewareThrowsOnUnknownName(): void
    {
        $mm = new MiddlewareManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Middleware 'nonexistent' not found");

        $mm->resolveRouteMiddleware([['type' => 'named', 'name' => 'nonexistent']]);
    }
}
