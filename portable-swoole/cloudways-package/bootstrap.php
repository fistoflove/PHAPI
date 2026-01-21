<?php
/**
 * PHAPI Bootstrap
 * Include this file to autoload PHAPI classes
 */

// Check if we're in a Composer project
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    // Manual autoloading for testing
    spl_autoload_register(function ($class) {
        $prefix = 'PHAPI\\';
        $base_dir = __DIR__ . '/src/';
        
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
