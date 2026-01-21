<?php

/**
 * Routes definition
 * All HTTP routes go here
 */

use PHAPI\HTTP\Response;

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

// Root endpoint
$api->get('/', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'PHPAPI is running',
        'endpoints' => ['/health', '/users', '/process']
    ]);
    
    $api->log()->debug()->info("Root endpoint accessed");
});

// Health check endpoint
$api->get('/health', function($input, $request, $response, $api) {
    Response::json($response, [
        'ok' => true,
        'time' => date('c')
    ]);
    
    $api->log()->debug()->info("Health check requested");
});

// ============================================================================
// ROUTES WITH VALIDATION
// ============================================================================

// Create user with validation
$api->post('/users', function($input, $request, $response, $api) {
    // Input is already validated
    Response::json($response, [
        'message' => 'User created',
        'user' => $input
    ], 201);
    
    $api->log()->access()->info("User created", [
        'email' => $input['email'] ?? ''
    ]);
})->validate([
    'name' => 'required|string|min:3|max:100',
    'email' => 'required|email',
    'age' => 'optional|integer|min:18|max:120'
]);

// Process endpoint with validation
$api->post('/process', function($input, $request, $response, $api) {
    Response::json($response, ['status' => 'queued'], 202);
    
    $api->log()->access()->info("Processing request", [
        'body' => $input
    ]);
    
    $api->dispatchTask('processData', $input);
    
    $api->log()->debug()->info("Task dispatched", [
        'task' => 'processData'
    ]);
})->validate([
    'task' => 'required|string',
    'data' => 'optional|array'
]);

// ============================================================================
// PROTECTED ROUTES WITH MIDDLEWARE
// ============================================================================

// Protected endpoint with auth middleware
$api->middleware('auth')
    ->get('/protected', function($input, $request, $response, $api) {
        Response::json($response, [
            'message' => 'This is a protected resource'
        ]);
    });

// Protected endpoint with middleware and validation
$api->middleware('auth')
    ->post('/protected/data', function($input, $request, $response, $api) {
        Response::json($response, [
            'message' => 'Data processed',
            'data' => $input
        ]);
    })->validate([
        'key' => 'required|string',
        'value' => 'required|string|min:1'
    ]);

// ============================================================================
// ROUTE GROUPS
// ============================================================================

// API v1 routes
$api->group('/api/v1', function($api) {
    // List users
    $api->get('/users', function($input, $request, $response, $api) {
        Response::json($response, [
            'users' => []
        ]);
    });
    
    // Create user with validation
    $api->post('/users', function($input, $request, $response, $api) {
        // Input is validated
        Response::json($response, [
            'message' => 'User created',
            'user' => $input
        ], 201);
    })->validate([
        'name' => 'required|string|min:3|max:100',
        'email' => 'required|email'
    ]);
});
