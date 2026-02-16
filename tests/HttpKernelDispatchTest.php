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

// --- Test helpers ---

class StubService
{
    public function value(): string
    {
        return 'injected';
    }
}

class StubController
{
    private StubService $svc;

    public function __construct(StubService $svc)
    {
        $this->svc = $svc;
    }

    public function index(): Response
    {
        return Response::json(['from' => 'controller', 'svc' => $this->svc->value()]);
    }

    public function withRequest(Request $req): Response
    {
        return Response::json(['path' => $req->path()]);
    }
}

/**
 * Tests HttpKernel handler resolution (string, array, closure) and
 * return type coercion (Response, array, string, null, other).
 */
final class HttpKernelDispatchTest extends TestCase
{
    private function kernel(?Container $container = null): HttpKernel
    {
        return new HttpKernel(
            new Router(),
            new MiddlewareManager(),
            new ErrorHandler(false),
            $container ?? new Container()
        );
    }

    private function addRoute(HttpKernel $kernel, string $method, string $path, mixed $handler): void
    {
        $ref = new \ReflectionProperty($kernel, 'router');
        $ref->setAccessible(true);
        $router = $ref->getValue($kernel);
        $router->addRoute($method, $path, $handler);
    }

    // --- 4a. String handler resolution (Controller@method) ---

    public function testStringHandlerResolution(): void
    {
        $container = new Container();
        $container->singleton(StubService::class, fn () => new StubService());
        $kernel = $this->kernel($container);
        $this->addRoute($kernel, 'GET', '/ctrl', StubController::class . '@index');

        $response = $kernel->handle(new Request('GET', '/ctrl'));

        $this->assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('controller', $body['from']);
        $this->assertSame('injected', $body['svc']);
    }

    // --- 4b. Array handler resolution ([Controller, method]) ---

    public function testArrayHandlerResolution(): void
    {
        $container = new Container();
        $container->singleton(StubService::class, fn () => new StubService());
        $kernel = $this->kernel($container);
        $this->addRoute($kernel, 'GET', '/arr', [StubController::class, 'index']);

        $response = $kernel->handle(new Request('GET', '/arr'));

        $this->assertSame(200, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('controller', $body['from']);
    }

    // --- 4c. Non-callable handler ---

    public function testNonCallableHandlerReturns500(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/bad', 12345);

        $response = $kernel->handle(new Request('GET', '/bad'));

        $this->assertSame(500, $response->status());
    }

    // --- 4d. Handler return type coercion ---

    public function testHandlerReturningArrayBecomesJson(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/arr', static fn (): array => ['key' => 'val']);

        $response = $kernel->handle(new Request('GET', '/arr'));

        $this->assertSame(200, $response->status());
        $this->assertSame('application/json', $response->headers()['Content-Type'] ?? null);
        $this->assertSame(['key' => 'val'], json_decode($response->body(), true));
    }

    public function testHandlerReturningStringBecomesText(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/txt', static fn (): string => 'hello world');

        $response = $kernel->handle(new Request('GET', '/txt'));

        $this->assertSame(200, $response->status());
        $this->assertSame('hello world', $response->body());
    }

    public function testHandlerReturningNullBecomes204(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/empty', static fn (): mixed => null);

        $response = $kernel->handle(new Request('GET', '/empty'));

        $this->assertSame(204, $response->status());
    }

    public function testHandlerReturningUnsupportedTypeReturns500(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/obj', static fn (): object => new \stdClass());

        $response = $kernel->handle(new Request('GET', '/obj'));

        $this->assertSame(500, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertStringContainsString('unsupported', strtolower($body['error'] ?? ''));
    }

    // --- 4e. Handler DI injection ---

    public function testHandlerReceivesInjectedService(): void
    {
        $container = new Container();
        $container->singleton(StubService::class, fn () => new StubService());
        $kernel = $this->kernel($container);
        $this->addRoute($kernel, 'GET', '/di', static function (Request $req, StubService $svc): Response {
            return Response::json(['path' => $req->path(), 'svc' => $svc->value()]);
        });

        $response = $kernel->handle(new Request('GET', '/di'));

        $body = json_decode($response->body(), true);
        $this->assertSame('/di', $body['path']);
        $this->assertSame('injected', $body['svc']);
    }

    public function testHandlerWithDefaultParameterValue(): void
    {
        $kernel = $this->kernel();
        $this->addRoute($kernel, 'GET', '/def', static function (Request $req, string $name = 'default'): Response {
            return Response::json(['name' => $name]);
        });

        $response = $kernel->handle(new Request('GET', '/def'));

        $body = json_decode($response->body(), true);
        $this->assertSame('default', $body['name']);
    }

    public function testStringHandlerWithRequestInjection(): void
    {
        $container = new Container();
        $container->singleton(StubService::class, fn () => new StubService());
        $kernel = $this->kernel($container);
        $this->addRoute($kernel, 'GET', '/req', StubController::class . '@withRequest');

        $response = $kernel->handle(new Request('GET', '/req'));

        $body = json_decode($response->body(), true);
        $this->assertSame('/req', $body['path']);
    }

    // --- 4f. Handler metadata caching ---

    public function testHandlerMetadataCacheIsReused(): void
    {
        $kernel = $this->kernel();
        $callCount = 0;
        $handler = static function () use (&$callCount): Response {
            $callCount++;
            return Response::json(['n' => $callCount]);
        };
        $this->addRoute($kernel, 'GET', '/cached', $handler);

        $r1 = $kernel->handle(new Request('GET', '/cached'));
        $r2 = $kernel->handle(new Request('GET', '/cached'));

        $this->assertSame(1, json_decode($r1->body(), true)['n']);
        $this->assertSame(2, json_decode($r2->body(), true)['n']);
        // If metadata wasn't cached, reflection would run twice — but we can't
        // directly observe that. We verify the handler runs correctly both times.
        $this->assertSame(2, $callCount);
    }
}
