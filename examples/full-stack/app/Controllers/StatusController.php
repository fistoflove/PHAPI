<?php

declare(strict_types=1);

namespace PHAPI\Examples\FullStack\Controllers;

use PHAPI\HTTP\Response;
use PHAPI\Runtime\RuntimeInterface;

final class StatusController
{
    public function __construct(
        private \DateTimeInterface $clock,
        private RuntimeInterface $runtime,
    ) {
    }

    public function show(): Response
    {
        return Response::json([
            'time' => $this->clock->format(DATE_ATOM),
            'runtime' => $this->runtime->name(),
            'long_running' => $this->runtime->isLongRunning(),
        ]);
    }
}
