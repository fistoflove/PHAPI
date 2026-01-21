<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Core\AuthConfigurator;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\MiddlewareManager;
use PHPUnit\Framework\TestCase;

final class AuthConfiguratorTest extends TestCase
{
    public function testRegistersAuthMiddleware(): void
    {
        $configurator = new AuthConfigurator();
        $auth = $configurator->configure([]);
        $middleware = new MiddlewareManager();

        $configurator->registerMiddleware($middleware, $auth);

        $handler = $middleware->getNamed('auth');
        $this->assertNotNull($handler);

        $request = new Request('GET', '/');
        $response = $handler($request, static fn (Request $req): Response => Response::json(['ok' => true]));

        $this->assertInstanceOf(Response::class, $response);
    }
}
