<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHAPI\Runtime\SwooleDriver;
use PHPUnit\Framework\TestCase;

final class SwooleDriverEmitTest extends TestCase
{
    public function testEmitPreservesMultipleSetCookieHeaders(): void
    {
        $response = Response::text('ok')
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/')
            ->withAddedHeader('X-Multi', 'one')
            ->withAddedHeader('X-Multi', 'two')
            ->withHeader('X-Test', 'yes');

        $driver = new SwooleDriver();
        $sink = new class () {
            public int $status = 0;
            /** @var array<int, array{name: string, value: string, replace: bool}> */
            public array $headers = [];
            public string $body = '';

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
            }
        };

        $method = new \ReflectionMethod($driver, 'emit');
        $method->setAccessible(true);
        $method->invoke($driver, $sink, $response);

        $setCookieHeaders = array_values(array_filter(
            $sink->headers,
            static fn (array $header): bool => strtolower($header['name']) === 'set-cookie'
        ));

        $this->assertCount(2, $setCookieHeaders);
        $this->assertFalse($setCookieHeaders[0]['replace']);
        $this->assertFalse($setCookieHeaders[1]['replace']);

        $multiHeaders = array_values(array_filter(
            $sink->headers,
            static fn (array $header): bool => strtolower($header['name']) === 'x-multi'
        ));

        $this->assertCount(2, $multiHeaders);
        $this->assertFalse($multiHeaders[0]['replace']);
        $this->assertFalse($multiHeaders[1]['replace']);
        $this->assertSame('ok', $sink->body);
    }
}
