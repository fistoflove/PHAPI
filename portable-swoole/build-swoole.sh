#!/bin/bash

# Build Swoole extension script
# Creates Swoole extension for PHP 8.1 on linux-x86_64

set -e
set -o pipefail

PHP_VERSION="${1:-8.1}"
SWOOLE_VERSION="${2:-5.1.0}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="${SCRIPT_DIR}/bin/extensions/php${PHP_VERSION}/linux-x86_64"

echo "🔨 Building Swoole Extension"
echo "=============================="
echo ""
echo "PHP Version: ${PHP_VERSION}"
echo "Swoole Version: ${SWOOLE_VERSION}"
echo "Output: ${OUTPUT_DIR}"
echo ""

# Check if Docker is available
if command -v docker &> /dev/null; then
    echo "🐳 Using Docker method..."
    echo ""
    
    # Check if image already exists
    if docker images | grep -q swoole-builder; then
        echo "📦 Docker image exists, rebuilding..."
    else
        echo "📦 Building Docker image (this may take 2-5 minutes)..."
    fi
    echo ""
    
    # Build using Docker with verbose output
    echo "🔨 Step 1/3: Building Docker image..."
    echo "   This may take 2-5 minutes. Building now..."
    echo ""
    
    # Show progress indicator
    (
        while true; do
            echo "   Still building... (this is normal, compilation takes time)"
            sleep 10
        done
    ) &
    PROGRESS_PID=$!
    
    # Build Docker image
    if docker build \
        --build-arg PHP_VERSION="${PHP_VERSION}" \
        --build-arg SWOOLE_VERSION="${SWOOLE_VERSION}" \
        -f "${SCRIPT_DIR}/Dockerfile.build-swoole" \
        -t swoole-builder \
        "${SCRIPT_DIR}" 2>&1 | tee /tmp/swoole-build.log; then
        BUILD_SUCCESS=true
    else
        BUILD_SUCCESS=false
    fi
    
    # Kill progress indicator
    kill $PROGRESS_PID 2>/dev/null || true
    wait $PROGRESS_PID 2>/dev/null || true
    
    echo ""
    
    if [ "$BUILD_SUCCESS" != "true" ]; then
        echo ""
        echo "❌ Docker build failed!"
        echo "   Check the output above for errors."
        echo "   Log file: /tmp/swoole-build.log"
        exit 1
    fi
    
    echo ""
    echo "✅ Docker image built successfully!"
    echo ""
    
    # Create output directory
    echo "📁 Step 2/3: Creating output directory..."
    mkdir -p "${OUTPUT_DIR}"
    echo "   Created: ${OUTPUT_DIR}"
    echo ""
    
    # Extract extension
    echo "📦 Step 3/3: Extracting Swoole extension..."
    echo "   Running container to copy extension..."
    
    # The extension should already be in /output from Dockerfile
    if docker run --rm -v "$(realpath "${OUTPUT_DIR}")":/output swoole-builder sh -c "test -f /output/swoole.so && cp /output/swoole.so /output/ || cp /build/swoole-src/modules/swoole.so /output/swoole.so && ls -lh /output/swoole.so"; then
        EXTRACT_SUCCESS=0
    else
        EXTRACT_SUCCESS=1
        echo ""
        echo "⚠️  First extraction attempt failed, trying alternative..."
        # Try to get from build directory
        docker run --rm -v "$(realpath "${OUTPUT_DIR}")":/output swoole-builder sh -c "cp /build/swoole-src/modules/swoole.so /output/swoole.so 2>&1 && ls -lh /output/swoole.so"
        EXTRACT_SUCCESS=$?
    fi
    
    if [ "$EXTRACT_SUCCESS" != "0" ]; then
        echo ""
        echo "❌ Failed to extract extension!"
        echo "   Debugging container contents..."
        docker run --rm swoole-builder sh -c "echo 'Checking /output:'; ls -la /output/ 2>&1; echo ''; echo 'Checking /build:'; ls -la /build/swoole-src/modules/ 2>&1"
        exit 1
    fi
    
    echo "   Extension copied successfully!"
    echo ""
    
    echo ""
    echo "✅ Extension built successfully!"
    echo "   Location: ${OUTPUT_DIR}/swoole.so"
    
elif command -v phpize &> /dev/null; then
    echo "🔧 Using local PHP method..."
    
    # Check PHP version matches
    LOCAL_PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
    if [ "${LOCAL_PHP_VERSION}" != "${PHP_VERSION}" ]; then
        echo "⚠️  Warning: Local PHP version is ${LOCAL_PHP_VERSION}, requested ${PHP_VERSION}"
        echo "   The extension may still work, but it's better to match versions."
        read -p "Continue? [y/N] " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
    
    # Create build directory
    BUILD_DIR="/tmp/swoole-build-$$"
    mkdir -p "${BUILD_DIR}"
    cd "${BUILD_DIR}"
    
    # Download and build
    echo "📥 Downloading Swoole..."
    git clone https://github.com/swoole/swoole-src.git
    cd swoole-src
    git checkout "v${SWOOLE_VERSION}"
    
    echo "🔨 Building extension..."
    phpize
    ./configure --with-php-config=$(which php-config)
    make
    
    # Create output directory
    mkdir -p "${OUTPUT_DIR}"
    
    # Copy extension
    cp modules/swoole.so "${OUTPUT_DIR}/swoole.so"
    
    # Cleanup
    rm -rf "${BUILD_DIR}"
    
    echo ""
    echo "✅ Extension built successfully!"
    echo "   Location: ${OUTPUT_DIR}/swoole.so"
    
else
    echo "❌ Error: Neither Docker nor PHP development tools found."
    echo ""
    echo "Please choose one:"
    echo "  1. Install Docker: https://docs.docker.com/get-docker/"
    echo "  2. Install PHP dev tools: sudo apt-get install php${PHP_VERSION}-dev"
    echo "  3. Use GitHub Actions to build (see GET-SWOOLE-EXTENSION.md)"
    exit 1
fi

echo ""
echo "📋 Verification:"
file "${OUTPUT_DIR}/swoole.so"
ls -lh "${OUTPUT_DIR}/swoole.so"

echo ""
echo "🎉 Done! Extension is ready to use."
echo "   Run: php bin/phapi-install"
