<?php

namespace PHAPI\Exceptions;

final class ValidationException extends PhapiException
{
    protected int $httpStatusCode = 400;
    
    private array $errors;

    public function __construct(string $message = "Validation failed", array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

