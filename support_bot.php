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

// Bot Super Admin Telegram Chat ID (Loaded dynamically from .env via TELEGRAM_ADMIN_CHAT_ID - No hardcoded fallback)
if (!defined('ADMIN_CHAT_ID')) {
    $adminChatIdEnv = getenv('TELEGRAM_ADMIN_CHAT_ID') ?: ($_ENV['TELEGRAM_ADMIN_CHAT_ID'] ?? ($_SERVER['TELEGRAM_ADMIN_CHAT_ID'] ?? ''));
    define('ADMIN_CHAT_ID', (string)$adminChatIdEnv);
}

/**
 * Validate chat ID format (must be a valid numeric / Telegram chat ID string)
 */
function isValidChatId($chatId) {
    if (!is_string($chatId) && !is_numeric($chatId)) {
        return false;
    }
    return (bool)preg_match('/^-?\d+$/', (string)$chatId);
}

/**
 * Check if the business is currently open (Working Hours Logic)
 * Default: Mon-Fri, 08:00 AM - 05:00 PM (Asia/Phnom_Penh / ICT)
 */
function isBusinessOpen() {
    $tzName = getenv('BUSINESS_TIMEZONE') ?: ($_ENV['BUSINESS_TIMEZONE'] ?? 'Asia/Phnom_Penh');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Phnom_Penh');
    }
    $now = new DateTime('now', $tz);
    
    $day = $now->format('D'); // Mon, Tue, Wed, Thu, Fri, Sat, Sun
    $time = $now->format('H:i'); // 08:30
    
    $workDaysConfig = getenv('BUSINESS_HOURS_DAYS') ?: ($_ENV['BUSINESS_HOURS_DAYS'] ?? 'Mon,Tue,Wed,Thu,Fri');
    $allowedDays = array_map('trim', explode(',', $workDaysConfig));
    
    $startTime = getenv('BUSINESS_HOURS_START') ?: ($_ENV['BUSINESS_HOURS_START'] ?? '08:00');
    $endTime   = getenv('BUSINESS_HOURS_END') ?: ($_ENV['BUSINESS_HOURS_END'] ?? '17:00');
    
    if (!in_array($day, $allowedDays)) {
        return false;
    }
    
    if ($time < $startTime || $time > $endTime) {
        return false;
    }
    
    return true;
}

/**
 * Send text message to Telegram chat
 */
