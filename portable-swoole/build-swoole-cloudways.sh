#!/bin/bash

# Quick build script for Cloudways-compatible Swoole extension
# Uses Debian 11 (Bullseye) base image for GLIBC compatibility

set -e

PHP_VERSION="${1:-8.1}"
SWOOLE_VERSION="${2:-v5.1.0}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="${SCRIPT_DIR}/bin/extensions/php${PHP_VERSION}/linux-x86_64"

echo "🔨 Building Swoole Extension for Cloudways"
echo "=============================================="
echo "PHP Version: $PHP_VERSION"
echo "Swoole Version: $SWOOLE_VERSION"
echo "Output: $OUTPUT_DIR"
echo ""

if ! command -v docker &> /dev/null; then
    echo "❌ Docker is required for this build"
    echo "   Install Docker or build directly on Cloudways server"
    exit 1
fi

echo "🐳 Building with Docker (Debian 11 for Cloudways compatibility)..."
echo ""

# Build Docker image with older base
if docker build \
    --progress=plain \
    -f - \
    -t swoole-cloudways \
    "${SCRIPT_DIR}" <<'DOCKERFILE'
FROM php:8.1-cli-bullseye

RUN apt-get update && \
    apt-get install -y build-essential git libssl-dev && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /build

RUN git clone --depth 1 --branch v5.1.0 https://github.com/swoole/swoole-src.git swoole-src && \
    cd swoole-src && \
    phpize && \
    ./configure --enable-openssl && \
    make -j$(nproc) && \
    mkdir -p /output && \
    cp modules/swoole.so /output/swoole.so && \
    chmod 644 /output/swoole.so && \
    echo "✅ Extension built!" && \
    file /output/swoole.so && \
    ls -lh /output/swoole.so
DOCKERFILE
then
    echo "✅ Docker build successful!"
else
    echo "❌ Docker build failed!"
    exit 1
fi

echo ""
echo "📦 Extracting extension..."

mkdir -p "$OUTPUT_DIR"

if docker run --rm \
    -v "$(realpath "$OUTPUT_DIR")":/output \
    swoole-cloudways \
    sh -c "cp /output/swoole.so /output/ 2>&1 || cp /build/swoole-src/modules/swoole.so /output/swoole.so && ls -lh /output/swoole.so"
then
    echo "✅ Extension extracted successfully!"
    echo ""
    echo "📋 Verification:"
    file "${OUTPUT_DIR}/swoole.so"
    echo ""
    echo "🎉 Done! Extension is ready for Cloudways."
    echo "   Location: ${OUTPUT_DIR}/swoole.so"
else
    echo "❌ Failed to extract extension!"
    exit 1
fi
