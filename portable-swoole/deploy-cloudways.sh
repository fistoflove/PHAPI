#!/bin/bash

# PHAPI Cloudways Deployment Script
# Scaffolds PHAPI app in public_html root for base domain access
# Usage: ./deploy-cloudways.sh

set -e

echo "🚀 PHAPI Cloudways Deployment"
echo "=============================================="
echo ""

# Step 1: Detect public_html location
echo "📂 Step 1/6: Detecting public_html directory..."

PUBLIC_HTML=""
if [ -d "public_html" ]; then
    PUBLIC_HTML="public_html"
    echo "   ✓ Found public_html in current directory"
elif [ "$(basename "$(pwd)")" = "public_html" ]; then
    PUBLIC_HTML="."
    echo "   ✓ Already in public_html directory"
elif [ -d "../public_html" ]; then
    PUBLIC_HTML="../public_html"
    echo "   ✓ Found public_html in parent directory"
else
    PUBLIC_HTML="."
    echo "   ⚠ Assuming current directory is public_html"
fi

cd "$PUBLIC_HTML"
PUBLIC_HTML_ABS="$(pwd)"
echo "   📁 Target: $PUBLIC_HTML_ABS"
echo ""

# Step 2: Check if PHAPI library is available
echo "📚 Step 2/6: Checking for PHAPI library..."

# Look for PHAPI source in common locations
PHAPI_SRC=""
if [ -d "cloudways-package/src" ]; then
    PHAPI_SRC="cloudways-package/src"
elif [ -d "../cloudways-package/src" ]; then
    PHAPI_SRC="../cloudways-package/src"
elif [ -d "vendor/phapi/phapi/src" ]; then
    PHAPI_SRC="vendor/phapi/phapi/src"
elif [ -d "src" ]; then
    PHAPI_SRC="src"
else
    echo "   ❌ Error: PHAPI source not found"
    echo "   Please extract the package first or ensure src/ directory exists"
    exit 1
fi

echo "   ✓ Found PHAPI at: $PHAPI_SRC"
echo ""

# Step 3: Install Swoole extension
echo "🔌 Step 3/6: Installing Swoole extension..."

# Find extension file - search multiple locations
EXTENSION_FILE=""
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_SEARCH_PATHS=(
    "${SCRIPT_DIR}/bin/extensions/php8.1/linux-x86_64/swoole.so"
    "${SCRIPT_DIR}/../cloudways-package/bin/extensions/php8.1/linux-x86_64/swoole.so"
    "cloudways-package/bin/extensions/php8.1/linux-x86_64/swoole.so"
    "../cloudways-package/bin/extensions/php8.1/linux-x86_64/swoole.so"
    "bin/extensions/php8.1/linux-x86_64/swoole.so"
)

for path in "${EXTENSION_SEARCH_PATHS[@]}"; do
    if [ -f "$path" ]; then
        # Get absolute path
        EXTENSION_FILE="$(cd "$(dirname "$path")" 2>/dev/null && pwd)/$(basename "$path")"
        if [ -f "$EXTENSION_FILE" ]; then
            break
        fi
    fi
done

if [ -n "$EXTENSION_FILE" ] && [ -f "$EXTENSION_FILE" ]; then
    # Ensure we're in public_html
    cd "$PUBLIC_HTML_ABS"
    
    # Create phapi.ini in public_html root with absolute path
    cat > phapi.ini << EOF
; PHAPI Custom php.ini
; Auto-generated for loading bundled Swoole extension
; Generated: $(date '+%Y-%m-%d %H:%M:%S')
; PHP Version: $(php -r 'echo PHP_VERSION;')
; Platform: $(php -r 'echo strtolower(PHP_OS) . "-" . php_uname("m");')

