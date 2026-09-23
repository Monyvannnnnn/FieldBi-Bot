#!/bin/sh
set -e

PORT="${PORT:-10000}"
echo "Starting Web API endpoint on port ${PORT}..."
php -S 0.0.0.0:${PORT} api.php &

echo "Starting Telegram Support Bot Poller Daemon..."
exec php support_bot_poller.php
