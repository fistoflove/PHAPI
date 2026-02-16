<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Server\CORSHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CORSHandlerTest extends TestCase
{
    /**
     * Fake Swoole request with server and header arrays.
     */
    private static function fakeRequest(string $method, ?string $origin = null): object
    {
        return new class ($method, $origin) {
            /** @var array<string, string> */
            public array $server;
            /** @var array<string, string> */
            public array $header;

            public function __construct(string $method, ?string $origin)
            {
                $this->server = ['request_method' => $method];
                $this->header = $origin !== null ? ['origin' => $origin] : [];
            }
        };
    }

    /**
     * Fake Swoole response that captures headers.
     */
    private static function fakeResponse(): object
    {
        return new class () {
            /** @var array<string, string> */
            public array $headers = [];

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
    }

    // --- Basic state ---

    public function testNotEnabledByDefault(): void
    {
        $cors = new CORSHandler();
        $this->assertFalse($cors->isEnabled());
        $this->assertNull($cors->getConfig());
    }

    public function testEnableSetsCorsEnabled(): void
    {
        $cors = new CORSHandler();
        $cors->enable();
        $this->assertTrue($cors->isEnabled());
        $this->assertNotNull($cors->getConfig());
    }

    // --- Origin determination via data provider ---

    /**
     * @return iterable<string, array{string|array<int,string>|null, bool, string|null, string|null}>
     */
    public static function originProvider(): iterable
    {
        // [origins, credentials, requestOrigin, expectedAllowOrigin]
        yield 'wildcard no credentials' => ['*', false, 'https://app.com', '*'];
        yield 'wildcard with credentials reflects origin' => ['*', true, 'https://app.com', 'https://app.com'];
        yield 'wildcard with credentials no origin' => ['*', true, null, '*'];
        yield 'specific origin match' => [['https://app.com'], false, 'https://app.com', 'https://app.com'];
        yield 'specific origin no match' => [['https://app.com'], false, 'https://evil.com', null];
        yield 'specific origin no header' => [['https://app.com'], false, null, null];
        yield 'multiple origins match second' => [['https://a.com', 'https://b.com'], false, 'https://b.com', 'https://b.com'];
        yield 'string origin' => ['https://single.com', false, 'https://any.com', 'https://single.com'];
    }

    #[DataProvider('originProvider')]
    public function testOriginDetermination(
        string|array|null $origins,
        bool $credentials,
        ?string $requestOrigin,
        ?string $expectedAllowOrigin
    ): void {
        $cors = new CORSHandler();
        $cors->enable($origins, credentials: $credentials);

        $request = self::fakeRequest('GET', $requestOrigin);
        $response = self::fakeResponse();

        $cors->addHeaders($request, $response);

        if ($expectedAllowOrigin === null) {
            $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        } else {
            $this->assertSame($expectedAllowOrigin, $response->headers['Access-Control-Allow-Origin'] ?? null);
        }
    }

    // --- Preflight ---

    public function testPreflightHandledForOptions(): void
    {
        $cors = new CORSHandler();
        $cors->enable('*');

        $request = self::fakeRequest('OPTIONS', 'https://app.com');
        $response = self::fakeResponse();

        $this->assertTrue($cors->handlePreflight($request, $response));
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $response->headers);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $response->headers);
        $this->assertArrayHasKey('Access-Control-Max-Age', $response->headers);
    }

    public function testPreflightNotHandledForGet(): void
    {
        $cors = new CORSHandler();
        $cors->enable('*');

        $request = self::fakeRequest('GET', 'https://app.com');
        $response = self::fakeResponse();

        $this->assertFalse($cors->handlePreflight($request, $response));
    }

    public function testPreflightNotHandledWhenDisabled(): void
    {
        $cors = new CORSHandler();

        $request = self::fakeRequest('OPTIONS', 'https://app.com');
        $response = self::fakeResponse();

        $this->assertFalse($cors->handlePreflight($request, $response));
    }

    // --- Headers content ---

    public function testCredentialsHeaderIncludedWhenEnabled(): void
    {
        $cors = new CORSHandler();
        $cors->enable(['https://app.com'], credentials: true);

        $request = self::fakeRequest('GET', 'https://app.com');
        $response = self::fakeResponse();
        $cors->addHeaders($request, $response);

        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials'] ?? null);
    }

    public function testCredentialsHeaderAbsentWhenDisabled(): void
    {
        $cors = new CORSHandler();
        $cors->enable(['https://app.com'], credentials: false);

        $request = self::fakeRequest('GET', 'https://app.com');
        $response = self::fakeResponse();
        $cors->addHeaders($request, $response);

        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testCustomMethodsAndHeaders(): void
    {
        $cors = new CORSHandler();
        $cors->enable('*', methods: ['GET', 'PATCH'], headers: ['Authorization', 'X-Custom'], maxAge: 600);

        $request = self::fakeRequest('GET', 'https://app.com');
        $response = self::fakeResponse();
        $cors->addHeaders($request, $response);

        $this->assertSame('GET, PATCH', $response->headers['Access-Control-Allow-Methods']);
        $this->assertSame('Authorization, X-Custom', $response->headers['Access-Control-Allow-Headers']);
        $this->assertSame('600', $response->headers['Access-Control-Max-Age']);
    }

    public function testAddHeadersNoOpWhenDisabled(): void
    {
        $cors = new CORSHandler();
        $response = self::fakeResponse();
        $cors->addHeaders(self::fakeRequest('GET', 'https://app.com'), $response);

        $this->assertEmpty($response->headers);
    }
}
