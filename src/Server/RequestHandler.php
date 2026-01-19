<?php

namespace PHAPI\Server;

use PHAPI\Exceptions\RouteNotFoundException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Response;
use PHAPI\HTTP\Validator;
use PHAPI\Logging\Logger;

/**
 * Handles HTTP request processing
 */
class RequestHandler
{
    private Router $router;
    private MiddlewareManager $middleware;
    private CORSHandler $cors;
    private ErrorHandler $errorHandler;
    private Logger $logger;
    private bool $debug;

    public function __construct(
        Router $router,
        MiddlewareManager $middleware,
        CORSHandler $cors,
        ErrorHandler $errorHandler,
        Logger $logger,
        bool $debug = false
    ) {
        $this->router = $router;
        $this->middleware = $middleware;
        $this->cors = $cors;
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * Handle an HTTP request
     *
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @param callable $apiInstanceGetter Callable that returns PHAPI instance
     */
    public function handle($request, $response, callable $apiInstanceGetter): void
    {
        $method = strtoupper($request->server['request_method'] ?? '');
        $uri = $request->server['request_uri'] ?? '';
        $startTime = microtime(true);

        $requestId = bin2hex(random_bytes(8));
        $responseStatus = 200;

        $timing = [
            'middleware_ms' => 0,
            'handler_ms' => 0,
            'validation_ms' => 0,
            'after_middleware_ms' => 0,
        ];

        $requestInfo = $this->extractRequestInfo($request, $method, $uri);
        $requestInfo['request_id'] = $requestId;

        $this->logger->access()->info('Request received', $requestInfo);

        // Handle CORS preflight
        if ($this->cors->handlePreflight($request, $response)) {
            Response::empty($response, 204);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->access()->info('Request completed', array_merge($requestInfo, [
                'status' => 204,
                'duration_ms' => $duration
            ], $timing));
            return;
        }

        // Apply CORS headers
        if ($this->cors->isEnabled()) {
            $this->cors->addHeaders($request, $response);
        }

        // Execute global middleware
        $middlewareStart = microtime(true);
        try {
            $middlewareResult = $this->middleware->executeGlobal($request, $response);
            $timing['middleware_ms'] = round((microtime(true) - $middlewareStart) * 1000, 2);

            if ($middlewareResult !== null) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $status = $response->status ?? 200;
                $this->logger->access()->info('Request completed (middleware)', array_merge($requestInfo, [
                    'status' => $status,
                    'duration_ms' => $duration
                ], $timing));
                return;
            }
        } catch (\Throwable $e) {
            $responseStatus = $this->errorHandler->handle($e, $request, $response, $apiInstanceGetter());
            $this->logCompletion($requestInfo, $responseStatus, $startTime, $timing);
            return;
        }

        // Find matching route
        $matchedRoute = $this->router->findRoute($method, $uri);

        if ($matchedRoute === null) {
            $error = new RouteNotFoundException($uri, $method);
            $responseStatus = $this->errorHandler->handle($error, $request, $response, $apiInstanceGetter());
        } else {
            try {
                $responseStatus = $this->processRoute($matchedRoute, $request, $response, $method, $timing, $apiInstanceGetter);
            } catch (\Throwable $e) {
                $responseStatus = $this->errorHandler->handle($e, $request, $response, $apiInstanceGetter());
            }
        }

        // Ensure statusCode is set for after middleware
        if (!isset($response->statusCode)) {
            $response->statusCode = $responseStatus;
        }

        // Execute after middleware
        $afterMiddlewareStart = microtime(true);
        $this->middleware->executeAfter($request, $response);
        $timing['after_middleware_ms'] = round((microtime(true) - $afterMiddlewareStart) * 1000, 2);

        $this->logCompletion($requestInfo, $responseStatus, $startTime, $timing);
    }

