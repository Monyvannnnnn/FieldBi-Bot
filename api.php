<?php
/**
 * Telegram Customer Support Bot Webhook & API Endpoint
 */

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Helper to detect HTTPS even behind reverse proxies (like Render, Cloudflare, Nginx)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

$scheme = $isHttps ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

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

// GET Request: Health Check Status
if ($requestMethod === 'GET') {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "ok" => true,
        "service" => "FieldBi Telegram Support Bot",
        "status" => "Online & Running",
        "endpoint_url" => $currentUrl
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
