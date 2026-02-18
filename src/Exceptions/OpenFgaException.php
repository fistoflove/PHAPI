<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class OpenFgaException extends PhapiException
{
    protected int $httpStatusCode = 502;

    public function __construct(
        private readonly string $fgaCode,
        string $fgaMessage,
        private readonly int $httpStatus = 0,
    ) {
        parent::__construct($fgaMessage);
    }

    public function fgaCode(): string
    {
        return $this->fgaCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
