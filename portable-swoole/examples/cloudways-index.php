<?php

/**
 * Cloudways Entry Point - index.php
 * 
 * This file should replace your public_html/index.php
 * It handles incoming web requests via Swoole HTTP server
 * 
 * Installation:
 * 1. Upload this file as index.php to your public_html directory
 * 2. Update the path to your PHAPI application
 * 3. Ensure Swoole extension is installed (php bin/phapi-install)
 * 4. Run: php -c phapi.ini examples/multi-file/app.php (or single-file.php)
 * 
 * Note: Swoole server must be running in background or via supervisor/systemd
 */

// Check if Swoole is loaded
if (!extension_loaded('swoole')) {
    // Try to load via custom php.ini
    $iniPath = __DIR__ . '/phapi.ini';
    if (file_exists($iniPath)) {
        // This won't actually load the extension in a web request,
        // but we can show an error
        die("Error: Swoole extension not loaded.\n\n" .
            "Please run from CLI:\n" .
            "  php -c phapi.ini examples/multi-file/app.php\n\n" .
            "Or set up a process manager (supervisor/systemd) to keep the server running.");
    } else {
        die("Error: Swoole extension not loaded and phapi.ini not found.\n\n" .
            "Please run: php bin/phapi-install");
    }
}

// Path to your PHAPI application
// Update this to point to your actual app.php
$appPath = __DIR__ . '/cloudways-package/examples/multi-file/app.php';

// For Cloudways, we have two options:
// Option 1: Swoole is running on a port (e.g., 9503) - proxy to it
// Option 2: Run PHAPI inline (not recommended for production)

// Option 1: Proxy to running Swoole server (recommended)
$swooleHost = '127.0.0.1';
$swoolePort = 9503;

// Check if Swoole server is running
$connection = @fsockopen($swooleHost, $swoolePort, $errno, $errstr, 1);
if ($connection) {
    // Proxy the request to Swoole server
    fclose($connection);
    
    // Forward the request using cURL (faster than file_get_contents)
    $url = "http://{$swooleHost}:{$swoolePort}" . $_SERVER['REQUEST_URI'];
    
    // Use cURL if available (much faster with connection reuse)
    if (function_exists('curl_init')) {
        static $ch = null;
        
        // Initialize or reuse cURL handle for connection reuse
        if ($ch === null) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 30,
                CURLOPT_TCP_KEEPINTVL => 10,
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
        }
        
        // Prepare headers
        $headers = [];
        $allHeaders = getallheaders();
        foreach ($allHeaders as $name => $value) {
            // Skip headers that shouldn't be forwarded
            if (strtolower($name) !== 'host' && strtolower($name) !== 'connection') {
                $headers[] = "$name: $value";
            }
        }
        
        // Forward real client IP
        $realIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!isset($allHeaders['X-Forwarded-For'])) {
            $headers[] = "X-Forwarded-For: $realIp";
        }
        if (!isset($allHeaders['X-Real-IP'])) {
            $headers[] = "X-Real-IP: $realIp";
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if (in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        }
        
        $response = curl_exec($ch);
        
        if ($response !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);
            
            http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            
            // Forward headers
            $headerLines = explode("\r\n", $responseHeaders);
            foreach ($headerLines as $headerLine) {
                if (empty($headerLine) || stripos($headerLine, 'HTTP/') === 0) {
                    continue;
                }
                
                $colonPos = strpos($headerLine, ':');
                if ($colonPos !== false) {
                    $headerName = trim(substr($headerLine, 0, $colonPos));
                    $headerValue = trim(substr($headerLine, $colonPos + 1));
                    
                    $skipHeaders = ['content-length', 'transfer-encoding', 'connection'];
                    if (!in_array(strtolower($headerName), $skipHeaders)) {
                        header("$headerName: $headerValue", false);
                    }
                }
            }
            
            echo $responseBody;
            exit;
        }
    } else {
        // Fallback to file_get_contents if cURL not available
        $url = "http://{$swooleHost}:{$swoolePort}" . $_SERVER['REQUEST_URI'];
        $context = stream_context_create([
            'http' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'header' => getallheaders(),
                'content' => file_get_contents('php://input'),
                'ignore_errors' => true,
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            // Get response headers
            $headers = [];
            foreach ($http_response_header as $header) {
                header($header);
            }
            echo $response;
            exit;
        }
    }
}

// Option 2: If server is not running, show error
http_response_code(503);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'Service Unavailable',
    'message' => 'PHAPI Swoole server is not running',
    'instructions' => 'Please start the server: php -c phapi.ini examples/multi-file/app.php'
], JSON_PRETTY_PRINT);

function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    return $headers;
}

