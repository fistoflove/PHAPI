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

class ClassHandlerTest extends TestCase
{
    public function testClassHandlerIsResolved(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/hello', [TestController::class, 'index']);

        $kernel = new HttpKernel(
            $router,
            new MiddlewareManager(),
            new ErrorHandler(false),
            new Container()
        );

        $response = $kernel->handle(new Request('GET', '/hello'));
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['hello' => 'world'], $payload);
    }
}

class TestController
{
    public function index(): Response
    {
        return Response::json(['hello' => 'world']);
    }
}
