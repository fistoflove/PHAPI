<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\HttpKernel;

class FpmDriver implements HttpRuntimeDriver
{
    private Capabilities $capabilities;

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
     *
     * @param HttpKernel $kernel
     * @return void
     */
    public function start(HttpKernel $kernel): void
    {
        $request = Request::fromGlobals();
        $response = $kernel->handle($request);
        $this->emit($response);
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
