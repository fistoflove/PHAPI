<?php

declare(strict_types=1);

namespace PHAPI\Examples\MultiRuntime\Controllers;

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

final class StatusController
{
    public function __construct(private \DateTimeInterface $clock)
    {
    }

    public function show(): Response
    {
        $runtime = PHAPI::app()?->runtime();
        return Response::json([
            'time' => $this->clock->format(DATE_ATOM),
            'runtime' => $runtime?->name(),
            'long_running' => $runtime?->isLongRunning(),
        ]);
    }
}
