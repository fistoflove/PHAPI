<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class HttpRequestException extends \RuntimeException
{
    private string $url;
    private int $status;
    private string $body;

    public function __construct(string $url, int $status, string $body, string $message = '')
    {
        $finalMessage = $message !== '' ? $message : "HTTP request failed for {$url} with status {$status}";
        parent::__construct($finalMessage);
        $this->url = $url;
        $this->status = $status;
        $this->body = $body;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}
