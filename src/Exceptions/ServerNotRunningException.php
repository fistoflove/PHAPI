<?php

declare(strict_types=1);

namespace PHAPI\Exceptions;

final class ServerNotRunningException extends PhapiException
{
    protected int $httpStatusCode = 503;

    /**
     * Create a server-not-running exception.
     */
    public function __construct(string $message = 'Server not running yet')
    {
        parent::__construct($message);
    }
}
