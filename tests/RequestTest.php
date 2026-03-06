<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testMethodIsUppercased(): void
    {
        $request = new Request('get', '/');
        $this->assertSame('GET', $request->method());
    }

    public function testPath(): void
    {
        $request = new Request('GET', '/users/42');
        $this->assertSame('/users/42', $request->path());
    }

    public function testQueryReturnsValueOrDefault(): void
    {
        $request = new Request('GET', '/', ['page' => '2', 'sort' => 'name']);

        $this->assertSame('2', $request->query('page'));
        $this->assertSame('name', $request->query('sort'));
        $this->assertNull($request->query('missing'));
        $this->assertSame('fallback', $request->query('missing', 'fallback'));
    }

    public function testQueryAll(): void
    {
        $query = ['page' => '1', 'limit' => '10'];
        $request = new Request('GET', '/', $query);
        $this->assertSame($query, $request->queryAll());
    }

    public function testHeaderIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', [], ['Content-Type' => 'application/json', 'X-Custom' => 'value']);

        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('application/json', $request->header('Content-Type'));
        $this->assertSame('value', $request->header('x-custom'));
        $this->assertNull($request->header('missing'));
        $this->assertSame('default', $request->header('missing', 'default'));
    }

    public function testHeaders(): void
    {
        $request = new Request('GET', '/', [], ['Accept' => 'text/html']);
        $headers = $request->headers();

        $this->assertArrayHasKey('accept', $headers);
        $this->assertSame('text/html', $headers['accept']);
    }

    public function testHostFromHeader(): void
    {
        $request = new Request('GET', '/', [], ['Host' => 'example.com']);
        $this->assertSame('example.com', $request->host());
    }

    public function testHostFromHttpHostServer(): void
    {
        $request = new Request('GET', '/', [], [], [], null, ['HTTP_HOST' => 'server.example.com']);
        $this->assertSame('server.example.com', $request->host());
    }

    public function testHostFromServerName(): void
    {
        $request = new Request('GET', '/', [], [], [], null, ['SERVER_NAME' => 'fallback.example.com']);
        $this->assertSame('fallback.example.com', $request->host());
    }

    public function testHostReturnsNullWhenMissing(): void
    {
        $request = new Request('GET', '/');
        $this->assertNull($request->host());
    }

    public function testHostPrefersHeaderOverServer(): void
    {
        $request = new Request('GET', '/', [], ['Host' => 'from-header.com'], [], null, ['HTTP_HOST' => 'from-server.com']);
        $this->assertSame('from-header.com', $request->host());
    }

    public function testCookieReturnsValueOrDefault(): void
    {
        $request = new Request('GET', '/', [], [], ['session' => 'abc123']);

        $this->assertSame('abc123', $request->cookie('session'));
        $this->assertNull($request->cookie('missing'));
        $this->assertSame('default', $request->cookie('missing', 'default'));
    }

    public function testCookies(): void
    {
        $cookies = ['a' => '1', 'b' => '2'];
        $request = new Request('GET', '/', [], [], $cookies);
        $this->assertSame($cookies, $request->cookies());
    }

    public function testBody(): void
    {
        $body = ['name' => 'John'];
        $request = new Request('POST', '/', [], [], [], $body);
        $this->assertSame($body, $request->body());
    }

    public function testBodyNull(): void
    {
        $request = new Request('GET', '/');
        $this->assertNull($request->body());
    }

    public function testParamAndParams(): void
    {
        $request = new Request('GET', '/users/42');
        $withParams = $request->withParams(['id' => '42', 'slug' => 'john']);

        $this->assertSame('42', $withParams->param('id'));
        $this->assertSame('john', $withParams->param('slug'));
        $this->assertNull($withParams->param('missing'));
        $this->assertSame('default', $withParams->param('missing', 'default'));
        $this->assertSame(['id' => '42', 'slug' => 'john'], $withParams->params());
    }

    public function testWithParamsReturnsClone(): void
    {
        $original = new Request('GET', '/');
        $clone = $original->withParams(['id' => '1']);

        $this->assertSame([], $original->params());
        $this->assertSame(['id' => '1'], $clone->params());
        $this->assertNotSame($original, $clone);
    }

    public function testServer(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1'];
        $request = new Request('GET', '/', [], [], [], null, $server);
        $this->assertSame($server, $request->server());
    }

    public function testContentLengthFromHeader(): void
    {
        $request = new Request('POST', '/', [], ['Content-Length' => '1024']);
        $this->assertSame(1024, $request->contentLength());
    }

    public function testContentLengthFromServer(): void
    {
        $request = new Request('POST', '/', [], [], [], null, ['CONTENT_LENGTH' => '512']);
        $this->assertSame(512, $request->contentLength());
    }

    public function testContentLengthPrefersHeaderOverServer(): void
    {
        $request = new Request('POST', '/', [], ['Content-Length' => '100'], [], null, ['CONTENT_LENGTH' => '200']);
        $this->assertSame(100, $request->contentLength());
    }

    public function testContentLengthReturnsNullWhenMissing(): void
    {
        $request = new Request('GET', '/');
        $this->assertNull($request->contentLength());
    }

    public function testContentLengthReturnsNullForNonNumeric(): void
    {
        $request = new Request('POST', '/', [], ['Content-Length' => 'invalid']);
        $this->assertNull($request->contentLength());
    }

    public function testEmptyQueryAndHeaders(): void
    {
        $request = new Request('DELETE', '/resource');

        $this->assertSame([], $request->queryAll());
        $this->assertSame([], $request->headers());
        $this->assertSame([], $request->cookies());
        $this->assertSame([], $request->params());
        $this->assertSame([], $request->server());
    }
}
