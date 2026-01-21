<?php

declare(strict_types=1);

namespace PHAPI\HTTP;

class Response
{
    private int $status;
    /**
     * @var array<string, string>
     */
    private array $headers = [];
    private string $body = '';
    /** @var (callable(): (iterable<mixed>|string|null))|null */
    private $streamCallback = null;

    /**
     * @param int $status
     * @param array<string, string> $headers
     * @param string $body
     * @return void
     */
    private function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data
     * @param int $status
     * @return self
     */
    public static function json($data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($status, ['Content-Type' => 'application/json'], $body === false ? '' : $body);
    }

    /**
     * Create a plain text response.
     *
     * @param string $text
     * @param int $status
     * @return self
     */
    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain'], $text);
    }

    /**
     * Create an HTML response.
     *
     * @param string $html
     * @param int $status
     * @return self
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html'], $html);
    }

    /**
     * Create an empty response.
     *
     * @param int $status
     * @return self
     */
    public static function empty(int $status = 204): self
    {
        return new self($status, [], '');
    }

    /**
     * Create a redirect response.
     *
     * @param string $url
     * @param int $status
     * @return self
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url], '');
    }

    /**
     * Create a JSON error response.
     *
     * @param string $message
     * @param int $status
     * @param array<string, mixed> $details
     * @return self
     */
    public static function error(string $message, int $status = 500, array $details = []): self
    {
        $payload = ['error' => $message];
        if ($details !== []) {
            $payload = array_merge($payload, $details);
        }
        return self::json($payload, $status);
    }

    /**
     * Create a streaming response.
     *
     * @param callable(): (iterable<mixed>|string|null) $callback
     * @param int $status
     * @param array<string, string> $headers
     * @return self
     */
    public static function stream(callable $callback, int $status = 200, array $headers = []): self
    {
        $response = new self($status, $headers, '');
        $response->streamCallback = $callback;
        return $response;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Get response headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get the response body.
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Determine if the response is streaming.
     *
     * @return bool
     */
    public function isStream(): bool
    {
        return $this->streamCallback !== null;
    }

    /**
     * Get the stream callback if present.
     *
     * @return callable|null
     */
    /**
     * @return (callable(): (iterable<mixed>|string|null))|null
     */
    public function streamCallback(): ?callable
    {
        return $this->streamCallback;
    }

    /**
     * Return a copy with an added header.
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public function withHeader(string $key, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$key] = $value;
        return $clone;
    }

    /**
     * Return a copy with a different status code.
     *
     * @param int $status
     * @return self
     */
    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /**
     * Return a copy with a new body.
     *
     * @param string $body
     * @return self
     */
    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
}
