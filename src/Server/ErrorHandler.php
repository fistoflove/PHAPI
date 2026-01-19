<?php

namespace PHAPI\Server;

use PHAPI\Exceptions\PhapiException;
use PHAPI\Exceptions\ValidationException;
use PHAPI\Exceptions\MethodNotAllowedException;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

class ErrorHandler
{
    private bool $debug;
    private $customHandler = null;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function setCustomHandler(callable $handler): void
    {
        $this->customHandler = $handler;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

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
        }

        return Response::json($errorData, $statusCode);
    }
}
