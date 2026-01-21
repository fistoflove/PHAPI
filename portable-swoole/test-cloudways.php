<?php

/**
 * Cloudways/CloudLinux Compatibility Test Script
 * 
 * Upload this file to your Cloudways server and run:
 *   php test-cloudways.php
 * 
 * This will test what's possible without sudo access
 */

echo "\n";
echo "================================================\n";
echo "  PHAPI Cloudways Compatibility Test\n";
echo "================================================\n\n";

$tests = [];
$canProceed = false;

// Test 1: PHP Version
echo "[1/8] Checking PHP Version...\n";
$phpVersion = PHP_VERSION;
$phpMajor = (int)PHP_MAJOR_VERSION;
$phpMinor = (int)PHP_MINOR_VERSION;
echo "  ✓ PHP Version: $phpVersion\n";
echo "  ✓ Major: $phpMajor, Minor: $phpMinor\n";

if ($phpMajor >= 8 && $phpMinor >= 1) {
    $tests['php_version'] = true;
    echo "  ✓ PHP version is compatible (8.1+)\n";
} else {
    $tests['php_version'] = false;
    echo "  ✗ PHP version too old (need 8.1+)\n";
}

// Test 2: Swoole Extension
echo "\n[2/8] Checking Swoole Extension...\n";
if (extension_loaded('swoole')) {
    $swooleVersion = phpversion('swoole');
    echo "  ✓ Swoole is loaded!\n";
    echo "  ✓ Swoole Version: $swooleVersion\n";
    $tests['swoole_loaded'] = true;
    $canProceed = true;
} else {
    echo "  ✗ Swoole extension is NOT loaded\n";
    $tests['swoole_loaded'] = false;
}

// Test 3: PHP Configuration
echo "\n[3/8] Checking PHP Configuration...\n";
$phpIniPath = php_ini_loaded_file();
$phpIniScanDir = php_ini_scanned_files();
$extensionDir = ini_get('extension_dir');
$disableFunctions = ini_get('disable_functions');

echo "  PHP ini loaded: " . ($phpIniPath ?: 'none') . "\n";
echo "  Extension directory: $extensionDir\n";
echo "  Disabled functions: " . ($disableFunctions ?: 'none') . "\n";

if ($phpIniPath && is_writable($phpIniPath)) {
    $tests['php_ini_writable'] = true;
    echo "  ✓ System php.ini is writable\n";
} else {
    $tests['php_ini_writable'] = false;
    echo "  ✗ System php.ini is NOT writable (expected)\n";
}

// Test 4: Custom php.ini Support
echo "\n[4/8] Testing Custom php.ini Support...\n";
$testIni = __DIR__ . '/test-php.ini';
$testIniContent = <<<'INI'
; Test php.ini
extension_dir = "/tmp/phapi-test-extensions"
INI;

if (file_put_contents($testIni, $testIniContent)) {
    $output = [];
    $returnVar = 0;
    exec("php -c $testIni -r 'echo php_ini_loaded_file();' 2>&1", $output, $returnVar);
    
    if ($returnVar === 0) {
        $tests['custom_php_ini'] = true;
        echo "  ✓ Custom php.ini files work!\n";
        echo "  ✓ Can use: php -c custom.ini script.php\n";
    } else {
        $tests['custom_php_ini'] = false;
        echo "  ✗ Custom php.ini test failed\n";
    }
    
    @unlink($testIni);
} else {
    $tests['custom_php_ini'] = false;
    echo "  ✗ Cannot create test php.ini file\n";
}

// Test 5: Extension Directory Detection
echo "\n[5/8] Testing Extension Loading Capabilities...\n";
$extDir = ini_get('extension_dir');
$extDirResolved = realpath($extDir) ?: $extDir;

echo "  Extension directory: $extDirResolved\n";

// Check if we can write to extension directory
if (is_writable($extDirResolved)) {
    $tests['extension_dir_writable'] = true;
    echo "  ✓ Extension directory is writable!\n";
    echo "  ✓ Could potentially install extensions there\n";
} else {
    $tests['extension_dir_writable'] = false;
    echo "  ✗ Extension directory is NOT writable (expected on shared hosting)\n";
}

// Test if we can load extensions from custom location
$testExtDir = __DIR__ . '/test-extensions';
if (is_dir($testExtDir) || mkdir($testExtDir, 0755, true)) {
    $tests['can_create_ext_dir'] = true;
    echo "  ✓ Can create extension directory in project\n";
    rmdir($testExtDir);
} else {
    $tests['can_create_ext_dir'] = false;
    echo "  ✗ Cannot create extension directory\n";
}

// Test 6: File Permissions
echo "\n[6/8] Testing File Permissions...\n";
$testFile = __DIR__ . '/test-write.txt';
if (file_put_contents($testFile, 'test')) {
    echo "  ✓ Can write files in current directory\n";
    $tests['can_write_files'] = true;
    
    // Test executable permissions
    if (chmod($testFile, 0755)) {
        $tests['can_set_executable'] = true;
        echo "  ✓ Can set executable permissions\n";
    } else {
        $tests['can_set_executable'] = false;
        echo "  ✗ Cannot set executable permissions\n";
    }
    
    @unlink($testFile);
} else {
    $tests['can_write_files'] = false;
    echo "  ✗ Cannot write files in current directory\n";
}

