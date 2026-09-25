<?php
/**
 * Helper script to update Telegram Bot Name, Description ("What can this bot do?"), and Short Description
 */

require_once __DIR__ . '/support_bot.php';

$botToken = BOT_TOKEN;

// 1. Set Bot Name
$nameUrl = "https://api.telegram.org/bot{$botToken}/setMyName";
$ch = curl_init($nameUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['name' => 'FieldBi Cambodia Support'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
]);
$resName = curl_exec($ch);
curl_close($ch);
echo "Set Name Result: " . $resName . "\n";

// 2. Set Bot Description ("What can this bot do?" card - English Only)
$descriptionText = "👋 Welcome to FieldBi Cambodia Support!\n\nFieldbi is a technology & software solutions company specializing in digital platforms and software engineering.\n\n💬 Send us your message, question, or job application details below, and our support team will assist you shortly.";

$descUrl = "https://api.telegram.org/bot{$botToken}/setMyDescription";
$ch = curl_init($descUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['description' => $descriptionText],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
]);
$resDesc = curl_exec($ch);
curl_close($ch);
echo "Set Description Result: " . $resDesc . "\n";

// 3. Set Bot Short Description (Bot profile preview)
$shortDescription = "FieldBi Cambodia Support — Official Customer & Career Support Bot.";
$shortDescUrl = "https://api.telegram.org/bot{$botToken}/setMyShortDescription";
$ch = curl_init($shortDescUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['short_description' => $shortDescription],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_OPTIONS    => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0
]);
$resShortDesc = curl_exec($ch);
curl_close($ch);
echo "Set Short Description Result: " . $resShortDesc . "\n";
