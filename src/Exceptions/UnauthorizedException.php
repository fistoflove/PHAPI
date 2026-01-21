<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class UnauthorizedException extends PhapiException
{
    protected int $httpStatusCode = 401;
}
