<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

use Exception;

abstract class PhapiException extends Exception
{
    protected int $httpStatusCode;

    /**
     * Create a PHAPI exception.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code for this exception.
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