// Test 7: Environment Variables
echo "\n[7/8] Testing Environment Variables...\n";
if (getenv('PHPRC') || getenv('PHP_INI_SCAN_DIR')) {
    echo "  ✓ Can use PHPRC environment variable\n";
    echo "  ✓ Current PHPRC: " . (getenv('PHPRC') ?: 'not set') . "\n";
    $tests['phprc_support'] = true;
} else {
    $tests['phprc_support'] = false;
    echo "  ✗ PHPRC environment variable not tested\n";
}

// Test 8: Execute Commands
echo "\n[8/8] Testing Command Execution...\n";
if (!empty($disableFunctions)) {
    $disabled = explode(',', $disableFunctions);
    $disabled = array_map('trim', $disabled);
    
    if (in_array('exec', $disabled)) {
        echo "  ✗ exec() is disabled\n";
        $tests['can_exec'] = false;
    } else {
        echo "  ✓ exec() is available\n";
        $tests['can_exec'] = true;
    }
    
    if (in_array('shell_exec', $disabled)) {
        echo "  ✗ shell_exec() is disabled\n";
        $tests['can_shell_exec'] = false;
    } else {
        echo "  ✓ shell_exec() is available\n";
        $tests['can_shell_exec'] = true;
    }
} else {
    echo "  ✓ No disabled functions detected\n";
    $tests['can_exec'] = true;
    $tests['can_shell_exec'] = true;
}

// Summary
echo "\n";
echo "================================================\n";
echo "  TEST SUMMARY\n";
echo "================================================\n\n";

$passingTests = array_filter($tests);
$failingTests = array_filter($tests, fn($v) => !$v);

echo "Passing Tests: " . count($passingTests) . "/" . count($tests) . "\n";
foreach ($passingTests as $test => $value) {
    echo "  ✓ " . ucwords(str_replace('_', ' ', $test)) . "\n";
}

if (!empty($failingTests)) {
    echo "\nFailing Tests:\n";
    foreach ($failingTests as $test => $value) {
        echo "  ✗ " . ucwords(str_replace('_', ' ', $test)) . "\n";
    }
}

// Recommendations
echo "\n";
echo "================================================\n";
echo "  RECOMMENDATIONS\n";
echo "================================================\n\n";

if ($tests['swoole_loaded']) {
    echo "✓ Swoole is already installed! You can proceed with PHAPI.\n\n";
} elseif ($tests['custom_php_ini']) {
    echo "RECOMMENDED APPROACH: Custom php.ini with bundled extensions\n";
    echo "  • Bundle pre-compiled Swoole extensions for PHP {$phpVersion}\n";
    echo "  • Use custom php.ini in your project\n";
    echo "  • Run: php -c php.ini app.php\n\n";
    
    if ($tests['can_write_files']) {
        echo "  • Can create extension directory in project\n";
        echo "  • Can write php.ini in project\n\n";
    }
} elseif ($tests['extension_dir_writable']) {
    echo "ALTERNATIVE: Install extension to system extension_dir\n";
    echo "  • Extension dir: $extDirResolved\n";
    echo "  • You can copy swoole.so there (if you have it)\n\n";
} else {
    echo "LIMITED OPTIONS:\n";
    echo "  • Request Swoole installation from Cloudways support\n";
    echo "  • Use Docker (if available)\n";
    echo "  • Consider a different hosting provider\n\n";
}

// System Information
echo "\n";
echo "================================================\n";
echo "  SYSTEM INFORMATION\n";
echo "================================================\n\n";

echo "Operating System: " . PHP_OS . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "PHP Binary: " . PHP_BINARY . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') . "\n";
echo "Current Directory: " . getcwd() . "\n";
echo "User: " . get_current_user() . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";

// Check for Cloudways/CloudLinux specific indicators
if (file_exists('/usr/local/cpanel')) {
    echo "\n✓ cPanel detected\n";
}

if (file_exists('/etc/cloudlinux-release')) {
    echo "✓ CloudLinux detected\n";
}

if (file_exists('/etc/cagefs')) {
    echo "✓ CageFS detected (user isolation)\n";
}

echo "\n";
echo "================================================\n";
echo "  NEXT STEPS\n";
echo "================================================\n\n";

echo "1. Copy this output and save it\n";
echo "2. Share with PHAPI developers if you need help\n";
echo "3. Based on results, we'll determine the best approach\n\n";

if ($tests['custom_php_ini'] && !$tests['swoole_loaded']) {
    echo "✓ Ready to test bundled extension approach!\n";
    echo "  • Upload Swoole extension for PHP {$phpVersion}\n";
    echo "  • Create custom php.ini\n";
    echo "  • Test loading\n\n";
}

