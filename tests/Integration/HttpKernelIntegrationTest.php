<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class HttpKernelIntegrationTest extends TestCase
{
    public function testValidationErrorsAreReturned(): void
    {
        $router = new Router();
        $router->addRoute(
            'POST',
            '/register',
            static function (): Response {
                return Response::json(['ok' => true]);
            },
            [],
            ['email' => 'required|email'],
            'body'
        );

        $middleware = new MiddlewareManager();
        $errorHandler = new ErrorHandler(false);
        $kernel = new HttpKernel($router, $middleware, $errorHandler, new Container());

        $request = new Request(
            'POST',
            '/register',
            [],
            ['content-type' => 'application/json'],
            [],
            [],
            []
        );

        $response = $kernel->handle($request);

        $this->assertSame(422, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Validation failed', $payload['error']);
        $this->assertArrayHasKey('email', $payload['errors']);
    }
}
