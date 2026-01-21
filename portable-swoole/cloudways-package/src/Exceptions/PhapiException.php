<?php

namespace PHAPI\Exceptions;

use Exception;

abstract class PhapiException extends Exception
{
    protected int $httpStatusCode;

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}

