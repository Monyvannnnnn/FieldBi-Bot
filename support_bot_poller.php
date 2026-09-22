<?php
/**
 * Real-time Poller Daemon for Telegram Support Bot with Connection Auto-Recovery
 */

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;
$offset = 0;

echo "[" . date('Y-m-d H:i:s') . "] Support Bot Poller started (Auto-Reconnect Enabled) for token: {$botToken}\n";

// Helper function to maintain active DB connection
function checkAndReconnectDb() {
    global $pdo, $conn, $driver;
    if (isset($driver) && $driver === 'pgsql' && $pdo) {
        try {
            $pdo->query("SELECT 1");
        } catch (Throwable $t) {
            echo "[" . date('Y-m-d H:i:s') . "] Re-establishing database connection...\n";
            $pdo = null;
            @require __DIR__ . '/../database.php';
        }
    }
}

while (true) {
    checkAndReconnectDb();

    $url = "https://api.telegram.org/bot{$botToken}/getUpdates?offset={$offset}&timeout=5";
    
    $ctx = stream_context_create([
        'http' => ['timeout' => 10]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    if ($response !== false) {
        $data = json_decode($response, true);
        
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

    // Flush and group pending customer messages (20 seconds window)
    try {
        flushPendingCustomerMessages(20);
    } catch (Throwable $e) {
        // Suppress & silently recover connection on next loop
    }
    
    usleep(500000); // Sleep 0.5s between polls
}
