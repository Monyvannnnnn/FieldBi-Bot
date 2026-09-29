#!/bin/sh
set -e

PORT="${PORT:-10000}"

if [ "$DISABLE_EMBEDDED_POLLER" != "true" ] && [ "$DISABLE_EMBEDDED_POLLER" != "1" ]; then
  echo "Starting Telegram Poller daemon in background..."
  php support_bot_poller.php &
else
  echo "Embedded poller disabled (Dedicated Worker Service running)."
fi

# Auto-register Webhook if USE_WEBHOOK=true
if [ "$USE_WEBHOOK" = "true" ] || [ "$WEBHOOK_MODE" = "true" ]; then
  if [ -n "$RENDER_EXTERNAL_URL" ] && [ -n "$TELEGRAM_BOT_TOKEN" ]; then
    echo "Registering Telegram Webhook: ${RENDER_EXTERNAL_URL}/api.php"
    curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook?url=${RENDER_EXTERNAL_URL}/api.php" || true
  fi
fi

echo "Starting Web API endpoint on port ${PORT}..."
exec php -S 0.0.0.0:${PORT} api.php
