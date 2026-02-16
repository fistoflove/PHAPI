<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHAPI\Server\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterMatchTest extends TestCase
{
    private static function buildRouter(): Router
    {
        $handler = static fn (): Response => Response::json([]);
        $router = new Router();
        $router->addRoute('GET', '/users', $handler);
        $router->addRoute('POST', '/users', $handler);
        $router->addRoute('GET', '/users/{id}', $handler);
        $router->addRoute('PUT', '/users/{id}', $handler);
        $router->addRoute('GET', '/search/{query?}', $handler);
        $router->addRoute('GET', '/files/{path+}', $handler);
        return $router;
    }

    /**
     * @return iterable<string, array{string, string, bool, array<string, string>}>
     */
    public static function matchProvider(): iterable
    {
        // [method, path, shouldMatch, expectedParams]
        yield 'static exact' => ['GET', '/users', true, []];
        yield 'static POST' => ['POST', '/users', true, []];
        yield 'single param' => ['GET', '/users/42', true, ['id' => '42']];
        yield 'param with PUT' => ['PUT', '/users/99', true, ['id' => '99']];
        yield 'optional param present' => ['GET', '/search/php', true, ['query' => 'php']];
        yield 'optional param absent' => ['GET', '/search', true, []];
        yield 'no match path' => ['GET', '/posts', false, []];
        yield 'no match deep' => ['GET', '/users/42/posts', false, []];
    }

    #[DataProvider('matchProvider')]
    public function testRouteMatching(string $method, string $path, bool $shouldMatch, array $expectedParams): void
    {
        $router = self::buildRouter();
        $match = $router->match($method, $path, null);

        if ($shouldMatch) {
            $this->assertNotNull($match['route'], "Expected route to match: {$method} {$path}");
            foreach ($expectedParams as $key => $value) {
                $this->assertSame($value, $match['route']['matchedParams'][$key] ?? null, "Param '{$key}' mismatch");
            }
        } else {
            $this->assertNull($match['route'], "Expected no route match: {$method} {$path}");
        }
    }

    /**
     * @return iterable<string, array{string, string, array<int, string>}>
     */
    public static function methodNotAllowedProvider(): iterable
    {
        yield 'DELETE on /users' => ['DELETE', '/users', ['GET', 'POST']];
        yield 'PATCH on /users/1' => ['PATCH', '/users/1', ['GET', 'PUT']];
        yield 'POST on /search' => ['POST', '/search', ['GET']];
    }

    #[DataProvider('methodNotAllowedProvider')]
    public function testMethodNotAllowed(string $method, string $path, array $expectedAllowed): void
    {
        $router = self::buildRouter();
        $match = $router->match($method, $path, null);

        $this->assertNull($match['route']);
        $allowed = $match['allowed'];
        sort($allowed);
        sort($expectedAllowed);
        $this->assertSame($expectedAllowed, $allowed);
    }

    public function testUrlForUnknownRouteThrows(): void
    {
        $router = new Router();
        $this->expectException(\RuntimeException::class);
        $router->urlFor('nonexistent');
    }
}
