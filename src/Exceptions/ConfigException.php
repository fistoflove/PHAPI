<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class ConfigException extends PhapiException
{
    protected int $httpStatusCode = 500;
}
