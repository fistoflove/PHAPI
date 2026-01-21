<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class ForbiddenException extends PhapiException
{
    protected int $httpStatusCode = 403;
}
