<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Auth\TokenGuard;
use PHAPI\Core\Container;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\RequestContext;
use PHAPI\HTTP\Response;
use PHAPI\Server\ErrorHandler;
use PHAPI\Server\HttpKernel;
use PHAPI\Server\MiddlewareManager;
use PHAPI\Server\Router;
use PHAPI\Services\SwooleTaskRunner;

final class ConcurrencyTest extends SwooleTestCase
{
    // --- 5a. RequestContext coroutine isolation ---

    public function testRequestContextIsolationAcrossCoroutines(): void
    {
        $count = 10;
        $results = [];

        \Swoole\Coroutine\run(function () use ($count, &$results): void {
            $wg = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < $count; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($i, &$results, $wg): void {
                    $req = new Request('GET', "/path-{$i}");
                    RequestContext::set($req);
                    \Swoole\Coroutine::sleep(0.001); // yield to other coroutines
                    $results[$i] = RequestContext::get()?->path();
                    RequestContext::clear();
                    $wg->done();
                });
            }

            $wg->wait();
        });

        for ($i = 0; $i < $count; $i++) {
            $this->assertSame("/path-{$i}", $results[$i], "Coroutine {$i} saw wrong request");
        }
    }

    // --- 5b. TokenGuard per-coroutine user resolution ---

    public function testTokenGuardIsolationAcrossCoroutines(): void
    {
        $resolver = fn (string $token): array => ['id' => $token, 'name' => "user-{$token}"];
        $guard = new TokenGuard($resolver);

        $results = [];

        \Swoole\Coroutine\run(function () use ($guard, &$results): void {
            $wg = new \Swoole\Coroutine\WaitGroup();

            foreach (['alpha', 'beta', 'gamma'] as $token) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($guard, $token, &$results, $wg): void {
                    $req = new Request('GET', '/', [], ['authorization' => "Bearer {$token}"]);
                    RequestContext::set($req);
                    \Swoole\Coroutine::sleep(0.001);
                    $results[$token] = $guard->id();
                    RequestContext::clear();
                    $wg->done();
                });
            }

            $wg->wait();
        });

        $this->assertSame('alpha', $results['alpha']);
        $this->assertSame('beta', $results['beta']);
        $this->assertSame('gamma', $results['gamma']);
    }

    // --- 5c. SwooleTaskRunner::parallel result ordering ---

    public function testParallelResultOrderingWithRandomDelays(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $results = null;

        \Swoole\Coroutine\run(function () use ($runner, &$results): void {
            $results = $runner->parallel([
                'fast' => static function (): string {
                    \Swoole\Coroutine::sleep(0.001);
                    return 'fast-done';
                },
                'slow' => static function (): string {
                    \Swoole\Coroutine::sleep(0.01);
                    return 'slow-done';
                },
                'medium' => static function (): string {
                    \Swoole\Coroutine::sleep(0.005);
                    return 'medium-done';
                },
            ]);
        });

        $this->assertIsArray($results);
        $this->assertSame('fast-done', $results['fast']);
        $this->assertSame('slow-done', $results['slow']);
        $this->assertSame('medium-done', $results['medium']);
    }

    // --- 5d. HttpKernel concurrent request handling ---

    public function testHttpKernelConcurrentRequestsDoNotLeak(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/echo/{id}', static function () use ($router): Response {
            $req = RequestContext::get();
            \Swoole\Coroutine::sleep(0.001);
            $afterYield = RequestContext::get();
            return Response::json([
                'id' => $req?->param('id'),
                'id_after_yield' => $afterYield?->param('id'),
            ]);
        });

        $kernel = new HttpKernel($router, new MiddlewareManager(), new ErrorHandler(false), new Container());
        $results = [];

        \Swoole\Coroutine\run(function () use ($kernel, &$results): void {
            $wg = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < 5; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($kernel, $i, &$results, $wg): void {
                    $response = $kernel->handle(new Request('GET', "/echo/{$i}"));
                    $results[$i] = json_decode($response->body(), true);
                    $wg->done();
                });
            }

            $wg->wait();
        });

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame((string) $i, $results[$i]['id'], "Request {$i} got wrong id");
            $this->assertSame((string) $i, $results[$i]['id_after_yield'], "Request {$i} context leaked after yield");
        }
    }
}
