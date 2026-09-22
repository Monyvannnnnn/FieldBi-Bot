<?php
/**
 * Telegram Customer Support Bot Handler
 * 
 * Supports both Supabase (PostgreSQL) and MySQL via database.php
 * Credentials configured in .env
 */

// Load environment variables from .env file if available
foreach ([__DIR__ . '/.env', __DIR__ . '/../.env'] as $envPath) {
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name  = trim($name);
                $value = trim(trim($value), '"\'');
                if (!getenv($name)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
} elseif (file_exists(__DIR__ . '/../database.php')) {
    require_once __DIR__ . '/../database.php';
}

// Telegram Bot Token (Loaded dynamically from .env via TELEGRAM_BOT_TOKEN)
if (!defined('BOT_TOKEN')) {
    $botTokenEnv = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? ($_SERVER['TELEGRAM_BOT_TOKEN'] ?? ''));
    if (empty($botTokenEnv)) {
        die("Error: TELEGRAM_BOT_TOKEN is not defined in .env file.");
    }
    define('BOT_TOKEN', $botTokenEnv);
}

// Bot Super Admin Telegram Chat ID (Loaded dynamically from .env via TELEGRAM_ADMIN_CHAT_ID)
if (!defined('ADMIN_CHAT_ID')) {
    $adminChatIdEnv = getenv('TELEGRAM_ADMIN_CHAT_ID') ?: ($_ENV['TELEGRAM_ADMIN_CHAT_ID'] ?? ($_SERVER['TELEGRAM_ADMIN_CHAT_ID'] ?? '7892238736'));
    define('ADMIN_CHAT_ID', (string)$adminChatIdEnv);
}

/**
 * Send text message to Telegram chat
 */
