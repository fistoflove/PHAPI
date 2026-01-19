<?php

namespace PHAPI\HTTP;

class Response
{
    private int $status;
    private array $headers = [];
    private string $body = '';
    /** @var callable|null */
    private $streamCallback = null;

    private function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function json($data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($status, ['Content-Type' => 'application/json'], $body === false ? '' : $body);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain'], $text);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html'], $html);
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, [], '');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url], '');
    }

    public static function error(string $message, int $status = 500, array $details = []): self
    {
        $payload = ['error' => $message];
        if (!empty($details)) {
            $payload = array_merge($payload, $details);
        }
        return self::json($payload, $status);
    }

    public static function stream(callable $callback, int $status = 200, array $headers = []): self
    {
        $response = new self($status, $headers, '');
        $response->streamCallback = $callback;
        return $response;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isStream(): bool
    {
        return $this->streamCallback !== null;
    }

    public function streamCallback(): ?callable
    {
        return $this->streamCallback;
    }

    public function withHeader(string $key, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$key] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
}
