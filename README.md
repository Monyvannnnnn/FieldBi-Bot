# Telegram Customer Support Bot
Fieldbi.310394
Complete multi-agent customer support ticketing and live-chat system powered by **PHP**, **Supabase (PostgreSQL)**, and **MySQL**.

## 📁 Directory Structure

```text
telegram_support_bot/
├── api.php                 # Webhook & API entrypoint for Telegram updates
├── support_bot.php         # Main update logic and ticketing handler
├── support_bot_poller.php  # Real-time background poller daemon
├── schema.sql              # Database DDL for Supabase / MySQL
└── README.md               # Feature guide and instructions
```

---

## ⚡ Quick Setup & Usage

### 1. Database Schema
Ensure the tables in `schema.sql` are created in your **Supabase SQL Editor** or **MySQL Database**.

### 2. For Support Agents (Employees)
1. Open the Telegram Bot.
2. Type `/agent` or `/register`.
3. Receive response: `✅ Agent Registered!`.

### 3. For Customers
1. Open the Telegram Bot.
2. Send `/start` or any support question (e.g. `"I want to apply for a job"`).
3. System creates a pending conversation and alerts all active support agents.

### 4. Claiming & Replying
- Agent receives alert with command `/claim {id}`.
- Agent types `/claim {id}`.
- Bot assigns the ticket atomically to that agent and notifies all other agents.
- Agent long-presses the notification in Telegram and selects **Reply** to respond back to the customer.

---

## 🔑 Environment Configuration (.env)

Configure your bot credentials in `.env` (or `.env.example`):
```ini
TELEGRAM_BOT_TOKEN=YOUR_TELEGRAM_BOT_TOKEN
TELEGRAM_ADMIN_CHAT_ID=7892238736
```

---

## 🚀 Running the Bot

### Webhook Mode (Production)
Set your Webhook URL via Telegram API:
```text
https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://YOUR_DOMAIN/telegram_support_bot/support_bot.php
```

### Poller Mode (Local Development / XAMPP)
Run the real-time polling script:
```bash
php telegram_support_bot/support_bot_poller.php
```
