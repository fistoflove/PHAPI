<?php

namespace PHAPI\Tests;

use PHAPI\Exceptions\HttpRequestException;
use PHAPI\Services\DefaultHttpClient;
use PHAPI\Services\SwooleHttpClient;

final class SwooleHttpClientTest extends SwooleTestCase
{
    public function testGetJsonWithMetaStartsCoroutineWhenNeeded(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->getJsonWithMeta('not-a-url');
    }

    public function testPostFormWithMetaStartsCoroutineWhenNeeded(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postFormWithMeta('not-a-url', ['a' => 'b']);
    }

    public function testGetJsonWithMetaAcceptsCustomHeaders(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->getJsonWithMeta('not-a-url', ['Authorization' => 'Bearer token123']);
    }

    public function testGetJsonAcceptsCustomHeaders(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->getJson('not-a-url', ['X-Custom' => 'value']);
    }

    public function testPostFormWithMetaAcceptsCustomHeaders(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postFormWithMeta('not-a-url', ['a' => 'b'], ['Authorization' => 'Bearer xyz']);
    }

    public function testPostJsonWithMetaThrowsOnInvalidUrl(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postJsonWithMeta('not-a-url', ['key' => 'value']);
    }

    public function testPostJsonThrowsOnInvalidUrl(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postJson('not-a-url', ['key' => 'value']);
    }

    public function testPostJsonWithMetaAcceptsCustomHeaders(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postJsonWithMeta('not-a-url', ['key' => 'value'], ['X-Api-Key' => 'secret']);
    }

    public function testPostJsonAcceptsCustomHeaders(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->postJson('not-a-url', ['key' => 'value'], ['Authorization' => 'Bearer abc']);
    }

    public function testDefaultHttpClientInheritsNewMethods(): void
    {
        $client = new DefaultHttpClient();

        $this->assertInstanceOf(SwooleHttpClient::class, $client);
        $this->assertTrue(method_exists($client, 'postJson'));
        $this->assertTrue(method_exists($client, 'postJsonWithMeta'));
    }

    public function testExistingCallsWithoutHeadersStillWork(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $client = new SwooleHttpClient();

        // Verify backward compatibility: calling without headers param still works
        try {
            $client->getJsonWithMeta('not-a-url');
            $this->fail('Expected HttpRequestException');
        } catch (HttpRequestException $e) {
            $this->assertSame('Invalid URL', $e->getMessage());
        }

        try {
            $client->postFormWithMeta('not-a-url', ['a' => 'b']);
            $this->fail('Expected HttpRequestException');
        } catch (HttpRequestException $e) {
            $this->assertSame('Invalid URL', $e->getMessage());
        }
    }
}
