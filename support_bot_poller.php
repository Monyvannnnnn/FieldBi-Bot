<?php
/**
 * Real-time High-Performance Poller Daemon for Telegram Support Bot with Connection Auto-Recovery
 */

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;
$offset = 0;

echo "[" . date('Y-m-d H:i:s') . "] Fast Support Bot Poller started (Auto-Reconnect Enabled) for token: {$botToken}\n";

// Helper function to maintain active DB connection
function checkAndReconnectDb() {
    global $pdo, $conn, $driver;
    if (isset($driver) && $driver === 'pgsql') {
        try {
            if ($pdo) {
                $pdo->query("SELECT 1");
            } else {
                throw new Exception("PDO is null");
            }
        } catch (Throwable $t) {
            echo "[" . date('Y-m-d H:i:s') . "] Re-establishing database connection...\n";
            $pdo = null;
            if (file_exists(__DIR__ . '/database.php')) {
                require __DIR__ . '/database.php';
            } elseif (file_exists(__DIR__ . '/../database.php')) {
                require __DIR__ . '/../database.php';
            }
            if (!$pdo) {
                sleep(1);
            }
        }
    }
}

// Persistent cURL handle for ultra-fast HTTPS requests to Telegram API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 3,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
]);

while (true) {
    checkAndReconnectDb();

    $url = "https://api.telegram.org/bot{$botToken}/getUpdates?offset={$offset}&timeout=1&allowed_updates=[\"message\",\"callback_query\"]";
    curl_setopt($ch, CURLOPT_URL, $url);
    
    $rawResponse = curl_exec($ch);
    
    if ($rawResponse !== false) {
        $data = json_decode($rawResponse, true);
        
        if (!empty($data['result'])) {
            foreach ($data['result'] as $up) {
                $updateId = $up['update_id'];
                echo "[" . date('Y-m-d H:i:s') . "] Processing update #{$updateId}...\n";
                
                try {
                    processSupportBotUpdate($up);
                } catch (Throwable $e) {
                    echo "Error processing update: " . $e->getMessage() . "\n";
                }
                
                $offset = $updateId + 1;
            }
        }
    }

    // Flush pending customer messages promptly (3 seconds window)
    try {
        flushPendingCustomerMessages(3);
    } catch (Throwable $e) {
        // Suppress & silently recover connection on next loop iteration
    }

    // Ultra-short sleep for instant 1-click response time
    usleep(100000); // 0.1s delay
}
