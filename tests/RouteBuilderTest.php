<?php

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHPUnit\Framework\TestCase;

class RouteBuilderTest extends TestCase
{
    public function testValidateUpdatesRegisteredRoute(): void
    {
        $api = new PHAPI(['runtime' => 'fpm']);
        $api->post('/register', function (): Response {
            return Response::json(['ok' => true]);
        })->validate([
            'email' => 'required|email',
        ]);

        $reflection = new \ReflectionClass($api);
        $routerProp = $reflection->getProperty('router');
        $routerProp->setAccessible(true);
        $router = $routerProp->getValue($api);
        $routes = $router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(['email' => 'required|email'], $routes[0]['validation']);
        $this->assertSame('body', $routes[0]['validationType']);
    }
}
