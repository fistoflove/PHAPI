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

    public function testCandidateIndexingPreservesRegistrationPrecedence(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/{slug}', fn () => 'wild');
        $router->addRoute('GET', '/users', fn () => 'static');

        $match = $router->match('GET', '/users', null);

        $this->assertNotNull($match['route']);
        $this->assertSame('/{slug}', $match['route']['path']);
    }

    public function testCandidateIndexingStillAllowsWildcardMethodTracking(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/{slug}', fn () => null);
        $router->addRoute('POST', '/users/{id}', fn () => null);

        $match = $router->match('PUT', '/users/123', null);

        $this->assertNull($match['route']);
        $this->assertSame(['POST'], $match['allowed']);
    }
}