extension = "$EXTENSION_FILE"
EOF
    echo "   ✓ Created phapi.ini in $PUBLIC_HTML_ABS/phapi.ini"
    echo "   📄 Extension path: $EXTENSION_FILE"
    
    # Verify Swoole loads
    if php -c phapi.ini -r "echo extension_loaded('swoole') ? 'OK' : 'FAIL';" 2>/dev/null | grep -q "OK"; then
        echo "   ✓ Swoole extension verified and loads correctly"
    else
        echo "   ⚠ Warning: Extension file found but may not load correctly"
        echo "   Testing: php -c phapi.ini -m | grep swoole"
        php -c phapi.ini -m 2>&1 | grep -i swoole || echo "   ❌ Extension did not load"
    fi
else
    echo "   ⚠ Swoole extension file not found"
    echo "   Searched in:"
    for path in "${EXTENSION_SEARCH_PATHS[@]}"; do
        echo "     - $path"
    done
    echo "   You may need to rebuild the extension or install it manually"
fi
echo ""

# Step 4: Ask user for structure
echo "📋 Step 4/7: Project structure..."
echo ""
echo "Choose your application structure:"
echo "  1) Single-file (everything in app.php - recommended for simple apps)"
echo "  2) Multi-file (separated into app/, routes, middlewares, tasks)"
echo ""
read -p "Enter choice [1]: " STRUCTURE_CHOICE
STRUCTURE_CHOICE=${STRUCTURE_CHOICE:-1}

if [ "$STRUCTURE_CHOICE" = "2" ]; then
    STRUCTURE="multi"
    echo "   ✓ Selected: Multi-file structure"
else
    STRUCTURE="single"
    echo "   ✓ Selected: Single-file structure"
fi
echo ""

# Step 4.5: Ask user for worker configuration
echo "⚙️  Step 5/7: Worker configuration..."
echo ""
echo "Configure Swoole workers:"
echo "  - HTTP workers: Handle incoming requests"
echo "    (0 = auto/CPU count, or specify a number)"
echo "    Note: You can set more than CPU count for I/O-bound apps (1-4x CPU recommended)"
echo "  - Task workers: Handle background tasks (default: 4)"
echo ""
read -p "HTTP workers (0 for auto/CPU count) [0]: " WORKER_NUM
WORKER_NUM=${WORKER_NUM:-0}
read -p "Task workers [4]: " TASK_WORKER_NUM
TASK_WORKER_NUM=${TASK_WORKER_NUM:-4}

if [ "$WORKER_NUM" = "0" ]; then
    WORKER_DESC="auto (CPU count)"
else
    WORKER_DESC="$WORKER_NUM"
fi

echo "   ✓ HTTP workers: $WORKER_DESC"
echo "   ✓ Task workers: $TASK_WORKER_NUM"
echo ""

# Step 6: Copy PHAPI library if needed
echo "📦 Step 6/7: Setting up PHAPI library..."

if [ ! -d "vendor/phapi" ]; then
    mkdir -p vendor
    if [ -d "$PHAPI_SRC" ]; then
        mkdir -p vendor/phapi/phapi
        cp -r "$PHAPI_SRC" vendor/phapi/phapi/src
        echo "   ✓ Copied PHAPI library to vendor/phapi/phapi"
    else
        echo "   ⚠ Could not copy PHAPI library - ensure it's available"
    fi
fi

# Create bootstrap autoloader
cat > bootstrap.php << 'BOOTSTRAP'
<?php
/**
 * PHAPI Bootstrap - Autoload PHAPI classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'PHAPI\\';
    $base_dir = __DIR__ . '/vendor/phapi/phapi/src/';
    
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
BOOTSTRAP

echo "   ✓ Created bootstrap.php"
echo ""

# Step 7: Create application structure
echo "🏗️  Step 7/7: Creating application structure..."

# Backup existing index.php if it exists
if [ -f "index.php" ]; then
    if [ ! -f "index.php.phapi-backup" ]; then
        cp index.php index.php.phapi-backup
        echo "   ✓ Backed up existing index.php"
    fi
fi

# Create app.php based on structure
if [ "$STRUCTURE" = "multi" ]; then
    # Multi-file structure
    mkdir -p app logs
    
    # Create app.php
    cat > app.php << 'APPBASE'
<?php
/**
 * PHAPI Application - Multi-file Structure
 */

// Check Swoole extension
if (!extension_loaded('swoole')) {
    die("Error: Swoole extension not loaded. Run: php bin/phapi-install\n");
}

