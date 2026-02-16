<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Response;
use PHAPI\HTTP\ResponseEnvelope;
use PHPUnit\Framework\TestCase;

final class ResponseEnvelopeTest extends TestCase
{
    public function testSuccessReturnsCorrectShape(): void
    {
        $result = ResponseEnvelope::success(['id' => 1, 'name' => 'test']);
        $this->assertSame(true, $result['ok']);
        $this->assertSame(['id' => 1, 'name' => 'test'], $result['data']);
    }

    public function testSuccessWithNullData(): void
    {
        $result = ResponseEnvelope::success(null);
        $this->assertSame(true, $result['ok']);
        $this->assertNull($result['data']);
    }

    public function testSuccessWithEmptyArray(): void
    {
        $result = ResponseEnvelope::success([]);
        $this->assertSame(true, $result['ok']);
        $this->assertSame([], $result['data']);
    }

    public function testSuccessWithScalarData(): void
    {
        $result = ResponseEnvelope::success('hello');
        $this->assertSame(true, $result['ok']);
        $this->assertSame('hello', $result['data']);
    }

    public function testErrorReturnsResponseWithCorrectShape(): void
    {
        $response = ResponseEnvelope::error('NOT_FOUND', 'Resource not found', 404);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->status());

        $body = json_decode($response->body(), true);
        $this->assertSame(false, $body['ok']);
        $this->assertSame('NOT_FOUND', $body['error']['code']);
        $this->assertSame('Resource not found', $body['error']['message']);
    }

    public function testErrorDefaultsTo400(): void
    {
        $response = ResponseEnvelope::error('VALIDATION_ERROR', 'Invalid input');
        $this->assertSame(400, $response->status());
    }

    public function testErrorWith500Status(): void
    {
        $response = ResponseEnvelope::error('INTERNAL_ERROR', 'Something broke', 500);
        $this->assertSame(500, $response->status());
    }

    public function testOkReturnsResponseWithSuccessEnvelope(): void
    {
        $response = ResponseEnvelope::ok(['users' => []], 200);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->status());

        $body = json_decode($response->body(), true);
        $this->assertSame(true, $body['ok']);
        $this->assertSame(['users' => []], $body['data']);
    }

    public function testOkWith201Status(): void
    {
        $response = ResponseEnvelope::ok(['id' => 'abc'], 201);
        $this->assertSame(201, $response->status());

        $body = json_decode($response->body(), true);
        $this->assertSame(true, $body['ok']);
        $this->assertSame(['id' => 'abc'], $body['data']);
    }

    public function testOkDefaultsTo200(): void
    {
        $response = ResponseEnvelope::ok(['test' => true]);
        $this->assertSame(200, $response->status());
    }
}
