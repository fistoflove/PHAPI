<?php

namespace PHAPI\Tests;

use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testNamedRouteUrlGeneration(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn () => null, [], null, 'body', 'users.show');

        $url = $router->urlFor('users.show', ['id' => 10], ['tab' => 'profile']);
        $this->assertSame('/users/10?tab=profile', $url);
    }

    public function testOptionalParams(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/search/{query?}', fn () => null);

        $match = $router->match('GET', '/search', null);
        $this->assertNotNull($match['route']);

        $match = $router->match('GET', '/search/php', null);
        $this->assertSame('php', $match['route']['matchedParams']['query']);
    }

    public function testMethodNotAllowed(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn () => null);

        $match = $router->match('POST', '/users/1', null);
        $this->assertNull($match['route']);
        $this->assertSame(['GET'], $match['allowed']);
    }
}
