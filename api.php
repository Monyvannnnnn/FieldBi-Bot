<?php
/**
 * Telegram Customer Support Bot Webhook & API Endpoint
 */

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET Request: Register Webhook with Telegram API & Return Status
if ($requestMethod === 'GET' || isset($_GET['action'])) {
    header("Content-Type: application/json; charset=utf-8");
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $webhookUrl = "{$scheme}://{$host}/telegram_support_bot/api.php";

    $whApiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($webhookUrl);
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
        "webhook_url" => $webhookUrl,
        "webhook_response" => json_decode($whRes, true)
    ]);
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
