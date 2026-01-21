<?php
/**
 * Minimal PHAPI Test
 * Only run this after compatibility test confirms Swoole is available
 */

require __DIR__ . '/bootstrap.php';

use PHAPI\PHAPI;
use PHAPI\HTTP\Response;

try {
    $api = new PHAPI('0.0.0.0', 9501);
    
    $api->get('/test', function($input, $request, $response, $api) {
        Response::json($response, [
            'message' => 'PHAPI is working!',
            'swoole' => extension_loaded('swoole'),
            'version' => phpversion('swoole')
        ]);
    });
    
    echo "✓ PHAPI initialized successfully\n";
    echo "✓ Starting server on http://0.0.0.0:9501\n";
    echo "✓ Test endpoint: http://localhost:9501/test\n";
    echo "\n";
    
    $api->run();
} catch (\Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
