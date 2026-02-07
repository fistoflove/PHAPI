<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class DatabaseException extends PhapiException
{
    protected int $httpStatusCode = 500;
    private ?string $sql;
    /**
     * @var array<int, mixed>
     */
    private array $bindings;

    /**
     * @param string $message
     * @param \Throwable|null $previous
     * @param string|null $sql
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        ?string $sql = null,
        array $bindings = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->sql = $sql;
        $this->bindings = $bindings;
    }

    public function sql(): ?string
    {
        return $this->sql;
    }

    /**
     * @return array<int, mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
