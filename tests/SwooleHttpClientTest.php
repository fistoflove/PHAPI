<?php

namespace PHAPI\Tests;

use PHAPI\Exceptions\HttpRequestException;
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
}
