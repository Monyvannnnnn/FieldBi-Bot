<?php
/**
 * Telegram Customer Support Bot Webhook & Server Dashboard Endpoint
 */

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Helper to detect HTTPS behind reverse proxies (Render, Cloudflare, Nginx)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

$scheme = $isHttps ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$filePath = __DIR__ . $uriPath;

// 1. Router handling for PHP built-in web server (Render Docker start.sh)
if ($uriPath !== '/' && $uriPath !== '/api.php' && file_exists($filePath) && !is_dir($filePath)) {
    if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
        require_once $filePath;
        exit;
    } else {
        // Return false so PHP built-in web server serves static files (PNG, JPG, CSS, JS) directly
        return false;
    }
}

// 2. Clean route /workflow or ?view=workflow
if ($uriPath === '/workflow' || (isset($_GET['view']) && $_GET['view'] === 'workflow')) {
    require_once __DIR__ . '/workflow.php';
    exit;
}

$currentUrl = "{$scheme}://{$host}{$uriPath}";

// Explicit Webhook Registration (?action=set_webhook)
if (isset($_GET['action']) && $_GET['action'] === 'set_webhook') {
    header("Content-Type: application/json; charset=utf-8");
    $whApiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($currentUrl);
    $ch = curl_init($whApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
    ]);
    $whRes = curl_exec($ch);
    curl_close($ch);

    echo json_encode([
        "ok" => true,
        "message" => "Telegram Support Bot Webhook Endpoint Registered",
        "webhook_url" => $currentUrl,
        "webhook_response" => json_decode($whRes, true)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Function to fetch all bot users and analytics
function getBotAnalyticsData() {
    global $pdo, $conn, $driver;
    $users = [];

    try {
        if (isset($driver) && $driver === 'pgsql' && $pdo) {
            $stmt = $pdo->query("
                SELECT c.id, c.customer_chat_id, c.customer_name, c.username, c.status, c.assigned_agent, c.created_at,
                       COALESCE(us.lang, 'en') as lang,
                       COALESCE(us.current_mode, 'general') as current_mode,
                       (SELECT COUNT(*) FROM pending_customer_messages p WHERE p.customer_chat_id = c.customer_chat_id) as msg_count
                FROM conversations c
                LEFT JOIN user_states us ON us.customer_chat_id = c.customer_chat_id
                ORDER BY c.id DESC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (isset($conn) && $conn) {
            $res = mysqli_query($conn, "
                SELECT c.id, c.customer_chat_id, c.customer_name, c.username, c.status, c.assigned_agent, c.created_at,
                       COALESCE(us.lang, 'en') as lang,
                       COALESCE(us.current_mode, 'general') as current_mode,
                       (SELECT COUNT(*) FROM pending_customer_messages p WHERE p.customer_chat_id = c.customer_chat_id) as msg_count
                FROM conversations c
                LEFT JOIN user_states us ON us.customer_chat_id = c.customer_chat_id
                ORDER BY c.id DESC
            ");
            if ($res) {
                $users = mysqli_fetch_all($res, MYSQLI_ASSOC);
            }
        }
    } catch (Throwable $e) {
        error_log("Dashboard analytics fetch error: " . $e->getMessage());
    }

    // Merge fallback JSON state files for users not yet in DB
    $stateDir = __DIR__ . '/storage/states';
    if (is_dir($stateDir)) {
        $files = glob("{$stateDir}/*.json");
        $existingChatIds = array_column($users, 'customer_chat_id');
        foreach ($files as $f) {
            $cid = basename($f, '.json');
            if (!in_array($cid, $existingChatIds) && isValidChatId($cid)) {
                $data = json_decode(@file_get_contents($f), true) ?: [];
                $users[] = [
                    'id' => '-',
                    'customer_chat_id' => $cid,
                    'customer_name' => 'User ' . $cid,
                    'username' => '',
                    'status' => 'general',
                    'assigned_agent' => '',
                    'created_at' => date('Y-m-d H:i:s', $data['time'] ?? time()),
                    'lang' => $data['lang'] ?? 'en',
                    'current_mode' => $data['mode'] ?? 'general',
                    'msg_count' => 0
                ];
            }
        }
    }

    return $users;
}

// GET Request: Serve HTML Web Dashboard or JSON API
if ($requestMethod === 'GET') {
    $wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json')
              || (isset($_GET['json']) && $_GET['json'] === '1')
              || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $usersData = getBotAnalyticsData();

    if ($wantsJson) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "ok" => true,
            "service" => "FieldBi Telegram Support Bot Server",
            "status" => "Online & Running",
            "endpoint_url" => $currentUrl,
            "total_users" => count($usersData),
            "users" => $usersData
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Calculate Summary Stats
    $totalUsers      = count($usersData);
    $pendingTickets  = 0;
    $handledTickets  = 0;
    $shortlisted     = 0;
    $khmerLangCount  = 0;
    $englishLangCount= 0;
    $totalMessages   = 0;

    foreach ($usersData as $u) {
        $st = strtolower($u['status'] ?? '');
        if ($st === 'pending') $pendingTickets++;
        elseif ($st === 'handled') $handledTickets++;
        elseif ($st === 'shortlisted') $shortlisted++;

        if (($u['lang'] ?? 'en') === 'kh') $khmerLangCount++;
        else $englishLangCount++;

        $totalMessages += (int)($u['msg_count'] ?? 0);
    }

    // Render Web Dashboard HTML
    header("Content-Type: text/html; charset=utf-8");
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FieldBi Support Bot — Server & User Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0b0f19;
            --bg-secondary: #111827;
            --bg-card: #1f2937;
            --bg-hover: #374151;
            --border-color: #2d3748;
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
            --brand-red: #e50914;
            --brand-red-glow: rgba(229, 9, 20, 0.35);
            --accent-green: #10b981;
            --accent-yellow: #f59e0b;
            --accent-purple: #8b5cf6;
            --accent-blue: #3b82f6;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-main);
            line-height: 1.5;
            padding: 24px;
            min-height: 100vh;
        }

        .container {
            max-width: 1320px;
            margin: 0 auto;
        }

        /* Top Header */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            background: linear-gradient(135deg, rgba(31, 41, 55, 0.9), rgba(17, 24, 39, 0.95));
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
        }

        .brand-container {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #e50914, #b91c1c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 24px;
            color: #ffffff;
            box-shadow: 0 0 20px var(--brand-red-glow);
        }

        .brand-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #ffffff;
        }

        .brand-title p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #34d399;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 10px #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background: var(--bg-hover);
            border-color: #4b5563;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e50914, #c80d17);
            border-color: #e50914;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 4px 14px var(--brand-red-glow);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #f40612, #b91c1c);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #4b5563;
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stat-value {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.1;
        }

        .stat-footer {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Controls & Filter Bar */
        .controls-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 280px;
        }

        .search-box input {
            width: 100%;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px 14px 10px 38px;
            color: var(--text-main);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-box input:focus {
            border-color: var(--brand-red);
            box-shadow: 0 0 0 2px var(--brand-red-glow);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-select {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 9px 14px;
            border-radius: 10px;
            font-size: 13px;
            outline: none;
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--brand-red);
        }

        /* Users Table Card */
        .table-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }

        .table-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-title {
            font-family: 'Outfit', sans-serif;
            font-size: 17px;
            font-weight: 600;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13.5px;
        }

        th {
            background: rgba(31, 41, 55, 0.5);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(45, 55, 72, 0.5);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(55, 65, 81, 0.4);
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .user-name {
            font-weight: 600;
            color: #ffffff;
        }

        .user-sub {
            font-size: 12px;
            color: var(--text-muted);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }

        .badge-pending {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-handled {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-shortlisted {
            background: rgba(139, 92, 246, 0.15);
            color: #a78bfa;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .badge-lang {
            background: rgba(59, 130, 246, 0.12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.25);
        }

        .empty-state {
            padding: 48px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-icon {
            font-size: 40px;
            margin-bottom: 12px;
        }

        /* Footer */
        footer {
            margin-top: 36px;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            padding-bottom: 20px;
        }

        footer a {
            color: var(--brand-red);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header>
            <div class="brand-container">
                <div class="brand-logo">f</div>
                <div class="brand-title">
                    <h1>FieldBi Telegram Support Server</h1>
                    <p>Live Active Users & Bot Analytics Dashboard</p>
                </div>
            </div>
            <div class="status-badge">
                <div class="pulse-dot"></div>
                Server Online & Running
            </div>
            <div class="header-actions">
                <a class="btn btn-primary" href="workflow.php">🔄 Supply Chain Workflow</a>
                <button class="btn" onclick="location.reload()">🔄 Refresh</button>
                <a class="btn" href="?format=json" target="_blank">📄 Raw JSON API</a>
            </div>
        </header>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span>Total Bot Users</span>
                    <div class="stat-icon" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">👥</div>
                </div>
                <div class="stat-value"><?= number_format($totalUsers) ?></div>
                <div class="stat-footer">Registered customers in database</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span>Pending Support Tickets</span>
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">⏳</div>
                </div>
                <div class="stat-value" style="color: #fbbf24;"><?= number_format($pendingTickets) ?></div>
                <div class="stat-footer">Awaiting agent response</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span>Resolved & Shortlisted</span>
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">✅</div>
                </div>
                <div class="stat-value" style="color: #34d399;"><?= number_format($handledTickets + $shortlisted) ?></div>
                <div class="stat-footer"><?= $handledTickets ?> Handled • <?= $shortlisted ?> Shortlisted</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span>Total Messages Exchanged</span>
                    <div class="stat-icon" style="background: rgba(229, 9, 20, 0.15); color: #ef4444;">💬</div>
                </div>
                <div class="stat-value"><?= number_format($totalMessages) ?></div>
                <div class="stat-footer">🇰🇭 <?= $khmerLangCount ?> Khmer • 🇬🇧 <?= $englishLangCount ?> English</div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="controls-card">
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="Search user by name, @username, or Chat ID..." onkeyup="filterUsers()">
            </div>
            <div class="filter-group">
                <select class="filter-select" id="statusFilter" onchange="filterUsers()">
                    <option value="all">All Ticket Statuses</option>
                    <option value="pending">🟡 Pending Only</option>
                    <option value="handled">🟢 Handled Only</option>
                    <option value="shortlisted">⭐ Shortlisted Only</option>
                </select>

                <select class="filter-select" id="langFilter" onchange="filterUsers()">
                    <option value="all">All Languages</option>
                    <option value="kh">🇰🇭 Khmer (kh)</option>
                    <option value="en">🇬🇧 English (en)</option>
                </select>
            </div>
        </div>

        <!-- Users Table -->
        <div class="table-card">
            <div class="table-header">
                <div class="table-title">Active Bot Users & Customers (<span id="userCount"><?= count($usersData) ?></span>)</div>
                <label style="font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh(this)"> Auto-refresh (10s)
                </label>
            </div>
            <div class="table-responsive">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th># Ticket</th>
                            <th>Telegram Customer</th>
                            <th>Chat ID</th>
                            <th>Language</th>
                            <th>Current Mode</th>
                            <th>Ticket Status</th>
                            <th>Messages</th>
                            <th>Assigned Agent</th>
                            <th>Joined Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usersData)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <div class="empty-icon">📭</div>
                                        <p>No active users or tickets found in database.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usersData as $u): ?>
                                <?php
                                $name = htmlspecialchars($u['customer_name'] ?? 'Customer');
                                $uname = trim($u['username'] ?? '');
                                $cid = htmlspecialchars($u['customer_chat_id'] ?? '');
                                $lang = strtolower($u['lang'] ?? 'en');
                                $mode = htmlspecialchars($u['current_mode'] ?? 'general');
                                $status = strtolower($u['status'] ?? 'general');
                                $agent = htmlspecialchars($u['assigned_agent'] ?? '-');
                                $msgCount = (int)($u['msg_count'] ?? 0);
                                $ticketId = htmlspecialchars($u['id'] ?? '-');
                                $dateStr = !empty($u['created_at']) ? date('M d, Y | h:i A', strtotime($u['created_at'])) : '-';
                                $initial = mb_substr($name, 0, 1, 'UTF-8');

                                $contactUrl = !empty($uname) ? "https://t.me/" . ltrim($uname, '@') : "tg://user?id={$cid}";
                                ?>
                                <tr class="user-row" 
                                    data-search="<?= strtolower($name . ' ' . $uname . ' ' . $cid) ?>"
                                    data-status="<?= $status ?>"
                                    data-lang="<?= $lang ?>">
                                    <td><strong>#<?= $ticketId ?></strong></td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar"><?= strtoupper($initial) ?></div>
                                            <div>
                                                <div class="user-name"><?= $name ?></div>
                                                <div class="user-sub"><?= !empty($uname) ? '@' . ltrim($uname, '@') : 'No username' ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><code><?= $cid ?></code></td>
                                    <td>
                                        <span class="badge badge-lang">
                                            <?= $lang === 'kh' ? '🇰🇭 Khmer' : '🇬🇧 English' ?>
                                        </span>
                                    </td>
                                    <td><code><?= $mode ?></code></td>
                                    <td>
                                        <?php if ($status === 'pending'): ?>
                                            <span class="badge badge-pending">🟡 Pending</span>
                                        <?php elseif ($status === 'handled'): ?>
                                            <span class="badge badge-handled">🟢 Handled</span>
                                        <?php elseif ($status === 'shortlisted'): ?>
                                            <span class="badge badge-shortlisted">⭐ Shortlisted</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(156, 163, 175, 0.15); color: #9ca3af;">⚪ <?= ucfirst($status) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= $msgCount ?></strong></td>
                                    <td><?= !empty($agent) && $agent !== '-' ? '👤 ' . $agent : '<span style="color: var(--text-muted);">Unassigned</span>' ?></td>
                                    <td style="font-size: 12.5px; color: var(--text-muted);"><?= $dateStr ?></td>
                                    <td>
                                        <a href="<?= $contactUrl ?>" target="_blank" class="btn" style="padding: 5px 10px; font-size: 12px;">
                                            ✈️ Contact
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer>
            FieldBi Cambodia Telegram Support Bot Server & Dashboard • Powered by <a href="https://fieldbi.com" target="_blank">FieldBi Technology</a>
        </footer>
    </div>

    <script>
        function filterUsers() {
            const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
            const statusVal = document.getElementById('statusFilter').value;
            const langVal   = document.getElementById('langFilter').value;

            const rows = document.querySelectorAll('.user-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowSearch = row.getAttribute('data-search') || '';
                const rowStatus = row.getAttribute('data-status') || '';
                const rowLang   = row.getAttribute('data-lang') || '';

                const matchesSearch = !searchVal || rowSearch.includes(searchVal);
                const matchesStatus = (statusVal === 'all') || (rowStatus === statusVal);
                const matchesLang   = (langVal === 'all') || (rowLang === langVal);

                if (matchesSearch && matchesStatus && matchesLang) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('userCount').innerText = visibleCount;
        }

        let autoRefreshTimer = null;
        function toggleAutoRefresh(cb) {
            if (cb.checked) {
                autoRefreshTimer = setInterval(() => {
                    location.reload();
                }, 10000);
            } else {
                if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            }
        }
    </script>
</body>
</html>
    <?php
    exit;
}

// POST Request: Handle Incoming Webhook Updates from Telegram
$content = file_get_contents("php://input");
$update  = json_decode($content, true);

if (!empty($update)) {
    try {
        processSupportBotUpdate($update);
    } catch (Throwable $e) {
        error_log("Error processing support bot update: " . $e->getMessage());
    }
}

http_response_code(200);
header("Content-Type: application/json");
echo json_encode(["ok" => true]);
exit;
