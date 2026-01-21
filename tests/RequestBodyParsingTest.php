<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\HTTP\Request;
use PHPUnit\Framework\TestCase;

final class RequestBodyParsingTest extends TestCase
{
    private array $backupPost = [];
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupPost = $_POST;
        $this->backupServer = $_SERVER;
        $_POST = [];
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->backupPost;
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    public function testJsonBodyParsing(): void
    {
        $raw = '{"email":"test@example.com"}';
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $raw);
        rewind($stream);

        $headers = ['content-type' => 'application/json'];
        $body = $this->invokeParseBody($headers, $stream);

        $this->assertSame(['email' => 'test@example.com'], $body);
    }

    public function testFormUrlencodedParsingFallsBackToPost(): void
    {
        $_POST = ['email' => 'form@example.com'];
        $headers = ['content-type' => 'application/x-www-form-urlencoded'];
        $body = $this->invokeParseBody($headers, null);

        $this->assertSame(['email' => 'form@example.com'], $body);
    }

    public function testMultipartParsingUsesPost(): void
    {
        $_POST = ['email' => 'multi@example.com'];
        $headers = ['content-type' => 'multipart/form-data; boundary=abc'];
        $body = $this->invokeParseBody($headers, null);

        $this->assertSame(['email' => 'multi@example.com'], $body);
    }

    public function testJsonBodyParsingWithMissingContentType(): void
    {
        $raw = '{"active":true}';
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $raw);
        rewind($stream);

        $headers = [];
        $body = $this->invokeParseBody($headers, $stream);

        $this->assertSame(['active' => true], $body);
    }

    /**
     * @param array<string, string> $headers
     * @param resource|null $stream
     * @return mixed
     */
    private function invokeParseBody(array $headers, $stream)
    {
        $method = 'POST';
        if ($stream !== null) {
            $original = fopen('php://input', 'r');
            $this->assertNotFalse($original);
            fclose($original);
        }

        $ref = new \ReflectionMethod(Request::class, 'parseBody');
        $ref->setAccessible(true);

        if ($stream !== null) {
            // Monkey-patch php://input by using a stream wrapper trick.
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', TestPhpStream::class);
            TestPhpStream::$data = stream_get_contents($stream) ?: '';
        }

        try {
            return $ref->invoke(null, $method, $headers);
        } finally {
            if ($stream !== null) {
                stream_wrapper_restore('php');
            }
        }
    }
}

final class TestPhpStream
{
    public static string $data = '';
    /** @var resource|null */
    public $context;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$data, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_stat(): array
    {
        return [];
    }
}
