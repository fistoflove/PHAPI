<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\Contracts\RuntimeInterface as PublicRuntimeInterface;

interface RuntimeInterface extends PublicRuntimeInterface, HttpRuntimeDriver
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
     * Register a request-start hook.
     *
     * @param callable(\PHAPI\HTTP\Request): void $handler
     * @return void
     */
    public function onRequestStart(callable $handler): void;

    /**
     * Register a request-end hook.
     *
     * @param callable(\PHAPI\HTTP\Request, \PHAPI\HTTP\Response): void $handler
     * @return void
     */
    public function onRequestEnd(callable $handler): void;

    /**
     * Register a boot hook.
     *
     * @param callable(): void $handler
     * @return void
     */
    public function onBoot(callable $handler): void;

    /**
     * Register a worker-start hook.
     *
     * @param callable(mixed, int): void $handler
     * @return void
     */
    public function onWorkerStart(callable $handler): void;

    /**
     * Register a shutdown hook.
     *
     * @param callable(): void $handler
     * @return void
     */
    public function onShutdown(callable $handler): void;
}
