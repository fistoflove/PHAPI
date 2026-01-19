<?php

namespace PHAPI\Tests;

use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

class MiddlewareArgsTest extends TestCase
{
    public function testNamedMiddlewareArgs(): void
    {
        $router = new Router();
        $middleware = new MiddlewareManager();
        $kernel = new HttpKernel($router, $middleware, new ErrorHandler(false), new Container());

        $middleware->registerNamed('role', function ($request, $next, array $args = []) {
            return Response::json(['args' => $args]);
        });

        $router->addRoute(
            'GET',
            '/test',
            function () {
                return Response::text('ok');
            },
            [['type' => 'named', 'name' => 'role', 'args' => ['admin', 'manager']]]
        );

        $response = $kernel->handle(new Request('GET', '/test'));
        $payload = json_decode($response->body(), true);

        $this->assertSame(['admin', 'manager'], $payload['args']);
    }
}
