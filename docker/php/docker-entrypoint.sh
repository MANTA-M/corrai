#!/bin/bash
set -e

# Ensure /var/log/corrai exists and has correct permissions
# Note: On volume mounts, chown may not work, but chmod should
mkdir -p /var/log/corrai
# Try to set ownership (may fail on some volume mounts, but that's okay)
chown -R www-data:www-data /var/log/corrai 2>/dev/null || true
# Set permissions - this should work even on volume mounts
chmod -R 775 /var/log/corrai

# Execute the original entrypoint
exec docker-php-entrypoint "$@"

