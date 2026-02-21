<?php

declare(strict_types=1);

namespace PHAPI\Auth;

final class AuthException extends \RuntimeException
{
    public function __construct(string $code, ?\Throwable $previous = null)
    {
        parent::__construct($code, 0, $previous);
    }
}
