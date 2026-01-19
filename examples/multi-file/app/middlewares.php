<?php

use PHAPI\HTTP\Response;

$api->middleware(function($request, $next) {
    return $next($request);
});

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
