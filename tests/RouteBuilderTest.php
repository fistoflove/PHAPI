<?php

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

class RouteBuilderTest extends SwooleTestCase
{
    public function testValidateUpdatesRegisteredRoute(): void
    {
        $api = new PHAPI(['runtime' => 'swoole']);
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
        $registerRoute = null;

        foreach ($routes as $route) {
            if ($route['path'] === '/register' && $route['method'] === 'POST') {
                $registerRoute = $route;
                break;
            }
        }

        $this->assertNotNull($registerRoute);
        $this->assertSame(['email' => 'required|email'], $registerRoute['validation']);
        $this->assertSame('body', $registerRoute['validationType']);
    }
}
