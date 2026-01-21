#!/bin/bash

# Package PHAPI for Cloudways Deployment
# Creates a zip file with library, examples, and deployment script

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_DIR="$SCRIPT_DIR/cloudways-package"
ZIP_NAME="phapi-cloudways.zip"

echo "📦 Packaging PHAPI for Cloudways Deployment..."
echo ""

# Clean previous package
rm -rf "$OUTPUT_DIR"
rm -f "$ZIP_NAME"
mkdir -p "$OUTPUT_DIR"

echo "✓ Creating package directory..."

# Copy source files (with new structure)
echo "✓ Copying source files..."
mkdir -p "$OUTPUT_DIR/src"
cp -r "$ROOT_DIR/src/"* "$OUTPUT_DIR/src/"

# Copy examples
echo "✓ Copying examples..."
mkdir -p "$OUTPUT_DIR/examples"
cp -r "$ROOT_DIR/examples/"* "$OUTPUT_DIR/examples/"

# Copy extension loader utilities
echo "✓ Copying extension utilities..."
mkdir -p "$OUTPUT_DIR/bin"
cp "$ROOT_DIR/bin/phapi-install" "$OUTPUT_DIR/bin/" 2>/dev/null || true
cp "$ROOT_DIR/bin/phapi-run" "$OUTPUT_DIR/bin/" 2>/dev/null || true

# Copy bundled extensions if they exist
if [ -d "$SCRIPT_DIR/bin/extensions" ]; then
    echo "✓ Copying bundled Swoole extensions..."
    mkdir -p "$OUTPUT_DIR/bin/extensions"
    cp -r "$SCRIPT_DIR/bin/extensions/"* "$OUTPUT_DIR/bin/extensions/" 2>/dev/null || true
    
    # Count extensions found
    EXT_COUNT=$(find "$OUTPUT_DIR/bin/extensions" -name "swoole.so" 2>/dev/null | wc -l)
    if [ "$EXT_COUNT" -gt 0 ]; then
        echo "  ✓ Found $EXT_COUNT extension(s) to include"
    fi
fi

# Copy test script
echo "✓ Copying test script..."
cp "$SCRIPT_DIR/test-cloudways.php" "$OUTPUT_DIR/"

# Copy composer.json (needed for autoloading) - modified for deployment package
echo "✓ Copying composer.json (deployment package version)..."
cat > "$OUTPUT_DIR/composer.json" << 'COMPOSER'
{
    "name": "phapi/phapi-cloudways",
    "description": "PHAPI Deployment Package for Cloudways",
    "type": "project",
    "require": {
        "php": "^8.1"
    },
    "autoload": {
        "psr-4": {
            "PHAPI\\": "src/"
        }
    },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "platform-check": false
    },
    "scripts": {
        "post-install-cmd": [
            "@php -r \"echo '✓ Composer installed successfully\\n';\""
        ]
    }
}
COMPOSER
echo "  ✓ Created deployment package composer.json (no Swoole requirement)"

# Create a bootstrap file for examples
echo "✓ Creating bootstrap file..."
cat > "$OUTPUT_DIR/bootstrap.php" << 'BOOTSTRAP'
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
BOOTSTRAP

# Copy Cloudways entry point example
echo "✓ Copying Cloudways entry point..."
mkdir -p "$OUTPUT_DIR/examples"
if [ ! -f "$OUTPUT_DIR/examples/cloudways-index.php" ]; then
    cp "$SCRIPT_DIR/examples/cloudways-index.php" "$OUTPUT_DIR/examples/" 2>/dev/null || true
fi

# Copy deployment script
echo "✓ Copying deployment script..."
cp "$SCRIPT_DIR/deploy-cloudways.sh" "$OUTPUT_DIR/" 2>/dev/null || true
chmod +x "$OUTPUT_DIR/deploy-cloudways.sh" 2>/dev/null || true

# Create README for the deployment package
echo "✓ Creating README..."
cat > "$OUTPUT_DIR/README.md" << 'README'
# PHAPI Cloudways Deployment Package

This package contains PHAPI library, examples, and deployment script for Cloudways.

## Quick Start

1. Upload this entire folder to your Cloudways server via:
   - FTP/SFTP
   - File Manager
   - Git (if available)

2. Extract if you uploaded as ZIP

3. Run the deployment script:
   ```bash
   cd cloudways-package
   ./deploy-cloudways.sh
   ```

4. The script will:
   - Detect `public_html` directory
   - Install Composer dependencies
   - Set up Swoole extension
   - Create/backup `index.php`
   - Scaffold your PHAPI application

5. Start the server:
   ```bash
   ./start-server.sh
   ```

## Files Included

- `deploy-cloudways.sh` - Automated deployment script
- `src/` - PHAPI library source code (organized: HTTP/, Logging/, Server/, Tools/)
- `examples/` - Example applications (single-file and multi-file)
- `bootstrap.php` - Autoloader for manual testing
- `composer.json` - Composer configuration
- `test-cloudways.php` - Compatibility test script (optional)

## Package Structure

```
cloudways-package/
├── src/
│   ├── PHAPI.php          # Main facade
│   ├── HTTP/              # HTTP components (Response, RouteBuilder, Validator)
│   ├── Logging/           # Logging components (Logger, ChannelLogger)
│   ├── Server/            # Server components (Router, RequestHandler, etc.)
│   ├── Exceptions/        # Custom exceptions
│   └── Tools/             # Development tools
├── examples/              # Example applications
├── bin/                   # CLI tools (phapi-install, phapi-run)
└── deploy-cloudways.sh    # Deployment script
```

## Next Steps

After deployment:
- Edit your application in `public_html/app.php` (single-file) or `public_html/app/` (multi-file)
- View logs: `tail -f public_html/logs/access.log`
- Stop server: `pkill -f "app.php"` or find PID with `ps aux | grep app.php`
README

# Copy specific example files (simplified versions for testing)
echo "✓ Creating simplified test examples..."

# Create a minimal test example
cat > "$OUTPUT_DIR/test-example.php" << 'EXAMPLE'
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
EXAMPLE

# Create ZIP file
echo ""
echo "✓ Creating ZIP archive..."
cd "$PROJECT_DIR"
zip -r "$ZIP_NAME" cloudways-package/ > /dev/null

# Cleanup
echo "✓ Cleaning up..."
# rm -rf "$OUTPUT_DIR"

echo ""
echo "✅ Package created successfully!"
echo ""
echo "📦 File: $ZIP_NAME"
echo "📁 Size: $(du -h "$ZIP_NAME" | cut -f1)"
echo ""
echo "Next steps:"
echo "  1. Upload $ZIP_NAME to your Cloudways server"
echo "  2. Extract it in your public_html or desired location"
echo "  3. Run deployment script:"
echo "     cd cloudways-package"
echo "     ./deploy-cloudways.sh"
echo "  4. Start the server:"
echo "     ./start-server.sh"
echo ""
echo "Or run in background:"
echo "   nohup php -c phapi.ini app.php > phapi.log 2>&1 &"
echo ""
echo "The script will:"
echo "  ✓ Install dependencies"
echo "  ✓ Set up Swoole extension"
echo "  ✓ Create/backup index.php"
echo "  ✓ Scaffold your PHAPI application"
echo "  ✓ Make everything accessible from your domain"
echo ""
