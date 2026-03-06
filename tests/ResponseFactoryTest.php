<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests Response factory methods, immutability, and edge cases.
 */
final class ResponseFactoryTest extends TestCase
{
    // --- 8a. redirect ---

    public function testRedirectSetsLocationHeader(): void
    {
        $response = Response::redirect('https://example.com/login');

        $this->assertSame(302, $response->status());
        $this->assertSame('https://example.com/login', $response->headers()['Location']);
        $this->assertSame('', $response->body());
    }

    public function testRedirectWithCustomStatus(): void
    {
        $response = Response::redirect('/new-location', 301);

        $this->assertSame(301, $response->status());
        $this->assertSame('/new-location', $response->headers()['Location']);
    }

    // --- 8b. stream ---

    public function testStreamResponseIsMarkedAsStream(): void
    {
        $callback = static fn () => yield 'chunk1';
        $response = Response::stream($callback, 200, ['Content-Type' => 'text/event-stream']);

        $this->assertTrue($response->isStream());
        $this->assertNotNull($response->streamCallback());
        $this->assertSame(200, $response->status());
        $this->assertSame('text/event-stream', $response->headers()['Content-Type']);
    }

    public function testNonStreamResponseIsNotStream(): void
    {
        $response = Response::json(['ok' => true]);

        $this->assertFalse($response->isStream());
        $this->assertNull($response->streamCallback());
    }

    // --- 8c. empty ---

    public function testEmptyResponseDefaults204(): void
    {
        $response = Response::empty();

        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body());
        $this->assertEmpty($response->headers());
    }

    public function testEmptyResponseCustomStatus(): void
    {
        $response = Response::empty(201);

        $this->assertSame(201, $response->status());
        $this->assertSame('', $response->body());
    }

    // --- 8d. error ---

    public function testErrorResponseStructure(): void
    {
        $response = Response::error('Something went wrong', 500);

        $this->assertSame(500, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Something went wrong', $body['error']);
        $this->assertArrayNotHasKey('details', $body);
    }

    public function testErrorResponseWithDetails(): void
    {
        $response = Response::error('Validation failed', 422, ['fields' => ['name' => 'required']]);

        $this->assertSame(422, $response->status());
        $body = json_decode($response->body(), true);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertSame(['name' => 'required'], $body['fields']);
    }

    // --- 8e. html ---

    public function testHtmlResponse(): void
    {
        $html = '<h1>Hello</h1>';
        $response = Response::html($html);

        $this->assertSame(200, $response->status());
        $this->assertSame('text/html', $response->headers()['Content-Type']);
        $this->assertSame($html, $response->body());
    }

    public function testHtmlResponseCustomStatus(): void
    {
        $response = Response::html('<p>Not Found</p>', 404);

        $this->assertSame(404, $response->status());
    }

    // --- 8f. Immutability ---

    public function testWithHeaderReturnsNewInstance(): void
    {
        $original = Response::json(['ok' => true]);
        $modified = $original->withHeader('X-Custom', 'value');

        $this->assertNotSame($original, $modified);
        $this->assertSame(['value'], $modified->headerValues('X-Custom'));
        $this->assertSame([], $original->headerValues('X-Custom'));
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = Response::json(['ok' => true]);
        $modified = $original->withStatus(201);

        $this->assertNotSame($original, $modified);
        $this->assertSame(201, $modified->status());
        $this->assertSame(200, $original->status());
    }

    public function testWithBodyReturnsNewInstance(): void
    {
        $original = Response::text('hello');
        $modified = $original->withBody('world');

        $this->assertNotSame($original, $modified);
        $this->assertSame('world', $modified->body());
        $this->assertSame('hello', $original->body());
    }

    public function testWithAddedHeaderPreservesDuplicates(): void
    {
        $response = Response::json(['ok' => true]);
        $response = $response->withAddedHeader('X-Multi', 'a');
        $response = $response->withAddedHeader('X-Multi', 'b');

        $this->assertSame(['a', 'b'], $response->headerValues('X-Multi'));
    }

    public function testWithHeaderReplacesExisting(): void
    {
        $response = Response::json(['ok' => true]);
        $response = $response->withHeader('Content-Type', 'text/plain');

        $this->assertSame(['text/plain'], $response->headerValues('Content-Type'));
        // Should only have one Content-Type, not two
        $this->assertCount(1, $response->headerValues('Content-Type'));
    }

    // --- json edge cases ---

    public function testJsonWithUnencodableData(): void
    {
        // json_encode returns false for resources; simulate with NAN
        // NAN causes json_encode to fail
        $response = Response::json(NAN);

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body(), 'Body should be empty string when json_encode fails');
    }

    // --- text factory ---

    public function testTextResponse(): void
    {
        $response = Response::text('plain content', 201);

        $this->assertSame(201, $response->status());
        $this->assertSame('text/plain', $response->headers()['Content-Type']);
        $this->assertSame('plain content', $response->body());
    }

    // --- headerLines preserves order ---

    public function testHeaderLinesPreservesOrder(): void
    {
        $response = Response::json(['ok' => true]);
        $response = $response->withAddedHeader('X-First', '1');
        $response = $response->withAddedHeader('X-Second', '2');
        $response = $response->withAddedHeader('X-First', '3');

        $lines = $response->headerLines();
        $custom = array_values(array_filter($lines, fn ($h) => str_starts_with($h['name'], 'X-')));

        $this->assertSame('X-First', $custom[0]['name']);
        $this->assertSame('1', $custom[0]['value']);
        $this->assertSame('X-Second', $custom[1]['name']);
        $this->assertSame('2', $custom[1]['value']);
        $this->assertSame('X-First', $custom[2]['name']);
        $this->assertSame('3', $custom[2]['value']);
    }
}
