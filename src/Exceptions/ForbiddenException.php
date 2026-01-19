<?php

namespace PHAPI\Exceptions;

final class ForbiddenException extends PhapiException
{
    protected int $httpStatusCode = 403;
}
