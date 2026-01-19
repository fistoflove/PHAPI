<?php

namespace PHAPI\Exceptions;

final class MethodNotAllowedException extends PhapiException
{
    protected int $httpStatusCode = 405;
    private array $allowedMethods;

    public function __construct(array $allowedMethods, string $message = 'Method not allowed')
    {
        parent::__construct($message);
        $this->allowedMethods = $allowedMethods;
    }

    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
