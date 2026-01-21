<?php

/**
 * Single-file approach - Everything in one file
 * Perfect for small apps, prototypes, or simple APIs
 */

// Check Swoole extension is loaded
if (!extension_loaded('swoole')) {
    die("Error: Swoole extension is not loaded!\n\n" .
        "Please run with: php -c ../phapi.ini single-file.php\n" .
        "Or from test-package root: php -c phapi.ini examples/single-file.php\n");
}

// Support both Composer and bootstrap.php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../bootstrap.php')) {
    require __DIR__ . '/../bootstrap.php';
} else {
    // Manual autoloading
    spl_autoload_register(function ($class) {
        $prefix = 'PHAPI\\';
        $base_dir = __DIR__ . '/../src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}

use PHAPI\PHAPI;
use PHAPI\HTTP\Response;

// Initialize API
$api = new PHAPI('0.0.0.0', 9503);

// Configure logging
$api->configureLogging(debug: false);

// Enable performance monitoring (optional)
// When enabled, processes health check logs every 5 minutes and stores metrics
// $api->enablePerformanceMonitoring();

// Enable CORS for all origins (simple one-liner)
$api->enableCORS();

// Or enable CORS for specific origins (production):
// $api->enableCORS(origins: ['https://example.com', 'https://app.example.com']);

// ============================================================================
// DATABASE (Optional)
// ============================================================================

// Configure SQLite database (optional - only needed if using database features)
// $api->configureDatabase(__DIR__ . '/db/phapi.sqlite', [
//     'autoload' => true  // Load autoload options into memory (via middleware)
// ]);

// WordPress-style options (requires database):
// $api->option('site_name', 'My Website');
// $api->option('theme_settings', ['color' => 'blue', 'font' => 'Arial']);
// $siteName = $api->option('site_name'); // 'My Website'

// Transients (temporary cache with expiration):
// $api->transient('cache_key', $data, 3600); // Expires in 1 hour
// $cached = $api->getTransient('cache_key', 'default'); // Get with default
// $api->deleteTransient('cache_key'); // Delete manually

// ============================================================================
// GLOBAL MIDDLEWARE - Runs before all routes
// ============================================================================

$api->middleware(function($request, $response, $next) use ($api) {
    // Example: Log all requests
    $api->log()->access()->info("Global middleware executed", [
        'uri' => $request->server['request_uri'] ?? ''
    ]);
    
    return $next(); // Continue to next middleware or route
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
    return $next(); // Continue to route handler
});

// Rate limiting middleware (example)
$api->addMiddleware('rateLimit', function($request, $response, $next) {
    // Example: Simple rate limiting check
    $ip = $request->server['remote_addr'] ?? 'unknown';
    
    // In real app, check rate limit here
    // if (rateLimitExceeded($ip)) {
    //     return Response::json($response, ['error' => 'Too many requests'], 429);
    // }
    
    return $next();
});

// ============================================================================
// ROUTES
// ============================================================================

// Root endpoint
$api->get('/', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'PHPAPI is running',
        'endpoints' => ['/health', '/users', '/process', '/protected']
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

// Create user endpoint with validation
$api->post('/users', function($input, $request, $response, $api) {
    // $input is already validated at this point
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

// ============================================================================
// ROUTES WITH MIDDLEWARE AND VALIDATION
// ============================================================================

// Protected process endpoint with auth middleware and validation
$api->middleware('auth')
    ->middleware('rateLimit')
    ->post('/process', function($input, $request, $response, $api) {
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
// ROUTES WITH INLINE MIDDLEWARE
// ============================================================================

// Protected route with inline middleware
$api->middleware(function($request, $response, $next) use ($api) {
    $api->log()->debug()->info("Inline middleware executed");
    return $next();
})->get('/protected', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'This is a protected route'
    ]);
});

// ============================================================================
// SCHEDULED JOBS (Recurring Tasks)
// ============================================================================
// Jobs run automatically at specified intervals using Swoole timers
// The handler receives the logger instance

// Cleanup job - runs every 60 seconds (1 minute)
$api->schedule('cleanup', 60, function($logger) {
    $logger->system()->info("Cleanup job running");
    
    // Perform cleanup tasks here (e.g., delete old files, expire cache, etc.)
    // Example: Clean up temporary files older than 1 hour
});

// Reporting job - runs every 300 seconds (5 minutes)
$api->schedule('reporting', 300, function($logger) {
    $logger->system()->info("Reporting job running");
    
    // Generate reports, send emails, etc.
});

// Clean expired transients job - runs every 300 seconds (5 minutes)
// Only needed if using database with transients
if ($api->db()) {
    $api->schedule('cleanExpiredTransients', 300, function($logger) {
        $deleted = \PHAPI\Database\Options::clearExpiredTransients();
        if ($deleted > 0) {
            $logger->system()->info("Cleaned expired transients", ['count' => $deleted]);
        }
    });
}

// ============================================================================
// BACKGROUND TASKS
// ============================================================================

$api->task('processData', function($data, $logger) {
    $logger->task()->info("Task started", ['data' => $data]);
    
    // Simulate processing
    sleep(5);
    
    $logger->task()->info("Task completed successfully", ['data' => $data]);
});

// ============================================================================
// KEEP-ALIVE TIMER (Prevents cold starts)
// ============================================================================
// Set up timer to hit /health endpoint every 5 seconds
// This keeps the application warm and prevents idle process termination
$api->onStart(function ($server) use ($api) {
    \Swoole\Timer::tick(5000, function () use ($server, $api) { // 5 seconds
        // Use coroutine HTTP client to hit health endpoint
        go(function () use ($server, $api) {
            try {
                $startTime = microtime(true);
                $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $server->port);
                $client->set(['timeout' => 2]);
                $client->get('/health');
                $responseTime = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
                $client->close();
                
                // Log response time to measure keep-alive effectiveness
                $api->log()->system()->info("Keep-alive health check", [
                    'response_time_ms' => $responseTime,
                    'status_code' => $client->statusCode ?? 0
                ]);
            } catch (\Throwable $e) {
                // Log failures for debugging
                $api->log()->system()->warning("Keep-alive health check failed", [
                    'error' => $e->getMessage()
                ]);
            }
        });
    });
});

// ============================================================================
// START SERVER
// ============================================================================

$api->run();
