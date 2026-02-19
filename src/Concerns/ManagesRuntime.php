<?php

declare(strict_types=1);

namespace PHAPI\Concerns;

use PHAPI\Contracts\WebSocketDriverInterface;
use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\SwooleDriver;
use PHAPI\Services\WebSocketConnection;
use PHAPI\Services\WebSocketMessage;

/**
 * Provides runtime lifecycle hooks, timers, task dispatch, and WebSocket handling.
 *
 * This trait is used by PHAPI and accesses the following properties via $this:
 * - RuntimeManager $runtimeManager
 * - callable|null $webSocketHandler
 */
trait ManagesRuntime
{
    /**
     * Register a WebSocket message handler for Swoole.
     *
     * @param callable(\Swoole\WebSocket\Server, mixed, SwooleDriver): void $handler
     * @return self
     */
    public function setWebSocketHandler(callable $handler): self
    {
        $this->webSocketHandler = $handler;
        $this->swooleDriver()->setWebSocketHandler($handler);
        return $this;
    }

    /**
     * Register a runtime-abstracted WebSocket message handler.
     *
     * @param callable(WebSocketMessage, WebSocketConnection): void $handler
     * @return self
     */
    public function onWebSocketMessage(callable $handler): self
    {
        return $this->setWebSocketHandler(function ($server, $frame, $driver) use ($handler): void {
            $fd = (int) ($frame->fd ?? 0);
            if ($fd <= 0) {
                return;
            }

            /** @var WebSocketDriverInterface $driver */
            $message = new WebSocketMessage($fd, (string) ($frame->data ?? ''));
            $connection = new WebSocketConnection($driver, $fd);
            $handler($message, $connection);
        });
    }

    /**
     * Register a Swoole task-worker handler.
     *
     * @param callable(\Swoole\Server, int, int, mixed): mixed $handler
     */
    public function setTaskHandler(callable $handler): self
    {
        $driver = $this->swooleDriver();
        $driver->setTaskHandler($handler);
        return $this;
    }

    /**
     * Register a Swoole task-finish handler.
     *
     * @param callable(\Swoole\Server, int, mixed): void $handler
     */
    public function setTaskFinishHandler(callable $handler): self
    {
        $driver = $this->swooleDriver();
        $driver->setTaskFinishHandler($handler);
        return $this;
    }

    /**
     * Dispatch a payload to Swoole task workers.
     *
     * @param mixed $payload
     * @return int|false
     */
    public function dispatchTask(mixed $payload)
    {
        $driver = $this->swooleDriver();
        return $driver->dispatchTask($payload);
    }

    /**
     * Register a boot hook for the active runtime.
     *
     * @param callable(): void $handler
     * @return self
     */
    public function onBoot(callable $handler): self
    {
        $this->runtimeManager->driver()->onBoot($handler);
        return $this;
    }

    /**
     * Register a worker-start hook for the active runtime.
     *
     * @param callable(mixed, int): void $handler
     * @return self
     */
    public function onWorkerStart(callable $handler): self
    {
        $this->runtimeManager->driver()->onWorkerStart($handler);
        return $this;
    }

    /**
     * Register a shutdown hook for the active runtime.
     *
     * @param callable(): void $handler
     * @return self
     */
    public function onShutdown(callable $handler): self
    {
        $this->runtimeManager->driver()->onShutdown($handler);
        return $this;
    }

    /**
     * Register a request-start hook for the active runtime.
     *
     * @param callable(\PHAPI\HTTP\Request): void $handler
     * @return self
     */
    public function onRequestStart(callable $handler): self
    {
        $this->runtimeManager->driver()->onRequestStart($handler);
        return $this;
    }

    /**
     * Register a request-end hook for the active runtime.
     *
     * @param callable(\PHAPI\HTTP\Request, \PHAPI\HTTP\Response): void $handler
     * @return self
     */
    public function onRequestEnd(callable $handler): self
    {
        $this->runtimeManager->driver()->onRequestEnd($handler);
        return $this;
    }

    /**
     * Return the active runtime capabilities.
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->runtimeManager->capabilities();
    }

    /**
     * Return the active runtime driver.
     *
     * @return \PHAPI\Runtime\RuntimeInterface
     */
    public function runtime(): \PHAPI\Runtime\RuntimeInterface
    {
        return $this->runtimeManager->driver();
    }

    /**
     * Register a recurring runtime timer.
     *
     * @param int $intervalMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function every(int $intervalMs, callable $handler)
    {
        return $this->runtimeManager->driver()->every($intervalMs, $handler);
    }

    /**
     * Register a one-shot runtime timer.
     *
     * @param int $delayMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function after(int $delayMs, callable $handler)
    {
        return $this->runtimeManager->driver()->after($delayMs, $handler);
    }

    /**
     * Clear a runtime timer id.
     *
     * @param int $timerId
     * @return bool
     */
    public function clearTimer(int $timerId): bool
    {
        return $this->runtimeManager->driver()->clearTimer($timerId);
    }

    /**
     * Determine whether a WebSocket connection is established.
     *
     * @param int $fd
     * @return bool
     */
    public function websocketIsEstablished(int $fd): bool
    {
        /** @var WebSocketDriverInterface $driver */
        $driver = $this->swooleDriver();
        return $driver->isConnectionEstablished($fd);
    }

    /**
     * Disconnect a WebSocket connection when supported by the runtime.
     *
     * @param int $fd
     * @param int $code
     * @param string $reason
     * @return bool
     */
    public function websocketDisconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        /** @var WebSocketDriverInterface $driver */
        $driver = $this->swooleDriver();
        return $driver->disconnect($fd, $code, $reason);
    }

    /**
     * Register a background process factory.
     *
     * @param callable(): mixed $factory
     * @param (callable(\Swoole\Process): void)|null $onStart
     * @param int $workerId
     * @return self
     */
    public function spawnProcess(callable $factory, ?callable $onStart = null, int $workerId = 0): self
    {
        $driver = $this->swooleDriver();
        $driver->spawnProcess($factory, $onStart, $workerId);
        return $this;
    }

    /**
     * Return the effective runtime name.
     *
     * @return string
     */
    public function runtimeName(): string
    {
        return $this->runtimeManager->driver()->name();
    }

    /**
     * @return SwooleDriver
     */
    private function swooleDriver(): SwooleDriver
    {
        $driver = $this->runtimeManager->driver();
        if (!$driver instanceof SwooleDriver) {
            throw new \RuntimeException('PHAPI requires Swoole runtime.');
        }

        return $driver;
    }
}
