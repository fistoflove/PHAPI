<?php

/**
 * Multi-file approach - Separated structure
 * Perfect for larger applications with multiple routes and tasks
 * 
 * Structure:
 * - app.php (this file) - Main entry point
 * - app/ folder containing:
 *   - middlewares.php
 *   - routes.php
 *   - tasks.php
 */

// Check Swoole extension is loaded
if (!extension_loaded('swoole')) {
    die("Error: Swoole extension is not loaded!\n\n" .
        "Please run with: php -c ../../phapi.ini app.php\n" .
        "Or from test-package root: php -c phapi.ini examples/multi-file/app.php\n");
}

// Support both Composer and bootstrap.php
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../bootstrap.php')) {
    require __DIR__ . '/../../bootstrap.php';
} else {
    // Manual autoloading
    spl_autoload_register(function ($class) {
        $prefix = 'PHAPI\\';
        $base_dir = __DIR__ . '/../../src/';
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

// ============================================================================
// INITIALIZE AND CONFIGURE API
// ============================================================================

$api = new PHAPI('0.0.0.0', 9503);

// Configure logging
$api->configureLogging(debug: false);

// Enable performance monitoring (optional)
// Processes health check logs every 5 minutes and stores metrics in performance.log
// $api->enablePerformanceMonitoring();

// Enable CORS for all origins
$api->enableCORS();

// Or for specific origins in production:
// $api->enableCORS(origins: ['https://example.com', 'https://app.example.com']);

// ============================================================================
// DATABASE (Optional)
// ============================================================================

// Configure SQLite database (optional - only needed if using database features)
// $api->configureDatabase(__DIR__ . '/../db/phapi.sqlite', [
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
// LOAD APP STRUCTURE
// ============================================================================
// Automatically loads: app/middlewares.php, app/routes.php, app/tasks.php
// Files are loaded in the correct order automatically

$api->loadApp();

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