function sendMessage($chatId, $text, $replyMarkup = null, $disableWebPagePreview = false, $linkPreviewOptions = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $postFields = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => $disableWebPagePreview ? 'true' : 'false'
    ];
    if ($linkPreviewOptions !== null) {
        $postFields['link_preview_options'] = is_array($linkPreviewOptions) ? json_encode($linkPreviewOptions) : $linkPreviewOptions;
    }
    if ($replyMarkup !== null) {
        $postFields['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Get user profile photo file_id from Telegram
 */
function getUserProfilePhotoFileId($userId) {
    if (!isValidChatId($userId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getUserProfilePhotos?user_id=" . urlencode($userId) . "&limit=1";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!empty($data['ok']) && !empty($data['result']['photos'][0])) {
        $photos = $data['result']['photos'][0];
        $largest = end($photos);
        return $largest['file_id'] ?? null;
    }
    return null;
}

/**
 * Initialize user_states table and is_cv column if not exist
 */
function initUserStatesSchema() {
    global $pdo, $conn, $driver;
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    try {
        if (isset($driver) && $driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_states (
                    customer_chat_id VARCHAR(50) PRIMARY KEY,
                    current_mode VARCHAR(50) DEFAULT 'general',
                    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
                );
                ALTER TABLE pending_customer_messages ADD COLUMN IF NOT EXISTS is_cv SMALLINT DEFAULT 0;
            ");
        } elseif ($conn) {
            mysqli_query($conn, "
                CREATE TABLE IF NOT EXISTS user_states (
                    customer_chat_id VARCHAR(50) PRIMARY KEY,
                    current_mode VARCHAR(50) DEFAULT 'general',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $check = mysqli_query($conn, "SHOW COLUMNS FROM pending_customer_messages LIKE 'is_cv'");
            if ($check && mysqli_num_rows($check) === 0) {
                mysqli_query($conn, "ALTER TABLE pending_customer_messages ADD COLUMN is_cv TINYINT(1) DEFAULT 0");
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Set current user workflow mode (e.g., 'submit_cv', 'ask_question', 'general')
 */
function setUserMode($chatId, $mode) {
    global $pdo, $conn, $driver;
    if (!isValidChatId($chatId)) return;
    initUserStatesSchema();

    $stateDir = __DIR__ . '/storage/states';
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }
    @file_put_contents("{$stateDir}/{$chatId}.json", json_encode(['mode' => $mode, 'time' => time()]));

    try {
        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->prepare("
                INSERT INTO user_states (customer_chat_id, current_mode) VALUES (?, ?)
                ON CONFLICT (customer_chat_id) DO UPDATE SET current_mode = EXCLUDED.current_mode
            ");
            $stmt->execute([$chatId, $mode]);
        } elseif ($conn) {
            $stmt = mysqli_prepare($conn, "INSERT INTO user_states (customer_chat_id, current_mode) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_mode = VALUES(current_mode)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ss", $chatId, $mode);
                mysqli_stmt_execute($stmt);
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Get current user workflow mode
 */
function getUserMode($chatId) {
    global $pdo, $conn, $driver;
    if (!isValidChatId($chatId)) return 'general';
    initUserStatesSchema();

    try {
        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT current_mode FROM user_states WHERE customer_chat_id = ?");
            $stmt->execute([$chatId]);
            $mode = $stmt->fetchColumn();
            if ($mode) return $mode;
        } elseif ($conn) {
            $stmt = mysqli_prepare($conn, "SELECT current_mode FROM user_states WHERE customer_chat_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $chatId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($res && $row = mysqli_fetch_assoc($res)) {
                    return $row['current_mode'] ?? 'general';
                }
            }
        }
    } catch (Throwable $e) {}

    $stateFile = __DIR__ . "/storage/states/{$chatId}.json";
    if (file_exists($stateFile)) {
        $data = json_decode(@file_get_contents($stateFile), true);
        if (!empty($data['mode']) && (time() - ($data['time'] ?? 0)) < 3600) {
            return $data['mode'];
        }
    }
    return 'general';
}

/**
 * Validate whether a message contains a valid CV (document, photo, or valid resume content)
 */
function isValidCvSubmission($message) {
    $document = $message["document"] ?? null;
    $photo    = $message["photo"] ?? null;
    $text     = trim($message["text"] ?? ($message["caption"] ?? ''));

    // 1. Check Document attachment
    if (!empty($document)) {
        $fileName = strtolower($document["file_name"] ?? '');
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $blockedExtensions = ['exe', 'bat', 'cmd', 'sh', 'apk', 'jar', 'vbs', 'scr', 'dll'];

        if (!empty($ext) && in_array($ext, $blockedExtensions)) {
            return false;
        }
        return true;
    }

    // 2. Check Photo attachment
    if (!empty($photo)) {
        return true;
    }

    // 3. Check Text or Link content
    if (!empty($text)) {
        return true;
    }

    return false;
}




/**
 * Send photo to Telegram chat
 */
function sendPhoto($chatId, $fileId, $caption = '', $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Send document to Telegram chat
 */
function sendDocument($chatId, $fileId, $caption = '', $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Answer Telegram Callback Query (Toast / Alert notification)
 */
function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert ? 'true' : 'false'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Edit message text in Telegram chat
 */
function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText";
    $postFields = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Edit message caption in Telegram chat
 */
function editMessageCaption($chatId, $messageId, $caption, $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageCaption";
    $postFields = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Flush and group pending customer messages (default 3 seconds delay for snappy response)
 */
function flushPendingCustomerMessages($forceDelaySeconds = 5) {
    global $pdo, $conn, $driver;

    if (isset($driver) && $driver === 'pgsql') {
        $readyStmt = $pdo->prepare("
            SELECT customer_chat_id, MAX(customer_name) as customer_name, MAX(username) as username
            FROM pending_customer_messages
            WHERE processed = 0
            GROUP BY customer_chat_id
            HAVING EXTRACT(EPOCH FROM (NOW() - MAX(created_at))) >= :delay
        ");
        $readyStmt->execute([':delay' => (int)$forceDelaySeconds]);
        $readyCustomers = $readyStmt->fetchAll(PDO::FETCH_ASSOC);

        $groupStmt = $pdo->query("SELECT group_chat_id FROM support_groups WHERE is_active = 1");
        $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT customer_chat_id, MAX(customer_name) as customer_name, MAX(username) as username
            FROM pending_customer_messages
            WHERE processed = 0
            GROUP BY customer_chat_id
            HAVING TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) >= ?
        ");
        $delayInt = (int)$forceDelaySeconds;
        mysqli_stmt_bind_param($stmt, "i", $delayInt);
        mysqli_stmt_execute($stmt);
        $readyCustomers = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

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
        $chatId       = (string)$cust['customer_chat_id'];
        $customerName = $cust['customer_name'] ?? 'Customer';
        $username     = trim($cust['username'] ?? '');

        if (!isValidChatId($chatId)) continue;

        // Fetch all pending messages for this customer (Parameterized Query)
        if (isset($driver) && $driver === 'pgsql') {
            $msgStmt = $pdo->prepare("SELECT * FROM pending_customer_messages WHERE customer_chat_id = ? AND processed = 0 ORDER BY id ASC");
            $msgStmt->execute([$chatId]);
            $pendingMsgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $msgStmt = mysqli_prepare($conn, "SELECT * FROM pending_customer_messages WHERE customer_chat_id = ? AND processed = 0 ORDER BY id ASC");
            mysqli_stmt_bind_param($msgStmt, "s", $chatId);
            mysqli_stmt_execute($msgStmt);
            $pendingMsgs = mysqli_fetch_all(mysqli_stmt_get_result($msgStmt), MYSQLI_ASSOC);
        }

        if (empty($pendingMsgs)) {
            continue;
        }

        // Combine text lines and find photo/doc
        $rawTextLines   = [];
        $photoFileId    = null;
        $docFileId      = null;
        $isCvSubmission = false;

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
            if (!empty($m['is_cv'])) {
                $isCvSubmission = true;
            }
            if (empty($username) && !empty($m['username'])) {
                $username = trim($m['username']);
            }
        }

        $messageBody  = !empty($rawTextLines) ? implode("\n", $rawTextLines) : '';
        $combinedText = !empty($messageBody) ? "💬 <b>Details:</b>\n<blockquote>" . $messageBody . "</blockquote>" : '';

        // Create/Update Conversation Ticket ID (Parameterized Query)
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

        // Format Contact Info with 1-Tap Clickable Telegram Links & Direct Contact Action
        $cleanCustName = $customerName;
        if (preg_match('/^(.*?)\s*(\(@[a-zA-Z0-9_]+\))$/', $customerName, $matches)) {
            $cleanCustName = trim($matches[1]);
        }

        if (!empty($username)) {
            $cleanUsername = ltrim(trim($username), '@');
            $contactUrl = "https://t.me/" . htmlspecialchars($cleanUsername);
            $userLink = "<a href=\"{$contactUrl}\">" . htmlspecialchars($cleanCustName) . "</a>";
            $contactDisplay = "{$userLink} (<code>@{$cleanUsername}</code>)";
        } else {
            $contactUrl = "tg://user?id={$chatId}";
            $userLink = "<a href=\"{$contactUrl}\">" . htmlspecialchars($cleanCustName) . "</a>";
            $contactDisplay = "{$userLink} (ID: <code>{$chatId}</code>)";
        }

        $formattedDate = date('d M Y | h:i A');

        if ($isCvSubmission) {
            $ticketHeader = "📄 <b>CV SUBMISSION #{$convId}</b>\n"
                          . "──────────────\n"
                          . "👤 <b>Candidate:</b> {$contactDisplay}\n"
                          . (!empty($combinedText) ? $combinedText . "\n" : "")
                          . "──────────────\n"
                          . "📅 <b>Date:</b> {$formattedDate}\n"
                          . "⏳ <b>Status:</b> <b>PENDING</b>\n"
                          . "──────────────\n"
                          . "💡 <i>Reply to this message to respond to candidate.</i>";

            $ticketBtn = [
                'inline_keyboard' => [
                    [
                        ['text' => '📄 Contact Candidate', 'url' => $contactUrl]
                    ]
                ]
            ];
        } else {
            $ticketHeader = "🎫 <b>SUPPORT TICKET #{$convId}</b>\n"
                          . "──────────────\n"
                          . "👤 <b>From:</b> {$contactDisplay}\n"
                          . (!empty($combinedText) ? $combinedText . "\n" : "")
                          . "──────────────\n"
                          . "📅 <b>Date:</b> {$formattedDate}\n"
                          . "⏳ <b>Status:</b> <b>PENDING</b>\n"
                          . "──────────────\n"
                          . "💡 <i>Reply to this message to respond.</i>";

            $ticketBtn = [
                'inline_keyboard' => [
                    [
                        ['text' => '💬 Contact Customer', 'url' => $contactUrl]
                    ]
                ]
            ];
        }



        // Post ONE combined ticket message into each Telegram Support Group (or Admin fallback)
        foreach ($groups as $g) {
            $gId = (string)$g['group_chat_id'];
            if (!isValidChatId($gId)) continue;
            $apiRes = null;

            if ($photoFileId) {
                $apiRes = sendPhoto($gId, $photoFileId, $ticketHeader, $ticketBtn);
            } elseif ($docFileId) {
                $apiRes = sendDocument($gId, $docFileId, $ticketHeader, $ticketBtn);
            } else {
                // Check if user has profile photo available if no public username
                if (empty($username)) {
                    $userProfilePhoto = getUserProfilePhotoFileId($chatId);
                    if (!empty($userProfilePhoto)) {
                        $apiRes = sendPhoto($gId, $userProfilePhoto, $ticketHeader, $ticketBtn);
                    }
                }

                // Send text message with Telegram profile link preview card enabled
                if (empty($apiRes)) {
                    $linkPreviewOptions = !empty($username) ? [
                        'url'                => $contactUrl,
                        'prefer_small_media' => true,
                        'show_above_text'    => false,
                        'is_disabled'        => false
                    ] : null;
                    $apiRes = sendMessage($gId, $ticketHeader, $ticketBtn, false, $linkPreviewOptions);
                }
            }


            if (!empty($apiRes['ok']) && isset($apiRes['result']['message_id'])) {
                $groupMessageId = $apiRes['result']['message_id'];

                // Map Group Message ID -> Customer Chat ID (Parameterized Query)
                if (isset($driver) && $driver === 'pgsql') {
                    $mapStmt = $pdo->prepare("
                        INSERT INTO group_messages (group_chat_id, group_message_id, customer_chat_id)
                        VALUES (:gid, :gmid, :cid)
                        ON CONFLICT (group_message_id) DO NOTHING
                    ");
                    $mapStmt->execute([
                        ':gid'  => $gId,
                        ':gmid' => $groupMessageId,
                        ':cid'  => $chatId
                    ]);
                } else {
                    $mapStmt = mysqli_prepare($conn, "INSERT IGNORE INTO group_messages (group_chat_id, group_message_id, customer_chat_id) VALUES (?, ?, ?)");
                    mysqli_stmt_bind_param($mapStmt, "sis", $gId, $groupMessageId, $chatId);
                    mysqli_stmt_execute($mapStmt);
                }
            }
        }

        // Mark pending messages as processed (Parameterized Query)
        if (isset($driver) && $driver === 'pgsql') {
            $markStmt = $pdo->prepare("UPDATE pending_customer_messages SET processed = 1 WHERE customer_chat_id = ? AND processed = 0");
            $markStmt->execute([$chatId]);
        } else {
            $markStmt = mysqli_prepare($conn, "UPDATE pending_customer_messages SET processed = 1 WHERE customer_chat_id = ? AND processed = 0");
            mysqli_stmt_bind_param($markStmt, "s", $chatId);
            mysqli_stmt_execute($markStmt);
        }
    }
}

/**
 * Main update handler function
 */
function processSupportBotUpdate($update) {
    global $pdo, $conn, $driver;

    // ========================================================
    // A. CALLBACK QUERY WORKFLOW
    // ========================================================
    if (isset($update["callback_query"])) {
        $cb         = $update["callback_query"];
        $cbId       = $cb["id"];
        $cbData     = $cb["data"] ?? '';
        $agentId    = (string)($cb["from"]["id"] ?? '');
        $agentFirst = trim($cb["from"]["first_name"] ?? '');
        $agentLast  = trim($cb["from"]["last_name"] ?? '');
        $agentName  = trim($agentFirst . ' ' . $agentLast);
        if (empty($agentName)) {
            $agentName = 'Support Agent';
        }

        if (strpos($cbData, 'claim_') === 0) {
            $convId = (int)substr($cbData, 6);

            $convData = null;
            if (isset($driver) && $driver === 'pgsql') {
                $stmt = $pdo->prepare("SELECT id, customer_chat_id, customer_name, username, assigned_agent FROM conversations WHERE id = ?");
                $stmt->execute([$convId]);
                $convData = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = mysqli_prepare($conn, "SELECT id, customer_chat_id, customer_name, username, assigned_agent FROM conversations WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "i", $convId);
                mysqli_stmt_execute($stmt);
                $convData = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            }

            if (!$convData) {
                answerCallbackQuery($cbId, "⚠️ Ticket #{$convId} not found!", true);
                return;
            }

            $assignedAgent = $convData['assigned_agent'] ?? null;

            if (!empty($assignedAgent)) {
                if ($assignedAgent === $agentName) {
                    answerCallbackQuery($cbId, "ℹ️ You have already claimed Ticket #{$convId}!", true);
                } else {
                    answerCallbackQuery($cbId, "⚠️ Ticket #{$convId} is already claimed by {$assignedAgent}!", true);
                }
                return;
            }

            // Assign ticket in database (Parameterized Query)
            if (isset($driver) && $driver === 'pgsql') {
                $upStmt = $pdo->prepare("UPDATE conversations SET status = 'claimed', assigned_agent = ?, assigned_agent_id = ? WHERE id = ?");
                $upStmt->execute([$agentName, $agentId, $convId]);
            } else {
                $upStmt = mysqli_prepare($conn, "UPDATE conversations SET status = 'claimed', assigned_agent = ?, assigned_agent_id = ? WHERE id = ?");
                mysqli_stmt_bind_param($upStmt, "ssi", $agentName, $agentId, $convId);
                mysqli_stmt_execute($upStmt);
            }

            // Edit Telegram Group message text / caption
            if (isset($cb["message"])) {
                $msg     = $cb["message"];
                $gChatId = (string)($msg["chat"]["id"] ?? '');
                $gMsgId  = $msg["message_id"];

                if (isValidChatId($gChatId)) {
                    $customerName   = $convData['customer_name'] ?? 'Customer';
                    $customerChatId = $convData['customer_chat_id'] ?? '';
                    $username       = trim($convData['username'] ?? '');

                    $cleanCustName = $customerName;
                    if (preg_match('/^(.*?)\s*(\(@[a-zA-Z0-9_]+\))$/', $customerName, $matches)) {
                        $cleanCustName = trim($matches[1]);
                    }

                    if (!empty($username)) {
                        $cleanUsername = ltrim(trim($username), '@');
                        $contactUrl = "https://t.me/" . htmlspecialchars($cleanUsername);
                        $userLink = "<a href=\"{$contactUrl}\">" . htmlspecialchars($cleanCustName) . "</a>";
                        $contactDisplay = "{$userLink} (<code>@{$cleanUsername}</code>)";
                    } else {
                        $contactUrl = "tg://user?id={$customerChatId}";
                        $userLink = "<a href=\"{$contactUrl}\">" . htmlspecialchars($cleanCustName) . "</a>";
                        $contactDisplay = "{$userLink} (ID: <code>{$customerChatId}</code>)";
                    }

                    $rawMsgText = $msg["text"] ?? ($msg["caption"] ?? '');
                    $messageContent = '';
                    if (preg_match('/💬 <b>(Message|Details):<\/b>\s*\n(?:<blockquote>|<i>[“"«]?)?(.*?)(?:<\/blockquote>|[”"»]?<\/i>)?(?=\n─|\n━|$)/s', $rawMsgText, $matches)) {
                        $messageContent = trim($matches[2]);
                    } elseif (preg_match('/(Message|Details):\s*\n(.*?)(?=\n─|\n━|$)/s', $rawMsgText, $matches)) {
                        $messageContent = trim($matches[2]);
                    }

                    $claimedDate = date('d M Y | h:i A');
                    $claimedBody = "🎫 <b>SUPPORT TICKET #{$convId}</b>\n"
                                 . "──────────────\n"
                                 . "👤 <b>From:</b> {$contactDisplay}\n"
                                 . (!empty($messageContent) ? "💬 <b>Details:</b>\n<blockquote>" . htmlspecialchars($messageContent) . "</blockquote>\n" : "")
                                 . "──────────────\n"
                                 . "📅 <b>Date:</b> {$claimedDate}\n"
                                 . "✅ <b>Status:</b> <b>CLAIMED</b> by <i>" . htmlspecialchars($agentName) . "</i>\n"
                                 . "──────────────\n"
                                 . "💬 <i>Agent " . htmlspecialchars($agentName) . " is handling this ticket.</i>";



                    $claimedBtn = [
                        'inline_keyboard' => [
                            [
                                ['text' => '💬 Contact Customer', 'url' => $contactUrl],
                                ['text' => '✅ Claimed by ' . $agentName, 'callback_data' => 'claimed']
                            ]
                        ]
                    ];

                    if (isset($msg["caption"])) {
                        editMessageCaption($gChatId, $gMsgId, $claimedBody, $claimedBtn);
                    } else {
                        editMessageText($gChatId, $gMsgId, $claimedBody, $claimedBtn);
                    }
                }
            }

            answerCallbackQuery($cbId, "✅ You have successfully claimed Ticket #{$convId}!");
            return;
        }

        if ($cbData === 'menu_submit_cv') {
            answerCallbackQuery($cbId, "📄 Option selected: Submit CV");
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            setUserMode($userChatId, 'submit_cv');
            sendMessage($userChatId, "📄 <b>SUBMIT CV / RESUME</b>\n────────────────────\nPlease upload your CV file (<b>PDF, DOC, DOCX</b>) or send your CV photo/details below.\n\n⏳ <i>Waiting for your CV upload...</i>");
            return;
        }

        if ($cbData === 'menu_ask_question') {
            answerCallbackQuery($cbId, "💬 Option selected: Ask Question");
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            setUserMode($userChatId, 'ask_question');
            sendMessage($userChatId, "💬 <b>ASK A QUESTION</b>\n────────────────────\nPlease type your message or question below, and our support team will assist you shortly!");
            return;
        }

        if (strpos($cbData, 'faq_') === 0 || $cbData === 'menu_faq') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            if ($cbData === 'menu_faq') {
                answerCallbackQuery($cbId, "❓ Frequently Asked Questions");
                $faqKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '📋 Job Openings', 'callback_data' => 'faq_jobs']
                        ],
                        [
                            ['text' => '📍 Office Location', 'callback_data' => 'faq_location'],
                            ['text' => '⏰ Working Hours', 'callback_data' => 'faq_hours']
                        ]
                    ]
                ];
                sendMessage($userChatId, "❓ <b>FREQUENTLY ASKED QUESTIONS</b>\n────────────────────\nPlease select a topic below to get instant answers:", $faqKeyboard);
                return;
            }

            $faqAnswers = [
                'faq_jobs' => "📋 <b>JOB OPENINGS</b>\n────────────────────\n• <b>Software Engineer</b>\n• <b>Marketing Specialist</b>\n• <b>Sales Representative</b>\n\n📄 <i>Tap /Submit_CV to apply directly!</i>",
                'faq_location' => "📍 <b>OFFICE LOCATION</b>\n────────────────────\n🏢 <b>Fieldbi Cambodia</b>\nPhnom Penh, Cambodia\n\n📍 <i>Contact our support team for full office directions.</i>",
                'faq_hours' => "⏰ <b>WORKING HOURS</b>\n────────────────────\n• <b>Monday – Friday:</b> 8:00 AM – 5:00 PM (ICT)\n• <b>Saturday:</b> 8:00 AM – 12:00 PM\n• <b>Sunday:</b> Closed"
            ];

            if (isset($faqAnswers[$cbData])) {
                answerCallbackQuery($cbId, "Answer loaded");
                sendMessage($userChatId, $faqAnswers[$cbData]);
            }
            return;
        }


        if (strpos($cbData, 'claimed') === 0) {
            answerCallbackQuery($cbId, "ℹ️ This ticket has already been claimed.", false);
            return;
        }
        return;
    }

    
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

    if (!isValidChatId($chatId)) {
        return;
    }

    $photoFileId = !empty($photo) ? end($photo)["file_id"] : null;
    $docFileId   = !empty($document) ? $document["file_id"] : null;
    $hasMedia    = !empty($photoFileId) || !empty($docFileId);
    $mainContent = !empty($text) ? $text : $caption;

    // ========================================================
    // B.1 /handled COMMAND WORKFLOW
    // ========================================================
    if (preg_match('/^\/handled(?:@\w+)?(?:\s+(?:#)?(\d+))?/i', $text, $matches)) {
        $agentFirstName = trim($message["from"]["first_name"] ?? '');
        $agentLastName  = trim($message["from"]["last_name"] ?? '');
        $agentName      = trim($agentFirstName . ' ' . $agentLastName);
        if (empty($agentName)) {
            $agentName = 'Support Agent';
        }

        $targetConvId = !empty($matches[1]) ? (int)$matches[1] : 0;

        // If no ticket ID in command, check if command was sent as a reply to a ticket card
        if (!$targetConvId && isset($message["reply_to_message"])) {
            $replyToId = $message["reply_to_message"]["message_id"];
            if (isset($driver) && $driver === 'pgsql') {
                $stmt = $pdo->prepare("
                    SELECT c.id 
                    FROM group_messages gm 
                    LEFT JOIN conversations c ON c.customer_chat_id = gm.customer_chat_id 
                    WHERE gm.group_message_id = ?
                ");
                $stmt->execute([$replyToId]);
                $targetConvId = (int)$stmt->fetchColumn();
            } else {
                $stmt = mysqli_prepare($conn, "
                    SELECT c.id 
                    FROM group_messages gm 
                    LEFT JOIN conversations c ON c.customer_chat_id = gm.customer_chat_id 
                    WHERE gm.group_message_id = ?
                ");
                mysqli_stmt_bind_param($stmt, "i", $replyToId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                $targetConvId = (int)($res['id'] ?? 0);
            }
        }

        // If still no ticket ID, fallback to most recent conversation
        if (!$targetConvId) {
            if (isset($driver) && $driver === 'pgsql') {
                $targetConvId = (int)$pdo->query("SELECT id FROM conversations ORDER BY id DESC LIMIT 1")->fetchColumn();
            } else {
                $res = mysqli_query($conn, "SELECT id FROM conversations ORDER BY id DESC LIMIT 1");
                $targetConvId = (int)(mysqli_fetch_assoc($res)['id'] ?? 0);
            }
        }

        if (!$targetConvId) {
            sendMessage($chatId, "⚠️ <b>No active tickets found to mark as handled.</b>");
            return;
        }

        // Update DB status = 'handled', assigned_agent = $agentName
        if (isset($driver) && $driver === 'pgsql') {
            $upStmt = $pdo->prepare("UPDATE conversations SET status = 'handled', assigned_agent = ? WHERE id = ?");
            $upStmt->execute([$agentName, $targetConvId]);
        } else {
            $upStmt = mysqli_prepare($conn, "UPDATE conversations SET status = 'handled', assigned_agent = ? WHERE id = ?");
            mysqli_stmt_bind_param($upStmt, "si", $agentName, $targetConvId);
            mysqli_stmt_execute($upStmt);
        }

        $notification = "✅ <b>Ticket #{$targetConvId} Handled</b> by <b>" . htmlspecialchars($agentName) . "</b>. No further action needed.";

        if ($isGroup) {
            sendMessage($chatId, $notification);
        } else {
            // Broadcast to active support groups if executed in private chat
            $groups = [];
            if (isset($driver) && $driver === 'pgsql') {
                $groups = $pdo->query("SELECT group_chat_id FROM support_groups WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $res = mysqli_query($conn, "SELECT group_chat_id FROM support_groups WHERE is_active = 1");
                $groups = mysqli_fetch_all($res, MYSQLI_ASSOC);
            }
            foreach ($groups as $g) {
                sendMessage($g['group_chat_id'], $notification);
            }
            sendMessage($chatId, $notification);
        }
        return;
    }

    // ========================================================
    // B.2 TICKET REPLY CHECK (Works in Groups AND Admin Private Chat)
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

        if ($targetCustomerChatId && isValidChatId($targetCustomerChatId)) {
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

            // Update DB status = 'handled', assigned_agent = $agentName
            if (isset($driver) && $driver === 'pgsql') {
                $upStmt = $pdo->prepare("UPDATE conversations SET status = 'handled', assigned_agent = ? WHERE customer_chat_id = ?");
                $upStmt->execute([$agentName, $targetCustomerChatId]);
            } else {
                $upStmt = mysqli_prepare($conn, "UPDATE conversations SET status = 'handled', assigned_agent = ? WHERE customer_chat_id = ?");
                mysqli_stmt_bind_param($upStmt, "ss", $agentName, $targetCustomerChatId);
                mysqli_stmt_execute($upStmt);
            }

            if ($photoFileId) {
                sendPhoto($targetCustomerChatId, $photoFileId, $caption);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Photo sent to {$userLink})");
            } elseif ($docFileId) {
                sendDocument($targetCustomerChatId, $docFileId, $caption);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Document sent to {$userLink})");
            } elseif (!empty($text)) {
                sendMessage($targetCustomerChatId, $text);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Response sent to {$userLink})");
            }
            return;
        }
    }

    // ========================================================
    // C. TELEGRAM GROUP CHAT WORKFLOW
    // ========================================================
    if ($isGroup) {
        $groupTitle = $message["chat"]["title"] ?? 'Support Group';

        // Check if group is already authorized in Database (Parameterized Query)
        $isGroupAuthorized = false;
        if (isset($driver) && $driver === 'pgsql') {
            $checkG = $pdo->prepare("SELECT 1 FROM support_groups WHERE group_chat_id = ? AND is_active = 1");
            $checkG->execute([$chatId]);
            $isGroupAuthorized = (bool)$checkG->fetchColumn();
        } else {
            $stmt = mysqli_prepare($conn, "SELECT group_chat_id FROM support_groups WHERE group_chat_id = ? AND is_active = 1");
            mysqli_stmt_bind_param($stmt, "s", $chatId);
            mysqli_stmt_execute($stmt);
            $resG = mysqli_stmt_get_result($stmt);
            $isGroupAuthorized = mysqli_num_rows($resG) > 0;
        }

        // Automatically activate/authorize any group where the bot is added or active (Parameterized Query)
        if (!$isGroupAuthorized) {
            if (isset($driver) && $driver === 'pgsql') {
                $stmt = $pdo->prepare("
                    INSERT INTO support_groups (group_chat_id, group_title, is_active)
                    VALUES (:gid, :title, 1)
                    ON CONFLICT (group_chat_id) DO UPDATE SET is_active = 1, group_title = EXCLUDED.group_title
                ");
                $stmt->execute([':gid' => $chatId, ':title' => $groupTitle]);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO support_groups (group_chat_id, group_title, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1, group_title = VALUES(group_title)");
                mysqli_stmt_bind_param($stmt, "ss", $chatId, $groupTitle);
                mysqli_stmt_execute($stmt);
            }
            sendMessage($chatId, "🛡️ <b>SUPPORT GROUP AUTHORIZED</b>\n────────────────────\nGroup: <b>" . htmlspecialchars($groupTitle) . "</b>\nStatus: 🟢 <b>Active</b>\n\n<i>This group will now receive all incoming customer support tickets.</i>");
        }

        // Ignore commands or empty messages in group
        if (empty($mainContent) && !$hasMedia) {
            return;
        }
        if (strpos($text, '/') === 0) {
            return;
        }

        // Fallback route: Send to most recent active customer
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

        if ($targetCustomerChatId && isValidChatId($targetCustomerChatId)) {
            $cleanCustName = $customerName;
            if (preg_match('/^(.*?)\s*(\(@[a-zA-Z0-9_]+\))$/', $customerName, $matches)) {
                $cleanCustName = trim($matches[1]);
            }
            $userLink = "<a href=\"tg://user?id={$targetCustomerChatId}\">" . htmlspecialchars($cleanCustName) . "</a>";

            if ($photoFileId) {
                sendPhoto($targetCustomerChatId, $photoFileId, $caption);
                sendMessage($chatId, "✅ <b>Photo Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif ($docFileId) {
                sendDocument($targetCustomerChatId, $docFileId, $caption);
                sendMessage($chatId, "✅ <b>Document Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif (!empty($text)) {
                sendMessage($targetCustomerChatId, $text);
                sendMessage($chatId, "✅ <b>Response Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            }
        }
        return;
    }

    // ========================================================
    // D. PRIVATE CHAT WORKFLOW
    // ========================================================
    if ($chatType === 'private') {
        if (strpos($text, '/start') === 0) {
            $firstName = trim($message["chat"]["first_name"] ?? '');
            $lastName  = trim($message["chat"]["last_name"] ?? '');
            $username  = trim($message["chat"]["username"] ?? '');

            $customerName = trim($firstName . ' ' . $lastName);
            if (empty($customerName)) {
                $customerName = 'Customer';
            }

            // Log initial contact timestamp so first message does not send a duplicate auto-reply
            if (isset($driver) && $driver === 'pgsql') {
                $bufStmt = $pdo->prepare("
                    INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id, processed)
                    VALUES (:cid, :name, :uname, '/start', NULL, NULL, 1)
                ");
                $bufStmt->execute([
                    ':cid'   => $chatId,
                    ':name'  => $customerName,
                    ':uname' => $username
                ]);
            } else {
                $bufStmt = mysqli_prepare($conn, "INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id, processed) VALUES (?, ?, ?, '/start', NULL, NULL, 1)");
                mysqli_stmt_bind_param($bufStmt, "sss", $chatId, $customerName, $username);
                mysqli_stmt_execute($bufStmt);
            }

            $welcomeText = "👋 <b>Welcome to Fieldbi Support!</b>\n"
                         . "────────────────────\n"
                         . "Fieldbi is a technology & software solutions company.\n\n"
                         . "Please select an option below or type your message:\n"
                         . "📄 /Submit_CV — Submit your CV / Resume\n"
                         . "💬 /Ask_Question — Ask a Question or Inquiry\n"
                         . "❓ /FAQ — Frequently Asked Questions";

            $welcomeKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📄 Submit CV', 'callback_data' => 'menu_submit_cv'],
                        ['text' => '💬 Ask Question', 'callback_data' => 'menu_ask_question']
                    ],
                    [
                        ['text' => '❓ FAQ / Quick Answers', 'callback_data' => 'menu_faq']
                    ]
                ]
            ];

            sendMessage($chatId, $welcomeText, $welcomeKeyboard);
            return;
        }

        if (preg_match('/^\/(submit_cv|submitcv|cv)(?:@\w+)?/i', $text)) {
            setUserMode($chatId, 'submit_cv');
            $msgText = "📄 <b>SUBMIT CV / RESUME</b>\n────────────────────\nPlease upload your CV file (<b>PDF, DOC, DOCX</b>) or send your CV photo/details below.\n\n⏳ <i>Waiting for your CV upload...</i>";
            sendMessage($chatId, $msgText);
            return;
        }

        if (preg_match('/^\/(ask_question|askquestion|ask)(?:@\w+)?/i', $text)) {
            setUserMode($chatId, 'ask_question');
            $msgText = "💬 <b>ASK A QUESTION</b>\n────────────────────\nPlease type your message or question below, and our support team will assist you shortly!";
            sendMessage($chatId, $msgText);
            return;
        }

        if (preg_match('/^\/(faq|help)(?:@\w+)?/i', $text)) {
            $faqKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📋 Job Openings', 'callback_data' => 'faq_jobs']
                    ],
                    [
                        ['text' => '📍 Office Location', 'callback_data' => 'faq_location'],
                        ['text' => '⏰ Working Hours', 'callback_data' => 'faq_hours']
                    ]
                ]
            ];
            $faqMsg = "❓ <b>FREQUENTLY ASKED QUESTIONS</b>\n────────────────────\nPlease select a topic below to get instant answers:";
            sendMessage($chatId, $faqMsg, $faqKeyboard);
            return;
        }

        if ($text === '/status' && !empty(ADMIN_CHAT_ID) && $senderId === ADMIN_CHAT_ID) {
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
            sendMessage($chatId, "📊 <b>BOT SYSTEM STATUS</b>\n────────────────────\n🟢 <b>Active Support Groups:</b> <code>{$gCount}</code>\n⏳ <b>Pending Messages:</b> <code>{$pCount}</code>");
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

            $currentMode = getUserMode($chatId);
            $isCvMessage = 0;

            if ($currentMode === 'submit_cv') {
                if (!isValidCvSubmission($message)) {
                    sendMessage($chatId, "⚠️ <b>Invalid CV Format!</b>\n────────────────────\nPlease upload your CV as a valid document (<b>PDF, DOC, DOCX</b>) or image (<b>PNG, JPG</b>).\n\n<i>If you wish to ask a general question instead, tap /Ask_Question.</i>");
                    return;
                }
                $isCvMessage = 1;
                setUserMode($chatId, 'general');
            } elseif ($currentMode === 'ask_question') {
                setUserMode($chatId, 'general');
            }

            // Check if customer sent any message in the last 15 minutes (Parameterized Query)
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
                $stmt = mysqli_prepare($conn, "
                    SELECT id FROM pending_customer_messages 
                    WHERE customer_chat_id = ? 
                      AND created_at >= NOW() - INTERVAL 15 MINUTE 
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($stmt, "s", $chatId);
                mysqli_stmt_execute($stmt);
                $resR = mysqli_stmt_get_result($stmt);
                $recentlyContacted = mysqli_num_rows($resR) > 0;
            }

            // Save message into pending buffer table (Parameterized Query)
            if (isset($driver) && $driver === 'pgsql') {
                $bufStmt = $pdo->prepare("
                    INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id, is_cv)
                    VALUES (:cid, :name, :uname, :msg, :photo, :doc, :iscv)
                ");
                $bufStmt->execute([
                    ':cid'   => $chatId,
                    ':name'  => $customerName,
                    ':uname' => $username,
                    ':msg'   => $mainContent,
                    ':photo' => $photoFileId,
                    ':doc'   => $docFileId,
                    ':iscv'  => $isCvMessage
                ]);
            } else {
                $bufStmt = mysqli_prepare($conn, "INSERT INTO pending_customer_messages (customer_chat_id, customer_name, username, message_text, photo_file_id, doc_file_id, is_cv) VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($bufStmt, "ssssssi", $chatId, $customerName, $username, $mainContent, $photoFileId, $docFileId, $isCvMessage);
                mysqli_stmt_execute($bufStmt);
            }

            if ($isCvMessage) {
                sendMessage($chatId, "✅ <b>CV Received & Submitted!</b>\n────────────────────\nThank you, <b>" . htmlspecialchars($customerName) . "</b>! 📄\n\nOur HR & Recruitment team has received your application and CV details. We will review your profile and reach out to you shortly.\n\n💬 <i>If you need to send additional documents or updates, feel free to send them here anytime.</i>");
            } else {

                // Send auto-acknowledgment ONLY once per 15-minute conversation window
                if (!$recentlyContacted) {
                    if (isBusinessOpen()) {
                        sendMessage($chatId, "👋 <b>Thank you for contacting Fieldbi!</b>\n────────────────────\nOur support team has received your message and will respond to you shortly.");
                    } else {
                        sendMessage($chatId, "🌙 <b>Thank you for contacting Fieldbi!</b>\n────────────────────\nOur office is currently closed.\n⏰ <b>Business Hours:</b> Mon – Fri, 8:00 AM – 5:00 PM (ICT)\n\nYour message has been received, and our team will respond as soon as we open!");
                    }
                }
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
        flushPendingCustomerMessages(5);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'online', 'bot' => 'Telegram Customer Support Bot']);
    }
}
