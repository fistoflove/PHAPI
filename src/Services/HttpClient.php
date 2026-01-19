<?php

namespace PHAPI\Services;

interface HttpClient
{
    public function getJson(string $url): array;
}
