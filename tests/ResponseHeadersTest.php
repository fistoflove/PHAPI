<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHPUnit\Framework\TestCase;

final class ResponseHeadersTest extends TestCase
{
    public function testWithAddedHeaderPreservesMultipleSetCookieValues(): void
    {
        $response = Response::text('ok')
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/');

        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $response->headerValues('Set-Cookie'));
    }

    public function testWithHeaderReplacesHeaderIgnoringCase(): void
    {
        $response = Response::text('ok')
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('set-cookie', 'b=2; Path=/')
            ->withHeader('SET-COOKIE', 'c=3; Path=/');

        $this->assertSame(['c=3; Path=/'], $response->headerValues('set-cookie'));
    }
}
