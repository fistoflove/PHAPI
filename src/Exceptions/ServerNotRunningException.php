<?php

namespace PHAPI\Exceptions;

final class ServerNotRunningException extends PhapiException
{
    protected int $httpStatusCode = 503;

    public function __construct(string $message = "Server not running yet")
    {
        parent::__construct($message);
    }
}

