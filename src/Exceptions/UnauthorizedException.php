<?php

namespace PHAPI\Exceptions;

final class UnauthorizedException extends PhapiException
{
    protected int $httpStatusCode = 401;
}
