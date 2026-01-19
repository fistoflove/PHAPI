<?php

namespace PHAPI\Server;

/**
 * Handles CORS (Cross-Origin Resource Sharing) configuration and headers
 */
class CORSHandler
{
    private ?array $config = null;

    /**
     * Enable CORS with configuration
     *
     * @param array|string|null $origins Allowed origins ('*' for all, array for specific origins)
     * @param array $methods Allowed HTTP methods
     * @param array $headers Allowed headers
     * @param bool $credentials Allow credentials
     * @param int $maxAge Preflight cache time in seconds
     * @return self
     */
    public function enable($origins = '*', array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], array $headers = ['Content-Type'], bool $credentials = false, int $maxAge = 3600): self
    {
        $this->config = [
            'origins' => $origins,
            'methods' => $methods,
            'headers' => $headers,
            'credentials' => $credentials,
            'maxAge' => $maxAge
        ];
        return $this;
    }

    /**
     * Check if CORS is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config !== null;
    }

    /**
     * Handle CORS preflight (OPTIONS) request
     *
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @return bool True if preflight was handled
     */
    public function handlePreflight($request, $response): bool
    {
        if ($this->config === null) {
            return false;
        }

        $method = strtoupper($request->server['request_method'] ?? '');
        if ($method !== 'OPTIONS') {
            return false;
        }

        $this->addHeaders($request, $response);
        return true;
    }

    /**
     * Add CORS headers to response
     *
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     */
    public function addHeaders($request, $response): void
    {
        if ($this->config === null) {
            return;
        }

        $origin = $request->header['origin'] ?? null;
        $origins = $this->config['origins'];

        $allowedOrigin = $this->determineOrigin($origins, $origin);
        if ($allowedOrigin === null) {
            return;
        }

        $response->header('Access-Control-Allow-Origin', $allowedOrigin);
        $response->header('Access-Control-Allow-Methods', implode(', ', $this->config['methods']));
        $response->header('Access-Control-Allow-Headers', implode(', ', $this->config['headers']));
        $response->header('Access-Control-Max-Age', (string)$this->config['maxAge']);

        if ($this->config['credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * Determine allowed origin based on configuration
     *
     * @param array|string|null $origins Configured origins
     * @param string|null $requestOrigin Request origin header
     * @return string|null Allowed origin or null if not allowed
     */
    private function determineOrigin($origins, ?string $requestOrigin): ?string
    {
        $allowedOrigin = '*';

        if ($origins !== '*' && $origins !== null) {
            if (is_array($origins)) {
                if ($requestOrigin !== null && in_array($requestOrigin, $origins)) {
                    $allowedOrigin = $requestOrigin;
                } else {
                    return null;
                }
            } else {
                $allowedOrigin = $origins;
            }
        } elseif ($origins === '*' && $this->config['credentials']) {
            $allowedOrigin = $requestOrigin ?? '*';
        }

        return $allowedOrigin;
    }

    /**
     * Get CORS configuration
     *
     * @return array|null
     */
    public function getConfig(): ?array
    {
        return $this->config;
    }
}

