#!/bin/bash
set -e

# Ensure /var/log/corrai exists and has correct permissions
# Note: On volume mounts, chown may not work, but chmod should
mkdir -p /var/log/corrai
# Try to set ownership (may fail on some volume mounts, but that's okay)
chown -R www-data:www-data /var/log/corrai 2>/dev/null || true
# Set permissions - this should work even on volume mounts
chmod -R 775 /var/log/corrai

# Writable home for www-data so PaddleX can cache OCR models
mkdir -p /var/corrai/home
chown -R www-data:www-data /var/corrai/home
chmod -R 775 /var/corrai/home

# Execute the original entrypoint
exec docker-php-entrypoint "$@"

