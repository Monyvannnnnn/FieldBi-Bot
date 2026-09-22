-- ============================================================
-- TELEGRAM GROUP CUSTOMER SUPPORT BOT DATABASE SCHEMA
-- Compatible with MySQL (XAMPP) & Supabase (PostgreSQL)
-- ============================================================

-- ------------------------------------------------------------
-- MySQL Schema (For Local XAMPP Setup)
-- ------------------------------------------------------------

-- 1. Support Groups Registry
CREATE TABLE IF NOT EXISTS support_groups (
    group_chat_id VARCHAR(100) PRIMARY KEY,
    group_title VARCHAR(255),
    is_active SMALLINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Conversations Table (Group Ticket Tracking)
CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_chat_id VARCHAR(50) NOT NULL UNIQUE,
    customer_name VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Group Message Map (Maps Group Message ID to Customer Chat ID)
CREATE TABLE IF NOT EXISTS group_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    group_chat_id VARCHAR(100) NOT NULL,
    group_message_id BIGINT NOT NULL UNIQUE,
    customer_chat_id VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Pending Customer Messages (Buffer for batching messages)
CREATE TABLE IF NOT EXISTS pending_customer_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_chat_id VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100),
    username VARCHAR(100),
    message_text TEXT,
    photo_file_id VARCHAR(255),
    doc_file_id VARCHAR(255),
    processed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