function sendMessage($chatId, $text, $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $postFields = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $postFields['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Send photo to Telegram chat
 */
function sendPhoto($chatId, $fileId, $caption = '', $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
    $postFields = [
        'chat_id'    => $chatId,
        'photo'      => $fileId,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $postFields['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Send document to Telegram chat
 */
function sendDocument($chatId, $fileId, $caption = '', $replyMarkup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    $postFields = [
        'chat_id'    => $chatId,
        'document'   => $fileId,
        'caption'    => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup !== null) {
        $postFields['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Flush and group pending customer messages (default 3 seconds delay for snappy response)
 */
function flushPendingCustomerMessages($forceDelaySeconds = 3) {
    global $pdo, $conn, $driver;

    if (isset($driver) && $driver === 'pgsql') {
        $readyStmt = $pdo->prepare("
            SELECT customer_chat_id, MAX(customer_name) as customer_name, MAX(username) as username
            FROM pending_customer_messages
            WHERE processed = 0
            GROUP BY customer_chat_id
            HAVING EXTRACT(EPOCH FROM (NOW() - MAX(created_at))) >= :delay
        ");
        $readyStmt->execute([':delay' => $forceDelaySeconds]);
        $readyCustomers = $readyStmt->fetchAll(PDO::FETCH_ASSOC);

        $groupStmt = $pdo->query("SELECT group_chat_id FROM support_groups WHERE is_active = 1");
        $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $res = mysqli_query($conn, "
            SELECT customer_chat_id, MAX(customer_name) as customer_name, MAX(username) as username
            FROM pending_customer_messages
            WHERE processed = 0
            GROUP BY customer_chat_id
            HAVING TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) >= {$forceDelaySeconds}
        ");
        $readyCustomers = mysqli_fetch_all($res, MYSQLI_ASSOC);

        $groupRes = mysqli_query($conn, "SELECT group_chat_id FROM support_groups WHERE is_active = 1");
        $groups = mysqli_fetch_all($groupRes, MYSQLI_ASSOC);
    }

    if (empty($readyCustomers)) {
        return;
    }

    // Fallback: If no support group is registered, forward to Super Admin chat directly
    if (empty($groups)) {
        if (defined('ADMIN_CHAT_ID') && !empty(ADMIN_CHAT_ID)) {
            $groups = [['group_chat_id' => (string)ADMIN_CHAT_ID]];
        } else {
            return;
        }
    }

    foreach ($readyCustomers as $cust) {
        $chatId       = $cust['customer_chat_id'];
        $customerName = $cust['customer_name'] ?? 'Customer';
        $username     = trim($cust['username'] ?? '');

        // Fetch all pending messages for this customer
        if (isset($driver) && $driver === 'pgsql') {
            $msgStmt = $pdo->prepare("SELECT * FROM pending_customer_messages WHERE customer_chat_id = ? AND processed = 0 ORDER BY id ASC");
            $msgStmt->execute([$chatId]);
            $pendingMsgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $res = mysqli_query($conn, "SELECT * FROM pending_customer_messages WHERE customer_chat_id = '{$chatId}' AND processed = 0 ORDER BY id ASC");
            $pendingMsgs = mysqli_fetch_all($res, MYSQLI_ASSOC);
        }

        if (empty($pendingMsgs)) {
            continue;
        }

        // Combine text lines and find photo/doc
        $rawTextLines = [];
        $photoFileId  = null;
        $docFileId    = null;

        foreach ($pendingMsgs as $m) {
            if (!empty($m['message_text'])) {
                $rawTextLines[] = htmlspecialchars($m['message_text']);
            }
            if (!empty($m['photo_file_id'])) {
                $photoFileId = $m['photo_file_id'];
            }
            if (!empty($m['doc_file_id'])) {
                $docFileId = $m['doc_file_id'];
            }
            if (empty($username) && !empty($m['username'])) {
                $username = trim($m['username']);
            }
        }

        $messageBody  = !empty($rawTextLines) ? implode("\n", $rawTextLines) : '';
        $combinedText = !empty($messageBody) ? "💬 <b>Message:</b>\n" . $messageBody : '';

        // Create/Update Conversation Ticket ID
        if (isset($driver) && $driver === 'pgsql') {
            $convStmt = $pdo->prepare("
                INSERT INTO conversations (customer_chat_id, customer_name, username) 
                VALUES (?, ?, ?)
                ON CONFLICT (customer_chat_id) DO UPDATE SET customer_name = EXCLUDED.customer_name, username = EXCLUDED.username
                RETURNING id
            ");
            $convStmt->execute([$chatId, $customerName, $username]);
            $convId = $convStmt->fetchColumn();
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO conversations (customer_chat_id, customer_name, username) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name), username = VALUES(username)");
            mysqli_stmt_bind_param($stmt, "sss", $chatId, $customerName, $username);
            mysqli_stmt_execute($stmt);

            $convStmt = mysqli_prepare($conn, "SELECT id FROM conversations WHERE customer_chat_id = ?");
            mysqli_stmt_bind_param($convStmt, "s", $chatId);
            mysqli_stmt_execute($convStmt);
            $conv = mysqli_fetch_assoc(mysqli_stmt_get_result($convStmt));
            $convId = $conv['id'];
        }

        // Format Contact Info with Public Username & Mention Link
        $personalChatUrl = !empty($username) ? "https://t.me/" . ltrim(trim($username), '@') : "tg://user?id={$chatId}";

        if (!empty($username)) {
            $cleanUsername = ltrim(trim($username), '@');
            $contactDisplay = "<b>" . htmlspecialchars($customerName) . "</b> (@" . htmlspecialchars($cleanUsername) . ")\n"
                            . "🔗 <b>Personal Chat:</b> <a href=\"{$personalChatUrl}\">https://t.me/{$cleanUsername}</a>";
        } else {
            $contactDisplay = "<a href=\"{$personalChatUrl}\"><b>" . htmlspecialchars($customerName) . "</b></a>\n"
                            . "🆔 <b>User ID:</b> <code>{$chatId}</code>\n"
                            . "🔗 <b>Personal Chat:</b> <a href=\"{$personalChatUrl}\">Open Chat Profile</a>";
        }

        // Single Combined Ticket Message Header with Consolidated Formatting
        $ticketHeader = "📩 <b>New Support Request (#{$convId})</b>\n"
                      . "━━━━━━━━━━━━━━\n"
                      . "👤 <b>From:</b> {$contactDisplay}\n"
                      . (!empty($combinedText) ? $combinedText . "\n" : "")
                      . "━━━━━━━━━━━━━━\n"
                      . "💡 <b>Options to Respond:</b>\n"
                      . "1️⃣ <b>Reply to this message</b> to answer via Bot\n"
                      . "2️⃣ <b>Click button below</b> to chat 1-on-1 personally";

        // Inline Keyboard Button for direct 1-on-1 personal chat
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💬 Chat Personally with Customer', 'url' => $personalChatUrl]
                ]
            ]
        ];

        // Post ONE combined ticket message into each Telegram Support Group (or Admin fallback)
        foreach ($groups as $g) {
            $gId = $g['group_chat_id'];
            $apiRes = null;

            if ($photoFileId) {
                $apiRes = sendPhoto($gId, $photoFileId, $ticketHeader, $inlineKeyboard);
            } elseif ($docFileId) {
                $apiRes = sendDocument($gId, $docFileId, $ticketHeader, $inlineKeyboard);
            } else {
                $apiRes = sendMessage($gId, $ticketHeader, $inlineKeyboard);
            }

            if (!empty($apiRes['ok']) && isset($apiRes['result']['message_id'])) {
                $groupMessageId = $apiRes['result']['message_id'];

                // Map Group Message ID -> Customer Chat ID
                if (isset($driver) && $driver === 'pgsql') {
                    $mapStmt = $pdo->prepare("
                        INSERT INTO group_messages (group_chat_id, group_message_id, customer_chat_id)
                        VALUES (:gid, :gmid, :cid)
                        ON CONFLICT (group_message_id) DO NOTHING
                    ");
                    $mapStmt->execute([
                        ':gid'  => (string)$gId,
                        ':gmid' => $groupMessageId,
                        ':cid'  => (string)$chatId
                    ]);
                } else {
                    $mapStmt = mysqli_prepare($conn, "INSERT IGNORE INTO group_messages (group_chat_id, group_message_id, customer_chat_id) VALUES (?, ?, ?)");
                    $gIdStr = (string)$gId;
                    $chatIdStr = (string)$chatId;
                    mysqli_stmt_bind_param($mapStmt, "sis", $gIdStr, $groupMessageId, $chatIdStr);
                    mysqli_stmt_execute($mapStmt);
                }
            }
        }

        // Mark pending messages as processed
        if (isset($driver) && $driver === 'pgsql') {
            $markStmt = $pdo->prepare("UPDATE pending_customer_messages SET processed = 1 WHERE customer_chat_id = ? AND processed = 0");
            $markStmt->execute([$chatId]);
        } else {
            mysqli_query($conn, "UPDATE pending_customer_messages SET processed = 1 WHERE customer_chat_id = '{$chatId}' AND processed = 0");
        }
    }
}

/**
 * Main update handler function
 */
function processSupportBotUpdate($update) {
    global $pdo, $conn, $driver;
    
    if (!isset($update["message"])) {
        return;
    }

    $message   = $update["message"];
    $text      = trim($message["text"] ?? '');
    $caption   = trim($message["caption"] ?? '');
    $photo     = $message["photo"] ?? null;
    $document  = $message["document"] ?? null;
    $chatId    = (string)($message["chat"]["id"] ?? '');
    $senderId  = (string)($message["from"]["id"] ?? '');
    $chatType  = $message["chat"]["type"] ?? 'private';
    $isGroup   = ($chatType === 'group' || $chatType === 'supergroup');

    $photoFileId = !empty($photo) ? end($photo)["file_id"] : null;
    $docFileId   = !empty($document) ? $document["file_id"] : null;
    $hasMedia    = !empty($photoFileId) || !empty($docFileId);
    $mainContent = !empty($text) ? $text : $caption;

    // ========================================================
    // 1. TICKET REPLY CHECK (Works in Groups AND Admin Private Chat)
    // ========================================================
    if (isset($message["reply_to_message"])) {
        $replyToMessageId = $message["reply_to_message"]["message_id"];
        $targetCustomerChatId = null;
        $customerName = 'Customer';

        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->prepare("
                SELECT gm.customer_chat_id, c.customer_name 
                FROM group_messages gm 
                LEFT JOIN conversations c ON c.customer_chat_id = gm.customer_chat_id 
                WHERE gm.group_message_id = ?
            ");
            $stmt->execute([$replyToMessageId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $targetCustomerChatId = $row['customer_chat_id'];
                $customerName         = $row['customer_name'] ?? 'Customer';
            }
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT gm.customer_chat_id, c.customer_name 
                FROM group_messages gm 
                LEFT JOIN conversations c ON c.customer_chat_id = gm.customer_chat_id 
                WHERE gm.group_message_id = ?
            ");
            mysqli_stmt_bind_param($stmt, "i", $replyToMessageId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($res) {
                $targetCustomerChatId = $res['customer_chat_id'];
                $customerName         = $res['customer_name'] ?? 'Customer';
            }
        }

        if ($targetCustomerChatId) {
            $agentFirstName = trim($message["from"]["first_name"] ?? '');
            $agentLastName  = trim($message["from"]["last_name"] ?? '');
            $agentName      = trim($agentFirstName . ' ' . $agentLastName);
            if (empty($agentName)) {
                $agentName = 'Support Agent';
            }

            $cleanCustName = $customerName;
            if (preg_match('/^(.*?)\s*(\(@[a-zA-Z0-9_]+\))$/', $customerName, $matches)) {
                $cleanCustName = trim($matches[1]);
            }

            $userLink = "<a href=\"tg://user?id={$targetCustomerChatId}\">" . htmlspecialchars($cleanCustName) . "</a>";

            if ($photoFileId) {
                sendPhoto($targetCustomerChatId, $photoFileId, $caption);
                sendMessage($chatId, "✅ <b>Photo sent to {$userLink}</b> by <i>{$agentName}</i>!");
            } elseif ($docFileId) {
                sendDocument($targetCustomerChatId, $docFileId, $caption);
                sendMessage($chatId, "✅ <b>Document sent to {$userLink}</b> by <i>{$agentName}</i>!");
            } elseif (!empty($text)) {
                sendMessage($targetCustomerChatId, $text);
                sendMessage($chatId, "✅ <b>Response sent to {$userLink}</b> by <i>{$agentName}</i>!");
            }
            return;
        }
    }

    // ========================================================
    // 2. TELEGRAM GROUP CHAT WORKFLOW
    // ========================================================
    if ($isGroup) {
        $groupTitle = $message["chat"]["title"] ?? 'Support Group';

        // Check if group is already authorized in Database
        $isGroupAuthorized = false;
        if (isset($driver) && $driver === 'pgsql') {
            $checkG = $pdo->prepare("SELECT 1 FROM support_groups WHERE group_chat_id = ? AND is_active = 1");
            $checkG->execute([$chatId]);
            $isGroupAuthorized = (bool)$checkG->fetchColumn();
        } else {
            $resG = mysqli_query($conn, "SELECT group_chat_id FROM support_groups WHERE group_chat_id = '{$chatId}' AND is_active = 1");
            $isGroupAuthorized = mysqli_num_rows($resG) > 0;
        }

        // Automatically activate/authorize any group where the bot is added or active
        if (!$isGroupAuthorized) {
            if (isset($driver) && $driver === 'pgsql') {
                $stmt = $pdo->prepare("
                    INSERT INTO support_groups (group_chat_id, group_title, is_active)
                    VALUES (:gid, :title, 1)
                    ON CONFLICT (group_chat_id) DO UPDATE SET is_active = 1, group_title = EXCLUDED.group_title
                ");
                $stmt->execute([':gid' => (string)$chatId, ':title' => $groupTitle]);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO support_groups (group_chat_id, group_title, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1, group_title = VALUES(group_title)");
                $chatIdStr = (string)$chatId;
                mysqli_stmt_bind_param($stmt, "ss", $chatIdStr, $groupTitle);
                mysqli_stmt_execute($stmt);
            }
            sendMessage($chatId, "🛡️ <b>Support Group Authorized!</b>\n\nThis group (<b>" . htmlspecialchars($groupTitle) . "</b>) is now active and will receive all incoming customer support tickets.");
        }

        // Ignore commands or empty messages in group
        if (empty($mainContent) && !$hasMedia) {
            return;
        }
        if (strpos($text, '/') === 0) {
            return;
        }

        // Fallback route: If agent typed without replying to a specific message, send to most recent active customer
        $agentFirstName = trim($message["from"]["first_name"] ?? '');
        $agentLastName  = trim($message["from"]["last_name"] ?? '');
        $agentName      = trim($agentFirstName . ' ' . $agentLastName);
        if (empty($agentName)) $agentName = 'Support Agent';

        $targetCustomerChatId = null;
        $customerName         = 'Customer';

        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->query("SELECT customer_chat_id, customer_name FROM conversations ORDER BY id DESC LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $targetCustomerChatId = $row['customer_chat_id'];
                $customerName         = $row['customer_name'] ?? 'Customer';
            }
        } else {
            $res = mysqli_query($conn, "SELECT customer_chat_id, customer_name FROM conversations ORDER BY id DESC LIMIT 1");
            $row = mysqli_fetch_assoc($res);
            if ($row) {
                $targetCustomerChatId = $row['customer_chat_id'];
                $customerName         = $row['customer_name'] ?? 'Customer';
            }
        }

        if ($targetCustomerChatId) {
            $cleanCustName = $customerName;
            if (preg_match('/^(.*?)\s*(\(@[a-zA-Z0-9_]+\))$/', $customerName, $matches)) {
                $cleanCustName = trim($matches[1]);
            }
            $userLink = "<a href=\"tg://user?id={$targetCustomerChatId}\">" . htmlspecialchars($cleanCustName) . "</a>";

            if ($photoFileId) {
                sendPhoto($targetCustomerChatId, $photoFileId, $caption);
                sendMessage($chatId, "✅ <b>Photo sent to {$userLink}</b> by <i>{$agentName}</i>!");
            } elseif ($docFileId) {
                sendDocument($targetCustomerChatId, $docFileId, $caption);
                sendMessage($chatId, "✅ <b>Document sent to {$userLink}</b> by <i>{$agentName}</i>!");
            } elseif (!empty($text)) {
                sendMessage($targetCustomerChatId, $text);
                sendMessage($chatId, "✅ <b>Response sent to {$userLink}</b> by <i>{$agentName}</i>!");
            }
        }
        return;
    }

    // ========================================================
    // 3. PRIVATE CHAT WORKFLOW
    // ========================================================
    if ($chatType === 'private') {
        if ($text === '/start') {
            sendMessage($chatId, "👋 <b>Welcome to Fieldbi Support!</b>\n\nFieldbi is a technology solutions company specializing in software engineering and digital services.\n\nPlease send your message, question, or application details below, and our support team will assist you shortly.");
            return;
        }

        if ($text === '/status' && $senderId === ADMIN_CHAT_ID) {
            $gCount = 0;
            $pCount = 0;
            if (isset($driver) && $driver === 'pgsql') {
                $gCount = (int)$pdo->query("SELECT COUNT(*) FROM support_groups WHERE is_active = 1")->fetchColumn();
                $pCount = (int)$pdo->query("SELECT COUNT(*) FROM pending_customer_messages WHERE processed = 0")->fetchColumn();
            } else {
                $resG = mysqli_query($conn, "SELECT COUNT(*) as c FROM support_groups WHERE is_active = 1");
                $gCount = (int)mysqli_fetch_assoc($resG)['c'];
                $resP = mysqli_query($conn, "SELECT COUNT(*) as c FROM pending_customer_messages WHERE processed = 0");
                $pCount = (int)mysqli_fetch_assoc($resP)['c'];
            }
            sendMessage($chatId, "📊 <b>Bot System Status</b>\n\n🟢 Active Support Groups: <b>{$gCount}</b>\n⏳ Pending Unprocessed Messages: <b>{$pCount}</b>");
            return;
        }

        if (!empty($mainContent) || $hasMedia) {
            $firstName = trim($message["chat"]["first_name"] ?? '');
            $lastName  = trim($message["chat"]["last_name"] ?? '');
            $username  = trim($message["chat"]["username"] ?? '');

            $customerName = trim($firstName . ' ' . $lastName);
            if (empty($customerName)) {
                $customerName = 'Customer';
            }

            // Check if customer sent any message in the last 15 minutes (to prevent auto-reply spam on multi-line messages)
            $recentlyContacted = false;
            if (isset($driver) && $driver === 'pgsql') {
                $checkRecent = $pdo->prepare("
                    SELECT 1 FROM pending_customer_messages 
                    WHERE customer_chat_id = ? 
                      AND created_at >= NOW() - INTERVAL '15 minutes' 
                    LIMIT 1
                ");
                $checkRecent->execute([$chatId]);
                $recentlyContacted = (bool)$checkRecent->fetchColumn();
            } else {
                $resR = mysqli_query($conn, "
                    SELECT id FROM pending_customer_messages 
                    WHERE customer_chat_id = '{$chatId}' 
                      AND created_at >= NOW() - INTERVAL 15 MINUTE 
                    LIMIT 1
                ");
                $recentlyContacted = mysqli_num_rows($resR) > 0;
            }

            // Save message into pending buffer table (including username)
            if (isset($driver) && $driver === 'pgsql') {
                $bufStmt = $pdo->prepare("
                    INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id)
                    VALUES (:cid, :name, :uname, :msg, :photo, :doc)
                ");
                $bufStmt->execute([
                    ':cid'   => $chatId,
                    ':name'  => $customerName,
                    ':uname' => $username,
                    ':msg'   => $mainContent,
                    ':photo' => $photoFileId,
                    ':doc'   => $docFileId
                ]);
            } else {
                $bufStmt = mysqli_prepare($conn, "INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id) VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($bufStmt, "ssssss", $chatId, $customerName, $username, $mainContent, $photoFileId, $docFileId);
                mysqli_stmt_execute($bufStmt);
            }

            // Send auto-acknowledgment ONLY once per 15-minute conversation window
            if (!$recentlyContacted) {
                sendMessage($chatId, "👋 <b>Thank you for contacting Fieldbi!</b>\n\nFieldbi is a technology & software solutions company. Our team has received your message and will assist you shortly.");
            }
            return;
        }
    }
}

// Handle Direct Webhook execution if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);

    if ($update) {
        processSupportBotUpdate($update);
        flushPendingCustomerMessages(3);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'online', 'bot' => 'Telegram Username Link Customer Support Bot']);
    }
}
