<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\HttpKernel;

class FpmDriver implements RuntimeInterface
{
    private Capabilities $capabilities;
    /**
     * @var callable(): void|null
     */
    private $onBoot = null;
    /**
     * @var callable(mixed, int): void|null
     */
    private $onWorkerStart = null;
    /**
     * @var callable(): void|null
     */
    private $onShutdown = null;

    /**
     * Initialize the FPM driver with synchronous capabilities.
     *
     * @return void
     */
    public function __construct()
    {
        $this->capabilities = new Capabilities(false, false, false, false);
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'fpm';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsWebSockets(): bool
    {
        return $this->capabilities->supportsWebSockets();
    }

    /**
     * {@inheritDoc}
     */
    public function isLongRunning(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @param HttpKernel $kernel
     * @return void
     */
    public function start(HttpKernel $kernel): void
    {
        if ($this->onBoot !== null) {
            ($this->onBoot)();
        }
        if ($this->onWorkerStart !== null) {
            ($this->onWorkerStart)(null, 0);
        }
        $request = Request::fromGlobals();
        $response = $kernel->handle($request);
        $this->emit($response);
        if ($this->onShutdown !== null) {
            ($this->onShutdown)();
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    /**
     * {@inheritDoc}
     */
    public function onBoot(callable $handler): void
    {
        $this->onBoot = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onWorkerStart(callable $handler): void
    {
        $this->onWorkerStart = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onShutdown(callable $handler): void
    {
        $this->onShutdown = $handler;
    }

    private function emit(Response $response): void
    {
        http_response_code($response->status());
        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($response->isStream()) {
            $callback = $response->streamCallback();
            if ($callback !== null) {
                $result = $callback();
                if (is_iterable($result)) {
                    foreach ($result as $chunk) {
                        echo $chunk;
                        flush();
                    }
                } elseif (is_string($result)) {
                    echo $result;
                }
            }
            return;
        }

        echo $response->body();
    }
}
