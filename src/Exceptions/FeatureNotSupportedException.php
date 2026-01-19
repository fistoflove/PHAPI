<?php

namespace PHAPI\Exceptions;

final class FeatureNotSupportedException extends PhapiException
{
    protected int $httpStatusCode = 501;
}