require __DIR__ . '/bootstrap.php';

use PHAPI\PHAPI;

// Initialize API
$api = new PHAPI('127.0.0.1', 9503);

// Configure logging
$api->configureLogging(null, true, \PHAPI\Logging\Logger::LEVEL_INFO, false);

// Enable performance monitoring (optional)
// Processes health check logs every 5 minutes and stores metrics in performance.log
// $api->enablePerformanceMonitoring();

// Enable CORS
$api->enableCORS();

// Configure workers
$api->setWorkers(__WORKER_NUM__, __TASK_WORKER_NUM__);

// ============================================================================
// DATABASE (Optional)
// ============================================================================

// Configure SQLite database (optional - only needed if using database features)
// Uncomment to enable database:
// $api->configureDatabase(__DIR__ . '/db/phapi.sqlite', [
//     'autoload' => true  // Load autoload options into memory (via middleware)
// ]);

// WordPress-style options (requires database):
// $api->option('site_name', 'My Website');
// $api->option('theme_settings', ['color' => 'blue', 'font' => 'Arial']);
// $siteName = $api->option('site_name'); // 'My Website'

// Load application components
$api->loadApp(__DIR__ . '/app');

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

// Start server
$api->run();
APPBASE

    # Replace worker placeholders with actual values
    sed -i "s/__WORKER_NUM__/$WORKER_NUM/g" app.php
    sed -i "s/__TASK_WORKER_NUM__/$TASK_WORKER_NUM/g" app.php

    # Create app/middlewares.php
    cat > app/middlewares.php << 'MIDDLEWARE'
<?php
/**
 * Middleware Definitions
 */

use PHAPI\HTTP\Response;

// Access PHAPI instance via global (set in app.php)
global $api;

// ============================================================================
// GLOBAL MIDDLEWARE - Runs before all routes
// ============================================================================

// Example: Global logging middleware (commented out by default)
// $api->middleware(function($request, $response, $next) use ($api) {
//     // This middleware runs before handlers
//     $api->log()->access()->info("Before handler", [
//         'method' => $request->server['request_method'] ?? '',
//         'uri' => $request->server['request_uri'] ?? ''
//     ]);
//     return $next();
// });

// ============================================================================
// AFTER MIDDLEWARE - Runs after handler, before response end
// ============================================================================

// Non-blocking logging - runs after handler executes
// The framework also logs "Request completed" automatically, so you may not need this
// $api->afterMiddleware(function($request, $response, $next) use ($api) {
//     $api->log()->access()->info("After handler", [
//         'method' => $request->server['request_method'] ?? '',
//         'uri' => $request->server['request_uri'] ?? '',
//         'status' => $response->statusCode ?? 200
//     ]);
//     return $next();
// });

// ============================================================================
// NAMED MIDDLEWARE - Reusable across routes
// ============================================================================

// Authentication middleware (example)
// $api->addMiddleware('auth', function($request, $response, $next) {
//     $token = $request->header['authorization'] ?? null;
//     
//     if (!$token) {
//         return Response::json($response, ['error' => 'Unauthorized'], 401);
//     }
//     
//     // In real app, validate token here
//     return $next();
// });
MIDDLEWARE

    # Create app/routes.php
    cat > app/routes.php << 'ROUTES'
<?php
/**
 * Route Definitions
 * All HTTP routes go here
 */

use PHAPI\HTTP\Response;

// Access PHAPI instance via global (set in app.php)
global $api;

// ============================================================================
// PUBLIC ROUTES
// ============================================================================

// Health check endpoint
$api->get('/health', function($input, $request, $response, $api) {
    Response::json($response, [
        'ok' => true,
        'time' => date('c')
    ]);
});

// Home route
$api->get('/', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'Welcome to PHAPI',
        'version' => '1.0',
        'endpoints' => ['/health', '/process'],
        'time' => date('c')
    ]);
});

