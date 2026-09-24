# Telegram Support Bot — UX Improvement Prompts

All prompts are copy-paste ready for `support_bot.php`.

---

## Priority 🔴 High

### 1. Welcome Menu with Inline Keyboard

Add to `/start` handler:

```php
if ($text === '/start') {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '💼 Apply for Job', 'callback_data' => 'menu_cv']],
            [['text' => '❓ Ask Question', 'callback_data' => 'menu_question']],
            [['text' => '📦 Product Inquiry', 'callback_data' => 'menu_product']],
            [['text' => '📞 Contact Us', 'callback_data' => 'menu_contact']]
        ]
    ];
    
    $msg = "👋 <b>Welcome to Fieldbi Support!</b>\n\n"
         . "How can we help you today?\n\n"
         . "Choose an option below or type your message:";
    
    sendMessage($chatId, $msg, $keyboard);
    return;
}
```

---

### 2. Typing Indicator

Add this function:

```php
function sendChatAction($chatId, $action = 'typing') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'action' => $action
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    curl_exec($ch);
    curl_close($ch);
}
```

Use before sending messages:

```php
sendChatAction($chatId, 'typing');
sleep(1);
sendMessage($chatId, $msg);
```

---

## Priority 🟡 Medium

### 3. Progress Indicator During Batching

When first message arrives:

```php
sendMessage($chatId, "📩 Message received! Please wait...");
```

Before posting to group:

```php
sendMessage($chatId, "⏳ Forwarding to our team...");
```

---

### 4. Quick Reply Buttons for Common Questions

```php
if ($text === '/faq') {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📋 Job Openings', 'callback_data' => 'faq_jobs']],
            [['text' => '💰 Salary Range', 'callback_data' => 'faq_salary']],
            [['text' => '📍 Office Location', 'callback_data' => 'faq_location']],
            [['text' => '⏰ Working Hours', 'callback_data' => 'faq_hours']]
        ]
    ];
    
    sendMessage($chatId, "❓ <b>Frequently Asked Questions</b>\n\nChoose a topic:", $keyboard);
    return;
}

// Handle FAQ clicks
if (strpos($cbData, 'faq_') === 0) {
    $faqAnswers = [
        'faq_jobs' => "💼 <b>Current Openings:</b>\n\n• Developer\n• Marketing Officer\n• Sales Representative\n\nApply with /submit_cv",
        'faq_salary' => "💰 <b>Salary Range:</b>\n\n• Developer: $800-$1500\n• Marketing: $500-$1000\n• Sales: $400-$800 + Commission",
        'faq_location' => "📍 <b>Office Location:</b>\n\nPhnom Penh, Cambodia\nStreet 123, Khan Daun Penh",
        'faq_hours' => "⏰ <b>Working Hours:</b>\n\nMon-Fri: 8:00 AM - 5:00 PM\nSat: 8:00 AM - 12:00 PM"
    ];
    
    if (isset($faqAnswers[$cbData])) {
        sendMessage($chatId, $faqAnswers[$cbData]);
    }
    return;
}
```

---

### 5. Customer Status Check

```php
if ($text === '/status') {
    $stmt = mysqli_prepare($conn, 
        "SELECT id, status, assigned_to FROM conversations WHERE customer_chat_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "s", $chatId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $conv = mysqli_fetch_assoc($result);
    
    if ($conv) {
        $statusText = [
            'pending' => '⏳ Waiting for agent...',
            'assigned' => '✅ Agent assigned! Expected reply: ~5 min',
            'resolved' => '✅ Resolved'
        ];
        sendMessage($chatId, "🎫 Ticket #{$conv['id']}\nStatus: " . $statusText[$conv['status']]);
    } else {
        sendMessage($chatId, "📭 No active tickets found.");
    }
    return;
}
```

---

## Priority 🟢 Low

### 6. Escalation Alert (No Reply in 2 Minutes)

```php
// Run periodically (cron or on next poll)
$escalationTime = 120; // 2 minutes

$stmt = mysqli_prepare($conn, 
    "SELECT id FROM conversations 
     WHERE status = 'pending' 
     AND TIMESTAMPDIFF(SECOND, created_at, NOW()) > ?"
);
mysqli_stmt_bind_param($stmt, "i", $escalationTime);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $groups = mysqli_query($conn, "SELECT group_chat_id FROM support_groups WHERE is_active = 1");
    while ($g = mysqli_fetch_assoc($groups)) {
        sendMessage($g['group_chat_id'], 
            "⚠️ <b>ESCALATION:</b> No agent has replied to ticket #{$row['id']} for 2 minutes!");
    }
}
```

---

### 7. After-Hours Auto-Reply

```php
// Add at start of message handling
if (!isBusinessOpen()) {
    sendMessage($chatId, "🌙 <b>We're currently closed.</b>\n\n"
        . "⏰ Working Hours: Mon-Fri, 8AM-5PM\n\n"
        . "We'll reply first thing tomorrow!\n\n"
        . "For urgent matters, email: info.cambodia@fieldbi.com");
    return;
}
```

---

### 8. Rich Ticket Display in Group

Replace `$ticketHeader` with:

```php
$ticketHeader = "━━━━━━━━━━━━━━━━━━━━\n"
              . "🎫 <b>NEW CUSTOMER #{$convId}</b>\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "👤 <b>Name:</b> {$customerName}\n"
              . "📱 <b>Phone:</b> {$phone}\n"
              . "📧 <b>Email:</b> {$email}\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "💬 <b>Message:</b>\n{$combinedText}\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "📅 " . date('d M Y H:i') . "\n"
              . "⏰ <b>Status:</b> PENDING\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "💡 <i>Reply to this message to respond.</i>";
```

---

## Implementation Checklist

| # | Feature | Status |
|---|---------|--------|
| 1 | Welcome keyboard menu | ⬜ |
| 2 | Typing indicator | ⬜ |
| 3 | Progress indicator | ⬜ |
| 4 | FAQ quick replies | ⬜ |
| 5 | Customer /status | ⬜ |
| 6 | Escalation alert | ⬜ |
| 7 | After-hours reply | ⬜ |
| 8 | Rich ticket display | ⬜ |
