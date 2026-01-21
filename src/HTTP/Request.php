<?php

declare(strict_types=1);

namespace PHAPI\HTTP;

class Request
{
    private string $method;
    private string $path;
    /**
     * @var array<string, mixed>
     */
    private array $query;
    /**
     * @var array<string, string>
     */
    private array $headers;
    /**
     * @var array<string, string>
     */
    private array $cookies;
    /**
     * @var mixed
     */
    private $body;
    /**
     * @var array<string, string>
     */
    private array $params = [];
    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * Create a request object.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param mixed $body
     * @param array<string, mixed> $server
     * @return void
     */
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

    /**
     * Build a request from PHP globals.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '/';
        }

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
            $_GET,
            $headers,
            $_COOKIE,
            $body,
            $_SERVER
        );
    }

    /**
     * @param string $method
     * @param array<string, string> $headers
     * @return mixed
     */
    private static function parseBody(string $method, array $headers)
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        $contentType = strtolower($headers['content-type'] ?? '');

        if (strpos($contentType, 'multipart/form-data') !== false) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return $_POST;
        }

        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return null;
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            if ($_POST !== []) {
                return $_POST;
            }
            parse_str($raw, $parsed);
            return $parsed;
        }

        if ($_POST !== []) {
            return $_POST;
        }

        if ($contentType === '') {
            $trimmed = ltrim($raw);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        return $raw;
    }

    /**
     * Get the HTTP method.
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Get the request path.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get a query parameter.
     *
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters.
     *
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * Get a header value.
     *
     * @param mixed $default
     * @return mixed
     */
    public function header(string $key, $default = null)
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * Get all headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Determine the request host.
     *
     * @return string|null
     */
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

    /**
     * Get a cookie value.
     *
     * @param mixed $default
     * @return mixed
     */
    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get all cookies.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get the parsed request body.
     *
     * @return mixed
     */
    public function body()
    {
        return $this->body;
    }

    /**
     * Get a route parameter.
     *
     * @param mixed $default
     * @return mixed
     */
    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get all route parameters.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Clone the request with route parameters.
     *
     * @param array<string, string> $params
     * @return self
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    /**
     * Get server parameters.
     *
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->server;
    }

    /**
     * Get the content length if present.
     *
     * @return int|null
     */
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
