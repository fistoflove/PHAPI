<?php

namespace PHAPI\Server;

use PHAPI\Exceptions\PhapiException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Response;
use PHAPI\Logging\Logger;

/**
 * Handles errors and exceptions
 */
class ErrorHandler
{
    private Logger $logger;
    private bool $debug;
    private $customHandler = null;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * Set custom error handler
     *
     * @param callable $handler Handler receives: ($exception, $request, $response, $api)
     */
    public function setCustomHandler(callable $handler): void
    {
        $this->customHandler = $handler;
    }

    /**
     * Enable or disable debug mode
     *
     * @param bool $debug
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Handle an exception
     *
     * @param \Throwable $exception Exception to handle
     * @param mixed $request Swoole request
     * @param mixed $response Swoole response
     * @param mixed $api PHAPI instance (for custom handlers)
     * @return int HTTP status code
     */
    public function handle(\Throwable $exception, $request, $response, $api): int
    {
        if ($this->customHandler !== null) {
            try {
                ($this->customHandler)($exception, $request, $response, $api);
                return 500;
            } catch (\Throwable $handlerError) {
                $this->logger->errors()->error("Error handler failed", [
                    'error' => $handlerError->getMessage()
                ]);
            }
        }

        $statusCode = 500;
        $errorData = ['error' => 'Internal Server Error'];

        if ($exception instanceof PhapiException) {
            $statusCode = $exception->getHttpStatusCode();
            $errorData = ['error' => $exception->getMessage()];

            if ($exception instanceof ValidationException) {
                $errorData['errors'] = $exception->getErrors();
            }

            if ($this->debug) {
                $errorData['detail'] = $exception->getMessage();
                $errorData['file'] = $exception->getFile();
                $errorData['line'] = $exception->getLine();
            }
        } else {
            if ($this->debug) {
                $errorData['detail'] = $exception->getMessage();
                $errorData['file'] = $exception->getFile();
                $errorData['line'] = $exception->getLine();
                $errorData['trace'] = explode("\n", $exception->getTraceAsString());
            }

            $this->logger->errors()->error("Unhandled exception", [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        }

        $this->logger->errors()->error("Request error", [
            'method' => $request->server['request_method'] ?? '',
            'uri' => $request->server['request_uri'] ?? '',
            'status' => $statusCode,
            'error' => $exception->getMessage(),
            'type' => get_class($exception)
        ]);

        Response::json($response, $errorData, $statusCode);
        return $statusCode;
    }
}

