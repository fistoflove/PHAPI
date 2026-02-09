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

final class HttpKernelReflectionCacheTest extends TestCase
{
    public function testReflectionMetadataCachesAreReusedAcrossRequests(): void
    {
        $router = new Router();
        $router->addRoute(
            'GET',
            '/cache',
            static function (Request $request): Response {
                return Response::text('ok');
            },
            [[
                'type' => 'inline',
                'handler' => static function (Request $request, callable $next): Response {
                    return $next($request);
                },
            ]]
        );

        $kernel = new HttpKernel($router, new MiddlewareManager(), new ErrorHandler(false), new Container());

        $first = $kernel->handle(new Request('GET', '/cache'));
        $this->assertSame(200, $first->status());

        $arityCountAfterFirst = $this->cacheCount($kernel, 'middlewareArityCache');
        $handlerCountAfterFirst = $this->cacheCount($kernel, 'handlerMetadataCache');
        $this->assertGreaterThan(0, $arityCountAfterFirst);
        $this->assertGreaterThan(0, $handlerCountAfterFirst);

        $second = $kernel->handle(new Request('GET', '/cache'));
        $this->assertSame(200, $second->status());

        $this->assertSame($arityCountAfterFirst, $this->cacheCount($kernel, 'middlewareArityCache'));
        $this->assertSame($handlerCountAfterFirst, $this->cacheCount($kernel, 'handlerMetadataCache'));
    }

    private function cacheCount(HttpKernel $kernel, string $property): int
    {
        $reflection = new \ReflectionProperty(HttpKernel::class, $property);
        $reflection->setAccessible(true);
        $cache = $reflection->getValue($kernel);

        return is_array($cache) ? count($cache) : 0;
    }
}
