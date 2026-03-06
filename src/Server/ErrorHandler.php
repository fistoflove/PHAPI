<?php

declare(strict_types=1);

namespace PHAPI\Server;

use PHAPI\Exceptions\MethodNotAllowedException;
use PHAPI\Exceptions\PhapiException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

/**
 * @api
 */
class ErrorHandler
{
    private bool $debug;
    private string $basePath;
    /**
     * @var callable(\Throwable, Request): (Response|mixed)|null
     */
    private $customHandler = null;

    /**
     * Create an error handler.
     *
     * @param bool $debug
     * @param string $basePath Base path stripped from debug output to avoid leaking server paths.
     * @return void
     */
    public function __construct(bool $debug = false, string $basePath = '')
    {
        $this->debug = $debug;
        $this->basePath = $basePath !== '' ? rtrim($basePath, '/') . '/' : '';
    }

    /**
     * Set a custom exception handler.
     *
     * @param callable(\Throwable, Request): (Response|mixed) $handler
     * @return void
     */
    public function setCustomHandler(callable $handler): void
    {
        $this->customHandler = $handler;
    }

    /**
     * Enable or disable debug output.
     *
     * @param bool $debug
     * @return void
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Handle an exception and return a response.
     *
     * @param \Throwable $exception
     * @param Request $request
     * @return Response
     */
    public function handle(\Throwable $exception, Request $request): Response
    {
        if ($this->customHandler !== null) {
            $result = ($this->customHandler)($exception, $request);
            if ($result instanceof Response) {
                return $result;
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
            if ($exception instanceof MethodNotAllowedException) {
                $errorData['allowed_methods'] = $exception->getAllowedMethods();
            }

            if ($this->debug) {
                $errorData['detail'] = $exception->getMessage();
                $errorData['file'] = $this->stripBasePath($exception->getFile());
                $errorData['line'] = $exception->getLine();
            }
        } else {
            if ($this->debug) {
                $errorData['detail'] = $exception->getMessage();
                $errorData['file'] = $this->stripBasePath($exception->getFile());
                $errorData['line'] = $exception->getLine();
                $errorData['trace'] = array_map(
                    fn (string $line): string => $this->stripBasePath($line),
                    explode("\n", $exception->getTraceAsString())
                );
            }
        }

        return Response::json($errorData, $statusCode);
    }

    private function stripBasePath(string $path): string
    {
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            return substr($path, strlen($this->basePath));
        }

        return $path;
    }
}
