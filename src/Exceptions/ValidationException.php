<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class ValidationException extends PhapiException
{
    protected int $httpStatusCode = 422;

    /**
     * @var array<string, string>
     */
    private array $errors;

    /**
     * Create a validation exception.
     *
     * @param string $message
     * @param array<string, string> $errors
     * @return void
     */
    public function __construct(string $message = 'Validation failed', array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * Get validation errors.
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
