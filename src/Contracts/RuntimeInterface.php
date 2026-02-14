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

    /**
     * Register a recurring runtime timer.
     *
     * @param int $intervalMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function every(int $intervalMs, callable $handler);

    /**
     * Register a one-shot runtime timer.
     *
     * @param int $delayMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function after(int $delayMs, callable $handler);

    /**
     * Clear a previously registered timer.
     *
     * @param int $timerId
     * @return bool
     */
    public function clearTimer(int $timerId): bool;
}
