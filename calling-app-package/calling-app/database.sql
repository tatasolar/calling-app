-- IMPORTANT: In phpMyAdmin, select your existing application database in the
-- left sidebar, then open that database's SQL tab and run this script.
-- Do not run it from the server-level SQL tab: no database will be selected.
-- This schema intentionally does not create or switch databases because
-- shared-host database users commonly lack the global CREATE DATABASE privilege.

CREATE TABLE IF NOT EXISTS `agents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(160) NOT NULL UNIQUE,
  `sip_extension` VARCHAR(16) NOT NULL DEFAULT '1001',
  `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
  `password_hash` VARCHAR(255) NOT NULL,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_code` VARCHAR(12) DEFAULT NULL,
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `sound_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `alarm_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shortcut_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `agent_id` INT UNSIGNED NOT NULL,
  `customer_ref` VARCHAR(100) DEFAULT NULL,
  `note_text` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  INDEX `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_notes_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `call_recordings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `agent_id` INT UNSIGNED NOT NULL,
  `peer_target` VARCHAR(64) NOT NULL,
  `call_type` ENUM('direct', 'group') NOT NULL DEFAULT 'direct',
  `file_name` VARCHAR(255) NOT NULL,
  `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_recordings_agent_created` (`agent_id`, `created_at`),
  CONSTRAINT `fk_rec_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_name` VARCHAR(64) NOT NULL DEFAULT 'solarooof-team',
  `sender_email` VARCHAR(160) NOT NULL,
  `sender_ext` VARCHAR(16) NOT NULL,
  `message_body` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_dispatches` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `agent_id` INT UNSIGNED NOT NULL,
  `channel` ENUM('sms', 'whatsapp') NOT NULL,
  `recipient` VARCHAR(64) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_dispatch_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `call_feedback` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `agent_id` INT UNSIGNED NOT NULL,
  `peer_target` VARCHAR(64) NOT NULL,
  `token_hash` CHAR(64) NOT NULL UNIQUE,
  `conduct_rating` TINYINT UNSIGNED DEFAULT NULL,
  `behavior_rating` TINYINT UNSIGNED DEFAULT NULL,
  `comments` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  INDEX `idx_feedback_expiry` (`expires_at`),
  CONSTRAINT `fk_feedback_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No accounts or passwords are seeded. Register an account through the app,
-- then grant administrator privileges to a trusted account from phpMyAdmin.
