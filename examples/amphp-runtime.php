<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'amphp',
    'debug' => true,
]);

$api->get('/external', function (): Response {
    try {
        $data = PHAPI::app()?->http()->getJson('https://api.example.com/data');
        return Response::json(['data' => $data]);
    } catch (\PHAPI\Exceptions\HttpRequestException $e) {
        return Response::error('Upstream error', 502, [
            'status' => $e->status(),
        ]);
    }
});

$api->run();
