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
 * Synchronize Telegram Bot Name & Description ("What can this bot do?" card)
 */
function updateBotProfileInfo() {
    $botToken = BOT_TOKEN;
    if (empty($botToken)) return;

    $descText = "👋 Welcome to FieldBi Cambodia Support!\n\nFieldbi is a technology & software solutions company specializing in digital platforms and software engineering.\n\n💬 Send us your message, question, or job application details below, and our support team will assist you shortly.";

    $endpoints = [
        "https://api.telegram.org/bot{$botToken}/setMyName" => ['name' => 'FieldBi Cambodia Support'],
        "https://api.telegram.org/bot{$botToken}/setMyDescription" => ['description' => $descText],
        "https://api.telegram.org/bot{$botToken}/setMyShortDescription" => ['short_description' => 'FieldBi Cambodia Support — Official Customer & Career Support Bot.']
    ];

    foreach ($endpoints as $url => $postData) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
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
 * Initialize user_states table and is_cv/lang columns if not exist
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
                    lang VARCHAR(10) DEFAULT 'en',
                    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
                );
                ALTER TABLE user_states ADD COLUMN IF NOT EXISTS lang VARCHAR(10) DEFAULT 'en';
                ALTER TABLE pending_customer_messages ADD COLUMN IF NOT EXISTS is_cv SMALLINT DEFAULT 0;
                ALTER TABLE conversations ADD COLUMN IF NOT EXISTS assigned_agent VARCHAR(100);
                ALTER TABLE conversations ADD COLUMN IF NOT EXISTS assigned_agent_id VARCHAR(50);
            ");
        } elseif ($conn) {
            mysqli_query($conn, "
                CREATE TABLE IF NOT EXISTS user_states (
                    customer_chat_id VARCHAR(50) PRIMARY KEY,
                    current_mode VARCHAR(50) DEFAULT 'general',
                    lang VARCHAR(10) DEFAULT 'en',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $checkLang = mysqli_query($conn, "SHOW COLUMNS FROM user_states LIKE 'lang'");
            if ($checkLang && mysqli_num_rows($checkLang) === 0) {
                mysqli_query($conn, "ALTER TABLE user_states ADD COLUMN lang VARCHAR(10) DEFAULT 'en'");
            }
            $check = mysqli_query($conn, "SHOW COLUMNS FROM pending_customer_messages LIKE 'is_cv'");
            if ($check && mysqli_num_rows($check) === 0) {
                mysqli_query($conn, "ALTER TABLE pending_customer_messages ADD COLUMN is_cv TINYINT(1) DEFAULT 0");
            }
            $checkAgent = mysqli_query($conn, "SHOW COLUMNS FROM conversations LIKE 'assigned_agent'");
            if ($checkAgent && mysqli_num_rows($checkAgent) === 0) {
                mysqli_query($conn, "ALTER TABLE conversations ADD COLUMN assigned_agent VARCHAR(100)");
            }
            $checkAgentId = mysqli_query($conn, "SHOW COLUMNS FROM conversations LIKE 'assigned_agent_id'");
            if ($checkAgentId && mysqli_num_rows($checkAgentId) === 0) {
                mysqli_query($conn, "ALTER TABLE conversations ADD COLUMN assigned_agent_id VARCHAR(50)");
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
    $stateFile = "{$stateDir}/{$chatId}.json";
    $existing = [];
    if (file_exists($stateFile)) {
        $existing = json_decode(@file_get_contents($stateFile), true) ?: [];
    }
    $existing['mode'] = $mode;
    $existing['time'] = time();
    @file_put_contents($stateFile, json_encode($existing));

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
 * Set user language preference ('kh' or 'en')
 */
function setUserLang($chatId, $lang) {
    global $pdo, $conn, $driver;
    if (!isValidChatId($chatId)) return;
    $lang = (strtolower(trim($lang)) === 'kh') ? 'kh' : 'en';
    initUserStatesSchema();

    $stateDir = __DIR__ . '/storage/states';
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }
    $stateFile = "{$stateDir}/{$chatId}.json";
    $existing = [];
    if (file_exists($stateFile)) {
        $existing = json_decode(@file_get_contents($stateFile), true) ?: [];
    }
    $existing['lang'] = $lang;
    $existing['time'] = time();
    @file_put_contents($stateFile, json_encode($existing));

    try {
        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->prepare("
                INSERT INTO user_states (customer_chat_id, lang) VALUES (?, ?)
                ON CONFLICT (customer_chat_id) DO UPDATE SET lang = EXCLUDED.lang
            ");
            $stmt->execute([$chatId, $lang]);
        } elseif ($conn) {
            $stmt = mysqli_prepare($conn, "INSERT INTO user_states (customer_chat_id, lang) VALUES (?, ?) ON DUPLICATE KEY UPDATE lang = VALUES(lang)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ss", $chatId, $lang);
                mysqli_stmt_execute($stmt);
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Get user language preference ('kh' or 'en', default 'en')
 */
function getUserLang($chatId) {
    global $pdo, $conn, $driver;
    if (!isValidChatId($chatId)) return 'en';
    initUserStatesSchema();

    try {
        if (isset($driver) && $driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT lang FROM user_states WHERE customer_chat_id = ?");
            $stmt->execute([$chatId]);
            $lang = $stmt->fetchColumn();
            if ($lang && in_array($lang, ['kh', 'en'])) return $lang;
        } elseif ($conn) {
            $stmt = mysqli_prepare($conn, "SELECT lang FROM user_states WHERE customer_chat_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $chatId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($res && $row = mysqli_fetch_assoc($res)) {
                    if (!empty($row['lang']) && in_array($row['lang'], ['kh', 'en'])) {
                        return $row['lang'];
                    }
                }
            }
        }
    } catch (Throwable $e) {}

    $stateFile = __DIR__ . "/storage/states/{$chatId}.json";
    if (file_exists($stateFile)) {
        $data = json_decode(@file_get_contents($stateFile), true);
        if (!empty($data['lang']) && in_array($data['lang'], ['kh', 'en'])) {
            return $data['lang'];
        }
    }
    return 'en';
}

/**
 * Get localized bot message text
 */
function getI18nText($key, $lang = 'en', $params = []) {
    $translations = [
        'welcome' => [
            'en' => "👋 <b>Welcome to Fieldbi Support!</b>\n────────────────────\nFieldbi is a technology & software solutions company.\n\nPlease select an option below or type your message:\n📄 /Submit_CV — Submit your CV / Resume\n💬 /Ask_Question — Ask a Question or Inquiry\n❓ /FAQ — Frequently Asked Questions\n🌐 /lang — Change Language (ភាសា)",
            'kh' => "👋 <b>សូមស្វាគមន៍មកកាន់ សេវាបម្រើអតិថិជន Fieldbi!</b>\n────────────────────\nFieldbi គឺជាក្រុមហ៊ុនផ្តល់ដំណោះស្រាយបច្ចេកវិទ្យា និងសូហ្វវែរ។\n\nសូមជ្រើសរើសជម្រើសខាងក្រោម ឬផ្ញើសាររបស់អ្នក៖\n📄 /Submit_CV — ដាក់ពាក្យ / CV\n💬 /Ask_Question — សួរសំណួរ\n❓ /FAQ — សំណួរដែលសួរញឹកញាប់\n🌐 /lang — ផ្លាស់ប្តូរភាសា"
        ],
        'submit_cv_prompt' => [
            'en' => "📄 <b>SUBMIT CV / RESUME</b>\n────────────────────\nPlease upload your CV file (<b>PDF, DOC, DOCX</b>) or send your CV photo/details below.\n\n⏳ <i>Waiting for your CV upload...</i>",
            'kh' => "📄 <b>ដាក់ពាក្យស្នើសុំការងារ (CV / RESUME)</b>\n────────────────────\nសូមបញ្ជូនឯកសារ CV របស់អ្នក (<b>PDF, DOC, DOCX</b>) ឬរូបថត/ព័ត៌មាន CV នៅខាងក្រោម។\n\n⏳ <i>កំពុងរង់ចាំការផ្ញើ CV របស់អ្នក...</i>"
        ],
        'ask_question_prompt' => [
            'en' => "💬 <b>ASK A QUESTION</b>\n────────────────────\nPlease type your message or question below, and our support team will assist you shortly!",
            'kh' => "💬 <b>សួរសំណួរ</b>\n────────────────────\nសូមវាយបញ្ចូលសារ ឬសំណួររបស់អ្នកនៅខាងក្រោម ក្រុមការងាររបស់យើងនឹងឆ្លើយតបជូនអ្នកក្នុងពេលឆាប់ៗនេះ!"
        ],
        'faq_menu' => [
            'en' => "❓ <b>FREQUENTLY ASKED QUESTIONS</b>\n────────────────────\nPlease select a topic below to get instant answers:",
            'kh' => "❓ <b>សំណួរដែលសួរញឹកញាប់ (FAQ)</b>\n────────────────────\nសូមជ្រើសរើសប្រធានបទខាងក្រោមដើម្បីទទួលបានចម្លើយភ្លាមៗ៖"
        ],
        'faq_jobs' => [
            'en' => "📋 <b>JOB OPENINGS</b>\n────────────────────\n• <b>Software Engineer</b>\n• <b>Marketing Specialist</b>\n• <b>Sales Representative</b>\n\n📄 <i>Tap /Submit_CV to apply directly!</i>",
            'kh' => "📋 <b>ឱកាសការងារ</b>\n────────────────────\n• <b>Software Engineer</b>\n• <b>Marketing Specialist</b>\n• <b>Sales Representative</b>\n\n📄 <i>ចុច /Submit_CV ដើម្បីដាក់ពាក្យផ្ទាល់!</i>"
        ],
        'faq_location' => [
            'en' => "📍 <b>OFFICE LOCATION</b>\n────────────────────\n🏢 <b>Fieldbi Cambodia</b>\nPhnom Penh, Cambodia\n\n📍 <i>Contact our support team for full office directions.</i>",
            'kh' => "📍 <b>ទីតាំងការិយាល័យ</b>\n────────────────────\n🏢 <b>Fieldbi Cambodia</b>\nរាជធានីភ្នំពេញ, ប្រទេសកម្ពុជា\n\n📍 <i>ទាក់ទងក្រុមការងារដើម្បីទទួលបានព័ត៌មានទីតាំងលម្អិត។</i>"
        ],
        'faq_hours' => [
            'en' => "⏰ <b>WORKING HOURS</b>\n────────────────────\n• <b>Monday – Friday:</b> 8:00 AM – 5:00 PM (ICT)\n• <b>Saturday:</b> 8:00 AM – 12:00 PM\n• <b>Sunday:</b> Closed",
            'kh' => "⏰ <b>ម៉ោងធ្វើការ</b>\n────────────────────\n• <b>ច័ន្ទ – សុក្រ:</b> 8:00 ព្រឹក – 5:00 ល្ងាច (ICT)\n• <b>សៅរ៍:</b> 8:00 ព្រឹក – 12:00 ថ្ងៃត្រង់\n• <b>អាទិត្យ:</b> ឈប់សម្រាក"
        ],
        'invalid_cv' => [
            'en' => "⚠️ <b>Invalid CV Format!</b>\n────────────────────\nPlease upload your CV as a valid document (<b>PDF, DOC, DOCX</b>) or image (<b>PNG, JPG</b>).\n\n<i>If you wish to ask a general question instead, tap /Ask_Question.</i>",
            'kh' => "⚠️ <b>ទម្រង់ CV មិនត្រឹមត្រូវ!</b>\n────────────────────\nសូមផ្ញើ CV ជាឯកសារ (<b>PDF, DOC, DOCX</b>) ឬជារូបថត (<b>PNG, JPG</b>)។\n\n<i>ប្រសិនបើអ្នកចង់សួរសំណួរទូទៅ សូមចុច /Ask_Question</i>"
        ],
        'cv_received' => [
            'en' => "✅ <b>CV Received & Submitted!</b>\n────────────────────\nThank you, <b>{name}</b>! 📄\n\nOur HR & Recruitment team has received your application and CV details. We will review your profile and reach out to you shortly.\n\n💬 <i>If you need to send additional documents or updates, feel free to send them here anytime.</i>",
            'kh' => "✅ <b>ទទួលបាន CV រួចរាល់ហើយ!</b>\n────────────────────\nសូមអរគុណ <b>{name}</b>! 📄\n\nក្រុមការងារធនធានមនុស្ស (HR) របស់យើងបានទទួល CV របស់អ្នកហើយ។ យើងនឹងពិនិត្យមើល និងទាក់ទងទៅអ្នកវិញក្នុងពេលឆាប់ៗនេះ。\n\n💬 <i>ប្រសិនបើអ្នកចង់ផ្ញើឯកសារបន្ថែម អ្នកអាចផ្ញើនៅទីនេះបានគ្រប់ពេល។</i>"
        ],
        'auto_ack_open' => [
            'en' => "👋 <b>Thank you for contacting Fieldbi!</b>\n────────────────────\nOur support team has received your message and will respond to you shortly.",
            'kh' => "👋 <b>សូមអរគុណសម្រាប់ការទាក់ទងមកកាន់ Fieldbi!</b>\n────────────────────\nក្រុមការងាររបស់យើងបានទទួលសាររបស់អ្នកហើយ និងកំពុងរៀបចំឆ្លើយតបជូនអ្នកក្នុងពេលឆាប់ៗនេះ।"
        ],
        'auto_ack_closed' => [
            'en' => "🌙 <b>Thank you for contacting Fieldbi!</b>\n────────────────────\nOur office is currently closed.\n⏰ <b>Business Hours:</b> Mon – Fri, 8:00 AM – 5:00 PM (ICT)\n\nYour message has been received, and our team will respond as soon as we open!",
            'kh' => "🌙 <b>សូមអរគុណសម្រាប់ការទាក់ទងមកកាន់ Fieldbi!</b>\n────────────────────\nពេលនេះការិយាល័យរបស់យើងត្រូវបានបិទសម្រាក។\n⏰ <b>ម៉ោងធ្វើការ:</b> ច័ន្ទ – សុក្រ, 8:00 ព្រឹក – 5:00 ល្ងាច (ICT)\n\nសាររបស់អ្នកត្រូវបានកត់ត្រាទុក ហើយក្រុមការងារនឹងឆ្លើយតបភ្លាមៗនៅពេលបើកដំណើរការឡើងវិញ!"
        ],
        'lang_prompt' => [
            'en' => "🌐 <b>SELECT LANGUAGE / ជ្រើសរើសភាសា</b>\n────────────────────\nPlease select your preferred language below:\n• <code>/lang kh</code> — ភាសាខ្មែរ (Khmer)\n• <code>/lang en</code> — English",
            'kh' => "🌐 <b>ជ្រើសរើសភាសា / SELECT LANGUAGE</b>\n────────────────────\nសូមជ្រើសរើសភាសាដែលអ្នកពេញចិត្ត៖\n• <code>/lang kh</code> — ភាសាខ្មែរ (Khmer)\n• <code>/lang en</code> — English"
        ],
        'lang_set_kh' => [
            'en' => "🇰🇭 <b>បានជ្រើសរើស ភាសាខ្មែរ រួចរាល់ហើយ!</b>\n────────────────────\nឥឡូវនេះ ប្រព័ន្ធនឹងឆ្លើយតបជាភាសាខ្មែរ។\n\nតើមានអ្វីឱ្យយើងខ្ញុំជួយដែរឬទេ?",
            'kh' => "🇰🇭 <b>បានជ្រើសរើស ភាសាខ្មែរ រួចរាល់ហើយ!</b>\n────────────────────\nឥឡូវនេះ ប្រព័ន្ធនឹងឆ្លើយតបជាភាសាខ្មែរ។\n\nតើមានអ្វីឱ្យយើងខ្ញុំជួយដែរឬទេ?"
        ],
        'lang_set_en' => [
            'en' => "🇬🇧 <b>Language set to English!</b>\n────────────────────\nThe bot will now respond in English.\n\nHow can we help you today?",
            'kh' => "🇬🇧 <b>Language set to English!</b>\n────────────────────\nThe bot will now respond in English.\n\nHow can we help you today?"
        ]
    ];

    $text = $translations[$key][$lang] ?? ($translations[$key]['en'] ?? '');
    foreach ($params as $paramKey => $paramVal) {
        $text = str_replace('{' . $paramKey . '}', $paramVal, $text);
    }
    return $text;
}

/**
 * Get localized inline keyboards
 */
function getI18nKeyboard($key, $lang = 'en') {
    if ($key === 'welcome') {
        return [
            'inline_keyboard' => [
                [
                    ['text' => ($lang === 'kh' ? '📄 ដាក់ពាក្យ / CV' : '📄 Submit CV'), 'callback_data' => 'menu_submit_cv'],
                    ['text' => ($lang === 'kh' ? '💬 សួរសំណួរ' : '💬 Ask Question'), 'callback_data' => 'menu_ask_question']
                ],
                [
                    ['text' => ($lang === 'kh' ? '❓ សំណួរដែលសួរញឹកញាប់' : '❓ FAQ / Quick Answers'), 'callback_data' => 'menu_faq']
                ],
                [
                    ['text' => ($lang === 'kh' ? '🌐 ផ្លាស់ប្តូរភាសា (Language)' : '🌐 Change Language / ភាសា'), 'callback_data' => 'menu_lang']
                ]
            ]
        ];
    }

    if ($key === 'faq_menu') {
        return [
            'inline_keyboard' => [
                [
                    ['text' => ($lang === 'kh' ? '📋 ឱកាសការងារ' : '📋 Job Openings'), 'callback_data' => 'faq_jobs']
                ],
                [
                    ['text' => ($lang === 'kh' ? '📍 ទីតាំងការិយាល័យ' : '📍 Office Location'), 'callback_data' => 'faq_location'],
                    ['text' => ($lang === 'kh' ? '⏰ ម៉ោងធ្វើការ' : '⏰ Working Hours'), 'callback_data' => 'faq_hours']
                ]
            ]
        ];
    }

    if ($key === 'lang_menu') {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🇰🇭 ភាសាខ្មែរ (Khmer)', 'callback_data' => 'lang_kh'],
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang_en']
                ]
            ]
        ];
    }

    return null;
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
 * Send voice message to Telegram chat
 */
function sendVoice($chatId, $fileId, $caption = '', $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendVoice";
    $postFields = [
        'chat_id'    => $chatId,
        'voice'      => $fileId,
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
 * Send audio file to Telegram chat
 */
function sendAudio($chatId, $fileId, $caption = '', $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendAudio";
    $postFields = [
        'chat_id'    => $chatId,
        'audio'      => $fileId,
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
 * Send video file to Telegram chat
 */
function sendVideo($chatId, $fileId, $caption = '', $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendVideo";
    $postFields = [
        'chat_id'    => $chatId,
        'video'      => $fileId,
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
 * Send sticker to Telegram chat
 */
function sendSticker($chatId, $fileId, $replyMarkup = null) {
    if (!isValidChatId($chatId)) return null;
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendSticker";
    $postFields = [
        'chat_id' => $chatId,
        'sticker' => $fileId
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
            $contactDisplay = "{$userLink} (KH: <code>{$chatId}</code>)";
        }

        $formattedDate = date('d M Y | h:i A');

        if ($isCvSubmission) {
            $ticketHeader = "📄 <b>HR RECRUITMENT TICKET #{$convId}</b>\n"
                          . "──────────────\n"
                          . "👤 <b>Candidate:</b> {$contactDisplay}\n"
                          . (!empty($combinedText) ? $combinedText . "\n" : "")
                          . "──────────────\n"
                          . "📅 <b>Date:</b> {$formattedDate}\n"
                          . "⏳ <b>Status:</b> <b>PENDING REVIEW</b>\n"
                          . "──────────────\n"
                          . "💡 <i>Use HR action buttons below to process candidate.</i>";

            $ticketBtn = [
                'inline_keyboard' => [
                    [
                        ['text' => '📄 Contact Candidate', 'url' => $contactUrl],
                        ['text' => '👤 Handle Request', 'callback_data' => "claim_{$convId}"]
                    ],
                    [
                        ['text' => '⭐ Shortlist & Interview', 'callback_data' => "hr_interview_{$convId}"],
                        ['text' => '❌ Decline', 'callback_data' => "hr_decline_{$convId}"]
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
                        ['text' => '💬 Contact Customer', 'url' => $contactUrl],
                        ['text' => '👤 Handle Request', 'callback_data' => "claim_{$convId}"]
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

                    $actionDate = date('d M Y | h:i A');
                    $updatedCard = "🎫 <b>SUPPORT TICKET #{$convId}</b>\n"
                                 . "──────────────\n"
                                 . "👤 <b>From:</b> {$contactDisplay}\n"
                                 . (!empty($messageContent) ? "💬 <b>Details:</b>\n<blockquote>" . htmlspecialchars($messageContent) . "</blockquote>\n" : "")
                                 . "──────────────\n"
                                 . "📅 <b>Date:</b> {$actionDate}\n"
                                 . "✅ <b>Status:</b> <b>CLAIMED</b> by <i>" . htmlspecialchars($agentName) . "</i>\n"
                                 . "──────────────\n"
                                 . "💬 <i>Replying in the group will send messages to this customer.</i>";

                    $updatedBtn = [
                        'inline_keyboard' => [
                            [
                                ['text' => '📄 Contact Customer', 'url' => $contactUrl],
                                ['text' => '✅ Handled', 'callback_data' => 'claimed']
                            ]
                        ]
                    ];

                    if (isset($msg["caption"])) {
                        editMessageCaption($gChatId, $gMsgId, $updatedCard, $updatedBtn);
                    } else {
                        editMessageText($gChatId, $gMsgId, $updatedCard, $updatedBtn);
                    }
                }
            }

            answerCallbackQuery($cbId, "✅ Request #{$convId} assigned to you!", false);
            return;
        }

        if (strpos($cbData, 'hr_') === 0) {
            try {
                $parts  = explode('_', $cbData);
                $action = $parts[1] ?? '';
                $convId = (int)($parts[2] ?? 0);

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
                    answerCallbackQuery($cbId, "⚠️ HR Ticket #{$convId} not found!", true);
                    return;
                }

                $customerName   = $convData['customer_name'] ?? 'Candidate';
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

                $statusText    = '';
                $statusBadge   = '';
                $toastMsg      = '';
                $custNotifyMsg = '';

                if ($action === 'interview' || $action === 'shortlist') {
                    $statusText    = 'shortlisted';
                    $statusBadge   = "⭐ <b>Status:</b> <b>SHORTLISTED & INTERVIEW SENT</b> by <i>" . htmlspecialchars($agentName) . "</i>";
                    $toastMsg      = "⭐ Candidate Shortlisted & Interview Sent!";

                    $displayGreeting = (!empty($cleanCustName) && $cleanCustName !== 'Candidate') ? "Dear <b>" . htmlspecialchars($cleanCustName) . "</b>," : "Dear <b>Mr. Chhourn Crymonyvann</b>,";

                    $custNotifyMsg = "{$displayGreeting}\n\nWe're pleased to inform you that you have successfully passed our shortlist stage. We would like to invite you to attend an interview as scheduled below:\n\n📅 <b>Date:</b> Wednesday 12 August 2026\n⏰ <b>Time:</b> 10am\n📍 <b>Location:</b> 6F C7, Olympia City, Sangkat Veal Vong, Khan  7 Makara, Phnom Penh, Cambodia\n\nplease kindly confirm your availability for the scheduled time. We look forward to meeting you.";
                } elseif ($action === 'decline') {
                    $statusText  = 'declined';
                    $statusBadge = "❌ <b>Status:</b> <b>DECLINED</b> by <i>" . htmlspecialchars($agentName) . "</i>";
                    $toastMsg    = "❌ Candidate Application Declined";
                }

                // Update DB status and assigned agent (Parameterized Query)
                try {
                    if (isset($driver) && $driver === 'pgsql') {
                        $upStmt = $pdo->prepare("UPDATE conversations SET status = ?, assigned_agent = ?, assigned_agent_id = ? WHERE id = ?");
                        $upStmt->execute([$statusText, $agentName, $agentId, $convId]);
                    } else {
                        $upStmt = mysqli_prepare($conn, "UPDATE conversations SET status = ?, assigned_agent = ?, assigned_agent_id = ? WHERE id = ?");
                        mysqli_stmt_bind_param($upStmt, "sssi", $statusText, $agentName, $agentId, $convId);
                        mysqli_stmt_execute($upStmt);
                    }
                } catch (Throwable $dbErr) {
                    error_log("DB update error in HR callback: " . $dbErr->getMessage());
                }

                // Send notification message to candidate if applicable
                if (!empty($custNotifyMsg) && isValidChatId($customerChatId)) {
                    sendMessage($customerChatId, $custNotifyMsg);
                }

                // Edit Telegram Group message text / caption
                if (isset($cb["message"])) {
                    $msg        = $cb["message"];
                    $gChatId    = (string)($msg["chat"]["id"] ?? '');
                    $gMsgId     = $msg["message_id"];
                    $rawMsgText = $msg["text"] ?? ($msg["caption"] ?? '');

                    $messageContent = '';
                    if (preg_match('/💬 <b>(Message|Details):<\/b>\s*\n(?:<blockquote>|<i>[“"«]?)?(.*?)(?:<\/blockquote>|[”"»]?<\/i>)?(?=\n─|\n━|$)/s', $rawMsgText, $matches)) {
                        $messageContent = trim($matches[2]);
                    } elseif (preg_match('/(Message|Details):\s*\n(.*?)(?=\n─|\n━|$)/s', $rawMsgText, $matches)) {
                        $messageContent = trim($matches[2]);
                    }

                    $actionDate = date('d M Y | h:i A');
                    $updatedCard = "📄 <b>HR RECRUITMENT TICKET #{$convId}</b>\n"
                                 . "──────────────\n"
                                 . "👤 <b>Candidate:</b> {$contactDisplay}\n"
                                 . (!empty($messageContent) ? "💬 <b>Details:</b>\n<blockquote>" . htmlspecialchars($messageContent) . "</blockquote>\n" : "")
                                 . "──────────────\n"
                                 . "📅 <b>Date:</b> {$actionDate}\n"
                                 . "{$statusBadge}\n"
                                 . "──────────────\n"
                                 . "💬 <i>Processed by HR Agent " . htmlspecialchars($agentName) . ".</i>";

                    $updatedBtn = [
                        'inline_keyboard' => [
                            [
                                ['text' => '📄 Contact Candidate', 'url' => $contactUrl],
                                ['text' => ($action === 'decline' ? '❌ Declined' : '✅ Interview Invited'), 'callback_data' => 'claimed']
                            ]
                        ]
                    ];

                    if (isset($msg["caption"])) {
                        editMessageCaption($gChatId, $gMsgId, $updatedCard, $updatedBtn);
                    } else {
                        editMessageText($gChatId, $gMsgId, $updatedCard, $updatedBtn);
                    }
                }

                answerCallbackQuery($cbId, $toastMsg, false);
            } catch (Throwable $e) {
                error_log("HR callback exception: " . $e->getMessage());
                answerCallbackQuery($cbId, "⚠️ Error processing HR request: " . $e->getMessage(), true);
            }
            return;
        }

        if ($cbData === 'menu_submit_cv') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            $userLang   = getUserLang($userChatId);
            setUserMode($userChatId, 'submit_cv');
            answerCallbackQuery($cbId, $userLang === 'kh' ? "📄 បានជ្រើសរើស: ដាក់ពាក្យ CV" : "📄 Option selected: Submit CV");
            sendMessage($userChatId, getI18nText('submit_cv_prompt', $userLang));
            return;
        }

        if ($cbData === 'menu_ask_question') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            $userLang   = getUserLang($userChatId);
            setUserMode($userChatId, 'ask_question');
            answerCallbackQuery($cbId, $userLang === 'kh' ? "💬 បានជ្រើសរើស: សួរសំណួរ" : "💬 Option selected: Ask Question");
            sendMessage($userChatId, getI18nText('ask_question_prompt', $userLang));
            return;
        }

        if (strpos($cbData, 'faq_') === 0 || $cbData === 'menu_faq') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            $userLang   = getUserLang($userChatId);
            if ($cbData === 'menu_faq') {
                answerCallbackQuery($cbId, $userLang === 'kh' ? "❓ សំណួរដែលសួរញឹកញាប់" : "❓ Frequently Asked Questions");
                sendMessage($userChatId, getI18nText('faq_menu', $userLang), getI18nKeyboard('faq_menu', $userLang));
                return;
            }

            if (in_array($cbData, ['faq_jobs', 'faq_location', 'faq_hours'])) {
                answerCallbackQuery($cbId, $userLang === 'kh' ? "បានទាញយកចម្លើយ" : "Answer loaded");
                sendMessage($userChatId, getI18nText($cbData, $userLang));
            }
            return;
        }

        if ($cbData === 'menu_lang') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            $userLang   = getUserLang($userChatId);
            answerCallbackQuery($cbId, "🌐 Select Language / ជ្រើសរើសភាសា");
            sendMessage($userChatId, getI18nText('lang_prompt', $userLang), getI18nKeyboard('lang_menu', $userLang));
            return;
        }

        if ($cbData === 'lang_kh') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            setUserLang($userChatId, 'kh');
            answerCallbackQuery($cbId, "🇰🇭 បានជ្រើសរើស ភាសាខ្មែរ!");
            sendMessage($userChatId, getI18nText('lang_set_kh', 'kh'), getI18nKeyboard('welcome', 'kh'));
            return;
        }

        if ($cbData === 'lang_en') {
            $userChatId = (string)($cb["message"]["chat"]["id"] ?? $agentId);
            setUserLang($userChatId, 'en');
            answerCallbackQuery($cbId, "🇬🇧 Language set to English!");
            sendMessage($userChatId, getI18nText('lang_set_en', 'en'), getI18nKeyboard('welcome', 'en'));
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
    $voice     = $message["voice"] ?? null;
    $audio     = $message["audio"] ?? null;
    $video     = $message["video"] ?? null;
    $sticker   = $message["sticker"] ?? null;
    $chatId    = (string)($message["chat"]["id"] ?? '');
    $senderId  = (string)($message["from"]["id"] ?? '');
    $chatType  = $message["chat"]["type"] ?? 'private';
    $isGroup   = ($chatType === 'group' || $chatType === 'supergroup');

    if (!isValidChatId($chatId)) {
        return;
    }

    $photoFileId   = !empty($photo) ? end($photo)["file_id"] : null;
    $docFileId     = !empty($document) ? $document["file_id"] : null;
    $voiceFileId   = !empty($voice) ? $voice["file_id"] : null;
    $audioFileId   = !empty($audio) ? $audio["file_id"] : null;
    $videoFileId   = !empty($video) ? $video["file_id"] : null;
    $stickerFileId = !empty($sticker) ? $sticker["file_id"] : null;
    $hasMedia      = !empty($photoFileId) || !empty($docFileId) || !empty($voiceFileId) || !empty($audioFileId) || !empty($videoFileId) || !empty($stickerFileId);
    $mainContent   = !empty($text) ? $text : $caption;

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

            if ($voiceFileId) {
                sendVoice($targetCustomerChatId, $voiceFileId, $caption);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Voice message sent to {$userLink})");
            } elseif ($audioFileId) {
                sendAudio($targetCustomerChatId, $audioFileId, $caption);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Audio sent to {$userLink})");
            } elseif ($videoFileId) {
                sendVideo($targetCustomerChatId, $videoFileId, $caption);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Video sent to {$userLink})");
            } elseif ($stickerFileId) {
                sendSticker($targetCustomerChatId, $stickerFileId);
                sendMessage($chatId, "✅ <b>Handled by {$agentName}</b> (Sticker sent to {$userLink})");
            } elseif ($photoFileId) {
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

            if ($voiceFileId) {
                sendVoice($targetCustomerChatId, $voiceFileId, $caption);
                sendMessage($chatId, "✅ <b>Voice Message Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif ($audioFileId) {
                sendAudio($targetCustomerChatId, $audioFileId, $caption);
                sendMessage($chatId, "✅ <b>Audio Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif ($videoFileId) {
                sendVideo($targetCustomerChatId, $videoFileId, $caption);
                sendMessage($chatId, "✅ <b>Video Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif ($stickerFileId) {
                sendSticker($targetCustomerChatId, $stickerFileId);
                sendMessage($chatId, "✅ <b>Sticker Delivered</b> to {$userLink} by <i>{$agentName}</i>");
            } elseif ($photoFileId) {
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
        // Multi-Language Command & Trigger Handling
        if ($text === '/lang kh' || $text === '/kh') {
            setUserLang($chatId, 'kh');
            sendMessage($chatId, getI18nText('lang_set_kh', 'kh'), getI18nKeyboard('welcome', 'kh'));
            return;
        }

        if ($text === '/lang en' || $text === '/en') {
            setUserLang($chatId, 'en');
            sendMessage($chatId, getI18nText('lang_set_en', 'en'), getI18nKeyboard('welcome', 'en'));
            return;
        }

        if (preg_match('/^\/(lang|language)(?:@\w+)?(?:\s+(kh|en))?/i', $text, $matches)) {
            $selectedLang = strtolower($matches[2] ?? '');
            if ($selectedLang === 'kh') {
                setUserLang($chatId, 'kh');
                sendMessage($chatId, getI18nText('lang_set_kh', 'kh'), getI18nKeyboard('welcome', 'kh'));
                return;
            } elseif ($selectedLang === 'en') {
                setUserLang($chatId, 'en');
                sendMessage($chatId, getI18nText('lang_set_en', 'en'), getI18nKeyboard('welcome', 'en'));
                return;
            } else {
                $userLang = getUserLang($chatId);
                sendMessage($chatId, getI18nText('lang_prompt', $userLang), getI18nKeyboard('lang_menu', $userLang));
                return;
            }
        }

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

            $userLang = getUserLang($chatId);
            sendMessage($chatId, getI18nText('welcome', $userLang), getI18nKeyboard('welcome', $userLang));
            return;
        }

        if (preg_match('/^\/(submit_cv|submitcv|cv)(?:@\w+)?/i', $text)) {
            setUserMode($chatId, 'submit_cv');
            $userLang = getUserLang($chatId);
            sendMessage($chatId, getI18nText('submit_cv_prompt', $userLang));
            return;
        }

        if (preg_match('/^\/(ask_question|askquestion|ask)(?:@\w+)?/i', $text)) {
            setUserMode($chatId, 'ask_question');
            $userLang = getUserLang($chatId);
            sendMessage($chatId, getI18nText('ask_question_prompt', $userLang));
            return;
        }

        if (preg_match('/^\/(faq|help)(?:@\w+)?/i', $text)) {
            $userLang = getUserLang($chatId);
            sendMessage($chatId, getI18nText('faq_menu', $userLang), getI18nKeyboard('faq_menu', $userLang));
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
            $userLang    = getUserLang($chatId);
            $isCvMessage = 0;

            if ($currentMode === 'submit_cv') {
                if (!isValidCvSubmission($message)) {
                    sendMessage($chatId, getI18nText('invalid_cv', $userLang));
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
                sendMessage($chatId, getI18nText('cv_received', $userLang, ['name' => htmlspecialchars($customerName)]));
            } else {

                // Send auto-acknowledgment ONLY once per 15-minute conversation window
                if (!$recentlyContacted) {
                    if (isBusinessOpen()) {
                        sendMessage($chatId, getI18nText('auto_ack_open', $userLang));
                    } else {
                        sendMessage($chatId, getI18nText('auto_ack_closed', $userLang));
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
