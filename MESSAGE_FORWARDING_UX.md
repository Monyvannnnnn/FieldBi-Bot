# Message Forwarding to Group — UX Improvements

## Current Implementation

```
🎫 NEW CUSTOMER #1
👤 From: John
💬 I want to apply for a job
```

---

## Recommended Implementation

```
━━━━━━━━━━━━━━━━━━━━
🎫 NEW CUSTOMER #1
━━━━━━━━━━━━━━━━━━━━
👤 Name: John (@john_doe)
📱 Phone: 099-123-456
💬 Message:
Hello, I want to apply for a developer position.
━━━━━━━━━━━━━━━━━━━━
📅 22 Sep 2026 | 03:30 PM
⏰ Status: PENDING
━━━━━━━━━━━━━━━━━━━━
💡 Reply to this message to respond.
```

---

## Features to Add

### 1. Customer Contact Info

```php
$firstName = $message["chat"]["first_name"] ?? '';
$lastName = $message["chat"]["last_name"] ?? '';
$username = $message["chat"]["username"] ?? '';

$contactDisplay = trim($firstName . ' ' . $lastName);
if ($username) {
    $contactDisplay .= " (@{$username})";
}
```

### 2. Message Timestamp

```php
$timestamp = date('d M Y H:i', $message['date'] ?? time());
```

### 3. Ticket Status Indicator

```php
$statusEmoji = [
    'pending' => '⏳',
    'assigned' => '🔄',
    'resolved' => '✅'
];
```

### 4. Escalation Warning (No Reply in 2 Minutes)

```php
// If no reply after 2 minutes
sendMessage($groupId, "⚠️ No agent has replied to ticket #{$convId} for 2 minutes!");
```

### 5. Reply Confirmation

```php
// When agent replies
sendMessage($groupId, "✅ {$agentName} replied to ticket #{$convId}");
```

---

## Full Code Implementation

```php
// Build rich ticket message
$ticketHeader = "━━━━━━━━━━━━━━━━━━━━\n"
              . "🎫 <b>NEW CUSTOMER #{$convId}</b>\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "👤 <b>From:</b> {$customerName}\n"
              . "💬 <b>Message:</b>\n{$combinedText}\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "📅 " . date('d M Y H:i') . "\n"
              . "⏰ <b>Status:</b> PENDING\n"
              . "━━━━━━━━━━━━━━━━━━━━\n"
              . "💡 <i>Reply to this message to respond.</i>";

// Post to all groups
foreach ($groups as $g) {
    sendMessage($g['group_chat_id'], $ticketHeader);
}
```

---

## Priority

| Feature | Impact |
|---------|--------|
| Rich ticket display | High — agents see all info at a glance |
| Timestamp | Medium — know when message was sent |
| Escalation warning | High — prevents forgotten tickets |
| Reply confirmation | Medium — avoids duplicate replies |