// Process endpoint - dispatches background task
$api->post('/process', function($input, $request, $response, $api) {
    // Return immediately (202 Accepted)
    Response::json($response, [
        'status' => 'queued',
        'message' => 'Task queued for processing'
    ], 202);
    
    // Dispatch task asynchronously (doesn't block response)
    $api->dispatchTask('processData', $input);
    
    $api->log()->debug()->info("Task dispatched", ['task' => 'processData']);
})->validate([
    'task' => 'required|string',
    'data' => 'optional|array'
]);

// ============================================================================
// ROUTES WITH VALIDATION (examples)
// ============================================================================

// Create user with validation
// $api->post('/users', function($input, $request, $response, $api) {
//     // $input is already validated at this point
//     Response::json($response, [
//         'message' => 'User created',
//         'user' => $input
//     ], 201);
// })->validate([
//     'name' => 'required|string|min:3|max:100',
//     'email' => 'required|email'
// ]);
ROUTES

    # Create app/tasks.php
    cat > app/tasks.php << 'TASKS'
<?php
/**
 * Background Task Definitions
 * Define async background tasks here
 */

// Access PHAPI instance via global (set in app.php)
global $api;

// ============================================================================
// TASK DEFINITIONS
// ============================================================================

// Process data task
$api->task('processData', function($data, $logger) {
    $logger->task()->info("Task started", ['data' => $data]);
    
    // Simulate processing (in real app, do actual work here)
    sleep(2);
    
    $logger->task()->info("Task completed", ['data' => $data]);
    
    return ['status' => 'processed', 'timestamp' => date('c')];
});
TASKS

    # Create app/jobs.php
    cat > app/jobs.php << 'JOBS'
<?php
/**
 * Scheduled Jobs
 * Recurring tasks that run automatically at specified intervals
 * 
 * Jobs are time-triggered tasks that use Swoole timers.
 * The handler receives the logger instance.
 */

// Access PHAPI instance via global (set in app.php)
global $api;

// ============================================================================
// SCHEDULED JOBS
// ============================================================================

