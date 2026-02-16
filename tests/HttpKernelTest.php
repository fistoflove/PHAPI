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

final class HttpKernelTest extends TestCase
{
    private function kernel(?Router $router = null, bool $debug = false): HttpKernel
    {
        return new HttpKernel(
            $router ?? new Router(),
            new MiddlewareManager(),
            new ErrorHandler($debug),
            new Container()
        );
    }

    public function testRouteNotFoundReturns404(): void
    {
        $kernel = $this->kernel();
        $response = $kernel->handle(new Request('GET', '/nonexistent'));

        $this->assertSame(404, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertStringContainsString('Route not found', $body['error']);
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/items', static fn (): Response => Response::json([]));
        $router->addRoute('POST', '/items', static fn (): Response => Response::json([]));

        $kernel = $this->kernel($router);
        $response = $kernel->handle(new Request('DELETE', '/items'));

        $this->assertSame(405, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Method not allowed', $body['error']);
        $allowed = $body['allowed_methods'];
        sort($allowed);
        $this->assertSame(['GET', 'POST'], $allowed);
    }

    public function testHandlerExceptionReturns500(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/boom', static function (): never {
            throw new \RuntimeException('handler exploded');
        });

        $kernel = $this->kernel($router);
        $response = $kernel->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Internal Server Error', $body['error']);
        $this->assertArrayNotHasKey('detail', $body);
    }

    public function testHandlerExceptionInDebugModeExposesDetail(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/boom', static function (): never {
            throw new \RuntimeException('handler exploded');
        });

        $kernel = $this->kernel($router, debug: true);
        $response = $kernel->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('handler exploded', $body['detail']);
        $this->assertArrayHasKey('trace', $body);
    }

    public function testResponseIncludesRequestIdHeader(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/ping', static fn (): Response => Response::json(['pong' => true]));

        $kernel = $this->kernel($router);
        $response = $kernel->handle(new Request('GET', '/ping'));

        $this->assertSame(200, $response->status());
        $this->assertArrayHasKey('X-Request-Id', $response->headers());
        $this->assertNotEmpty($response->headers()['X-Request-Id']);
    }

    public function testCustomRequestIdIsPreserved(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/ping', static fn (): Response => Response::json(['pong' => true]));

        $kernel = $this->kernel($router);
        $request = new Request('GET', '/ping', [], ['x-request-id' => 'custom-123']);
        $response = $kernel->handle($request);

        $this->assertSame('custom-123', $response->headers()['X-Request-Id']);
    }

    public function testAccessLoggerReceivesRequestResponseAndMeta(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/logged', static fn (): Response => Response::json(['ok' => true]));

        $logged = null;
        $kernel = new HttpKernel(
            $router,
            new MiddlewareManager(),
            new ErrorHandler(false),
            new Container(),
            function (Request $req, Response $res, array $meta) use (&$logged): void {
                $logged = ['method' => $req->method(), 'status' => $res->status(), 'meta' => $meta];
            }
        );

        $kernel->handle(new Request('GET', '/logged'));

        $this->assertNotNull($logged);
        $this->assertSame('GET', $logged['method']);
        $this->assertSame(200, $logged['status']);
        $this->assertArrayHasKey('request_id', $logged['meta']);
        $this->assertArrayHasKey('duration_ms', $logged['meta']);
        $this->assertIsFloat($logged['meta']['duration_ms']);
    }
}
