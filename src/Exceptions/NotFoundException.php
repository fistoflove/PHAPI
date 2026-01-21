<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