// Cleanup job - runs every 60 seconds (1 minute)
$api->schedule('cleanup', 60, function($logger) use ($api) {
    $logger->system()->info("Cleanup job running");
    
    // Perform cleanup tasks here
    // Example: Delete old temporary files, expire cache entries, etc.
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

// Reporting job - runs every 300 seconds (5 minutes)
$api->schedule('reporting', 300, function($logger) {
    $logger->system()->info("Reporting job running");
    
    // Generate reports, send summary emails, etc.
});

// Data sync job - runs every 3600 seconds (1 hour)
$api->schedule('dataSync', 3600, function($logger) use ($api) {
    $logger->system()->info("Data sync job running");
    
    // Sync data with external services, backup databases, etc.
});
JOBS

    echo "   ✓ Created multi-file structure:"
    echo "     - app.php (main entry point)"
    echo "     - app/middlewares.php"
    echo "     - app/routes.php"
    echo "     - app/tasks.php"
    echo "     - app/jobs.php"
    echo "     - logs/ (for log files)"

else
    # Single-file structure
    cat > app.php << 'APPSINGLE'
<?php
/**
 * PHAPI Application - Single-file Structure
 * Everything in one file - perfect for simple APIs
 */

// Check Swoole extension
if (!extension_loaded('swoole')) {
    die("Error: Swoole extension not loaded. Run: php bin/phapi-install\n");
}

require __DIR__ . '/bootstrap.php';

use PHAPI\PHAPI;
use PHAPI\HTTP\Response;

// Initialize API
$api = new PHAPI('127.0.0.1', 9503);

// Configure logging
$api->configureLogging(null, true, \PHAPI\Logging\Logger::LEVEL_INFO, false);

// Enable performance monitoring (optional)
// Processes health check logs every 5 minutes and stores metrics in performance.log
// $api->enablePerformanceMonitoring();

// Enable CORS
$api->enableCORS();

// Configure workers
$api->setWorkers(__WORKER_NUM__, __TASK_WORKER_NUM__);

// ============================================================================
// DATABASE (Optional)
// ============================================================================

// Configure SQLite database (optional - only needed if using database features)
// Uncomment to enable database:
// $api->configureDatabase(__DIR__ . '/db/phapi.sqlite', [
//     'autoload' => true  // Load autoload options into memory (via middleware)
// ]);

// WordPress-style options (requires database):
// $api->option('site_name', 'My Website');
// $api->option('theme_settings', ['color' => 'blue', 'font' => 'Arial']);
// $siteName = $api->option('site_name'); // 'My Website'

// ============================================================================
// SCHEDULED JOBS (Recurring Tasks)
// ============================================================================

// Cleanup job - runs every 60 seconds (1 minute)
$api->schedule('cleanup', 60, function($logger) use ($api) {
    $logger->system()->info("Cleanup job running");
    
    // Perform cleanup tasks here (e.g., delete old files, expire cache, etc.)
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

// Reporting job - runs every 300 seconds (5 minutes)
$api->schedule('reporting', 300, function($logger) {
    $logger->system()->info("Reporting job running");
    
    // Generate reports, send emails, etc.
});

// ============================================================================
// TASKS
// ============================================================================

// Define background task for processing
$api->task('processData', function($data, $logger) {
    $logger->task()->info("Task started", ['data' => $data]);
    
    // Simulate processing (in real app, do actual work here)
    sleep(2);
    
    $logger->task()->info("Task completed", ['data' => $data]);
    
    return ['status' => 'processed', 'timestamp' => date('c')];
});

// ============================================================================
// ROUTES
// ============================================================================

// Health check
$api->get('/health', function($input, $request, $response, $api) {
    Response::json($response, ['status' => 'ok', 'time' => date('c')]);
});

// Home route
$api->get('/', function($input, $request, $response, $api) {
    Response::json($response, [
        'message' => 'Welcome to PHAPI',
        'version' => '1.0',
        'time' => date('c')
    ]);
});

// Process endpoint - dispatches background task
$api->post('/process', function($input, $request, $response, $api) {
    // Return immediately (202 Accepted)
    Response::json($response, [
        'status' => 'queued',
        'message' => 'Task queued for processing'
    ], 202);
    
    // Dispatch task asynchronously (doesn't block response)
    $api->dispatchTask('processData', $input);
    
    $api->log()->debug()->info("Task dispatched", ['task' => 'processData']);
})->validate([
    'task' => 'required|string',
    'data' => 'optional|array'
]);

// Example: POST route with validation
$api->post('/api/users', function($input, $request, $response, $api) {
    // $input is already validated at this point
    Response::json($response, [
        'message' => 'User created',
        'user' => $input
    ], 201);
})->validate([
    'name' => 'required|string|min:3',
    'email' => 'required|email'
]);

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
APPSINGLE

    # Replace worker placeholders with actual values
    sed -i "s/__WORKER_NUM__/$WORKER_NUM/g" app.php
    sed -i "s/__TASK_WORKER_NUM__/$TASK_WORKER_NUM/g" app.php

    echo "   ✓ Created single-file structure:"
    echo "     - app.php (complete application)"
    echo "     - logs/ (for log files)"
fi

mkdir -p logs
echo "   ✓ Created logs directory"
echo ""

# Create .htaccess to ensure index.php is used for all requests
cat > .htaccess << 'HTACCESS'
# PHAPI - Route all requests to index.php
# This ensures Swoole server handles all routes via index.php proxy

# Use index.php as entry point
DirectoryIndex index.php

# Route all requests to index.php (if mod_rewrite is available)
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Don't rewrite if file/directory exists
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    
    # Route everything else to index.php
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# If mod_rewrite is not available, try FallbackResource (Apache 2.2.16+)
<IfModule mod_dir.c>
    DirectoryIndex index.php
</IfModule>
HTACCESS

echo "   ✓ Created .htaccess (routes all requests to index.php)"

# Create index.php that proxies to Swoole
cat > index.php << 'INDEX'
<?php
/**
 * PHAPI Entry Point - Proxies to Swoole Server
 * This file receives web requests and forwards them to the Swoole server
 */

$swooleHost = '127.0.0.1';
$swoolePort = 9503;

// Check if Swoole server is running
function isSwooleRunning($host, $port) {
    $connection = @fsockopen($host, $port, $errno, $errstr, 1);
    if ($connection) {
        fclose($connection);
        return true;
    }
    return false;
}

// Proxy request to Swoole using cURL (with connection reuse)
// Note: Connection reuse works when PHP-FPM reuses worker processes.
// With mod_php/CGI, each request is a new process, but cURL is still
// much faster than file_get_contents due to native C implementation.
function proxyToSwoole($host, $port, $uri, $method, $headers, $body) {
    static $ch = null;
    
    $url = "http://{$host}:{$port}{$uri}";
    
    // Check if cURL extension is available
    if (!function_exists('curl_init')) {
        // Fallback to file_get_contents if cURL is not available
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => $headers,
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
                'follow_location' => false
            ]
        ];
        
        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false && isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'HTTP/') === 0) {
                    http_response_code((int)substr($header, 9, 3));
                } elseif (stripos($header, 'Content-Length:') === false &&
                         stripos($header, 'Transfer-Encoding:') === false) {
                    header($header);
                }
            }
            return $response;
        }
        return false;
    }
    
    // Initialize or reuse cURL handle for connection reuse
    if ($ch === null) {
        $ch = curl_init();
        
        // Connection reuse settings
        curl_setopt_array($ch, [
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 10,
            CURLOPT_FRESH_CONNECT => false, // Reuse existing connections
            CURLOPT_FORBID_REUSE => false,
            
            // Basic options
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Use HTTP/1.1 for keep-alive
            
            // Enable connection reuse
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
    }
    
    // Prepare headers array (cURL expects array format)
    $curlHeaders = [];
    foreach ($headers as $header) {
        // Skip empty headers
        if (!empty(trim($header))) {
            $curlHeaders[] = trim($header);
        }
    }
    
    // Set request-specific options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    
    // Set body for methods that support it
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    
    // Execute request
    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        // Log error if needed (but don't expose to client)
        return false;
    }
    
    // Parse response
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    
    // Get status code
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    http_response_code($statusCode);
    
    // Parse and forward headers
    $headerLines = explode("\r\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        if (empty($headerLine) || stripos($headerLine, 'HTTP/') === 0) {
            continue; // Skip HTTP status line
        }
        
        $colonPos = strpos($headerLine, ':');
        if ($colonPos === false) {
            continue;
        }
        
        $headerName = trim(substr($headerLine, 0, $colonPos));
        $headerValue = trim(substr($headerLine, $colonPos + 1));
        
        // Skip headers that PHP handles automatically or shouldn't be forwarded
        $skipHeaders = ['content-length', 'transfer-encoding', 'connection'];
        if (!in_array(strtolower($headerName), $skipHeaders)) {
            header("$headerName: $headerValue", false);
        }
    }
    
    return $responseBody;
}

