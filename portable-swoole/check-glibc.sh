#!/bin/bash
echo "Checking GLIBC versions..."
echo ""
echo "Local system:"
strings /lib/x86_64-linux-gnu/libc.so.6 | grep GLIBC_ | tail -5
echo ""
echo "Required by extension:"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
strings "${SCRIPT_DIR}/bin/extensions/php8.1/linux-x86_64/swoole.so" 2>/dev/null | grep GLIBC | tail -10 || echo "Cannot read extension"