    /**
     * Process a matched route
     *
     * @param array $route Route definition
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @param string $method HTTP method
     * @param array &$timing Timing array reference
     * @param callable $apiInstanceGetter Callable that returns PHAPI instance
     * @return int HTTP status code
     */
    private function processRoute(array $route, $request, $response, string $method, array &$timing, callable $apiInstanceGetter): int
    {
        $responseStatus = 200;
        $routeMiddlewareStart = microtime(true);

        // Execute route-specific middleware
        $middlewareResult = $this->middleware->executeRoute($route['middleware'], $request, $response);
        $timing['middleware_ms'] += round((microtime(true) - $routeMiddlewareStart) * 1000, 2);

        if ($middlewareResult !== null) {
            if (isset($response->statusCode)) {
                $responseStatus = $response->statusCode;
            }
            return $responseStatus;
        }

        // Parse request body
        $input = $this->parseRequestBody($request, $method);
        if ($input === false) {
            $error = new ValidationException('Invalid JSON', [
                'json_error' => json_last_error_msg(),
                'json_error_code' => json_last_error()
            ]);
            return $this->errorHandler->handle($error, $request, $response, $apiInstanceGetter());
        }

        // Parse query parameters
        $queryParams = $this->parseQueryParams($request);

        // Execute validation
        if ($route['validation'] !== null) {
            $validationStart = microtime(true);
            $this->executeValidation($route, $input, $queryParams);
            $timing['validation_ms'] = round((microtime(true) - $validationStart) * 1000, 2);
        }

        // Execute route handler
        $handlerStart = microtime(true);
        $route['handler']($input, $request, $response, $apiInstanceGetter());
        $timing['handler_ms'] = round((microtime(true) - $handlerStart) * 1000, 2);

        if (isset($response->statusCode)) {
            $responseStatus = $response->statusCode;
        }

        return $responseStatus;
    }

    /**
     * Parse request body based on Content-Type
     *
     * @param mixed $request Swoole request
     * @param string $method HTTP method
     * @return mixed|false Parsed body or false on error
     */
    private function parseRequestBody($request, string $method)
    {
        if ($method !== 'POST') {
            return null;
        }

        $contentType = $request->header['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $input = json_decode($request->rawContent() ?? '', true);
            if ($input === null && json_last_error()) {
                return false;
            }
            return $input;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') ||
            str_contains($contentType, 'multipart/form-data')) {
            return $request->post ?? [];
        }

        return $request->rawContent() ?? '';
    }

    /**
     * Parse query parameters
     *
     * @param mixed $request Swoole request
     * @return array Query parameters
     */
    private function parseQueryParams($request): array
    {
        $queryParams = [];
        if (isset($request->server['query_string'])) {
            parse_str($request->server['query_string'], $queryParams);
        }
        return $queryParams;
    }

    /**
     * Execute validation rules
     *
     * @param array $route Route definition
     * @param mixed $input Request body
     * @param array $queryParams Query parameters
     * @throws ValidationException
     */
    private function executeValidation(array $route, $input, array $queryParams): void
    {
        $validationType = $route['validationType'];
        $dataToValidate = [];

        if ($validationType === 'body') {
            $dataToValidate = is_array($input) ? $input : [];
        } elseif ($validationType === 'query') {
            $dataToValidate = $queryParams;
        }

        $validator = new Validator($dataToValidate, $validationType);
        $validator->rules($route['validation']);
        $validator->validate();
    }

    /**
     * Extract request information for logging
     *
     * @param mixed $request Swoole request
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array Request information
     */
    private function extractRequestInfo($request, string $method, string $uri): array
    {
        // Get real client IP from headers (for reverse proxies)
        $ip = $this->getClientIp($request);
        
        return [
            'method' => $method,
            'uri' => $uri,
            'ip' => $ip,
            'user_agent' => $request->header['user-agent'] ?? 'unknown',
            'referer' => $request->header['referer'] ?? '',
            'host' => $request->header['host'] ?? ($request->server['server_name'] ?? 'unknown'),
            'protocol' => $request->server['server_protocol'] ?? 'HTTP/1.1',
            'content_type' => $request->header['content-type'] ?? '',
            'content_length' => isset($request->header['content-length']) ? (int)$request->header['content-length'] : 0,
            'query_string' => $request->server['query_string'] ?? '',
        ];
    }

    /**
     * Get real client IP address, checking proxy headers first
     *
     * @param mixed $request Swoole request
     * @return string Client IP address
     */
    private function getClientIp($request): string
    {
        // Check X-Forwarded-For header (most common)
        if (isset($request->header['x-forwarded-for'])) {
            $ips = explode(',', $request->header['x-forwarded-for']);
            $ip = trim($ips[0]);
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Check X-Real-IP header
        if (isset($request->header['x-real-ip'])) {
            $ip = trim($request->header['x-real-ip']);
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Fallback to remote_addr
        return $request->server['remote_addr'] ?? 'unknown';
    }

    /**
     * Log request completion
     *
     * @param array $requestInfo Request information
     * @param int $status HTTP status code
     * @param float $startTime Request start time
     * @param array $timing Timing information
     */
    private function logCompletion(array $requestInfo, int $status, float $startTime, array $timing): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logger->access()->info('Request completed', array_merge($requestInfo, [
            'status' => $status,
            'duration_ms' => $duration
        ], $timing));
    }
}

