<?php

declare(strict_types=1);

namespace PHAPI\Services;

final class DefaultHttpClient extends SwooleHttpClient
{
    public function __construct(float $timeout = 5.0)
    {
        parent::__construct($timeout);
    }
}