// Get request headers
function getRequestHeaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[] = "$headerName: $value";
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers[] = "Content-Type: " . $_SERVER['CONTENT_TYPE'];
    }
    
    // Forward real client IP for logging
    $realIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Only add if not already present (to avoid duplication)
        $headers[] = "X-Forwarded-For: $realIp";
    }
    
    // Also add X-Real-IP
    if (!isset($_SERVER['HTTP_X_REAL_IP'])) {
        $headers[] = "X-Real-IP: $realIp";
    }
    
    return $headers;
}

// Check if server is running
if (!isSwooleRunning($swooleHost, $swoolePort)) {
    http_response_code(503);
    header('Content-Type: application/json');
    
    $error = [
        'error' => 'Service Unavailable',
        'message' => 'PHAPI Swoole server is not running',
        'instructions' => [
            '1. Start the server:',
            '   php -c phapi.ini app.php',
            '',
            '2. Or run in background:',
            '   nohup php -c phapi.ini app.php > phapi.log 2>&1 &',
            '',
            '3. Check if running:',
            '   ps aux | grep "app.php"'
        ]
    ];
    
    echo json_encode($error, JSON_PRETTY_PRINT);
    exit;
}

// Proxy the request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$headers = getRequestHeaders();
$body = file_get_contents('php://input');

