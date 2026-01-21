<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\PHAPI;
use PHAPI\Server\Router;
use PHPUnit\Framework\TestCase;

final class DefaultEndpointsTest extends TestCase
{
    public function testDefaultEndpointsAreRegistered(): void
    {
        $api = new PHAPI([
            'default_endpoints' => [
                'monitor' => true,
            ],
        ]);

        $router = $this->getRouter($api);
        $paths = array_map(static function (array $route): string {
            return $route['path'];
        }, $router->getRoutes());

        $this->assertContains('/monitor', $paths);
    }

    public function testDefaultEndpointsCanBeDisabled(): void
    {
        $api = new PHAPI([
            'default_endpoints' => false,
        ]);

        $router = $this->getRouter($api);
        $paths = array_map(static function (array $route): string {
            return $route['path'];
        }, $router->getRoutes());

        $this->assertNotContains('/health', $paths);
        $this->assertNotContains('/monitor', $paths);
    }

    private function getRouter(PHAPI $api): Router
    {
        $ref = new \ReflectionProperty(PHAPI::class, 'router');
        $ref->setAccessible(true);
        /** @var Router $router */
        $router = $ref->getValue($api);
        return $router;
    }
}
