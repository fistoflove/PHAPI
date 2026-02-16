<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Runtime\SwooleDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SwooleDriver::buildRequest(), parseBody(), and emit() —
 * the Swoole↔PHAPI translation boundary that every request passes through.
 */
final class SwooleDriverTranslationTest extends TestCase
{
    private SwooleDriver $driver;
    private \ReflectionMethod $buildRequest;
    private \ReflectionMethod $parseBody;
    private \ReflectionMethod $emit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new SwooleDriver();
        $this->buildRequest = new \ReflectionMethod($this->driver, 'buildRequest');
        $this->buildRequest->setAccessible(true);
        $this->parseBody = new \ReflectionMethod($this->driver, 'parseBody');
        $this->parseBody->setAccessible(true);
        $this->emit = new \ReflectionMethod($this->driver, 'emit');
        $this->emit->setAccessible(true);
    }

    private function fakeSwooleRequest(array $server = [], ?array $get = null, ?array $header = null, ?array $cookie = null, string $rawContent = ''): object
    {
        return new class ($server, $get, $header, $cookie, $rawContent) {
            public ?array $server;
            public ?array $get;
            public ?array $header;
            public ?array $cookie;
            private string $raw;

            public function __construct(?array $server, ?array $get, ?array $header, ?array $cookie, string $raw)
            {
                $this->server = $server;
                $this->get = $get;
                $this->header = $header;
                $this->cookie = $cookie;
                $this->raw = $raw;
            }

            public function rawContent(): string
            {
                return $this->raw;
            }
        };
    }

    private function fakeSwooleResponse(): object
    {
        return new class () {
            public int $status = 0;
            /** @var array<int, array{name: string, value: string, replace: bool}> */
            public array $headers = [];
            public string $body = '';
            public bool $ended = false;

            public function status(int $status): void
            {
                $this->status = $status;
            }

            public function header(string $name, string $value, bool $replace = true): void
            {
                $this->headers[] = ['name' => $name, 'value' => $value, 'replace' => $replace];
            }

            public function write(string $chunk): void
            {
                $this->body .= $chunk;
            }

            public function end(string $body = ''): void
            {
                $this->body .= $body;
                $this->ended = true;
            }
        };
    }

    // --- 1a. buildRequest — malformed URI handling ---

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function uriProvider(): iterable
    {
        yield 'normal path' => [['request_method' => 'GET', 'request_uri' => '/users/42'], '/users/42'];
        yield 'root' => [['request_method' => 'GET', 'request_uri' => '/'], '/'];
        yield 'with query string' => [['request_method' => 'GET', 'request_uri' => '/search?q=php'], '/search'];
        yield 'with fragment' => [['request_method' => 'GET', 'request_uri' => '/page#section'], '/page'];
        yield 'encoded characters' => [['request_method' => 'GET', 'request_uri' => '/users/hello%20world'], '/users/hello%20world'];
        yield 'triple slash normalizes' => [['request_method' => 'GET', 'request_uri' => '///'], '/'];
        yield 'missing uri defaults to /' => [['request_method' => 'GET'], '/'];
    }

    #[DataProvider('uriProvider')]
    public function testBuildRequestUriParsing(array $server, string $expectedPath): void
    {
        $swooleReq = $this->fakeSwooleRequest($server);
        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertSame($expectedPath, $request->path());
    }

    // --- 1b. buildRequest — missing Swoole request fields ---

    public function testBuildRequestWithBareMinimumFields(): void
    {
        $swooleReq = $this->fakeSwooleRequest(
            server: ['request_method' => 'POST', 'request_uri' => '/api'],
            get: null,
            header: null,
            cookie: null,
            rawContent: ''
        );

        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertSame('POST', $request->method());
        $this->assertSame('/api', $request->path());
        $this->assertNull($request->query('anything'));
        $this->assertNull($request->header('anything'));
        $this->assertNull($request->cookie('anything'));
    }

    public function testBuildRequestPreservesQueryParams(): void
    {
        $swooleReq = $this->fakeSwooleRequest(
            server: ['request_method' => 'GET', 'request_uri' => '/search'],
            get: ['q' => 'swoole', 'page' => '2']
        );

        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertSame('swoole', $request->query('q'));
        $this->assertSame('2', $request->query('page'));
    }

    public function testBuildRequestPreservesHeaders(): void
    {
        $swooleReq = $this->fakeSwooleRequest(
            server: ['request_method' => 'GET', 'request_uri' => '/'],
            header: ['content-type' => 'application/json', 'authorization' => 'Bearer tok']
        );

        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('Bearer tok', $request->header('authorization'));
    }

    public function testBuildRequestPreservesCookies(): void
    {
        $swooleReq = $this->fakeSwooleRequest(
            server: ['request_method' => 'GET', 'request_uri' => '/'],
            cookie: ['session_id' => 'abc123']
        );

        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertSame('abc123', $request->cookie('session_id'));
    }

    public function testBuildRequestSetsTimestamps(): void
    {
        $swooleReq = $this->fakeSwooleRequest(
            server: ['request_method' => 'GET', 'request_uri' => '/']
        );

        /** @var Request $request */
        $request = $this->buildRequest->invoke($this->driver, $swooleReq);

        $this->assertNotNull($request->server('REQUEST_TIME_FLOAT'));
        $this->assertNotNull($request->server('REQUEST_TIME'));
    }

    // --- 1c. parseBody — content type edge cases ---

    /**
     * @return iterable<string, array{string, array<string, string>, string, mixed}>
     */
    public static function parseBodyProvider(): iterable
    {
        yield 'json valid' => ['POST', ['content-type' => 'application/json'], '{"key":"val"}', ['key' => 'val']];
        yield 'json with charset' => ['POST', ['content-type' => 'application/json; charset=utf-8'], '{"a":1}', ['a' => 1]];
        yield 'json invalid' => ['POST', ['content-type' => 'application/json'], '{bad json', null];
        yield 'json empty body' => ['POST', ['content-type' => 'application/json'], '', null];
        yield 'form urlencoded' => ['POST', ['content-type' => 'application/x-www-form-urlencoded'], 'name=Jo&age=30', ['name' => 'Jo', 'age' => '30']];
        yield 'form empty body' => ['POST', ['content-type' => 'application/x-www-form-urlencoded'], '', null];
        yield 'unknown content type returns raw' => ['POST', ['content-type' => 'text/plain'], 'raw body', 'raw body'];
        yield 'no content type returns raw' => ['POST', [], 'some data', 'some data'];
        yield 'GET ignores body' => ['GET', ['content-type' => 'application/json'], '{"ignored":true}', null];
        yield 'HEAD ignores body' => ['HEAD', ['content-type' => 'application/json'], '{"ignored":true}', null];
        yield 'PUT with json' => ['PUT', ['content-type' => 'application/json'], '{"update":true}', ['update' => true]];
        yield 'PATCH with json' => ['PATCH', ['content-type' => 'application/json'], '{"patch":1}', ['patch' => 1]];
        yield 'DELETE with empty body' => ['DELETE', [], '', null];
    }

    #[DataProvider('parseBodyProvider')]
    public function testParseBody(string $method, array $headers, string $raw, mixed $expected): void
    {
        $result = $this->parseBody->invoke($this->driver, $method, $headers, $raw);
        $this->assertSame($expected, $result);
    }

    // --- 1d. emit — streaming responses ---

    public function testEmitStreamWithIterableCallback(): void
    {
        $response = Response::stream(function () {
            return (function () {
                yield 'chunk1';
                yield 'chunk2';
                yield 'chunk3';
            })();
        });

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $this->assertSame('chunk1chunk2chunk3', $sink->body);
        $this->assertTrue($sink->ended);
    }

    public function testEmitStreamWithStringCallback(): void
    {
        $response = Response::stream(fn () => 'full-string-body');

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $this->assertSame('full-string-body', $sink->body);
        $this->assertTrue($sink->ended);
    }

    public function testEmitStreamWithNullCallback(): void
    {
        $response = Response::stream(fn () => null);

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $this->assertSame('', $sink->body);
        $this->assertTrue($sink->ended);
    }

    // --- 1e. emit — multi-value headers ---

    public function testEmitSetCookieHeadersNotReplaced(): void
    {
        $response = Response::text('ok')
            ->withAddedHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2');

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $cookies = array_values(array_filter(
            $sink->headers,
            static fn (array $h): bool => strtolower($h['name']) === 'set-cookie'
        ));

        $this->assertCount(2, $cookies);
        $this->assertSame('a=1', $cookies[0]['value']);
        $this->assertSame('b=2', $cookies[1]['value']);
        // Set-Cookie must never replace
        $this->assertFalse($cookies[0]['replace']);
        $this->assertFalse($cookies[1]['replace']);
    }

    public function testEmitSingleValueHeaderReplaces(): void
    {
        $response = Response::text('ok')
            ->withHeader('X-Custom', 'value');

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $custom = array_values(array_filter(
            $sink->headers,
            static fn (array $h): bool => strtolower($h['name']) === 'x-custom'
        ));

        $this->assertCount(1, $custom);
        $this->assertTrue($custom[0]['replace']);
    }

    public function testEmitSetsStatusCode(): void
    {
        $response = Response::json(['ok' => true], 201);

        $sink = $this->fakeSwooleResponse();
        $this->emit->invoke($this->driver, $sink, $response);

        $this->assertSame(201, $sink->status);
        $this->assertTrue($sink->ended);
    }
}
