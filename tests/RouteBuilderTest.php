<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

class RouteBuilderTest extends SwooleTestCase
{
    private function getRoutes(PHAPI $api): array
    {
        $reflection = new \ReflectionClass($api);
        $routerProp = $reflection->getProperty('router');
        $routerProp->setAccessible(true);
        $router = $routerProp->getValue($api);
        return $router->getRoutes();
    }

    private function findRoute(PHAPI $api, string $method, string $path): ?array
    {
        foreach ($this->getRoutes($api) as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return $route;
            }
        }
        return null;
    }

    public function testValidateUpdatesRegisteredRoute(): void
    {
        $api = new PHAPI();
        $api->post('/register', function (): Response {
            return Response::json(['ok' => true]);
        })->validate([
            'email' => 'required|email',
        ]);

        $route = $this->findRoute($api, 'POST', '/register');

        $this->assertNotNull($route);
        $this->assertSame(['email' => 'required|email'], $route['validation']);
        $this->assertSame('body', $route['validationType']);
    }

    public function testValidateWithQueryType(): void
    {
        $api = new PHAPI();
        $api->get('/search', function (): Response {
            return Response::json([]);
        })->validate(['q' => 'required|string'], 'query');

        $route = $this->findRoute($api, 'GET', '/search');

        $this->assertNotNull($route);
        $this->assertSame(['q' => 'required|string'], $route['validation']);
        $this->assertSame('query', $route['validationType']);
    }

    public function testNameRegistersNamedRoute(): void
    {
        $api = new PHAPI();
        $api->get('/users/{id}', function (): Response {
            return Response::json([]);
        })->name('users.show');

        $route = $this->findRoute($api, 'GET', '/users/{id}');
        $this->assertNotNull($route);
        $this->assertSame('users.show', $route['name']);
    }

    public function testNamedRouteUrlGeneration(): void
    {
        $api = new PHAPI();
        $api->get('/users/{id}', function (): Response {
            return Response::json([]);
        })->name('users.show');

        $url = $api->url('users.show', ['id' => '42']);
        $this->assertSame('/users/42', $url);
    }

    public function testMiddlewareAsString(): void
    {
        $api = new PHAPI();
        $api->get('/admin', function (): Response {
            return Response::json([]);
        })->middleware('role:admin');

        $route = $this->findRoute($api, 'GET', '/admin');
        $this->assertNotNull($route);
        $this->assertNotEmpty($route['middleware']);

        $mw = $route['middleware'][0];
        $this->assertSame('named', $mw['type']);
        $this->assertSame('role', $mw['name']);
        $this->assertSame(['admin'], $mw['args']);
    }

    public function testMiddlewareAsCallable(): void
    {
        $api = new PHAPI();
        $middleware = function (Request $request, callable $next): Response {
            return $next($request);
        };

        $api->get('/test', function (): Response {
            return Response::json([]);
        })->middleware($middleware);

        $route = $this->findRoute($api, 'GET', '/test');
        $this->assertNotNull($route);
        $this->assertNotEmpty($route['middleware']);
        $this->assertSame('inline', $route['middleware'][0]['type']);
    }

    public function testHostConstraint(): void
    {
        $api = new PHAPI();
        $api->get('/api/status', function (): Response {
            return Response::json([]);
        })->host('api.example.com');

        $route = $this->findRoute($api, 'GET', '/api/status');
        $this->assertNotNull($route);
        $this->assertSame('api.example.com', $route['host']);
    }

    public function testHostConstraintWithArray(): void
    {
        $api = new PHAPI();
        $api->get('/shared', function (): Response {
            return Response::json([]);
        })->host(['a.example.com', 'b.example.com']);

        $route = $this->findRoute($api, 'GET', '/shared');
        $this->assertNotNull($route);
        $this->assertSame(['a.example.com', 'b.example.com'], $route['host']);
    }

    public function testChainingMultipleMethods(): void
    {
        $api = new PHAPI();
        $api->post('/items', function (): Response {
            return Response::json([]);
        })
            ->validate(['name' => 'required|string'])
            ->name('items.create')
            ->middleware('auth');

        $route = $this->findRoute($api, 'POST', '/items');
        $this->assertNotNull($route);
        $this->assertSame(['name' => 'required|string'], $route['validation']);
        $this->assertSame('items.create', $route['name']);
        $this->assertNotEmpty($route['middleware']);
    }

    public function testFluentGetRegistersNewRoute(): void
    {
        $api = new PHAPI();
        $api->get('/first', fn (): Response => Response::json([]))
            ->get('/second', fn (): Response => Response::json([]));

        $this->assertNotNull($this->findRoute($api, 'GET', '/first'));
        $this->assertNotNull($this->findRoute($api, 'GET', '/second'));
    }

    public function testFluentPostRegistersNewRoute(): void
    {
        $api = new PHAPI();
        $api->get('/list', fn (): Response => Response::json([]))
            ->post('/create', fn (): Response => Response::json([]));

        $this->assertNotNull($this->findRoute($api, 'GET', '/list'));
        $this->assertNotNull($this->findRoute($api, 'POST', '/create'));
    }

    public function testFluentPutRegistersNewRoute(): void
    {
        $api = new PHAPI();
        $api->get('/item', fn (): Response => Response::json([]))
            ->put('/item', fn (): Response => Response::json([]));

        $this->assertNotNull($this->findRoute($api, 'GET', '/item'));
        $this->assertNotNull($this->findRoute($api, 'PUT', '/item'));
    }

    public function testFluentPatchRegistersNewRoute(): void
    {
        $api = new PHAPI();
        $api->get('/item', fn (): Response => Response::json([]))
            ->patch('/item', fn (): Response => Response::json([]));

        $this->assertNotNull($this->findRoute($api, 'PATCH', '/item'));
    }

    public function testFluentDeleteRegistersNewRoute(): void
    {
        $api = new PHAPI();
        $api->get('/item', fn (): Response => Response::json([]))
            ->delete('/item', fn (): Response => Response::json([]));

        $this->assertNotNull($this->findRoute($api, 'DELETE', '/item'));
    }

    public function testMiddlewareWithMultiplePipeArgs(): void
    {
        $api = new PHAPI();
        $api->get('/admin', function (): Response {
            return Response::json([]);
        })->middleware('role_all:admin|editor|viewer');

        $route = $this->findRoute($api, 'GET', '/admin');
        $this->assertNotNull($route);

        $mw = $route['middleware'][0];
        $this->assertSame('role_all', $mw['name']);
        $this->assertSame(['admin', 'editor', 'viewer'], $mw['args']);
    }
}
