<?php

namespace PHAPI\HTTP;

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $headers;
    private array $cookies;
    private $body;
    private array $params = [];
    private array $server;

    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        array $cookies = [],
        $body = null,
        array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->cookies = $cookies;
        $this->body = $body;
        $this->server = $server;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($key, 5))));
                    $headers[$header] = $value;
                }
            }
        }

        $body = self::parseBody($method, $headers);

        return new self(
            $method,
            $path,
            $_GET ?? [],
            $headers,
            $_COOKIE ?? [],
            $body,
            $_SERVER
        );
    }

    private static function parseBody(string $method, array $headers)
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        $contentType = strtolower($headers['content-type'] ?? '');
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return null;
        }

        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return null;
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($raw, $parsed);
            return $parsed;
        }

        return $raw;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function header(string $key, $default = null)
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function host(): ?string
    {
        $host = $this->header('host');
        if ($host !== null && $host !== '') {
            return $host;
        }

        if (isset($this->server['HTTP_HOST'])) {
            return $this->server['HTTP_HOST'];
        }

        return $this->server['SERVER_NAME'] ?? null;
    }

    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function body()
    {
        return $this->body;
    }

    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    public function params(): array
    {
        return $this->params;
    }

    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function server(): array
    {
        return $this->server;
    }

    public function contentLength(): ?int
    {
        $length = $this->header('content-length');
        if ($length !== null && is_numeric($length)) {
            return (int)$length;
        }

        if (isset($this->server['CONTENT_LENGTH']) && is_numeric($this->server['CONTENT_LENGTH'])) {
            return (int)$this->server['CONTENT_LENGTH'];
        }

        return null;
    }
}
