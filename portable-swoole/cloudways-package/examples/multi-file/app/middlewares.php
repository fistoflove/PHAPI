<?php

/**
 * Middleware definitions
 * All global and named middleware goes here
 * 
 * Note: This file assumes $api variable is available from app.php
 */

use PHAPI\HTTP\Response;

// ============================================================================
// GLOBAL MIDDLEWARE - Runs before all routes
// ============================================================================

// Example: Authentication, rate limiting, request validation
// Note: Runs BEFORE handler, so any work here blocks the request
// For non-blocking logging, use afterMiddleware() instead

// Example before middleware (commented out - uncomment if needed):
// $api->middleware(function($request, $response, $next) use ($api) {
//     // Can only log request info here (not response status - handler hasn't run yet)
//     $api->log()->access()->info("Before handler", [
//         'method' => $request->server['request_method'] ?? '',
//         'uri' => $request->server['request_uri'] ?? ''
//     ]);
//     return $next();
// });

// ============================================================================
// AFTER MIDDLEWARE - Runs after handler, before response end
// ============================================================================

// Non-blocking logging - runs after handler executes (response already sent to client)
// This is perfect for logging without blocking the request handler
// The framework also logs "Request completed" automatically, so you may not need this
$api->afterMiddleware(function($request, $response, $next) use ($api) {
    // Handler has executed and response is already sent to client
    // This logging is truly non-blocking - doesn't delay response to client
    // Can access response status via $response->statusCode (injected by framework)
    $api->log()->access()->info("After handler", [
        'method' => $request->server['request_method'] ?? '',
        'uri' => $request->server['request_uri'] ?? '',
        'status' => $response->statusCode ?? 200
    ]);
    
    return $next();
});

// ============================================================================
// NAMED MIDDLEWARE - Reusable across routes
// ============================================================================

// Authentication middleware
$api->addMiddleware('auth', function($request, $response, $next) {
    $token = $request->header['authorization'] ?? null;
    
    if (!$token) {
        return Response::json($response, ['error' => 'Unauthorized'], 401);
    }
    
    // In real app, validate token here
    // Example: if (!validateToken($token)) { return Response::json(...); }
    
    return $next();
});

// Rate limiting middleware (example)
$api->addMiddleware('rateLimit', function($request, $response, $next) {
    $ip = $request->server['remote_addr'] ?? 'unknown';
    
    // In real app, check rate limits here
    // Example: if (rateLimitExceeded($ip)) {
    //     return Response::json($response, ['error' => 'Too many requests'], 429);
    // }
    
    return $next();
});

// CORS middleware (alternative to enableCORS() if you need more control)
// Note: enableCORS() is recommended for simple cases
$api->addMiddleware('cors', function($request, $response, $next) {
    $origin = $request->header['origin'] ?? '*';
    
    $response->header('Access-Control-Allow-Origin', $origin);
    $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    $response->header('Access-Control-Max-Age', '3600');
    
    // Handle preflight OPTIONS request
    if ($request->server['request_method'] === 'OPTIONS') {
        Response::empty($response, 204);
        return; // Stop execution for preflight
    }
    
    return $next();
});

