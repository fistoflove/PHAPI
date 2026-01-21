<?php

namespace PHAPI\HTTP;

/**
 * Response helper - provides convenient methods for Swoole HTTP responses
 */
class Response
{
    /**
     * Send JSON response
     * 
     * @param mixed $response Swoole HTTP response object
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code (default: 200)
     * @return void
     */
    public static function json($response, $data, int $status = 200): void
    {
        $response->status($status);
        $response->statusCode = $status; // Track status for after middleware
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Send text response
     * 
     * @param mixed $response Swoole HTTP response object
     * @param string $text Text content
     * @param int $status HTTP status code (default: 200)
     * @return void
     */
    public static function text($response, string $text, int $status = 200): void
    {
        $response->status($status);
        $response->statusCode = $status; // Track status for after middleware
        $response->header('Content-Type', 'text/plain');
        $response->end($text);
    }

    /**
     * Send HTML response
     * 
     * @param mixed $response Swoole HTTP response object
     * @param string $html HTML content
     * @param int $status HTTP status code (default: 200)
     * @return void
     */
    public static function html($response, string $html, int $status = 200): void
    {
        $response->status($status);
        $response->statusCode = $status; // Track status for after middleware
        $response->header('Content-Type', 'text/html');
        $response->end($html);
    }

    /**
     * Send empty response (no body)
     * 
     * @param mixed $response Swoole HTTP response object
     * @param int $status HTTP status code (default: 204)
     * @return void
     */
    public static function empty($response, int $status = 204): void
    {
        $response->status($status);
        $response->statusCode = $status; // Track status for after middleware
        $response->end('');
    }

    /**
     * Send redirect response
     * 
     * @param mixed $response Swoole HTTP response object
     * @param string $url Redirect URL
     * @param int $status HTTP status code (default: 302)
     * @return void
     */
    public static function redirect($response, string $url, int $status = 302): void
    {
        $response->status($status);
        $response->statusCode = $status; // Track status for after middleware
        $response->header('Location', $url);
        $response->end('');
    }

    /**
     * Send error response (JSON)
     * 
     * @param mixed $response Swoole HTTP response object
     * @param string $message Error message
     * @param int $status HTTP status code (default: 500)
     * @param array $details Additional error details
     * @return void
     */
    public static function error($response, string $message, int $status = 500, array $details = []): void
    {
        $data = ['error' => $message];
        if (!empty($details)) {
            $data = array_merge($data, $details);
        }
        self::json($response, $data, $status);
    }
}

