#!/bin/sh
set -e

PORT="${PORT:-10000}"

echo "Starting Telegram Poller daemon in background..."
php support_bot_poller.php &

echo "Starting Web API endpoint on port ${PORT}..."
exec php -S 0.0.0.0:${PORT} api.php
