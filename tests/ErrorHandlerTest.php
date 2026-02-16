<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Exceptions\MethodNotAllowedException;
use PHAPI\Exceptions\RouteNotFoundException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    // --- Custom handler ---

    public function testCustomHandlerReturningResponseShortCircuits(): void
    {
        $handler = new ErrorHandler(false);
        $custom = Response::json(['custom' => true], 418);

        $receivedException = null;
        $receivedRequest = null;

        $handler->setCustomHandler(function (\Throwable $e, Request $r) use ($custom, &$receivedException, &$receivedRequest): Response {
            $receivedException = $e;
            $receivedRequest = $r;
            return $custom;
        });

        $request = new Request('GET', '/fail');
        $exception = new \RuntimeException('boom');
        $response = $handler->handle($exception, $request);

        $this->assertSame(418, $response->status());
        $this->assertSame($exception, $receivedException);
        $this->assertSame($request, $receivedRequest);
    }

    public function testCustomHandlerReturningNonResponseFallsThrough(): void
    {
        $handler = new ErrorHandler(false);
        $handler->setCustomHandler(fn (\Throwable $e, Request $r): string => 'not a response');

        $response = $handler->handle(new \RuntimeException('boom'), new Request('GET', '/'));

        $this->assertSame(500, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Internal Server Error', $body['error']);
    }

    // --- Debug mode ---

    public function testDebugModeExposesDetailsForGenericException(): void
    {
        $handler = new ErrorHandler(true);
        $exception = new \RuntimeException('secret detail');
        $response = $handler->handle($exception, new Request('GET', '/'));

        $body = json_decode($response->body(), true);
        $this->assertSame(500, $response->status());
        $this->assertSame('secret detail', $body['detail']);
        $this->assertArrayHasKey('file', $body);
        $this->assertArrayHasKey('line', $body);
        $this->assertArrayHasKey('trace', $body);
        $this->assertIsArray($body['trace']);
    }

    public function testProductionModeHidesDetailsForGenericException(): void
    {
        $handler = new ErrorHandler(false);
        $response = $handler->handle(new \RuntimeException('secret'), new Request('GET', '/'));

        $body = json_decode($response->body(), true);
        $this->assertSame(500, $response->status());
        $this->assertSame('Internal Server Error', $body['error']);
        $this->assertArrayNotHasKey('detail', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('line', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function testDebugModeExposesDetailsForPhapiException(): void
    {
        $handler = new ErrorHandler(true);
        $response = $handler->handle(new RouteNotFoundException('/missing', 'GET'), new Request('GET', '/missing'));

        $body = json_decode($response->body(), true);
        $this->assertSame(404, $response->status());
        $this->assertArrayHasKey('detail', $body);
        $this->assertArrayHasKey('file', $body);
        $this->assertArrayHasKey('line', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function testProductionModeHidesDetailsForPhapiException(): void
    {
        $handler = new ErrorHandler(false);
        $response = $handler->handle(new RouteNotFoundException('/missing', 'GET'), new Request('GET', '/missing'));

        $body = json_decode($response->body(), true);
        $this->assertSame(404, $response->status());
        $this->assertArrayNotHasKey('detail', $body);
        $this->assertArrayNotHasKey('file', $body);
    }

    // --- Specific exception types ---

    public function testValidationExceptionIncludes422AndErrors(): void
    {
        $handler = new ErrorHandler(false);
        $exception = new ValidationException('Validation failed', ['email' => 'required']);
        $response = $handler->handle($exception, new Request('POST', '/register'));

        $body = json_decode($response->body(), true);
        $this->assertSame(422, $response->status());
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame(['email' => 'required'], $body['errors']);
    }

    public function testMethodNotAllowedIncludesAllowHeader(): void
    {
        $handler = new ErrorHandler(false);
        $exception = new MethodNotAllowedException(['GET', 'POST']);
        $response = $handler->handle($exception, new Request('DELETE', '/items'));

        $this->assertSame(405, $response->status());
        $this->assertSame('GET, POST', $response->headers()['Allow'] ?? null);

        $body = json_decode($response->body(), true);
        $this->assertSame(['GET', 'POST'], $body['allowed_methods']);
    }

    // --- setDebug toggle ---

    public function testSetDebugTogglesDetailExposure(): void
    {
        $handler = new ErrorHandler(false);
        $request = new Request('GET', '/');
        $exception = new \RuntimeException('detail');

        $prod = json_decode($handler->handle($exception, $request)->body(), true);
        $this->assertArrayNotHasKey('detail', $prod);

        $handler->setDebug(true);
        $debug = json_decode($handler->handle($exception, $request)->body(), true);
        $this->assertSame('detail', $debug['detail']);
    }
}
