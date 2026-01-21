<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class MethodNotAllowedException extends PhapiException
{
    protected int $httpStatusCode = 405;
    /**
     * @var array<int, string>
     */
    private array $allowedMethods;

    /**
     * Create a method-not-allowed exception.
     *
     * @param array<int, string> $allowedMethods
     * @param string $message
     * @return void
     */
    public function __construct(array $allowedMethods, string $message = 'Method not allowed')
    {
        parent::__construct($message);
        $this->allowedMethods = $allowedMethods;
    }

    /**
     * Get the allowed HTTP methods.
     *
     * @return array<int, string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
