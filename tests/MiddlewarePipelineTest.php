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

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewareOrderAndAfterMiddleware(): void
    {
        $order = [];
        $router = new Router();
        $router->addRoute('GET', '/pipe', static function (): Response {
            return Response::json(['ok' => true]);
        }, [
            ['type' => 'inline', 'handler' => static function (Request $request, callable $next) use (&$order): Response {
                $order[] = 'route';
                return $next($request);
            }],
        ]);

        $middleware = new MiddlewareManager();
        $middleware->addGlobalMiddleware(static function (Request $request, callable $next) use (&$order): Response {
            $order[] = 'global';
            return $next($request);
        });

        $middleware->addAfterMiddleware(static function (Request $request, Response $response): Response {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            $payload['after'] = true;
            return Response::json($payload, $response->status());
        });

        $kernel = new HttpKernel($router, $middleware, new ErrorHandler(false), new Container());
        $request = new Request('GET', '/pipe');

        $response = $kernel->handle($request);
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['global', 'route'], $order);
        $this->assertTrue($body['after']);
    }
}
