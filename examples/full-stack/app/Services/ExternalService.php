<?php

declare(strict_types=1);

namespace PHAPI\Examples\FullStack\Services;

use PHAPI\Services\HttpClient;

final class ExternalService
{
    public function __construct(private HttpClient $httpClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        return $this->httpClient->getJson('https://jsonplaceholder.typicode.com/todos/1');
    }
}
