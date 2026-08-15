<?php
// ============================================================
//  Dievon – Email System Database Migrations
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

function runEmailSystemMigrations(PDO $pdo): bool {
    try {
        // 1. Create email_logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `email_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email_type` VARCHAR(50) NOT NULL,
            `recipient` VARCHAR(150) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
            `error_message` TEXT NULL,
            `test_mode` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (`email_type`),
            INDEX (`recipient`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Create password_resets table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(150) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (`email`),
            INDEX (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Create inquiries table (for contact & product enquiries)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(30) DEFAULT '',
            `message` TEXT NOT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Add last_notified_status to orders table to prevent duplicate emails
        $chkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?");
        $chkCol->execute(['last_notified_status']);
        if (!(int)$chkCol->fetchColumn()) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `last_notified_status` VARCHAR(50) DEFAULT '' AFTER `status`");
        }

        // 5. Add tracking_number and carrier to orders table if missing
        $chkCol->execute(['tracking_number']);
        if (!(int)$chkCol->fetchColumn()) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `tracking_number` VARCHAR(100) DEFAULT '' AFTER `last_notified_status`");
        }

        $chkCol->execute(['carrier']);
        if (!(int)$chkCol->fetchColumn()) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `carrier` VARCHAR(100) DEFAULT '' AFTER `tracking_number`");
        }

        return true;
    } catch (PDOException $e) {
        error_log("Email migration error: " . $e->getMessage());
        return false;
    }
}

// Migration helper function (called from admin/setup_database.php)
// Do not auto-run DDL queries during regular page requests to prevent HTTP 500 locks.
