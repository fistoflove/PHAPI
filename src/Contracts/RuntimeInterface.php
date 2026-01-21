<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface RuntimeInterface
{
    /**
     * Return the runtime name.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Determine if the runtime supports WebSockets.
     *
     * @return bool
     */
    public function supportsWebSockets(): bool;

    /**
     * Determine if the runtime is long-running.
     *
     * @return bool
     */
    public function isLongRunning(): bool;
}
