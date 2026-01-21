<?php

use PHAPI\HTTP\Response;
use PHAPI\HTTP\Request;

final class ExampleMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

$api->middleware(function($request, $next) {
    return $next($request);
});

$api->middleware(ExampleMiddleware::class);

$api->afterMiddleware(function($request, $response) {
    return $response;
});

$api->addMiddleware('auth', function($request, $next) {
    $token = $request->header('authorization');
    if (!$token) {
        return Response::json(['error' => 'Unauthorized'], 401);
    }
    return $next($request);
});
