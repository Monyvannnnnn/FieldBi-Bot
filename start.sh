#!/bin/sh
set -e

PORT="${PORT:-10000}"
echo "Starting Web API endpoint on port ${PORT}..."
exec php -S 0.0.0.0:${PORT} api.php