$response = proxyToSwoole($swooleHost, $swoolePort, $uri, $method, $headers, $body);

if ($response !== false) {
    echo $response;
} else {
    http_response_code(502);
    echo json_encode([
        'error' => 'Bad Gateway',
        'message' => 'Failed to connect to PHAPI server'
    ], JSON_PRETTY_PRINT);
}
INDEX

echo "   ✓ Created index.php (proxies to Swoole)"
echo ""

# Create start script
cat > start-server.sh << STARTER
#!/bin/bash
# PHAPI Server Starter

SCRIPT_DIR="\$(cd "\$(dirname "\${BASH_SOURCE[0]}")" && pwd)"
cd "\$SCRIPT_DIR"

echo "🚀 Starting PHAPI Server..."
echo ""

# Check for phapi.ini
INI_FILE="phapi.ini"
if [ ! -f "\$INI_FILE" ]; then
    echo "❌ Error: phapi.ini not found in current directory"
    echo "   Current directory: \$(pwd)"
    echo "   Expected: \$INI_FILE"
    exit 1
fi

# Check Swoole loads
if php -c "\$INI_FILE" -m 2>/dev/null | grep -q swoole; then
    echo "✓ Swoole extension loaded from \$INI_FILE"
else
    echo "❌ Error: Swoole extension not loaded"
    echo ""
    echo "Troubleshooting:"
    echo "  1. Check phapi.ini exists: ls -la \$INI_FILE"
    echo "  2. Check extension path: cat \$INI_FILE"
    echo "  3. Test manual load: php -c \$INI_FILE -m | grep swoole"
    echo "  4. Check extension file exists at the path specified in \$INI_FILE"
    exit 1
fi

# Check if already running
if command -v lsof >/dev/null 2>&1 && lsof -Pi :9503 -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "⚠ Server already running on port 9503"
    echo "   Stop it first or change the port in app.php"
    exit 1
fi

echo "Starting server on http://127.0.0.1:9503"
echo "Access via your domain (index.php proxies to this)"
echo ""
echo "Press Ctrl+C to stop"
echo ""

php -c "\$INI_FILE" app.php
STARTER

chmod +x start-server.sh
echo "   ✓ Created start-server.sh"
echo ""

# Final instructions
echo "=============================================="
echo "✅ Deployment Complete!"
echo "=============================================="
echo ""
echo "📋 Your application structure:"
echo "   $PUBLIC_HTML_ABS/"
echo "   ├── index.php (entry point - proxies to Swoole)"
echo "   ├── app.php (PHAPI application)"
if [ "$STRUCTURE" = "multi" ]; then
    echo "   ├── app/"
    echo "   │   ├── middlewares.php"
    echo "   │   ├── routes.php"
    echo "   │   └── tasks.php"
fi
echo "   ├── bootstrap.php (autoloader)"
echo "   ├── vendor/phapi/phapi/ (PHAPI library)"
echo "   └── logs/ (log files)"
echo ""
echo "🚀 Next Steps:"
echo ""
echo "1. Start the PHAPI server:"
echo "   ./start-server.sh"
echo ""
echo "   Or run in background:"
echo "   nohup php -c phapi.ini app.php > phapi.log 2>&1 &"
echo ""
echo "2. Access your application:"
echo "   Visit your Cloudways domain - it will proxy to Swoole!"
echo ""
echo "3. Edit your application:"
if [ "$STRUCTURE" = "multi" ]; then
    echo "   - Routes: app/routes.php"
    echo "   - Middleware: app/middlewares.php"
    echo "   - Tasks: app/tasks.php"
else
    echo "   - All code: app.php"
fi
echo ""
echo "4. View logs:"
echo "   tail -f logs/access.log"
echo ""
echo "📝 Notes:"
echo "   • index.php proxies requests to Swoole (port 9503)"
echo "   • Keep Swoole server running for production (use supervisor/systemd)"
echo "   • Original index.php backed up to: index.php.phapi-backup"
echo ""
echo "🎉 Ready to go! Start the server and visit your domain."
