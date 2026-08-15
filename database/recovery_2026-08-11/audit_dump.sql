-- ============================================================
-- Dievon — Full Database Backup
-- Database: dievonfashion
-- Generated: 2026-08-08 18:18:49
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
-- @DIEVON_STMT_END
SET NAMES utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `account_deletions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `account_deletions`;
-- @DIEVON_STMT_END
CREATE TABLE `account_deletions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_hash` char(64) NOT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deletion_hash` (`email_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_audit_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_audit_logs`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_admin` (`admin_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `admin_audit_logs` (`id`, `admin_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
('2', '1', 'category_add', 'Created category: Man', '::1', '2026-08-03 17:05:58'),
('3', '1', 'category_delete', 'Deleted category: Man', '::1', '2026-08-03 17:06:03'),
('4', '1', 'category_add', 'Created category: Man', '::1', '2026-08-03 17:22:30'),
('5', '1', 'category_add', 'Created category: Shirt', '::1', '2026-08-03 17:22:41'),
('6', '1', 'category_add', 'Created category: Shirt', '::1', '2026-08-03 17:33:26'),
('7', '1', 'category_delete', 'Deleted category: Shirt', '::1', '2026-08-03 17:45:50'),
('8', '1', 'category_delete', 'Deleted category: Shirt', '::1', '2026-08-03 17:51:43'),
('9', '1', 'category_delete', 'Deleted category: Man', '::1', '2026-08-03 17:52:27'),
('10', '1', 'product_updated', '#18 \"Prince (Copy)\" — ₹3,333.00, draft', '::1', '2026-08-03 18:08:39'),
('11', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:14:53'),
('12', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:14:59'),
('13', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:15:02'),
('14', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:15:04'),
('15', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:15:30'),
('16', '1', 'soft_delete_product', 'Soft-deleted product ID 14', '::1', '2026-08-03 18:15:48'),
('17', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:18:25'),
('18', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:18:37'),
('19', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:18:45'),
('20', '1', 'soft_delete_product', 'Soft-deleted product ID 18', '::1', '2026-08-03 18:18:50'),
('21', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, published', '::1', '2026-08-03 18:23:46'),
('22', '1', 'purge_product', 'Permanently deleted product ID 18 (Prince (Copy))', '::1', '2026-08-03 18:34:20'),
('23', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, published', '::1', '2026-08-03 19:03:32'),
('24', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, published', '::1', '2026-08-03 19:05:52'),
('25', '1', 'role_created', 'Created role Editor', '::1', '2026-08-04 22:01:01'),
('26', '1', 'role_updated', 'Set Editor to 0 capability(ies)', '::1', '2026-08-04 22:02:04'),
('27', '1', 'category_tree_repaired', '6 category(ies) re-parented: Short Kurtis → Kurtis, Long Kurtis → Kurtis, Anarkali Sets → Kurtis, Sharara & Gharara Sets → Kurtis, Straight Cut Kurtis → Kurtis, Testing → Women\'s Tops', '127.0.0.1', '2026-08-04 22:08:06'),
('28', '1', 'admin_user_created', 'Created staff account flowtest (Product Manager)', '127.0.0.1', '2026-08-04 22:11:18'),
('29', '1', 'admin_users_save_roles', 'Updated 1 staff account(s)', '::1', '2026-08-04 22:15:40'),
('30', '1', 'size_charts_cleaned', 'Removed left-over size chart(s): #11', '127.0.0.1', '2026-08-04 22:25:35'),
('31', '1', 'refund_issued', 'Order CB-610110 (#10) — ₹100.00 via manual, ref 1 — Test partial', '127.0.0.1', '2026-08-04 23:47:48'),
('32', '1', 'refund_issued', 'Order CB-610110 (#10) — ₹244.00 via manual, ref 2 — Test remainder', '127.0.0.1', '2026-08-04 23:47:48'),
('49', '1', 'soft_delete_product', 'Soft-deleted product ID 13', '::1', '2026-08-05 22:19:41'),
('50', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-05 23:52:47'),
('51', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-05 23:53:05'),
('52', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 00:01:51'),
('53', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 00:03:33'),
('54', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 00:08:00'),
('55', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 00:08:40'),
('56', '1', 'product_updated', '#11 \"Sienna Wide-Leg Silk Trousers\" — ₹160.00, draft', '::1', '2026-08-06 00:08:51'),
('57', '1', 'product_updated', '#11 \"Sienna Wide-Leg Silk Trousers\" — ₹160.00, published', '::1', '2026-08-06 00:08:59'),
('58', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 00:09:08'),
('59', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, published', '::1', '2026-08-06 00:09:10'),
('60', '1', 'cod_marked_paid', 'Order CB-REVTEST marked Cash on delivery', '::1', '2026-08-06 11:49:15'),
('61', '1', 'refund_issued', 'Order REF-TEST (#119) — ₹750.00 via manual, ref 1 — e2e test', '127.0.0.1', '2026-08-06 12:30:09'),
('62', '1', 'refund_issued', 'Order REF-TEST (#121) — ₹750.00 via manual, ref 2 — e2e test', '127.0.0.1', '2026-08-06 12:30:41'),
('63', '1', 'refund_issued', 'Order REF-TEST (#123) — ₹750.00 via manual, ref 3 — e2e test', '127.0.0.1', '2026-08-06 12:31:25'),
('64', '1', 'refund_issued', 'Order REF-TEST (#126) — ₹750.00 via manual, ref 4 — e2e test', '127.0.0.1', '2026-08-06 12:31:47'),
('65', '1', 'cod_remitted', 'Order #127 — COD remittance recorded: 1050', '::1', '2026-08-06 12:32:08'),
('66', '1', 'cod_remitted', 'Order #127 — COD remittance recorded: 999', '::1', '2026-08-06 12:32:08'),
('67', '1', 'refund_issued', 'Order REF-TEST (#129) — ₹750.00 via manual, ref 5 — e2e test', '127.0.0.1', '2026-08-06 12:32:08'),
('68', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, draft', '::1', '2026-08-06 18:24:14'),
('69', '1', 'product_updated', '#12 \"Iris Straight Crepe Pants\" — ₹140.00, published', '::1', '2026-08-06 18:24:17'),
('70', '1', 'product_updated', '#5 \"Luna Satin Coord Set\" — ₹290.00, draft', '::1', '2026-08-06 18:24:54'),
('71', '1', 'product_updated', '#5 \"Luna Satin Coord Set\" — ₹290.00, published', '::1', '2026-08-06 18:24:56'),
('72', '1', 'product_created', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:27:55'),
('73', '1', 'product_updated', '#15 \"test\" — ₹675.00, published, compare-at ₹1,200.00', '::1', '2026-08-06 18:28:03'),
('74', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:32:48'),
('75', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:32:54'),
('76', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:33:02'),
('77', '1', 'product_updated', '#15 \"test\" — ₹675.00, published, compare-at ₹1,200.00', '::1', '2026-08-06 18:33:04'),
('78', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:34:37'),
('79', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:38:25'),
('80', '1', 'product_updated', '#15 \"test\" — ₹675.00, published, compare-at ₹1,200.00', '::1', '2026-08-06 18:38:26'),
('81', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:39:16'),
('82', '1', 'product_updated', '#15 \"test\" — ₹675.00, published, compare-at ₹1,200.00', '::1', '2026-08-06 18:39:18'),
('83', '1', 'product_updated', '#15 \"test\" — ₹675.00, draft, compare-at ₹1,200.00', '::1', '2026-08-06 18:40:07'),
('84', '1', 'product_updated', '#15 \"test\" — ₹675.00, published, compare-at ₹1,200.00', '::1', '2026-08-06 18:40:09'),
('87', '1', 'media_quarantine', 'Quarantined 15 file(s) into 2026-08-06_222643', '::1', '2026-08-06 23:26:43'),
('88', '1', 'lookbook_update', 'Replaced lookbook_1.png', '::1', '2026-08-07 10:27:09'),
('89', '1', 'lookbook_restore', 'Restored lookbook_1.png to its original', '::1', '2026-08-07 10:28:26'),
('90', '1', 'lookbook_update', 'Replaced lookbook_3.png', '::1', '2026-08-07 10:36:20'),
('91', '1', 'lookbook_restore', 'Restored lookbook_3.png to its original', '::1', '2026-08-07 10:37:02'),
('92', '1', 'lookbook_update', 'Replaced lookbook_2.png', '::1', '2026-08-07 10:45:31'),
('93', '1', 'lookbook_update', 'Replaced lookbook_1.png', '127.0.0.1', '2026-08-07 13:07:05'),
('94', '1', 'lookbook_restore', 'Restored lookbook_1.png to its original', '127.0.0.1', '2026-08-07 13:07:29'),
('95', '1', 'lookbook_update', 'Replaced lookbook_4.png', '127.0.0.1', '2026-08-07 13:40:40'),
('96', '1', 'delete_blog', 'Deleted blog post ID 1', '::1', '2026-08-07 15:59:35');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_login_codes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_login_codes`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_login_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `purpose` enum('login','setup','password_reset') NOT NULL DEFAULT 'login',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alc_admin` (`admin_id`,`created_at`),
  KEY `idx_alc_expiry` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `admin_login_codes` (`id`, `admin_id`, `code_hash`, `purpose`, `expires_at`, `used_at`, `created_at`) VALUES
('1', '1', '$2y$10$qv9LAQPk9m/Ox2VMpzFEC.hvYtZnt8Ka5czhkzvsPw.yoapw9oFl6', 'password_reset', '2026-08-06 22:11:06', '2026-08-06 22:01:06', '2026-08-06 22:01:06'),
('2', '1', '$2y$10$vLvyHM/TzUvDPsqu725GG.2y954jrAO5wwEXPYYal36yX8YwO2DDe', 'password_reset', '2026-08-06 22:11:06', '2026-08-06 22:01:06', '2026-08-06 22:01:06');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_login_history`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_login_history`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_login_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `username` varchar(80) NOT NULL,
  `succeeded` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alh_time` (`created_at`),
  KEY `idx_alh_admin` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `admin_login_history` (`id`, `admin_id`, `username`, `succeeded`, `ip_address`, `user_agent`, `created_at`) VALUES
('52', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 13:01:56'),
('53', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 13:09:56'),
('54', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.24012.9 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36', '2026-08-02 13:10:18'),
('55', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-02 13:15:10'),
('56', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 14:23:34'),
('57', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 14:24:08'),
('58', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 14:42:07'),
('59', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 14:47:46'),
('60', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 15:03:17'),
('61', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 15:06:14'),
('62', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 15:09:25'),
('63', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 15:09:38'),
('64', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 16:47:54'),
('65', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 17:25:14'),
('66', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-02 17:52:36'),
('67', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 17:53:20'),
('68', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 17:53:38'),
('69', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 17:53:53'),
('70', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 17:54:29'),
('71', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 18:21:02'),
('72', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 18:21:19'),
('73', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-02 18:21:36'),
('74', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-03 12:22:29'),
('77', NULL, 'dievonadmin', '0', '::1', 'Python-urllib/3.9', '2026-08-03 13:13:18'),
('78', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:13:21'),
('79', '1', 'dievonadmin', '0', '::1', 'Python-urllib/3.9', '2026-08-03 13:13:24'),
('80', '1', 'dievonadmin', '1', '::1', 'curl/8.7.1', '2026-08-03 13:13:30'),
('81', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:18:01'),
('82', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:27:40'),
('83', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:45:16'),
('84', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:48:22'),
('85', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:48:23'),
('86', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:49:05'),
('87', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:51:16'),
('88', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:51:27'),
('89', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 13:53:04'),
('90', '1', 'dievonadmin', '1', '::1', 'Python-urllib/3.9', '2026-08-03 14:15:09'),
('91', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-03 16:37:45'),
('92', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-03 16:38:16'),
('93', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-03 18:20:44'),
('94', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-03 18:20:56'),
('95', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.24012.11 Chrome/148.0.7778.280 Electron/42.7.0 Safari/537.36', '2026-08-04 00:18:43'),
('96', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-04 00:58:53'),
('97', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-04 14:57:35'),
('111', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:28:07'),
('112', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:29:09'),
('113', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:29:24'),
('114', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:29:59'),
('115', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:30:12'),
('116', NULL, 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:30:34'),
('117', NULL, 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:31:36'),
('118', NULL, 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-05 18:33:27'),
('119', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:42:43'),
('120', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:42:56'),
('121', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:43:05'),
('122', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:43:16'),
('123', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:43:27'),
('124', NULL, 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:43:36'),
('125', NULL, 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:44:49'),
('126', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:53:38'),
('127', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:53:49'),
('128', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:54:45'),
('129', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:54:55'),
('130', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:57:02'),
('131', '1', 'dievonadmin', '0', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 16:57:05'),
('132', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 17:00:36'),
('133', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-06 18:21:18'),
('134', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-08-07 08:16:39'),
('135', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/150.0.0.0 Safari/537.36', '2026-08-07 10:26:54'),
('136', '1', 'dievonadmin', '1', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', '2026-08-08 10:46:51');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_roles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_roles`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `capabilities` text,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `admin_roles` (`id`, `slug`, `name`, `description`, `capabilities`, `is_system`, `created_at`) VALUES
('1', 'owner', 'Owner', 'Everything, including money, settings and staff.', '[\"*\"]', '1', '2026-08-04 21:28:59'),
('2', 'catalogue', 'Catalogue manager', 'Products, categories, media and SEO.', '[\"catalogue.manage\",\"media.manage\",\"seo.manage\",\"content.manage\",\"sizeguide.manage\",\"orders.view\"]', '1', '2026-08-04 21:28:59'),
('3', 'orders', 'Order staff', 'Orders, returns and support tickets.', '[\"orders.view\",\"orders.manage\",\"returns.manage\",\"tickets.manage\",\"customers.view\",\"catalogue.view\"]', '1', '2026-08-04 21:28:59'),
('5', 'editor', 'Editor', NULL, '[]', '0', '2026-08-04 22:01:01');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_user_capabilities`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_user_capabilities`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_user_capabilities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `capability` varchar(60) NOT NULL,
  `mode` enum('grant','deny') NOT NULL DEFAULT 'grant',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_cap` (`admin_id`,`capability`),
  KEY `idx_auc_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `admin_users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_users`;
-- @DIEVON_STMT_END
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `role` enum('owner','catalogue','orders') NOT NULL DEFAULT 'orders',
  `role_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `totp_method` enum('app','email') NOT NULL DEFAULT 'app',
  `recovery_codes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `admin_users` (`id`, `username`, `password_hash`, `full_name`, `email`, `email_verified_at`, `role`, `role_id`, `is_active`, `must_change_password`, `last_login_at`, `created_at`, `totp_secret`, `totp_enabled`, `totp_method`, `recovery_codes`) VALUES
('1', 'dievonadmin', '$2y$10$zwdAclLGKR4u3kPqUHI.oeic2w.ecfXhDPFdPwj/68Y2wOsfXcwW.', 'Shop Owner', NULL, NULL, 'owner', NULL, '1', '0', '2026-08-08 10:46:51', '2026-08-02 12:15:09', NULL, '0', 'app', NULL);
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `banners`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `banners`;
-- @DIEVON_STMT_END
CREATE TABLE `banners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subtitle` text,
  `image` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT 'shop',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `sort_order` int(11) DEFAULT '0',
  `gender` enum('women','men','both') NOT NULL DEFAULT 'women',
  `placement` enum('hero','occasion') NOT NULL DEFAULT 'hero',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `banners` (`id`, `title`, `subtitle`, `image`, `link`, `status`, `sort_order`, `gender`, `placement`) VALUES
('1', 'Luxury Indian Womenswear', 'Hand-finished kurtis, suits, co-ords and more. Designed for grace in motion.', 'cotton_short_kurti_1784414719998.jpg', 'shop?category=Kurtis', 'Active', '1', 'women', 'hero'),
('2', '3-Piece Suits', 'Volume II — Elegant tailored brocades and soft raw silks.', 'silk_3_piece_1784414705496.jpg', 'shop?category=3+Piece+Suits', 'Active', '2', 'women', 'hero'),
('3', 'Coord Sets', 'Volume III — Luxurious satin wide-legs and tailored organic linens.', 'linen_coord_1784414712638.jpg', 'shop?category=Coord+Sets', 'Active', '3', 'women', 'hero');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `blog_posts`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `blog_posts`;
-- @DIEVON_STMT_END
CREATE TABLE `blog_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'Style Guide',
  `image` varchar(255) DEFAULT NULL,
  `excerpt` text,
  `content` longtext NOT NULL,
  `content_format` enum('text','html') NOT NULL DEFAULT 'text',
  `tags` varchar(255) DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text,
  `status` enum('Published','Draft') DEFAULT 'Published',
  `published_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `blog_posts` (`id`, `title`, `slug`, `category`, `image`, `excerpt`, `content`, `content_format`, `tags`, `meta_title`, `meta_description`, `status`, `published_date`, `created_at`) VALUES
('2', 'Calfskin Sourcing: Preserving the Tuscan Heritage', 'calfskin-sourcing-tuscan-heritage', 'Atelier Craftsmanship', 'lookbook_2.png', 'A tour inside our leather ateliers in Florence, where raw leather meets gold hardware.', 'Tuscany is famous across the globe for its leatherwork heritage, but what sets the Elysian leather handbag apart? It starts at the selection tables, where our master artisans source only full-grain calfskins showing dense, uniform grain.\n\nVegetable tanning processes follow, utilising oak bark and chestnut extracts in a slow, 30-day barrel tanning cycle. This traditional, chemical-free method preserves the organic texture of the hides.', 'text', 'leather, florence, craftsmanship, luxury bags', NULL, NULL, 'Draft', '2026-07-10', '2026-07-29 09:45:39'),
('3', 'The Autumn Silhouette Sneak Preview', 'autumn-silhouette-sneak-preview', 'Editorial', 'lookbook_1.png', 'A sneak peek at next season\'s cashmere double-breasted trench coats and blazers.', 'As the warm coastal winds of summer mature, our design desk in Paris turns its focus to structural tailoring. The upcoming Autumn Collection centers on Virgin Wool and Cashmere outer layers.\n\nOur signature double-breasted trench coat is woven in Biella, Italy from a heavy, double-faced cashmere virgin wool blend.', 'text', 'autumn, trench coat, cashmere, paris', NULL, NULL, 'Draft', '2026-07-01', '2026-07-29 09:45:39'),
('4', 'How to Choose a Kurti Size: A Measuring and Fit Guide', 'how-to-choose-a-kurti-size', 'Size & Fit', 'lookbook_1.png', 'Four measurements, one chart, and the fit rules that decide whether a kurti works — including what to do when you land between two sizes.', 'Measure your bust, waist, hips and shoulder in inches, then pick the size whose bust number matches yours — on a kurti, the bust does most of the deciding. Everything below is for the cases where that simple answer isn\'t enough.\n\n## Take four measurements, and take them properly\n\nUse a soft tape. A stiff steel one won\'t follow the curve of your body and reads short.\n\nBust. Wear the bra you\'d normally wear under a kurti — a padded one can add half an inch, and on fabric with no stretch that half inch decides your size. Run the tape around the fullest part of your chest, level front and back, snug without compressing. Breathe out normally.\n\nWaist. Not where your jeans sit — the narrowest point of your torso, usually an inch or two above the navel. If you can\'t find it, bend sideways; the crease that forms is your natural waist.\n\nHips. Feet together, tape around the fullest part of your seat. Feet apart gives you a smaller number than the one you actually need.\n\nShoulder. Across the back, from the bony point at the top of one shoulder to the same point on the other. This is the one measurement you cannot take accurately on yourself; if nobody can help, lay a kurti that already fits you well flat on a table and measure between the shoulder seams.\n\nMost sizing mistakes are measuring mistakes, not chart mistakes. If an attempt felt awkward, do it again.\n\n## The size number is your bust, not a label\n\nOn a kurti, the number in brackets after the size is the bust measurement in inches. The Dievon sizes read XS (30), S (32), M (34), L (36), XL (38), 2XL (40), 3XL (42), 4XL (44) and 5XL (46). If your bust measures 36 inches, you are looking at an L, and the 36 is telling you so directly. A size that is only a label carries no such information, which is why guessing from what you usually buy is unreliable.\n\nThe sizes step up in a fixed rhythm: two inches of bust, two of waist, two of hip and half an inch of shoulder per size. That tells you how far off you are — a 35-inch bust is one inch above M and one inch below L, not stranded.\n\n## When you fall between two sizes\n\nDeal with the measurements in order of how hard each one is to fix.\n\nShoulder first. A seam sitting too far down your arm drags the whole kurti out of shape — the armhole binds, the neckline pulls, and taking in the sides won\'t fix it. It is also the hardest thing for a tailor to alter. If your shoulder measures 15 but your bust says M, the L is very likely the right kurti.\n\nBust second. Woven fabric doesn\'t stretch across the chest, so if it\'s tight there you get horizontal pull lines and a front hem that rides up. Caught between two sizes on the bust, take the larger one.\n\nWaist and hips last, because those are the cheap fixes. A kurti that\'s generous through the body can be taken in at the side seams; one that\'s tight cannot be let out. Size to your largest relevant measurement and bring it in afterwards if you want it closer.\n\nThe exception is when your top and bottom halves land on different rows — a 34-inch bust with 39-inch hips, say. There, the length of the kurti decides for you.\n\n## Short kurti, long kurti: the hem changes the answer\n\nOn a long kurti the hem falls well below the fullest part of your hips, so fit is governed almost entirely by shoulder and bust. Hips matter mainly if there are side slits — snug hips make the slits sit open rather than hang closed, which changes how much of your bottoms shows.\n\nOn a short kurti the hem lands on or just below the fullest part of the seat, which is the worst place for a garment to be tight: it cups, it rides up, and it creases across the front. So if your hips point to a larger size than your bust does, follow the hips for a short kurti and the bust for a long one.\n\nWhat goes underneath matters too. Over a high-waisted or gathered bottom, allow for the bulk of the waistband sitting exactly where a short kurti ends.\n\n## Fabric changes how much the numbers matter\n\nNone of the fabrics Dievon stocks — crepe, georgette, linen, organza and silk — are knits, and none of them stretch. That makes the chart more important, not less. They don\'t all behave alike, though.\n\nCrepe and georgette drape and move, and are the most forgiving through the waist and hip. Georgette especially hangs away from the body, so going up a size costs you very little in shape.\n\nLinen has no give and creases where it is strained. A linen kurti that\'s borderline tight will look it from the first wear. Take the larger size.\n\nOrganza is crisp and stands away from the body, which sounds forgiving but isn\'t: it holds its shape rather than yielding, and shows strain at the shoulder and armhole. Silk is smooth, unstretchy and marks at stress points. On both, go up rather than down.\n\n## Reading the chart\n\nIn inches, in the order bust, waist, hips, shoulder: XS is 30, 24, 33, 13.5. S is 32, 26, 35, 14. M is 34, 28, 37, 14.5. L is 36, 30, 39, 15. XL is 38, 32, 41, 15.5. 2XL is 40, 34, 43, 16. 3XL is 42, 36, 45, 16.5. 4XL is 44, 38, 47, 17. 5XL is 46, 40, 49, 17.5.\n\nFind the row your bust sits in. Check the shoulder figure on that row against your own; if yours is larger, move up. Check the hips, and if they are larger by a row and you are buying a short kurti, move up. Waist rarely decides anything on its own. The same chart covers the kurti in a 3 piece suit or a coord set, so size the set by the kurti.\n\nWhen it arrives, try it on before the tags come off, over the innerwear and bottoms you plan to wear with it. Raise both arms, sit down, reach forward. A kurti that fits only while you\'re standing still isn\'t going to work.\n\nIf it\'s wrong, you have 14 days from delivery to return it unworn, with tags attached and in its original packaging — so keep the packaging until you\'ve decided. Orders placed before 2pm IST are dispatched the same business day, and delivery takes 3–5 business days within India. Standard delivery is ₹99, free over ₹2,000, and you can pay by UPI, card or Cash on Delivery.', 'text', 'kurti size, size guide, how to measure, fit advice, kurtis, size chart', 'Kurti Size Guide: How to Measure and Choose | Dievon', 'Measure bust, waist, hips and shoulder, then read the Dievon kurti size chart from XS (30) to 5XL (46) — plus what to do when you fall between sizes.', 'Published', '2026-06-07', '2026-08-02 17:23:40'),
('5', 'How to Wash a Cotton Kurti So It Doesn\'t Fade or Shrink', 'how-to-wash-a-cotton-kurti', 'Fabric & Care', 'lookbook_2.png', 'Cold water, shade drying and a slightly damp iron will keep a cotton kurti looking new for years — here is exactly how to do each one.', 'Wash a cotton kurti in cold water, dry it out of direct sun, and iron it while it is still slightly damp. Those three habits prevent almost all of the fading, shrinking and shoulder-stretching that ruins cotton in its first year. Worth saying at the start: Dievon kurtis are cut in crepe, georgette, linen, organza and silk rather than cotton, so if yours is one of ours, read the general rules and then the last section.\n\n## Before the first wash\n\nIf there is any chance you will send a new kurti back, do not wash it. Returns are accepted within 14 days of delivery, unworn, with tags attached and in the original packaging — a wash ends all four conditions at once. Try it on, check the fit against the size chart, and decide first.\n\nOnce you have decided to keep it, test the dye. Dampen a scrap of white cloth, press it firmly against an inside seam allowance for about thirty seconds, and look at the scrap. If any colour has transferred, that kurti washes alone. Deep indigo, maroon and black are the usual offenders: surface dye sits loosely on the fibre until a few washes have taken it off.\n\n## Cold water is most of the battle\n\nHot water does two things you do not want. It swells the cotton fibre, so dye that has not fully bonded washes straight out, and it releases the tension put into the yarn during weaving — which is what shrinkage actually is. Cotton that has not been pre-shrunk does most of its shrinking in the first hot wash, and it does not come back; you notice it across the bust and in the sleeve length.\n\nUse cold water, or at most water you could comfortably keep your hand in. A mild liquid detergent is kinder than a harsh powder, and dark cotton should be kept away from bleach and from optical brighteners, which leave a faint chalky cast on black, navy and deep maroon.\n\nTurn the kurti inside out, close any hooks or buttons, and give it room: a kurti crushed into a full bucket abrades against itself, and that abrasion lifts the fuzzy surface that makes cotton look tired. Machine washing is fine on a gentle cycle at low spin, ideally in a mesh bag — but do not leave it sitting wet in the drum. In Indian humidity a damp bundle smells within hours, and loose dye settles back onto the cloth in patches.\n\n## Stopping the colour from bleeding\n\nLong soaks are the commonest mistake. Loose dye leaves the fabric in the first ten or fifteen minutes; after that it is floating in the water waiting to redeposit, usually on the paler parts of the same garment. Soak briefly or not at all.\n\nThe salt trick your family swears by helps set some older direct dyes, which is why it has survived, but it does little for the reactive dyes used on most cotton today. Use it if you like — just not in place of the thing that works, which is washing the kurti alone for its first two or three washes.\n\nRinse until the water runs completely clear, not almost clear. And never leave a wet kurti bunched up, even for twenty minutes: creases hold dye, and dye that dries in a crease line stays there.\n\n## Drying out of the sun\n\nSunlight fades dyes because ultraviolet light breaks apart the colour-carrying part of the dye molecule, and heat speeds that up. Between roughly 11am and 4pm the strongest UV and the highest temperature come together, and a few hours of that, repeated weekly, will visibly flatten a black kurti. Dry in shade with moving air instead — a covered balcony corner, or indoors near an open window. Drying inside out protects the printed face.\n\nHow you hang it matters as much as where. Wet cotton is heavy, and a hanger puts all that weight on two points, which is how you get permanent bumps at the shoulders and a stretched neckline. Fold the kurti over the line at the hem instead, or spread it across two parallel lines. If the line is bare metal, cover it; a rust mark is effectively permanent. While it is damp, pull the side seams straight and flatten the hem with your palm.\n\n## Ironing and monsoon storage\n\nIron cotton while it is just damp. Dry cotton needs far more heat to release a crease, and heat is what scorches fibres and puts a shine on the surface. If it has dried fully, mist it rather than turning the dial up. Iron dark colours and prints inside out, and lay raised or embroidered work face down on a folded towel so the iron flattens the base fabric, not the stitching. Never iron over a stain you have not removed, or over deodorant residue — heat sets both.\n\nThrough the monsoon, a kurti must be bone dry before it is put away, not nearly dry. Mildew shows as brown or black speckles and eats into the fibre, so the marks do not wash out. Store it in a breathable cotton bag or an old dupatta rather than a plastic cover, which traps moisture against the cloth, and add a few silica gel sachets. Never store a starched kurti: silverfish go for starch far more readily than for the fibre itself.\n\n## What changes for crepe, georgette, linen, organza and silk\n\nLinen is the closest to cotton — cold water, shade drying, ironed damp — but it creases far more readily and should not be left hard-folded for months. Hang it or roll it instead.\n\nCrepe and georgette are woven from high-twist yarn, and that twist is where their texture comes from. Wash them cold and gently, press the water out rather than wringing, and dry flat or over a wide line — a hard twist sets creases that are hard to iron out without flattening the surface.\n\nSilk and organza are the two where professional cleaning is the safer default, and where sunlight does the most damage in the least time. Organza holds its crispness through a finish that water and rubbing can dull. If you wash silk at home, keep it brief and cold, never soak it, and press on the lowest setting with a cloth between iron and fabric.\n\nWhatever the fabric, the care label takes priority over everything above, and some things belong with a cleaner rather than in a bucket: heavy thread work or zari, stones glued rather than stitched, a lined piece whose layers would shrink at different rates, and set-in oil, ink or grease.', 'text', 'cotton kurti care, fabric care, washing kurtis, colour bleeding, monsoon storage, dry cleaning', 'Cotton Kurti Care: Wash, Dry, Iron, Store | Dievon', 'A practical cotton kurti care guide: cold-water washing, stopping colour bleed, shade drying, ironing damp, monsoon storage and when to dry clean.', 'Published', '2026-06-14', '2026-08-02 17:23:40'),
('6', 'How to Style a Three-Piece Suit: Dupatta, Shoes, Jewellery', 'how-to-style-a-three-piece-suit', 'Style Guide', 'lookbook_3.png', 'The dupatta does most of the styling work in a three-piece suit; here is how to drape it, and what to wear with it.', 'A three-piece suit is three decisions, and the dupatta makes most of them. The kurta and bottom arrive as a fixed pair; the third piece is what turns the same set into a Tuesday at work or a Saturday reception.\n\n## Match the drape to the neckline\n\nFive drapes are worth knowing, and each one either shows a neckline or hides it.\n\nThe single-shoulder drape — pleated lengthwise, laid over one shoulder, pinned at the shoulder seam, one end falling behind — leaves the whole front of the kurta visible. Use it when the neckline is the point: a V, a sweetheart, a square neck, or an embroidered yoke.\n\nBoth ends hanging in front, stole-style, is the everyday drape. It draws two vertical lines down the body and leaves the centre of the kurta on show, so it suits a round neck, a keyhole, or a tie-up front where you want the tie to read.\n\nThe neck loop — one turn around the neck, ends hanging front or back — is the one people get wrong. It crowds anything already happening at the throat. Keep it away from mandarin and band collars, from high round necks, and from a boat neck, where two horizontal lines end up fighting each other. It belongs on a plain, low round neck and very little else.\n\nCross-body — over one shoulder, diagonally across the back, tucked or pinned at the opposite waist — is the practical one: hands free, nothing slipping, holds through a commute. It covers half the chest, so keep it to simple symmetrical necklines, and run it down the plain side of a worked yoke.\n\nThe belted drape changes the silhouette fastest. Drape over one shoulder, bring both ends down the front, then cinch a thin belt at the natural waist over kurta and dupatta together. On a straight-cut kurta with no waist seam, that is the difference between a column and a shape.\n\n## Let the fabric decide how it falls\n\nYou cannot make a dupatta do what its fabric refuses to do.\n\nOrganza holds air. It stands away from the body and keeps a pleat with no effort, which makes it excellent for shoulder pleats and hopeless for a neck loop. It also shows pin holes, so pin inside the shoulder seam, not through the face of the fabric.\n\nGeorgette is the opposite: light, fluid, sits close to the body. Best for neck loops, cross-body drapes and anything that has to follow a curve. It is also the slipperiest, so one pin at the shoulder is not optional.\n\nCrepe has weight and a matte surface, and hangs straight without flaring — which is what makes the two-ends-front drape look deliberate rather than accidental. Silk behaves similarly but slides more; pleat it and pin it. Linen drapes in planes rather than folds, takes a belt or a cross-body tuck well, and creases the moment you sit down.\n\n## Footwear: the hem is the whole decision\n\nChoose the shoes first, then have the bottom hemmed to them. Half an inch of clearance off the floor with those shoes on is about right; longer drags, shorter reads as an accident.\n\nIf the bottom is wide and full, it needs height under it, usually two inches or more, or the hem sweeps the ground. Nobody sees the shoe under all that fabric, so pick a block heel or wedge and put stability first. A narrow, straight bottom shows the whole shoe, so a pointed toe earns its place: it continues the vertical line the drape already started. With a short kurti and a narrow bottom the ankle shows, and that is where an ankle strap matters — it cuts the leg, so skip it if you want length.\n\nFlat juttis and Kolhapuris carry daytime and daytime festive. For evening, change the finish rather than the shape — the same block heel in a metallic reads differently.\n\n## Jewellery: one focal point, never two\n\nPick one place to put weight and leave everything else quiet. A worked yoke means no necklace, earrings only. A heavily embellished dupatta means small ears. If both kurta and dupatta are plain, that is when a collar necklace or a long chain finally has a job.\n\nThen match the piece to the neckline. High or closed necks take earrings, or a long chain worn over the kurta. A deep V takes a short pendant sitting above the point, not inside it. A boat neck takes a choker or collar necklace following the same horizontal. A round neck takes a chain a couple of inches below it, so the two curves do not overlap.\n\nSleeves decide the wrists: fitted sleeves take two or three thin bangles, flared and bell sleeves take none. Going from day to evening, add metal rather than size — studs become jhumkas, one bangle becomes a stack, the necklace stays as it was. And respect the fabric: heavy pieces drag on georgette, and a pinned brooch will tear organza.\n\n## One suit, office by day and event by night\n\nBuy for the office version — a quiet colour, crepe or georgette, a straight bottom — and let the evening be the upgrade. Not linen, though: it will not survive folded in a bag through a working day.\n\nDay: cross-body, or both ends in front. Studs, flats, and the dupatta off the neck so it is not moving every time you reach for something.\n\nEvening, in about five minutes: re-drape as a pinned single shoulder, add a belt at the waist, swap studs for jhumkas, add bangles, change into the heel. Change the bag too — a tote to a small pouch signals the shift faster than anything you do to the clothes. Carry in jhumkas, a thin belt, one safety pin, and the shoes.\n\n## When you do not want to wear the dupatta\n\nLeaving it at home is legitimate, with two caveats. The dupatta was framing the neck, so the kurta will want a chain or collar necklace to do that job. And check opacity — a fine georgette kurta is often cut assuming a second layer.\n\nOtherwise, put it to work. Fold it lengthwise into a three-inch band and wear it as a sash over a straight kurta. Knot it around the handle of a tote. Keep it folded in your bag as a stole for an over-cooled office, or for covering your head at a temple, and do not wear it in between. Or wear it with another suit entirely — one set\'s dupatta over a contrasting solid kurta is the cheapest way to get three looks out of two.\n\nA dupatta also cuts down neatly into a scarf or a contrast hem panel; hem the raw edges the same day, because georgette and organza fray fast. And store it rolled, not hung — a wire hanger leaves a crease across organza that is very hard to press out.', 'text', 'three piece suits, dupatta styling, kurti styling, jewellery, footwear, office to evening', 'How to Style a Three-Piece Suit | Dievon', 'Dupatta draping for every neckline, plus footwear, jewellery weight and how to take one three-piece suit from an office day to an evening event.', 'Published', '2026-06-21', '2026-08-02 17:23:40'),
('7', 'How to Measure Yourself for Dievon Sizes: A Step-by-Step Guide', 'how-to-measure-yourself-for-dievon-sizes', 'Size & Fit', 'lookbook_1.png', 'A soft tape, a fitted top and five minutes will give you four numbers that let you order any Dievon garment in the right size, first time.', 'You need three things to measure yourself for a Dievon garment: a soft tape measure, a close-fitting top, and, if you can manage it, a second person to hold the other end. That third one matters more than it sounds. Almost every bad measurement comes from twisting your own body to read a number off your own back.\n\nMeasure yourself honestly, in inches, then match your figures against the Dievon size chart further down. A size chart is only as good as the measurements you bring to it.\n\n## Before you start\n\nUse a soft tailor\'s tape, the flexible fabric or plastic kind that follows a curve. A metal builder\'s tape will not sit against your body and reads short across the bust and hips every time. With no tape at all, use a length of string: wrap it, pinch the overlap, mark the spot with a pen, then lay the string flat along a ruler. Pinch firmly and mark precisely, because error creeps in at each step.\n\nWear a close-fitting top and the bra you would normally wear under a kurti. Measuring over a kurti, a dupatta or a heavily padded bra adds an inch or two and quietly pushes you into the wrong row.\n\nStand on a flat floor, feet together, arms relaxed at your sides. Breathe out normally. Do not pull your stomach in, do not lift your chest, do not stand on tiptoe.\n\nKeep everything in inches: the chart is in inches, and converting from centimetres midway through is where rounding errors creep in.\n\n## Where to put the tape\n\nBust. Around the fullest part of the bust, usually level with the nipples, meeting at the front. The tape must stay parallel to the floor the whole way round; it has a habit of riding up across the back, which makes the number smaller than it should be. This is the one a second person helps with most.\n\nWaist. Not where your jeans sit, which is usually lower. Your natural waist is the narrowest part of your torso, generally an inch or so above the navel. To find it without guessing, bend sideways at the middle: the crease that forms is your natural waist. Straighten up before you measure.\n\nHips. The fullest part of the hips and seat, usually seven to nine inches below the natural waist. Keep your feet together, since standing with them apart widens the reading, and check in a mirror that the tape has not dipped at the back.\n\nShoulder. Across the upper back, from the bony point at the outer edge of one shoulder to the same point on the other, letting the tape follow the curve of your back rather than cutting straight through the air.\n\nLength. From the highest point of your shoulder, where a shoulder seam would meet your neck, straight down the front to where you want the hem to sit.\n\n## The mistakes that cost you a size\n\nPulling too tight. The tape should be snug but not compressing anything; you should be able to slide one finger under it. A tight tape can cost you a full inch.\n\nLetting the tape tilt. A tape that rides up two inches at the back reads smaller than the truth.\n\nMeasuring over clothing. Even a thin kurti adds a fraction; a padded bra adds more.\n\nRounding down. If you measure 34.5, write 34.5, not 34. Half inches decide close calls.\n\nMeasuring only once. Take each measurement twice, a few minutes apart, and use the larger figure if they differ.\n\nMeasuring a garment you already own and treating that as a body measurement. It is cut with ease built in, so it reads bigger than you are. Useful for length, misleading for width.\n\n## The Dievon size chart\n\nAll figures in inches.\n\nXS  bust 30, waist 24, hips 33, shoulder 13.5\nS   bust 32, waist 26, hips 35, shoulder 14\nM   bust 34, waist 28, hips 37, shoulder 14.5\nL   bust 36, waist 30, hips 39, shoulder 15\nXL  bust 38, waist 32, hips 41, shoulder 15.5\n2XL bust 40, waist 34, hips 43, shoulder 16\n3XL bust 42, waist 36, hips 45, shoulder 16.5\n4XL bust 44, waist 38, hips 47, shoulder 17\n5XL bust 46, waist 40, hips 49, shoulder 17.5\n\nThe number in brackets beside each size — XS(30), M(34), XL(38) — is simply the bust figure in inches. Once you notice that, the chart is readable at a glance.\n\nNotice too that it moves in even steps. Every size up adds 2 inches to bust, waist and hips, and half an inch to shoulder. So if something feels two inches tight anywhere, you are exactly one size out.\n\n## Choosing your size, and your length\n\nFor kurtis, short kurtis, women\'s tops, 3 piece suits and coord sets, lead with the bust: find the row your bust measurement sits in and start there. For bottoms, lead with waist and hips, and if those land in different rows, take the larger.\n\nBetween two sizes, take the larger. Crepe, georgette, linen, organza and silk are woven rather than knitted, so there is little stretch to borrow from, and half an inch tight across the bust stays half an inch tight.\n\nIf your measurements split across rows, with the bust at M and the hips at XL, decide by the shape of the garment. For anything fitted through the body, buy for your largest measurement and accept that the smaller area sits looser. For a straight or A-line kurti that skims the hip rather than following it, bust and shoulder matter more. Use shoulder as the tiebreaker: it is the hardest thing to alter afterwards, and the most likely to make an otherwise well-sized garment feel wrong.\n\nLength is personal, so build your own reference once. Run the tape from the high point of your shoulder down the front and write down three figures: where it reaches mid-thigh, your knee, and mid-calf. From then on, a length quoted on a product page tells you immediately where the hem will fall on you. Do the same for bottoms, noting your waist-to-ankle length and your inseam, measured from the crotch down the inside of the leg. If length is critical, measure in the footwear you plan to wear; heels move a hem by an inch or more.\n\nAnd if a garment still does not sit the way you expected, Dievon accepts returns within 14 days of delivery, provided the item is unworn, has its tags attached and comes back in its original packaging.', 'text', 'size chart, how to measure, fit guide, kurti sizing, body measurements, size guide', 'How to Measure Yourself for Dievon Sizes | Dievon', 'A step-by-step guide to measuring your bust, waist, hips and shoulder at home in inches, plus the full Dievon size chart and how to read your numbers.', 'Published', '2026-06-28', '2026-08-02 17:23:40'),
('8', 'Work Kurtis: Necklines, Fabrics and a Five-Piece Capsule', 'kurti-styles-for-work', 'Style Guide', 'lookbook_2.png', 'Crepe holds up, linen doesn\'t, and the slit that looks fine standing may not once you sit — how to pick a kurti that lasts a full working day.', 'The most reliable work kurti in Dievon\'s range is crepe, three-quarter sleeved, closed at the neck, knee-length or longer, with side slits starting below the hip. Here is why each of those choices matters after six hours in a chair, and how three kurtis and two bottoms cover a full week.\n\n## Necklines and sleeves that read as professional\n\nA closed round neck is the safest neckline for an office and the most useful, because it is the only one that layers cleanly under everything else. A boat neck is next best: it covers the collarbone, sits flat, and looks considered rather than plain.\n\nA V-neck is fine at work, but judge it seated. A V that sits above the sternum when you stand opens by an inch or more the moment you lean over a laptop.\n\nMandarin and band collars read the most formal. Avoid beading at the neckline: it catches on lanyards, seatbelts and bag straps, and frays first.\n\nThree-quarter sleeves, ending on the lower forearm, are the working length: they clear a desk, a keyboard and a wash basin. Full sleeves are the most formal, but check the cuff, because a wide or flared one sweeps your keyboard all day. Elbow-length suits a hot commute into a cold office. Sleeveless assumes you will layer.\n\nOne fit note outranks all of that. On a structured kurti with a set-in sleeve, the shoulder seam should land on your shoulder point; if it drops onto your upper arm, the garment reads casual whatever the fabric. Measure shoulder point to shoulder point and check it against the chart, which runs from 13.5 inches at XS to 17.5 at 5XL. For soft, draped shapes, let bust decide.\n\n## Which fabrics hold a crease and which surrender\n\nThe useful question is not how a fabric looks at 9am but how it looks at 6pm.\n\nCrepe is the best work fabric Dievon stocks, and it is not close. Its tightly twisted yarns give the surface a fine pebbled texture and a natural spring: creases form less readily, and the texture hides the ones that do. It does not shine under office lighting. If you buy one work kurti, buy crepe.\n\nGeorgette is crepe\'s lighter cousin, made from the same high-twist yarns, so it shares the crease resistance and adds airflow. Two caveats: it is semi-sheer, so it needs something under it, and it snags on velcro, on a rough chair arm, on a ring. A pulled thread in georgette does not go back.\n\nLinen creases the moment you sit and keeps the crease all day; twenty minutes in a chair prints a map of the seat across your lap. That is what linen is, not a flaw, and it is the coolest thing you can wear in an Indian summer. Save it for days without back-to-back meetings.\n\nSilk sits mid-table for creasing but brings two other desk problems: it water-spots, so a splash from a chai glass dries into a visible ring, and it shows perspiration at the underarm far more than crepe. Keep it for air-conditioned days.\n\nOrganza is not a working-day fabric. It is crisp and translucent by design, and the folds it takes are hard-edged and slow to fall out. Use it for a dupatta or a sleeve detail, not for eight hours in a chair.\n\n## Length, slits and the sitting-down test\n\nKnee-length and below is the standard for work, and it is the length that lets a slit behave. Short kurtis sit at the hip or just under, still fine provided the bottom carries the formality: something straight and tailored rather than a legging.\n\nSlits let you take a full stride and stop a straight hem restricting you on stairs, but the mistake is judging one standing up. When you sit, the hem rides up and the slit opens several inches higher than where it was cut, so sit, cross your legs, and look. A side slit beginning at mid-thigh or lower stays put; a front or centre slit is the one most likely to part.\n\nCommuting shifts the answer. Side slits are safer on a two-wheeler or in an auto, and a long unslit hem is the one that catches. Keep your fullest bottoms for days you are not on a scooter.\n\nTwo smaller things. Fine crepe and light georgette cling in dry air-conditioning; a plain slip fixes that and the sheerness at once. And a pale, smooth fabric takes the imprint of a mesh chair back where a textured crepe will not.\n\n## Layering for a cold office and a hot street\n\nThe dupatta from a three-piece suit is the most useful layer you own, and it does not have to be worn wide. Folded lengthwise into a narrow stole over one shoulder, it adds formality without bulk and comes off in ten seconds outside. A crepe or silk dupatta takes the edge off hard air-conditioning; organza is decorative rather than warm.\n\nA fitted top under a sleeveless or open-necked kurti solves coverage and cold together, and beats sizing up. Coord sets are the decision-free option for mornings when you have no decisions left.\n\n## Building a five-piece work capsule\n\nFive pieces, three kurtis and two bottoms, make six combinations: a working week with one spare.\n\nOne crepe kurti in a plain neutral, closed round or boat neck, three-quarter sleeve; this is the one you will reach for most. A second crepe kurti in another neutral or a small, quiet print, cut the same way, so colour makes it a different outfit. One georgette kurti for the hottest weeks and the days you are out of the office more than in it.\n\nThen two bottoms: one narrow and straight, the more formal of the pair and the better one for commuting, and one fuller, for heat and long sitting days. Both must work with all three kurtis, or you own five pieces that make two outfits.\n\nThree rules keep it honest: no more than one print among the five, both bottoms plain, and buy the neutrals first and the print last, because the print is what you tire of. If your week includes a formal or client-facing day, or an office Diwali, a three-piece suit or a coord set is the sixth piece, not a substitute for any of the five.\n\nOne note on ordering: standard delivery is ₹99 and free over ₹2,000, so one basket beats five. And since half of this has to be judged sitting down, use the 14-day return window, unworn with tags attached in the original packaging, to try each piece in a chair first.', 'text', 'kurtis, workwear, crepe, fabric guide, capsule wardrobe, size and fit', 'Work Kurti Guide: Fabric, Fit, Capsule | Dievon', 'Which necklines, sleeve lengths and fabrics work for a full office day, how slits behave when you sit, and a five-piece kurti capsule for the week.', 'Published', '2026-07-05', '2026-08-02 17:23:40'),
('9', 'What to Wear to Every Indian Wedding Event, and Diwali', 'what-to-wear-indian-wedding-events-and-diwali', 'Occasion Edit', 'lookbook_3.png', 'Mehendi and haldi get messy, sangeet is the one you dance in, the ceremony is the longest sit — here is how to dress for each, and re-wear it afterwards.', 'Mehendi and haldi are daytime events where your clothes will get marked. Sangeet is the one you have to dance in. The ceremony is the longest sit and the most covered. The reception is the only event of the week where you can dress the way you would for a night out. Almost every other decision — fabric, cut, how much you spend — follows from those four facts.\n\n## How formal each event actually is\n\nMehendi is the least formal: usually daytime, often at home or in a garden, and you will be sitting still with your hands held out for an hour or more, unable to adjust anything. Sleeves that need pushing up are a genuine problem. So is a bottom you have to gather in one hand to walk.\n\nHaldi is less formal still, and considerably messier. Turmeric stains, and it holds hardest on protein fibres — silk above all. Wear something you would not mind losing, and note that a turmeric mark shows far less on yellow than on anything else.\n\nSangeet is where formality jumps. Evening, dancing, and you will be photographed from every angle by people holding phones at chest height. If you are spending on one outfit for the week, spend it here.\n\nThe ceremony is usually the earliest start and the longest sit, and the most conservative: more coverage, quieter colour. It often means sitting cross-legged on the floor for a long stretch, sometimes outdoors, sometimes beside a fire that is warmer than it looks in the photographs.\n\nThe reception is the most straightforward of the five: indoors, evening, a seated dinner and a standing greeting line. Formal, but not ritual.\n\n## Which fabric suits which event\n\nThe Dievon range runs to five fabrics, and they behave differently enough that fabric matters more than category.\n\nGeorgette drapes and moves. Its slight surface texture hides creases, which makes it the most forgiving thing to sit in for hours and the easiest to dance in. Mehendi and sangeet.\n\nCrepe is matte, has some weight, and holds its shape instead of clinging. It creases less than you expect and drops out when you stand. It is the most reliable travelling fabric of the five: good for the ceremony, and good for anything you are putting on straight off a train.\n\nOrganza is sheer and structured. It holds a shape away from the body and catches light, which is why it photographs well in the evening. It is also the least forgiving: it snags, it does not enjoy three hours crushed under a seatbelt, and being sheer it needs something under it. Reception, or the later half of a sangeet.\n\nSilk reads formal immediately and carries colour with more depth than anything else here. It is warm, which is worth remembering for an outdoor daytime ceremony, and it marks with water. Never haldi.\n\nLinen creases the moment you sit down and stays creased. That is fine for a relaxed daytime mehendi at somebody\'s house. It is the wrong choice for an evening spent getting up out of a chair in front of a camera.\n\n## Matching the category to the event\n\nThe catalogue runs to kurtis, including short kurtis, 3 piece suits, coord sets, tops and bottoms. Each has an event it is obviously right for.\n\nA 3 piece suit is the least risky answer to the ceremony. The pieces are picked to work together, so there is nothing to decide on the morning of an early start, and a set covers more than a top and bottom chosen separately.\n\nCoord sets earn their keep across a whole wedding week, because they are the one thing you can split. Worn together they are an outfit; worn apart they are two more.\n\nFor sangeet, a short kurti with a fitted bottom is the practical choice — a shorter hem does not get trodden on when other people are dancing around you. For the ceremony and for daytime, a full-length kurti covers more and creases less across the lap.\n\nTops and bottoms bought separately are the cheapest way to get several looks out of one order. A georgette or organza top over a plain bottom is a reception outfit; the same bottom under a plain top is not.\n\n## Getting the fit right for a day you cannot change out of\n\nThe size chart is body measurements in inches, not garment measurements, so measure yourself and read across. M is bust 34, waist 28, hips 37, shoulder 14.5. XL is bust 38, waist 32, hips 41, shoulder 15.5. The range runs XS (30) to 5XL (46).\n\nTwo things go wrong on wedding days. The first is the waist: it is larger seated than standing, so if you are between sizes on a fitted bottom you will be happier in the larger one after four hours in a chair. If you expect to sit cross-legged on the floor, measure your hips at their widest while actually sitting that way.\n\nThe second is the shoulder, the number nearly everyone skips. It runs 13.5 inches at XS to 17.5 at 5XL, and it decides whether you can lift your arms above your head. If you are going to dance, or hold a thali, check it.\n\n## Diwali, and wearing it all again\n\nDiwali asks for less than a wedding and more of your day. It is several days rather than one, it moves between houses, and a good part of it happens in a kitchen or beside lit diyas. That argues for crepe and georgette — they take a full day, they do not announce every crease, and they are not distracting to cook or serve in. Organza is sheer and floats, so keep it well away from open flames.\n\nAfterwards, the trick to re-wearing a festive piece is to break up the set rather than the whole look. Wear the festive half against something plain: a coord set top with everyday bottoms, an organza or silk top with a crepe bottom for a formal day at work, a wedding kurti with plain trousers. Anything that read as \"occasion\" only because both halves matched will quietly stop doing so.\n\nOne piece of timing. Orders placed before 2pm IST are dispatched the same business day, and delivery within India takes 3 to 5 business days, so order a clear week ahead of the first event — two if you want to try more than one size. Delivery is ₹99, and free over ₹2,000; with the range running ₹95 to ₹420, that threshold takes five pieces even at the top of it, so assume you are paying the ₹99. Returns are accepted within 14 days of delivery, unworn, with tags attached and in the original packaging, so try everything on the day it arrives and leave the tags on. UPI, cards and Cash on Delivery are all accepted domestically.', 'text', 'wedding, festive, occasion dressing, diwali, georgette, organza', 'Indian Wedding Outfit Guide by Event | Dievon', 'A practical guide to dressing for mehendi, haldi, sangeet, the ceremony, reception and Diwali — which fabrics sit well, and how to re-wear each piece.', 'Published', '2026-07-12', '2026-08-02 17:23:40'),
('10', 'Crepe, Georgette, Linen, Organza, Silk: What to Wear When', 'crepe-georgette-linen-organza-silk-season-guide', 'Fabric & Care', 'lookbook_1.png', 'Linen in June, crepe and georgette through the monsoon, silk in December — how each of our five fabrics handles heat, damp, drape and creasing.', 'Buy linen in June, crepe or georgette in August, and silk in December. That is the short answer. The longer one matters because each of these five fabrics fails in a specific, predictable way, and choosing well is really choosing which failure you can live with that month.\n\n## Two of these are fibres, three are weaves\n\nLinen and silk are fibres: linen spun from flax, silk reeled from the cocoon, each behaving the way its raw material behaves however it is woven. Crepe, georgette and organza are constructions. Crepe is any fabric given a pebbled, matte surface by tightly twisted yarn. Georgette is a lighter, grainier, semi-sheer relative of the same idea. Organza is a plain weave held so open and so tightly spun that it stands up by itself.\n\nCarry that into every product page you open: a weave name tells you how a piece will hang and crease, not how it will breathe. Drape you can read from a photograph; breathability you cannot.\n\nSo judge on four questions instead. Does air move through it? Does moisture move through it? How does it fall? How does it crease?\n\n## Crepe: creases least, breathes least\n\nCrepe falls close to the body in soft vertical folds — weight without bulk. It skims rather than stands away, which is why straight kurtis, coord sets and bottoms in crepe hang cleanly instead of ballooning.\n\nIts great virtue is the absence of creasing. The pebbled surface breaks up fold lines, so crepe comes out of a packed bag settled rather than crushed, and survives a commute, a scooter ride and a full day at a desk.\n\nPeak summer is its weak point. The weave is dense and slows airflow, so on a long day outdoors it holds heat, and underarm marks show plainly on mid-tone solids. Prints and deep shades hide them. In an air-conditioned office in June, crepe is comfortable.\n\nMonsoon is where it earns its keep. It takes on little water, shrugs off a light shower without spotting, and dries indoors overnight when nothing else in the house will. In a north Indian winter it is the best base layer here: it cuts wind, layers without bulk, and does not build the static charge georgette does.\n\n## Georgette: light enough to stay off your skin\n\nGeorgette moves. It flares rather than clings, and the twisted yarn gives it a spring that keeps lifting it away from the body. That gap between cloth and skin is worth more in an Indian summer than any breathability claim: still air trapped against you is what makes heat unbearable. It is semi-sheer, and that is the point: air passes straight through. Check whether a piece is lined, and keep a plain slip for the ones that are not.\n\nThrough the monsoon it shares crepe\'s advantages — fast drying, no water spots, a grainy surface that hides damp patches. In a dry north Indian December it develops one irritating habit: static. It will cling to leggings, and a slip underneath solves it. Creasing is barely a concern. Snagging is — keep it away from rings, bag zips and velcro.\n\n## Linen: the real summer fabric, and it will crease\n\nLinen is the only fabric here that manages heat by moving moisture, not just air. Flax absorbs sweat and releases it to the air, and its thick, irregular yarn holds the cloth off the skin instead of sticking to it. In dry, high-summer heat, nothing else comes close.\n\nIt drapes stiffly. An A-line kurti in linen stands away from the body rather than falling against it, and it softens with every wash rather than every wear.\n\nIt will crease, and not conditionally. Twenty minutes seated puts lines across your lap; a seatbelt leaves a diagonal. Either you read those creases as texture or you buy something else — there is no third option. Steam a fresh crease rather than pressing it; a steamy bathroom works if you have no steamer.\n\nMonsoon is the season to leave linen in the cupboard. It pulls damp out of the air, feels clammy once humidity is high, is slow to dry, and if left crumpled and wet it will mildew and keep the smell. A north Indian December is too cold for linen on its own, though it works layered in October and February — and in Mumbai, Chennai or Bengaluru, where December is a mild summer, it works all year.\n\n## Organza and silk: opposite weaknesses\n\nOrganza is structural, not comfortable. Air goes straight through it, but it absorbs nothing, so in real heat sweat has nowhere to go, and the crisp, wiry hand can scratch at the seams. It does not drape; it holds. That is what you want from a sleeve, an overlay or a dupatta that stands away from the body instead of collapsing onto it — which is why organza belongs to party, wedding and Diwali dressing, not to a Tuesday. Its creases set as sharp folds that do not fall out on their own, so store it rolled or hanging, never folded under a stack, and press on low heat with a cloth between iron and fabric. Rain is its worst enemy of the five: water spots it, and the surface snags when damp.\n\nSilk works at both ends of the year. As a protein fibre it insulates in cold air yet still feels cool against skin in heat, because it takes up and releases moisture. Its limit is not temperature but sweat and water: perspiration and deodorant leave marks that do not come out, and a single raindrop dries to a visible ring. It drapes with weight and slip, and creases only moderately — those creases usually hang out overnight. So: silk for an evening event in June, never for a day outdoors, and not at all in the monsoon. Let deodorant dry completely before you dress, and follow the care label.\n\n## What to buy in June, August and December\n\nIn June, linen for daytime and georgette for everything else — loose over fitted, always. Light colours reflect heat but show sweat; prints hide it. Keep silk for the evening.\n\nIn August, crepe and georgette in darker shades or prints, and nothing you would mind getting splashed. Linen, silk and organza stay in the cupboard.\n\nOctober and November do you a favour: no rain, cool mornings. Organza and silk season, conveniently also festive season.\n\nDecember to February in the north: crepe, and georgette layered over a warm inner, with silk for anything dressy and organza only as an outer layer. Further south, treat December as a mild June.\n\nHand-feel is the one thing a screen cannot give you, so open the parcel and try the piece indoors before the tags come off: returns are 14 days from delivery, unworn, with tags attached and in the original packaging.', 'text', 'fabric guide, seasonal dressing, summer fabrics, monsoon dressing, winter dressing, fabric care', 'Best Fabric for Each Indian Season | Dievon', 'How crepe, georgette, linen, organza and silk behave in Indian summer, monsoon damp and north Indian winter, and which one to buy in June or December.', 'Published', '2026-07-19', '2026-08-02 17:23:40'),
('11', 'How to Pack and Store Ethnic Wear Without Creasing It', 'how-to-pack-and-store-ethnic-wear-without-creasing-it', 'Fabric & Care', 'lookbook_2.png', 'Fold what holds its shape, roll what drapes — plus rescuing a creased dupatta with no iron, and keeping silk safe through the monsoon.', 'Fold what holds its shape, roll what drapes, and turn anything embroidered inside out before it goes near a suitcase. Most of what goes wrong with ethnic wear in transit comes down to not knowing which of your clothes belongs in which group.\n\n## Fold or roll, fabric by fabric\n\nRolling does not prevent creases. It replaces sharp fold lines with soft, rounded ones, and soft ones drop out on their own. Fold when you want to protect structure or surface work; roll when you want drape to recover. Rolls go in the base of the case, folded pieces flat on top.\n\nCrepe rolls well and forgives most things. Its crimped weave springs back, so a crepe kurti usually needs nothing more than ten minutes on a hanger at the other end. One caution: crepe holds a water mark, so do not spot-clean it with a wet cloth and expect it to dry invisibly.\n\nGeorgette rolls too, loosely. It has enough natural crinkle that fold lines rarely read as creases. Its real risk is snagging, not wrinkling: keep it away from zip teeth, luggage straps and anything embroidered in the same bag.\n\nLinen will crease; the job is to minimise it. Roll it firmly enough that there are no slack folds inside, and unpack it the moment you arrive — linen creases set harder the longer they sit under weight. This is the fabric to travel in rather than pack.\n\nOrganza needs the most care. It is stiff and sheer, and a hard crease in it can be close to permanent. Never roll it tight, never fold it into quarters. Lay it flat with plain unprinted paper between layers, as the top item in the case. If you must fold, fold once over a rolled towel so the fold takes a curve rather than an edge.\n\nSilk should be folded, not rolled: rolling drags silk across itself and can leave faint diagonal lines. Fold along the existing seams, with tissue in each fold. Apply perfume and deodorant before you put silk on, never after — alcohol marks it, and the mark shows up days later, not immediately.\n\n## The dupatta is the hard part\n\nA dupatta has the largest surface area and the least structure of anything in a 3 Piece Suit, which is why it comes out of the case looking worst.\n\nThe best method is to fold it in three lengthwise into a long band, then roll that band around a cardboard tube or a rolled hand towel. No fold lines run across the width, which is exactly where creases show when it is draped.\n\nSecond best: once the case is packed, open the dupatta out completely, spread it flat over everything and shut the lid. Nothing sits on it and nothing folds it.\n\nEither way, pack the dupatta apart from the kurti. You will want to steam it separately, and digging it out of a folded set undoes the folding.\n\n## Creases, and no iron in sight\n\nHang the garment in the bathroom, run the shower as hot as it goes for ten minutes with the door shut, then leave it in there another fifteen, out of the spray. That handles crepe, georgette and silk. It improves linen without fixing it.\n\nIf that is not enough, mist the creased area with plain water until it is damp rather than wet, tug the fabric along the grain, and hang it. Most of the crease goes as it dries.\n\nA kettle works as a hand steamer: hold the fabric about thirty centimetres from the spout, keep it moving, never let it touch hot metal. Do not do this to organza, sequins or metallic thread — heat and moisture soften adhesive and dull coatings.\n\nGravity does more than people expect: a creased kurti hung overnight on a wide hanger, with the hem lightly weighted, is usually wearable by morning.\n\n## Embroidery, sequins and anything that catches\n\nInside out, always. Sequin edges and embroidery threads catch on whatever they touch, and what they damage is usually not themselves but the georgette or organza packed beside them.\n\nFold either side of a motif, never across it. A fold that runs through raised work crushes it and leaves a line no amount of steam will lift. Put embroidered pieces at the top of the stack so nothing presses on them for the whole journey.\n\nSteam heavy work from the reverse. If you have to press, lay a plain cotton cloth over the area and work around the motif, not over it.\n\n## Putting it away between seasons\n\nClean everything before it goes into storage. Sweat, perfume and sugar leave marks you cannot see now that oxidise brown over a few months, and they are what draws insects. Make sure each piece is bone dry.\n\nStore in cotton or muslin bags, never polythene. Plastic traps moisture, yellows silk over time and gives mildew somewhere to start. Put plain unprinted paper in each fold, and refold everything a different way every few months so no single crease line has time to set — silk and organza most of all.\n\nStore heavy pieces flat rather than hanging. Months of dense embroidery pulling on a shoulder will distort it. Keep the shelf out of direct light, since dye fades fastest along the ridge of a fold.\n\n## Monsoon: damp, mildew and silverfish\n\nSilverfish are after starch, sizing and paper more than cloth itself, so starched garments are the ones most likely to be eaten. Rinse starch out before storing anything for the season.\n\nKeep the pile off the floor and away from the outside wall, where damp collects, and leave a finger\'s width between garments — a crammed cupboard holds moisture. Save the silica gel sachets from packaging, dry them in the sun for an hour, and tuck them into a corner of the shelf rather than against the cloth. Dried neem leaves or whole cloves in a small muslin pouch work as a deterrent; if you use camphor, wrap it and keep it off the fabric, especially silk.\n\nOn the first properly dry day, take everything out and air it in the shade for two or three hours. Direct sun fades dye and weakens silk. Check once a month through the season, and if anything smells musty, air it straight away instead of folding it back. Mildew does not resolve on its own.\n\nOne note on timing: order well ahead of the trip, not the week of it. Orders placed before 2pm IST are dispatched the same business day and take three to five business days within India, which leaves you time to try things on at home while the tags are still attached. Returns run fourteen days from delivery, unworn, tags attached, original packaging — so check the fit before you pack, not at the destination.', 'text', 'fabric care, packing, travel, storage, monsoon care, dupatta', 'How to Pack and Store Ethnic Wear | Dievon', 'Fold or roll? What to do about a creased dupatta with no iron, how to protect sequins in transit, and how to store silk and organza between seasons.', 'Published', '2026-07-26', '2026-08-02 17:23:40');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `brands`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `brands`;
-- @DIEVON_STMT_END
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `categories`;
-- @DIEVON_STMT_END
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `parent_id` int(11) DEFAULT '0',
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_title` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` varchar(320) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intro` text COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_alt` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` enum('women','men','unisex') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'women',
  `default_hsn_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_gst_rate` decimal(5,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `categories` (`id`, `name`, `sort_order`, `created_at`, `parent_id`, `slug`, `meta_title`, `meta_description`, `intro`, `image`, `image_alt`, `gender`, `default_hsn_code`, `default_gst_rate`) VALUES
('1', 'Kurtis', '1', '2026-07-18 23:55:43', '0', 'kurtis', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('2', '3 Piece Suits', '2', '2026-07-18 23:55:43', '0', '3-piece-suits', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('3', 'Coord Sets', '3', '2026-07-18 23:55:43', '0', 'coord-sets', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('4', 'Short Kurtis', '4', '2026-07-18 23:55:43', '1', 'short-kurtis', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('5', 'Women\'s Tops', '5', '2026-07-18 23:55:43', '0', 'womens-tops', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('6', 'Bottoms', '6', '2026-07-18 23:55:43', '0', 'bottoms', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('7', 'Long Kurtis', '2', '2026-07-27 00:11:12', '1', 'long-kurtis', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('8', 'Anarkali Sets', '3', '2026-07-27 00:11:12', '1', 'anarkali-sets', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('9', 'Sharara & Gharara Sets', '4', '2026-07-27 00:11:12', '1', 'sharara-gharara-sets', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('10', 'Straight Cut Kurtis', '5', '2026-07-27 00:11:12', '1', 'straight-cut-kurtis', NULL, NULL, NULL, NULL, NULL, 'women', NULL, '0.00'),
('17', 'Shirts', '1', '2026-08-03 17:57:44', '0', 'mens-shirts', NULL, NULL, NULL, NULL, NULL, 'men', NULL, '0.00'),
('18', 'Trousers', '2', '2026-08-03 17:57:44', '0', 'mens-trousers', NULL, NULL, NULL, NULL, NULL, 'men', NULL, '0.00');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `category_slug_history`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `category_slug_history`;
-- @DIEVON_STMT_END
CREATE TABLE `category_slug_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `old_slug` varchar(120) NOT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_old_slug` (`old_slug`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customer_addresses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_addresses`;
-- @DIEVON_STMT_END
CREATE TABLE `customer_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `address_type` enum('shipping','billing','both') DEFAULT 'shipping',
  `full_name` varchar(191) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `postcode` varchar(20) NOT NULL,
  `country` varchar(100) DEFAULT 'India',
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customer_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_logs`;
-- @DIEVON_STMT_END
CREATE TABLE `customer_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customer_remember_tokens`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_remember_tokens`;
-- @DIEVON_STMT_END
CREATE TABLE `customer_remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `selector` char(32) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_remember_selector` (`selector`),
  KEY `idx_remember_customer` (`customer_id`),
  KEY `idx_remember_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customer_returns`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_returns`;
-- @DIEVON_STMT_END
CREATE TABLE `customer_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_code` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `request_type` enum('return','exchange') NOT NULL,
  `reason` varchar(255) NOT NULL,
  `exchange_size` varchar(50) DEFAULT NULL,
  `details` text,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Pickup Scheduled','Quality Check','Completed') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_code` (`return_code`),
  KEY `customer_id` (`customer_id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customer_tickets`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_tickets`;
-- @DIEVON_STMT_END
CREATE TABLE `customer_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
  `admin_reply` text,
  `replied_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_code` (`ticket_code`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `customers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customers`;
-- @DIEVON_STMT_END
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email_verified_at` datetime DEFAULT NULL,
  `email_verify_token` varchar(64) DEFAULT NULL,
  `email_verify_token_expires` datetime DEFAULT NULL,
  `email_verify_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `email_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `email_logs`;
-- @DIEVON_STMT_END
CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_type` varchar(50) NOT NULL,
  `recipient` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text,
  `test_mode` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_type` (`email_type`),
  KEY `recipient` (`recipient`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `gallery`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `gallery`;
-- @DIEVON_STMT_END
CREATE TABLE `gallery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `gallery` (`id`, `filename`, `caption`, `sort_order`, `created_at`) VALUES
('1', 'lookbook_1.png', 'The Autumn Silk & Wool Collection Edit', '1', '2026-07-18 23:55:43'),
('2', 'lookbook_2.png', 'Bespoke Handcrafted Leather Goods Campaign', '2', '2026-07-18 23:55:43'),
('3', 'lookbook_3.png', 'Tuscan Atelier Behind-the-Scenes Chronicles', '3', '2026-07-18 23:55:43');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `homepage_sections`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `homepage_sections`;
-- @DIEVON_STMT_END
CREATE TABLE `homepage_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_key` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `eyebrow` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `sort_order` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES
('1', 'hero_slider', 'Hero Banner Slider', 'Volume Collections', '1', '1'),
('2', 'collections', 'Dievon Collections', 'Curated Ensembles', '1', '2'),
('3', 'new_arrivals', 'New Arrivals', 'Latest Creations', '1', '3'),
('4', 'best_sellers', 'Best Sellers & Trending', 'Curated Favorites', '1', '4'),
('5', 'editorial_story', 'The Dievon Story', 'Craftsmanship & Heritage', '1', '5'),
('6', 'reviews', 'Customer Reviews', 'Verified Ratings & Feedback', '1', '6'),
('7', 'instagram', '@DievonOfficial Instagram', 'Follow Our Atelier', '1', '7'),
('8', 'newsletter', 'VIP Atelier Newsletter', 'Exclusive Offers & Releases', '1', '8');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `inquiries`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `inquiries`;
-- @DIEVON_STMT_END
CREATE TABLE `inquiries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `invoice_sequences`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `invoice_sequences`;
-- @DIEVON_STMT_END
CREATE TABLE `invoice_sequences` (
  `financial_year` varchar(9) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `invoice_sequences` (`financial_year`, `last_number`) VALUES
('2026-27', '2');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `login_attempts`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `login_attempts`;
-- @DIEVON_STMT_END
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(191) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_id_time` (`identifier`,`attempted_at`),
  KEY `idx_attempt_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `mega_menu_links`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `mega_menu_links`;
-- @DIEVON_STMT_END
CREATE TABLE `mega_menu_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `column_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `mega_menu_links` (`id`, `column_name`, `link_name`, `link_url`, `sort_order`, `created_at`) VALUES
('1', 'Occasions', 'Wedding Guest Edit', 'shop.php?category=3+Piece+Suits', '1', '2026-07-19 00:06:07'),
('2', 'Occasions', 'Office Sophistication', 'shop.php?category=Coord+Sets', '2', '2026-07-19 00:06:07'),
('3', 'Occasions', 'Private Party Gowns', 'shop.php?category=Kurtis', '3', '2026-07-19 00:06:07'),
('4', 'Occasions', 'Festive Accents', 'shop.php?category=Short+Kurtis', '4', '2026-07-19 00:06:07'),
('5', 'Journal & Story', 'Our Heritage', 'about.php', '1', '2026-07-19 00:06:07'),
('6', 'Journal & Story', 'Lookbooks', 'gallery.php', '2', '2026-07-19 00:06:07'),
('7', 'Journal & Story', 'Maison Journal', 'blog.php', '3', '2026-07-19 00:06:07'),
('8', 'Journal & Story', 'Private Fittings', 'contact.php', '4', '2026-07-19 00:06:07');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `newsletter_subscribers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `newsletter_subscribers`;
-- @DIEVON_STMT_END
CREATE TABLE `newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `newsletter_subscribers` (`id`, `email`, `created_at`) VALUES
('2', 'princevir2610@gmail.com', '2026-08-02 00:20:35');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `order_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `order_items`;
-- @DIEVON_STMT_END
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `variant_name` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT '1',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_item_order_id` (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `order_refunds`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `order_refunds`;
-- @DIEVON_STMT_END
CREATE TABLE `order_refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `order_code` varchar(30) NOT NULL DEFAULT '',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `method` varchar(20) NOT NULL DEFAULT 'manual',
  `payout_channel` varchar(20) DEFAULT NULL,
  `razorpay_payment_id` varchar(100) DEFAULT NULL,
  `razorpay_refund_id` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_by` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_refunds_order` (`order_id`),
  KEY `idx_order_refunds_rzp` (`razorpay_refund_id`),
  KEY `idx_order_refunds_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `orders`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `orders`;
-- @DIEVON_STMT_END
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `postcode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `items_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `refunded_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `delivered_at` datetime DEFAULT NULL,
  `last_notified_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `carrier` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `stock_deducted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `promo_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_charge` decimal(6,2) NOT NULL DEFAULT '0.00',
  `cod_fee` decimal(6,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `currency_symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '₹',
  `payment_method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'later',
  `payment_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unpaid',
  `razorpay_payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `review_requested_at` datetime DEFAULT NULL,
  `remitted_at` datetime DEFAULT NULL,
  `remitted_amount` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`),
  UNIQUE KEY `uq_order_code` (`order_code`),
  KEY `idx_orders_refund_watch` (`payment_status`,`status`),
  KEY `idx_orders_is_deleted` (`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `password_resets`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `password_resets`;
-- @DIEVON_STMT_END
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_attributes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_attributes`;
-- @DIEVON_STMT_END
CREATE TABLE `product_attributes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attr_type` enum('color','size') NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `product_attributes` (`id`, `attr_type`, `name`, `code`, `created_at`) VALUES
('3', 'color', 'red', '#541c1c', '2026-07-29 20:34:23'),
('4', 'color', 'red', '#541c1c', '2026-07-29 21:08:36'),
('5', 'color', 'Rose Petal', 'rose-petal', '2026-08-03 13:47:08'),
('6', 'color', 'Champagne', 'champagne', '2026-08-03 13:47:08'),
('7', 'color', 'Emerald Green', 'emerald-green', '2026-08-03 13:47:08'),
('8', 'color', 'Olive Silk', 'olive-silk', '2026-08-03 13:47:08'),
('9', 'color', 'Natural Sand', 'natural-sand', '2026-08-03 13:47:08'),
('10', 'color', 'Indigo Blue', 'indigo-blue', '2026-08-03 13:47:08'),
('11', 'color', 'Lilac', 'lilac', '2026-08-03 13:47:08'),
('12', 'color', 'Natural Olive', 'natural-olive', '2026-08-03 13:47:08'),
('13', 'color', 'Midnight Black', 'midnight-black', '2026-08-03 13:47:08'),
('14', 'color', 'Bone Ivory', 'bone-ivory', '2026-08-03 13:47:08'),
('15', 'size', 'XS', 'xs', '2026-08-03 13:47:08'),
('16', 'size', 'S', 's', '2026-08-03 13:47:08'),
('17', 'size', 'M', 'm', '2026-08-03 13:47:08'),
('18', 'size', 'L', 'l', '2026-08-03 13:47:08'),
('19', 'size', 'XL', 'xl', '2026-08-03 13:47:08');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_color_images`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_color_images`;
-- @DIEVON_STMT_END
CREATE TABLE `product_color_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `color_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_color_id` (`color_id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `product_color_images` (`id`, `color_id`, `image`, `sort_order`, `created_at`) VALUES
('8', '7', 'color_gallery_1785496841_995eea70.jpg', '1', '2026-07-31 12:20:43'),
('9', '7', 'color_gallery_1785496848_bbb698ed.jpeg', '2', '2026-07-31 12:20:49'),
('10', '7', 'color_gallery_1785496854_474e4983.png', '3', '2026-07-31 12:20:56'),
('12', '4', 'dievon_silk_dress.png', '0', '2026-07-31 12:21:54'),
('19', '11', 'prince-1_667dc3ea.jpg', '1', '2026-08-05 13:36:26'),
('20', '11', 'prince-2_cefd1b7f.jpg', '2', '2026-08-05 13:36:26'),
('21', '12', 'color_gallery_1786032147_7d8e9592.jpg', '1', '2026-08-06 17:02:29'),
('22', '12', 'color_gallery_1786032153_7eeb7720.jpg', '2', '2026-08-06 17:02:34'),
('23', '15', 'color_gallery_1786037552_261f658d.jpg', '1', '2026-08-06 18:32:33'),
('24', '15', 'color_gallery_1786037557_5d8aefc5.jpg', '2', '2026-08-06 18:32:39'),
('25', '15', 'color_gallery_1786037564_ccaed8ca.jpg', '3', '2026-08-06 18:32:47'),
('26', '15', 'test-1_bdcbeb9f.jpg', '4', '2026-08-06 18:51:02'),
('27', '15', 'test-2_42d5e401.png', '5', '2026-08-06 18:51:02'),
('28', '15', 'test-3_57afc9ae.jpg', '6', '2026-08-06 18:51:02'),
('29', '15', 'test-4_7d36ad57.jpeg', '7', '2026-08-06 18:51:02');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_colors`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_colors`;
-- @DIEVON_STMT_END
CREATE TABLE `product_colors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `color_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_override` decimal(10,2) DEFAULT NULL,
  `mrp_price_override` decimal(10,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `product_colors` (`id`, `product_id`, `color_name`, `sku`, `thumbnail`, `price_override`, `mrp_price_override`, `sort_order`, `is_active`, `created_at`) VALUES
('4', '1', 'Ivory Gold', NULL, 'dievon_silk_dress.png', NULL, NULL, '1', '1', '2026-07-29 20:17:34'),
('7', '1', 'pink', '90', 'pink-thumb_1785496833_acf3240c.jpg', '89.00', NULL, '2', '1', '2026-07-31 12:20:25'),
('11', '13', 'Blue', NULL, 'prince-1_667dc3ea.jpg', NULL, NULL, '1', '1', '2026-08-05 08:52:29'),
('12', '12', 'Bone Ivory', '78', NULL, '889.00', NULL, '1', '1', '2026-08-06 17:02:03'),
('15', '15', 'Olive Silk', '1000', 'test-1_bdcbeb9f.jpg', '77.00', '54.00', '1', '1', '2026-08-06 18:32:15');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_component_specifications`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_component_specifications`;
-- @DIEVON_STMT_END
CREATE TABLE `product_component_specifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `component_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `label` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_component_id` (`component_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `product_component_specifications` (`id`, `component_id`, `product_id`, `label`, `value`, `unit`, `sort_order`, `created_at`, `updated_at`) VALUES
('1', '1', '1', 'Length', '42', 'inch', '1', '2026-07-30 08:27:23', '2026-07-30 08:27:23'),
('2', '1', '1', 'Sleeve', 'Half Sleeve', NULL, '2', '2026-07-30 08:27:23', '2026-07-30 08:27:23'),
('3', '2', '1', 'Fabric', 'Cotton Blend', NULL, '1', '2026-07-30 08:27:24', '2026-07-30 08:27:24'),
('4', '2', '1', 'Fit', 'Straight', NULL, '2', '2026-07-30 08:27:24', '2026-07-30 08:27:24');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_components`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_components`;
-- @DIEVON_STMT_END
CREATE TABLE `product_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `product_components` (`id`, `product_id`, `name`, `sort_order`, `created_at`, `updated_at`) VALUES
('1', '1', 'Top', '1', '2026-07-30 08:27:02', '2026-07-30 08:27:02'),
('2', '1', 'Bottom Wear', '2', '2026-07-30 08:27:24', '2026-07-30 08:38:41');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_country_prices`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_country_prices`;
-- @DIEVON_STMT_END
CREATE TABLE `product_country_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `country_code` char(2) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_country` (`product_id`,`country_code`),
  KEY `idx_pcp_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_images`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_images`;
-- @DIEVON_STMT_END
CREATE TABLE `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int(11) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_questions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_questions`;
-- @DIEVON_STMT_END
CREATE TABLE `product_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `asker_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `question` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_questions_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_reviews`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_reviews`;
-- @DIEVON_STMT_END
CREATE TABLE `product_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reviewer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` int(11) NOT NULL,
  `review_text` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `customer_id` int(11) DEFAULT NULL,
  `author_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `is_verified_purchase` tinyint(1) NOT NULL DEFAULT '0',
  `order_id` int(11) DEFAULT NULL,
  `reported_count` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_review_customer_product` (`customer_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_specifications`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_specifications`;
-- @DIEVON_STMT_END
CREATE TABLE `product_specifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `label` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `product_specifications` (`id`, `product_id`, `label`, `value`, `unit`, `sort_order`, `created_at`, `updated_at`) VALUES
('1', '1', 'Fabric', 'Pure Georgette', NULL, '1', '2026-07-30 08:27:02', '2026-07-30 08:33:00'),
('2', '1', 'Occasion', 'Wedding', NULL, '2', '2026-07-30 08:27:02', '2026-07-30 08:33:00'),
('3', '1', 'Hand-Embroidery Karigar Technique', 'Zardozi with 22kt gold-plated thread, hand-stitched by Lucknow artisans', NULL, '3', '2026-07-30 08:28:39', '2026-07-30 08:33:00'),
('4', '1', 'Stretch Percentage', '0', '%', '4', '2026-07-30 08:37:11', '2026-07-30 08:37:11');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `product_variants`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_variants`;
-- @DIEVON_STMT_END
CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `color_id` int(11) DEFAULT NULL,
  `size_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_qty` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_color_id` (`color_id`)
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `product_variants` (`id`, `product_id`, `name`, `price`, `sort_order`, `available`, `created_at`, `color_id`, `size_code`, `stock_qty`) VALUES
('1', '1', 'Size: XS', '180.00', '1', '1', '2026-07-18 23:55:43', NULL, 'XS', '10'),
('2', '1', 'Size: S', '180.00', '2', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('3', '1', 'Size: M', '180.00', '3', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('4', '1', 'Size: L', '180.00', '4', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('5', '1', 'Size: XL', '180.00', '5', '1', '2026-07-18 23:55:43', NULL, 'XL', '10'),
('6', '2', 'Size: S', '165.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '0'),
('7', '2', 'Size: M', '165.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '13'),
('8', '2', 'Size: L', '165.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '13'),
('9', '3', 'Size: S', '350.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('10', '3', 'Size: M', '350.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('11', '3', 'Size: L', '350.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('12', '3', 'Size: XL', '350.00', '4', '1', '2026-07-18 23:55:43', NULL, 'XL', '10'),
('13', '4', 'Size: XS', '420.00', '1', '1', '2026-07-18 23:55:43', NULL, 'XS', '10'),
('14', '4', 'Size: S', '420.00', '2', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('15', '4', 'Size: M', '420.00', '3', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('16', '4', 'Size: L', '420.00', '4', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('17', '5', '32/S', '290.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('18', '5', '34/M', '290.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('19', '5', '36/L', '290.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('20', '6', 'Size: S', '245.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '11'),
('21', '6', 'Size: M', '245.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '11'),
('22', '6', 'Size: L', '245.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '11'),
('23', '7', 'Size: S', '95.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '15'),
('24', '7', 'Size: M', '95.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '15'),
('25', '7', 'Size: L', '95.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '15'),
('26', '7', 'Size: XL', '95.00', '4', '1', '2026-07-18 23:55:43', NULL, 'XL', '15'),
('27', '8', 'Size: XS', '135.00', '1', '1', '2026-07-18 23:55:43', NULL, 'XS', '10'),
('28', '8', 'Size: S', '135.00', '2', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('29', '8', 'Size: M', '135.00', '3', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('30', '8', 'Size: L', '135.00', '4', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('31', '9', 'Size: S', '110.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '15'),
('32', '9', 'Size: M', '110.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '15'),
('33', '9', 'Size: L', '110.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '15'),
('34', '10', 'Size: S', '125.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('35', '10', 'Size: M', '125.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('36', '10', 'Size: L', '125.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('37', '11', 'Size: S', '160.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '13'),
('38', '11', 'Size: M', '160.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '13'),
('39', '11', 'Size: L', '160.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '13'),
('40', '12', '32/S', '140.00', '1', '1', '2026-07-18 23:55:43', NULL, 'S', '10'),
('41', '12', '34/M', '140.00', '2', '1', '2026-07-18 23:55:43', NULL, 'M', '10'),
('42', '12', '36/L', '140.00', '3', '1', '2026-07-18 23:55:43', NULL, 'L', '10'),
('49', '1', '30/XS', '180.00', '6', '1', '2026-07-29 20:17:50', '4', 'XS', '8'),
('50', '1', '32/S', '180.00', '7', '1', '2026-07-29 20:17:50', '4', 'S', '12'),
('51', '1', '34/M', '180.00', '8', '1', '2026-07-29 20:17:50', '4', 'M', '5'),
('52', '1', '36/L', '180.00', '9', '1', '2026-07-29 20:17:50', '4', 'L', '0'),
('53', '1', '38/XL', '180.00', '10', '1', '2026-07-29 20:17:50', '4', 'XL', '3'),
('59', '1', '30/XS', '180.00', '11', '1', '2026-07-31 12:20:36', '7', 'XS', '0'),
('60', '1', '32/S', '180.00', '12', '1', '2026-07-31 12:20:37', '7', 'S', '0'),
('67', '12', 'Pink', '32.00', '4', '1', '2026-08-06 18:22:48', NULL, NULL, '32'),
('68', '12', '30/XS', '140.00', '5', '1', '2026-08-06 18:24:01', NULL, 'XS', NULL),
('69', '5', '30/XS', '290.00', '4', '1', '2026-08-06 18:24:46', NULL, 'XS', NULL),
('80', '16', 'Size: S', '1499.00', '0', '1', '2026-08-06 22:25:20', NULL, 'S', '8'),
('81', '17', 'Size: S', '1899.00', '0', '1', '2026-08-06 22:25:20', NULL, 'S', '8'),
('82', '16', 'Size: M', '1499.00', '1', '1', '2026-08-06 22:25:20', NULL, 'M', '12'),
('83', '17', 'Size: M', '1899.00', '1', '1', '2026-08-06 22:25:20', NULL, 'M', '12'),
('84', '16', 'Size: L', '1499.00', '2', '1', '2026-08-06 22:25:20', NULL, 'L', '10'),
('85', '17', 'Size: L', '1899.00', '2', '1', '2026-08-06 22:25:20', NULL, 'L', '10'),
('86', '16', 'Size: XL', '1499.00', '3', '1', '2026-08-06 22:25:20', NULL, 'XL', '6'),
('87', '17', 'Size: XL', '1899.00', '3', '1', '2026-08-06 22:25:20', NULL, 'XL', '6'),
('88', '16', 'Size: 2XL', '1499.00', '4', '1', '2026-08-06 22:25:20', NULL, '2XL', '4'),
('89', '17', 'Size: 2XL', '1899.00', '4', '1', '2026-08-06 22:25:20', NULL, '2XL', '4');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `products`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `products`;
-- @DIEVON_STMT_END
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `emoji` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '?',
  `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `badge` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nuts_allergy` tinyint(1) NOT NULL DEFAULT '0',
  `track_stock` tinyint(1) NOT NULL DEFAULT '0',
  `stock_qty` int(11) NOT NULL DEFAULT '0',
  `damage_stock` int(11) NOT NULL DEFAULT '0',
  `sold_offline` int(11) NOT NULL DEFAULT '0',
  `total_stock` int(11) NOT NULL DEFAULT '0',
  `sold_online` int(11) NOT NULL DEFAULT '0',
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fabric` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sleeve` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `neck` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pattern` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occasion` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_percentage` int(11) DEFAULT '0',
  `video_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wash_care` text COLLATE utf8mb4_unicode_ci,
  `shipping_info` text COLLATE utf8mb4_unicode_ci,
  `returns_info` text COLLATE utf8mb4_unicode_ci,
  `specifications` json DEFAULT NULL,
  `atelier_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_way` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sourcing` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `composition` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dimensions` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `seo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` text COLLATE utf8mb4_unicode_ci,
  `related_ids` text COLLATE utf8mb4_unicode_ci,
  `cost_price` decimal(10,2) DEFAULT '0.00',
  `mrp_price` decimal(10,2) DEFAULT '0.00',
  `hsn_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gst_rate` decimal(5,2) DEFAULT '0.00',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `fit_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_height` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_size_worn` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `image_alt` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `use_category_tax` tinyint(1) NOT NULL DEFAULT '1',
  `price_includes_gst` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_products_category_id` (`category_id`),
  KEY `idx_products_available` (`available`),
  KEY `idx_products_avail_cat` (`available`,`category_id`),
  KEY `idx_products_brand` (`brand`),
  KEY `idx_products_fabric` (`fabric`),
  KEY `idx_products_price` (`price`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `products` (`id`, `name`, `description`, `price`, `category`, `category_id`, `emoji`, `color`, `badge`, `available`, `image`, `created_at`, `nuts_allergy`, `track_stock`, `stock_qty`, `damage_stock`, `sold_offline`, `total_stock`, `sold_online`, `brand`, `fabric`, `sleeve`, `neck`, `pattern`, `occasion`, `discount_percentage`, `video_url`, `wash_care`, `shipping_info`, `returns_info`, `specifications`, `atelier_code`, `color_way`, `sourcing`, `composition`, `sku`, `barcode`, `weight`, `dimensions`, `tags`, `seo_url`, `meta_title`, `meta_description`, `related_ids`, `cost_price`, `mrp_price`, `hsn_code`, `gst_rate`, `is_deleted`, `fit_description`, `model_height`, `model_size_worn`, `updated_at`, `image_alt`, `use_category_tax`, `price_includes_gst`) VALUES
('1', 'Aurelia Silk Kurti', 'Handwoven mulberry silk kurti with a relaxed drape.', '180.00', 'Kurtis', '1', '✨', '', '', '0', 'dievon_silk_dress.png', '2026-07-18 23:55:43', '0', '0', '0', '0', '0', '50', '0', '', 'Silk', '', '', '', '', '0', '', '', '', '', NULL, '', '', '', '', '', '', '', '', '', '', '', '', '', '0.00', '0.00', NULL, '0.00', '0', '', '', '', '2026-08-05 08:38:20', NULL, '1', '1'),
('2', 'Zariah Georgette Kurti', 'A lightweight georgette kurti in a flowy, fluid silhouette with side slits and a soft crepe lining. Perfect for hot summer afternoons.', '165.00', 'Kurtis', '1', '👗', 'Rose Petal', 'New', '1', 'georgette_kurti_1784414698877.jpg', '2026-07-18 23:55:43', '0', '1', '40', '0', '0', '40', '7', 'Dievon', 'Georgette', 'Half Sleeve', 'Collar', 'Embroidered', 'Casual', '0', 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Zariah Georgette Kurti — Buy Floral Kurtis Online | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-06 11:49:15', 'Zariah Georgette Kurti in floral print — lightweight flowy silhouette with side slits', '1', '1'),
('3', 'Adara Brocade 3-Piece Suit', 'A masterclass of tailoring. Includes an intricately woven brocade kurti, solid straight-cut pencil trousers, and a lightweight embroidered organza dupatta with gold lace borders.', '350.00', '3 Piece Suits', '2', '👗', 'Champagne', 'Hot', '1', 'adara_brocade_suit_1785962869.jpg', '2026-07-18 23:55:43', '0', '1', '25', '0', '0', '25', '0', 'Dievon', 'Brocade', '3/4 Sleeve', 'Boat Neck', 'Embroidered', 'Casual', '0', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Adara Brocade 3-Piece Suit — Luxury Ethnic Wear | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:48:52', 'Adara Brocade 3-Piece Suit — intricately woven brocade kurti with straight-cut trousers', '1', '1'),
('4', 'Meera Silk 3-Piece Suit', 'Premium raw-silk kurti and pants coupled with a hand-painted pure chiffon dupatta. Features sophisticated modern styling cuts with traditional borders.', '420.00', '3 Piece Suits', '2', '👗', 'Emerald Green', 'Best Seller', '1', 'silk_3_piece_1784414705496.jpg', '2026-07-18 23:55:43', '0', '1', '20', '0', '0', '20', '0', 'Dievon', 'Silk', 'Full Sleeve', 'V-Neck', 'Embroidered', 'Casual', '0', 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Meera Silk 3-Piece Suit — Premium Raw Silk Suits | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:45:43', 'Meera Silk 3-Piece Suit — premium raw-silk kurti with hand-painted chiffon dupatta', '1', '1'),
('5', 'Luna Satin Coord Set', 'A minimal chic look. Features a luxurious, fluid satin button-down tunic shirt with matching wide-leg trousers. High comfort styling for lounges or travel.', '290.00', 'Coord Sets', '3', '👗', 'Olive Silk', 'New', '1', 'luna_satin_coord_1785962869.jpg', '2026-07-18 23:55:43', '0', '1', '30', '0', '0', '30', '0', 'Dievon', 'Satin', 'Full Sleeve', 'Round Neck', 'Striped', 'Formal', '0', '', '', NULL, NULL, NULL, NULL, 'Olive Silk', 'INDIA', '', 'DV-LUNASATI', '', '', '', 'coord sets, satin, olive silk, striped, formal, full sleeve, round neck, dievon, luna satin coord set', 'luna-satin-coord-set', 'Luna Satin Coord Set — Chic Co-ord Sets for Women | Dievon', 'A minimal chic look. Features a luxurious, fluid satin button-down tunic shirt with matching wide-leg trousers. High comfort styling for lounges or travel.', NULL, '0.00', '0.00', NULL, '0.00', '0', '', '', '', '2026-08-06 18:24:56', 'Luna Satin Coord Set — fluid satin button-down tunic with matching wide-leg trousers', '1', '1'),
('6', 'Soraya Linen Coord Set', 'Ethically sourced linen coord set featuring a tailored belted peplum tunic top and breathable crop straight-leg trousers.', '245.00', 'Coord Sets', '3', '👗', 'Natural Sand', 'Best Seller', '1', 'linen_coord_1784414712638.jpg', '2026-07-18 23:55:43', '0', '1', '35', '0', '0', '35', '1', 'Dievon', 'Linen', 'Half Sleeve', 'Collar', 'Striped', 'Formal', '0', '', '', '', '', NULL, '', '', '', '', '', '', '', '', '', '', 'Soraya Linen Coord Set — Ethical Linen Co-ords | Dievon', '', '', '0.00', '0.00', '', '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:45:43', 'Soraya Linen Coord Set — tailored belted peplum tunic with breathable crop straight pants', '1', '1'),
('7', 'Raya Cotton Short Kurti', 'A chic everyday staple. Tailored from hand-loomed organic cotton, featuring a modern mandarin collar and clean front-button placket.', '95.00', 'Short Kurtis', '4', '👚', 'Indigo Blue', 'New', '1', 'cotton_short_kurti_1784414719998.jpg', '2026-07-18 23:55:43', '0', '1', '60', '0', '0', '60', '0', 'Dievon', 'Cotton', 'Half Sleeve', 'Boat Neck', 'Striped', 'Wedding', '0', 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Raya Cotton Short Kurti — Organic Cotton Kurtis | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:45:43', 'Raya Cotton Short Kurti — hand-loomed organic cotton with modern mandarin collar', '1', '1'),
('8', 'Kiara Georgette Short Kurti', 'Short, flowy kurti featuring detailed neckline embroidery in silver zari threads. A beautiful, lightweight option for evening gatherings.', '135.00', 'Short Kurtis', '4', '👚', 'Lilac', 'Hot', '1', 'georgette_short_kurti_1784414734051.jpg', '2026-07-18 23:55:43', '0', '1', '40', '0', '0', '40', '1', 'Dievon', 'Georgette', 'Half Sleeve', 'Boat Neck', 'Embroidered', 'Party', '0', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Kiara Georgette Short Kurti — Embroidered Kurtis | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:45:43', 'Kiara Georgette Short Kurti — short flowy kurti with silver zari neckline embroidery', '1', '1'),
('9', 'Elena Cowl-Neck Satin Top', 'A fluid, high-lustre satin top featuring a beautiful cowl neck drop. Complements straight-fit skirts or trousers perfectly.', '110.00', 'Women\'s Tops', '5', '👚', 'Champagne', 'Best Seller', '1', 'elena_cowl_neck_satin_top_1785962967.jpg', '2026-07-18 23:55:43', '0', '1', '45', '0', '0', '45', '0', 'Dievon', 'Satin', 'Full Sleeve', 'Collar', 'Solid', 'Wedding', '0', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Elena Cowl-Neck Satin Top — Women Tops Online | Dievon', NULL, NULL, '0.00', '0.00', NULL, '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:49:29', 'Elena Cowl-Neck Satin Top — fluid high-lustre satin top with beautiful cowl neck drop', '1', '1'),
('10', 'Freya Linen Wrap Top', 'A clean wrap-around blouse crafted in breathable pre-washed linen. Features adjustable tie closure straps.', '125.00', 'Women\'s Tops', '5', '👚', 'Natural Olive', 'New', '1', 'linen_wrap_top_1784414741898.jpg', '2026-07-18 23:55:43', '0', '1', '30', '0', '0', '30', '0', 'Dievon', 'Linen', 'Full Sleeve', 'Boat Neck', 'Embroidered', 'Formal', '0', '', '', '', '', NULL, '', '', '', '', '', '', '', '', '', '', 'Freya Linen Wrap Top — Linen Blouses for Women | Dievon', '', '', '0.00', '0.00', '', '0.00', '0', NULL, NULL, NULL, '2026-08-05 21:45:43', 'Freya Linen Wrap Top — clean wrap-around blouse in breathable pre-washed linen', '1', '1'),
('11', 'Sienna Wide-Leg Silk Trousers', 'High-waisted, wide-leg trousers styled in heavy satin silk. Features hidden side zip fasteners and a relaxed, comfortable silhouette.', '160.00', 'Bottoms', '6', '👖', 'Midnight Black', 'Hot', '1', 'silk_trousers_1784414749191.jpg', '2026-07-18 23:55:43', '0', '1', '40', '0', '0', '40', '0', 'Dievon', 'Silk', '3/4 Sleeve', 'Boat Neck', 'Embroidered', 'Party', '0', 'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', '', '', '', NULL, '', '', '', '', 'DV-SIENNAWI', '', '', '', 'bottoms, silk, midnight black, embroidered, party, 3/4 sleeve, boat neck, dievon, sienna wide-leg silk trousers', 'sienna-wide-leg-silk-trousers', 'Sienna Wide-Leg Silk Trousers — Silk Pants | Dievon', 'High-waisted, wide-leg trousers styled in heavy satin silk. Features hidden side zip fasteners and a relaxed, comfortable silhouette.', NULL, '0.00', '0.00', '', '0.00', '0', '', '', '', '2026-08-06 00:08:59', 'Sienna Wide-Leg Silk Trousers — high-waisted wide-leg trousers in heavy satin silk', '1', '1'),
('12', 'Iris Straight Crepe Pants', 'Tailored straight-leg crepe trousers with a clean high waist.', '140.00', 'Bottoms', '6', '✨', '', '', '1', 'crepe_pants_1784414756343.jpg', '2026-07-18 23:55:43', '0', '1', '0', '0', '0', '35', '0', 'Dievon', '', '', '', '', '', '0', '', '', '', '', NULL, '', '', 'INDIA', '', 'DV-IRISSTRA', '', '', '', 'bottoms, dievon, iris straight crepe pants', 'iris-straight-crepe-pants', 'Iris Straight Crepe Pants — Bottoms | Dievon', 'Tailored straight-leg crepe trousers with a clean high waist.', '', '0.00', '0.00', '6101', '0.00', '0', '', '', '', '2026-08-06 18:24:17', 'Iris Straight Crepe Pants', '1', '1'),
('16', 'Oxford Structured Shirt — Navy', 'A crisp, structured cotton shirt in deep navy. Regular fit with a classic point collar and single cuff. Cut to a clean, office-ready silhouette — team it with the matching Dievon trousers or a pair of chinos.', '1499.00', 'Shirts', '17', '👔', 'Navy', 'New', '0', 'mens_test_oxford_shirt_1786051520.jpg', '2026-08-06 22:25:20', '0', '1', '40', '0', '0', '40', '0', 'Dievon', 'Cotton Poplin', 'Full Sleeve', 'Point Collar', 'Solid', 'Formal', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'men, shirt, formal, navy, cotton', NULL, 'Oxford Structured Shirt — Buy Men\'s Formal Shirts Online | Dievon', NULL, NULL, '700.00', '2299.00', NULL, '0.00', '1', NULL, NULL, NULL, '2026-08-08 10:50:40', 'Oxford Structured Shirt in navy — men\'s formal cotton shirt by Dievon', '1', '1'),
('17', 'Tailored Pleat Trousers — Charcoal', 'Sharp, tailored trousers in charcoal with a single pleat and straight leg. Mid-rise with a hook-and-bar fastening and side adjusters — the office staple that moves from desk to dinner.', '1899.00', 'Trousers', '18', '👖', 'Charcoal', 'New', '0', 'mens_test_tailored_trousers_1786051520.jpg', '2026-08-06 22:25:20', '0', '1', '36', '0', '0', '36', '0', 'Dievon', 'Polywool Blend', NULL, NULL, 'Solid', 'Formal', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'men, trousers, formal, charcoal, pleat', NULL, 'Tailored Pleat Trousers — Buy Men\'s Formal Trousers Online | Dievon', NULL, NULL, '900.00', '2799.00', NULL, '0.00', '1', NULL, NULL, NULL, '2026-08-08 10:50:40', 'Tailored Pleat Trousers in charcoal — men\'s formal trousers by Dievon', '1', '1');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `promo_codes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `promo_codes`;
-- @DIEVON_STMT_END
CREATE TABLE `promo_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `discount_type` enum('percentage','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `min_order` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_uses` int(11) DEFAULT NULL,
  `uses_count` int(11) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
INSERT INTO `promo_codes` (`id`, `code`, `description`, `discount_type`, `discount_value`, `min_order`, `max_uses`, `uses_count`, `active`, `expires_at`, `created_at`) VALUES
('1', 'WELCOME10', '', 'percentage', '10.00', '0.00', NULL, '0', '1', '2027-12-31', '2026-07-18 23:55:43'),
('2', 'MAISON50', '', 'fixed', '50.00', '300.00', NULL, '0', '1', '2027-12-31', '2026-07-18 23:55:43');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `razorpay_pending_orders`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `razorpay_pending_orders`;
-- @DIEVON_STMT_END
CREATE TABLE `razorpay_pending_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `razorpay_order_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_paise` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `customer_email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `postcode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `order_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  `items_json` json NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_charge` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `promo_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `currency_symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '₹',
  `consumed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rzp_order` (`razorpay_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `review_images`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `review_images`;
-- @DIEVON_STMT_END
CREATE TABLE `review_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `review_id` (`review_id`),
  CONSTRAINT `review_images_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `review_reports`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `review_reports`;
-- @DIEVON_STMT_END
CREATE TABLE `review_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `reason` varchar(60) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_review` (`review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `reviews`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `reviews`;
-- @DIEVON_STMT_END
CREATE TABLE `reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `rating` int(11) DEFAULT '5',
  `comment` text NOT NULL,
  `status` enum('Approved','Pending','Hidden') DEFAULT 'Approved',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `search_history`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `search_history`;
-- @DIEVON_STMT_END
CREATE TABLE `search_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `seo_settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `seo_settings`;
-- @DIEVON_STMT_END
CREATE TABLE `seo_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_slug` varchar(100) NOT NULL,
  `meta_title` varchar(255) NOT NULL,
  `meta_description` text,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `og_image` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_slug` (`page_slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `seo_settings` (`id`, `page_slug`, `meta_title`, `meta_description`, `meta_keywords`, `og_image`, `updated_at`) VALUES
('1', 'home', '', '', 'luxury fashion, dievon', 'lookbook_1.png', '2026-08-08 11:05:35'),
('2', 'shop', 'Shop Luxury Collections | Dievon', 'Browse the full Dievon collection — kurtis, three-piece suits, co-ord sets, women\'s tops and bottoms. Detailed size guides, secure checkout, easy returns.', 'buy kurtis online, three piece suits, coord sets, womens ethnic wear india', 'lookbook_2.png', '2026-08-03 14:15:10'),
('3', 'about', 'Our Heritage & Craftsmanship | Dievon', 'Learn about Dievon\'s dedication to sustainable luxury, bespoke tailoring, and heritage embroidery.', 'heritage fashion, luxury atelier, bespoke kurtis', 'lookbook_3.png', '2026-07-31 23:18:28'),
('4', 'contact', 'Contact Our Atelier | Dievon Support', 'Get in touch with Dievon customer care for custom fitting, order inquiries, and personal styling.', 'contact dievon, customer care, styling inquiries', 'lookbook_1.png', '2026-07-31 23:18:28');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `size_guide_body_global`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `size_guide_body_global`;
-- @DIEVON_STMT_END
CREATE TABLE `size_guide_body_global` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gender` enum('women','men') NOT NULL DEFAULT 'women',
  `size_label` varchar(20) NOT NULL,
  `numeric_size` varchar(20) DEFAULT NULL,
  `bust` decimal(6,2) DEFAULT NULL,
  `waist` decimal(6,2) DEFAULT NULL,
  `hips` decimal(6,2) DEFAULT NULL,
  `shoulder` decimal(6,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gender_size` (`gender`,`size_label`),
  KEY `idx_sgbg_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=84 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `size_guide_body_global` (`id`, `gender`, `size_label`, `numeric_size`, `bust`, `waist`, `hips`, `shoulder`, `sort_order`, `updated_at`) VALUES
('48', 'women', 'XS', '30', '30.00', '24.00', '33.00', '13.50', '0', NULL),
('49', 'women', 'S', '32', '32.00', '26.00', '35.00', '14.00', '1', NULL),
('50', 'women', 'M', '34', '34.00', '28.00', '37.00', '14.50', '2', NULL),
('51', 'women', 'L', '36', '36.00', '30.00', '39.00', '15.00', '3', NULL),
('52', 'women', 'XL', '38', '38.00', '32.00', '41.00', '15.50', '4', NULL),
('53', 'women', '2XL', '40', '40.00', '34.00', '43.00', '16.00', '5', NULL),
('54', 'women', '3XL', '42', '42.00', '36.00', '45.00', '16.50', '6', NULL),
('55', 'women', '4XL', '44', '44.00', '38.00', '47.00', '17.00', '7', NULL),
('56', 'women', '5XL', '46', '46.00', '40.00', '49.00', '17.50', '8', NULL),
('75', 'men', 'XS', '36', '36.00', '28.00', '36.00', '16.50', '0', NULL),
('76', 'men', 'S', '38', '38.00', '30.00', '38.00', '17.00', '1', NULL),
('77', 'men', 'M', '40', '40.00', '32.00', '40.00', '17.50', '2', NULL),
('78', 'men', 'L', '42', '42.00', '34.00', '42.00', '18.00', '3', NULL),
('79', 'men', 'XL', '44', '44.00', '36.00', '44.00', '18.50', '4', NULL),
('80', 'men', '2XL', '46', '46.00', '38.00', '46.00', '19.00', '5', NULL),
('81', 'men', '3XL', '48', '48.00', '40.00', '48.00', '19.50', '6', NULL),
('82', 'men', '4XL', '50', '50.00', '42.00', '50.00', '20.00', '7', NULL),
('83', 'men', '5XL', '52', '52.00', '44.00', '52.00', '20.50', '8', NULL);
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `size_guide_charts`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `size_guide_charts`;
-- @DIEVON_STMT_END
CREATE TABLE `size_guide_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `gender` enum('women','men') DEFAULT NULL,
  `unit` enum('in','cm') NOT NULL DEFAULT 'in',
  `tolerance` varchar(40) DEFAULT NULL,
  `instructions_shoulder` text,
  `instructions_bust` text,
  `instructions_waist` text,
  `instructions_hips` text,
  `instructions_length` text,
  `instructions_sleeve` text,
  `illustration_image` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `pos_shoulder_top` decimal(5,2) DEFAULT NULL,
  `pos_shoulder_width` decimal(5,2) DEFAULT NULL,
  `pos_bust_top` decimal(5,2) DEFAULT NULL,
  `pos_bust_width` decimal(5,2) DEFAULT NULL,
  `pos_waist_top` decimal(5,2) DEFAULT NULL,
  `pos_waist_width` decimal(5,2) DEFAULT NULL,
  `pos_hips_top` decimal(5,2) DEFAULT NULL,
  `pos_hips_width` decimal(5,2) DEFAULT NULL,
  `pos_length_top` decimal(5,2) DEFAULT NULL,
  `pos_length_bottom` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `size_guide_charts` (`id`, `category_id`, `product_id`, `gender`, `unit`, `tolerance`, `instructions_shoulder`, `instructions_bust`, `instructions_waist`, `instructions_hips`, `instructions_length`, `instructions_sleeve`, `illustration_image`, `updated_at`, `pos_shoulder_top`, `pos_shoulder_width`, `pos_bust_top`, `pos_bust_width`, `pos_waist_top`, `pos_waist_width`, `pos_hips_top`, `pos_hips_width`, `pos_length_top`, `pos_length_bottom`) VALUES
('3', '1', NULL, 'women', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('4', '2', NULL, 'women', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('5', '3', NULL, 'women', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('6', '4', NULL, 'women', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('7', '5', NULL, 'women', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('8', '6', NULL, 'women', 'in', '± 0.5 in', '', '', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the waistband straight down along the outer seam to the ankle or desired hem.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('10', '17', NULL, 'men', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the chest, just under the arms, keeping it parallel to the floor.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down the front to the hem.', 'Measure from the shoulder seam down the outside of the arm to the cuff.', NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('11', '18', NULL, 'men', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the chest, just under the arms, keeping it parallel to the floor.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the top of the waistband down the outside of the leg to the hem.', NULL, NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('12', NULL, NULL, NULL, 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL, NULL, '2026-08-06 21:43:21', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
('13', NULL, NULL, 'men', 'in', '± 0.5 in', 'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the chest, just under the arms, keeping it parallel to the floor.', 'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.', 'Measure from the highest point of the shoulder straight down the front to the hem.', 'Measure from the shoulder seam down the outside of the arm to the cuff.', NULL, '2026-08-06 21:49:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `size_guide_content`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `size_guide_content`;
-- @DIEVON_STMT_END
CREATE TABLE `size_guide_content` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chart_id` int(11) NOT NULL,
  `measurement_type` enum('body','garment') NOT NULL DEFAULT 'body',
  `size_label` varchar(20) NOT NULL,
  `numeric_size` varchar(20) DEFAULT NULL,
  `bust` decimal(6,2) DEFAULT NULL,
  `waist` decimal(6,2) DEFAULT NULL,
  `hips` decimal(6,2) DEFAULT NULL,
  `shoulder` decimal(6,2) DEFAULT NULL,
  `sleeve` decimal(6,2) DEFAULT NULL,
  `length` decimal(6,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chart_type_size` (`chart_id`,`measurement_type`,`size_label`),
  KEY `idx_chart_id` (`chart_id`)
) ENGINE=InnoDB AUTO_INCREMENT=213 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `size_guide_content` (`id`, `chart_id`, `measurement_type`, `size_label`, `numeric_size`, `bust`, `waist`, `hips`, `shoulder`, `sleeve`, `length`, `sort_order`) VALUES
('6', '3', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('7', '3', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('8', '3', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('9', '3', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('10', '3', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('11', '3', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('12', '3', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('13', '3', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('14', '3', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('15', '3', 'garment', 'XS', '30', '33.00', '27.00', '35.00', '14.00', '21.00', '42.00', '9'),
('16', '3', 'garment', 'S', '32', '35.00', '29.00', '37.00', '14.50', '21.50', '42.00', '10'),
('17', '3', 'garment', 'M', '34', '37.00', '31.00', '39.00', '15.00', '21.50', '43.00', '11'),
('18', '3', 'garment', 'L', '36', '39.00', '33.00', '41.00', '15.50', '21.50', '43.00', '12'),
('19', '3', 'garment', 'XL', '38', '41.00', '35.00', '43.00', '16.00', '22.00', '44.00', '13'),
('20', '3', 'garment', '2XL', '40', '43.00', '37.00', '45.00', '16.50', '22.00', '44.00', '14'),
('21', '3', 'garment', '3XL', '42', '45.00', '39.00', '47.00', '17.00', '22.00', '45.00', '15'),
('22', '3', 'garment', '4XL', '44', '47.00', '41.00', '49.00', '17.50', '22.50', '45.00', '16'),
('23', '3', 'garment', '5XL', '46', '49.00', '43.00', '51.00', '18.00', '22.50', '46.00', '17'),
('24', '4', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('25', '4', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('26', '4', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('27', '4', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('28', '4', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('29', '4', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('30', '4', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('31', '4', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('32', '4', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('33', '4', 'garment', 'XS', '30', '33.00', '27.00', '35.00', '14.00', '21.00', '42.00', '9'),
('34', '4', 'garment', 'S', '32', '35.00', '29.00', '37.00', '14.50', '21.50', '42.00', '10'),
('35', '4', 'garment', 'M', '34', '37.00', '31.00', '39.00', '15.00', '21.50', '43.00', '11'),
('36', '4', 'garment', 'L', '36', '39.00', '33.00', '41.00', '15.50', '21.50', '43.00', '12'),
('37', '4', 'garment', 'XL', '38', '41.00', '35.00', '43.00', '16.00', '22.00', '44.00', '13'),
('38', '4', 'garment', '2XL', '40', '43.00', '37.00', '45.00', '16.50', '22.00', '44.00', '14'),
('39', '4', 'garment', '3XL', '42', '45.00', '39.00', '47.00', '17.00', '22.00', '45.00', '15'),
('40', '4', 'garment', '4XL', '44', '47.00', '41.00', '49.00', '17.50', '22.50', '45.00', '16'),
('41', '4', 'garment', '5XL', '46', '49.00', '43.00', '51.00', '18.00', '22.50', '46.00', '17'),
('42', '5', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('43', '5', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('44', '5', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('45', '5', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('46', '5', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('47', '5', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('48', '5', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('49', '5', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('50', '5', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('51', '5', 'garment', 'XS', '30', '32.00', '26.00', '35.00', '14.00', '21.00', '24.00', '9'),
('52', '5', 'garment', 'S', '32', '34.00', '28.00', '37.00', '14.50', '21.50', '24.00', '10'),
('53', '5', 'garment', 'M', '34', '36.00', '30.00', '39.00', '15.00', '21.50', '25.00', '11'),
('54', '5', 'garment', 'L', '36', '38.00', '32.00', '41.00', '15.50', '21.50', '25.00', '12'),
('55', '5', 'garment', 'XL', '38', '40.00', '34.00', '43.00', '16.00', '22.00', '26.00', '13'),
('56', '5', 'garment', '2XL', '40', '42.00', '36.00', '45.00', '16.50', '22.00', '26.00', '14'),
('57', '5', 'garment', '3XL', '42', '44.00', '38.00', '47.00', '17.00', '22.00', '27.00', '15'),
('58', '5', 'garment', '4XL', '44', '46.00', '40.00', '49.00', '17.50', '22.50', '27.00', '16'),
('59', '5', 'garment', '5XL', '46', '48.00', '42.00', '51.00', '18.00', '22.50', '28.00', '17'),
('60', '6', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('61', '6', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('62', '6', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('63', '6', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('64', '6', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('65', '6', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('66', '6', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('67', '6', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('68', '6', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('69', '6', 'garment', 'XS', '30', '33.00', '27.00', '35.00', '14.00', '21.00', '30.00', '9'),
('70', '6', 'garment', 'S', '32', '35.00', '29.00', '37.00', '14.50', '21.50', '30.00', '10'),
('71', '6', 'garment', 'M', '34', '37.00', '31.00', '39.00', '15.00', '21.50', '31.00', '11'),
('72', '6', 'garment', 'L', '36', '39.00', '33.00', '41.00', '15.50', '21.50', '31.00', '12'),
('73', '6', 'garment', 'XL', '38', '41.00', '35.00', '43.00', '16.00', '22.00', '32.00', '13'),
('74', '6', 'garment', '2XL', '40', '43.00', '37.00', '45.00', '16.50', '22.00', '32.00', '14'),
('75', '6', 'garment', '3XL', '42', '45.00', '39.00', '47.00', '17.00', '22.00', '33.00', '15'),
('76', '6', 'garment', '4XL', '44', '47.00', '41.00', '49.00', '17.50', '22.50', '33.00', '16'),
('77', '6', 'garment', '5XL', '46', '49.00', '43.00', '51.00', '18.00', '22.50', '34.00', '17'),
('78', '7', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('79', '7', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('80', '7', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('81', '7', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('82', '7', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('83', '7', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('84', '7', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('85', '7', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('86', '7', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('87', '7', 'garment', 'XS', '30', '32.00', '26.00', '35.00', '14.00', '21.00', '22.00', '9'),
('88', '7', 'garment', 'S', '32', '34.00', '28.00', '37.00', '14.50', '21.50', '22.00', '10'),
('89', '7', 'garment', 'M', '34', '36.00', '30.00', '39.00', '15.00', '21.50', '23.00', '11'),
('90', '7', 'garment', 'L', '36', '38.00', '32.00', '41.00', '15.50', '21.50', '23.00', '12'),
('91', '7', 'garment', 'XL', '38', '40.00', '34.00', '43.00', '16.00', '22.00', '24.00', '13'),
('92', '7', 'garment', '2XL', '40', '42.00', '36.00', '45.00', '16.50', '22.00', '24.00', '14'),
('93', '7', 'garment', '3XL', '42', '44.00', '38.00', '47.00', '17.00', '22.00', '25.00', '15'),
('94', '7', 'garment', '4XL', '44', '46.00', '40.00', '49.00', '17.50', '22.50', '25.00', '16'),
('95', '7', 'garment', '5XL', '46', '48.00', '42.00', '51.00', '18.00', '22.50', '26.00', '17'),
('114', '8', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('115', '8', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('116', '8', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('117', '8', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('118', '8', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('119', '8', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('120', '8', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('121', '8', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('122', '8', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('123', '8', 'garment', 'XS', '30', NULL, '26.00', '35.00', NULL, NULL, '38.00', '9'),
('124', '8', 'garment', 'S', '32', NULL, '28.00', '37.00', NULL, NULL, '38.00', '10'),
('125', '8', 'garment', 'M', '34', NULL, '30.00', '39.00', NULL, NULL, '39.00', '11'),
('126', '8', 'garment', 'L', '36', NULL, '32.00', '41.00', NULL, NULL, '39.00', '12'),
('127', '8', 'garment', 'XL', '38', NULL, '34.00', '43.00', NULL, NULL, '40.00', '13'),
('128', '8', 'garment', '2XL', '40', NULL, '36.00', '45.00', NULL, NULL, '40.00', '14'),
('129', '8', 'garment', '3XL', '42', NULL, '38.00', '47.00', NULL, NULL, '41.00', '15'),
('130', '8', 'garment', '4XL', '44', NULL, '40.00', '49.00', NULL, NULL, '41.00', '16'),
('131', '8', 'garment', '5XL', '46', NULL, '42.00', '51.00', NULL, NULL, '42.00', '17'),
('141', '10', 'body', 'XS', '36', '36.00', '28.00', '34.00', '16.50', NULL, NULL, '0'),
('142', '10', 'body', 'S', '38', '38.00', '30.00', '36.00', '17.00', NULL, NULL, '1'),
('143', '10', 'body', 'M', '40', '40.00', '32.00', '38.00', '17.50', NULL, NULL, '2'),
('144', '10', 'body', 'L', '42', '42.00', '34.00', '40.00', '18.00', NULL, NULL, '3'),
('145', '10', 'body', 'XL', '44', '44.00', '36.00', '42.00', '18.50', NULL, NULL, '4'),
('146', '10', 'body', '2XL', '46', '46.00', '38.00', '44.00', '19.00', NULL, NULL, '5'),
('147', '10', 'body', '3XL', '48', '48.00', '40.00', '46.00', '19.50', NULL, NULL, '6'),
('148', '10', 'body', '4XL', '50', '50.00', '42.00', '48.00', '20.00', NULL, NULL, '7'),
('149', '10', 'body', '5XL', '52', '52.00', '44.00', '50.00', '20.50', NULL, NULL, '8'),
('150', '10', 'garment', 'XS', '36', '36.00', '28.00', NULL, '16.50', '23.50', '28.00', '9'),
('151', '10', 'garment', 'S', '38', '38.00', '30.00', NULL, '17.00', '24.00', '28.50', '10'),
('152', '10', 'garment', 'M', '40', '40.00', '32.00', NULL, '17.50', '24.50', '29.00', '11'),
('153', '10', 'garment', 'L', '42', '42.00', '34.00', NULL, '18.00', '25.00', '29.50', '12'),
('154', '10', 'garment', 'XL', '44', '44.00', '36.00', NULL, '18.50', '25.50', '30.00', '13'),
('155', '10', 'garment', '2XL', '46', '46.00', '38.00', NULL, '19.00', '26.00', '30.50', '14'),
('156', '10', 'garment', '3XL', '48', '48.00', '40.00', NULL, '19.50', '26.50', '31.00', '15'),
('157', '10', 'garment', '4XL', '50', '50.00', '42.00', NULL, '20.00', '27.00', '31.50', '16'),
('158', '10', 'garment', '5XL', '52', '52.00', '44.00', NULL, '20.50', '27.50', '32.00', '17'),
('159', '11', 'body', 'XS', '36', '36.00', '28.00', '34.00', '16.50', NULL, NULL, '0'),
('160', '11', 'body', 'S', '38', '38.00', '30.00', '36.00', '17.00', NULL, NULL, '1'),
('161', '11', 'body', 'M', '40', '40.00', '32.00', '38.00', '17.50', NULL, NULL, '2'),
('162', '11', 'body', 'L', '42', '42.00', '34.00', '40.00', '18.00', NULL, NULL, '3'),
('163', '11', 'body', 'XL', '44', '44.00', '36.00', '42.00', '18.50', NULL, NULL, '4'),
('164', '11', 'body', '2XL', '46', '46.00', '38.00', '44.00', '19.00', NULL, NULL, '5'),
('165', '11', 'body', '3XL', '48', '48.00', '40.00', '46.00', '19.50', NULL, NULL, '6'),
('166', '11', 'body', '4XL', '50', '50.00', '42.00', '48.00', '20.00', NULL, NULL, '7'),
('167', '11', 'body', '5XL', '52', '52.00', '44.00', '50.00', '20.50', NULL, NULL, '8'),
('168', '11', 'garment', 'XS', '36', NULL, '28.00', '36.00', NULL, NULL, '40.00', '9'),
('169', '11', 'garment', 'S', '38', NULL, '30.00', '38.00', NULL, NULL, '40.00', '10'),
('170', '11', 'garment', 'M', '40', NULL, '32.00', '40.00', NULL, NULL, '40.00', '11'),
('171', '11', 'garment', 'L', '42', NULL, '34.00', '42.00', NULL, NULL, '40.00', '12'),
('172', '11', 'garment', 'XL', '44', NULL, '36.00', '44.00', NULL, NULL, '40.00', '13'),
('173', '11', 'garment', '2XL', '46', NULL, '38.00', '46.00', NULL, NULL, '40.00', '14'),
('174', '11', 'garment', '3XL', '48', NULL, '40.00', '48.00', NULL, NULL, '40.00', '15'),
('175', '11', 'garment', '4XL', '50', NULL, '42.00', '50.00', NULL, NULL, '40.00', '16'),
('176', '11', 'garment', '5XL', '52', NULL, '44.00', '52.00', NULL, NULL, '40.00', '17'),
('177', '12', 'body', 'XS', '30', '30.00', '24.00', '33.00', '13.50', NULL, NULL, '0'),
('178', '12', 'body', 'S', '32', '32.00', '26.00', '35.00', '14.00', NULL, NULL, '1'),
('179', '12', 'body', 'M', '34', '34.00', '28.00', '37.00', '14.50', NULL, NULL, '2'),
('180', '12', 'body', 'L', '36', '36.00', '30.00', '39.00', '15.00', NULL, NULL, '3'),
('181', '12', 'body', 'XL', '38', '38.00', '32.00', '41.00', '15.50', NULL, NULL, '4'),
('182', '12', 'body', '2XL', '40', '40.00', '34.00', '43.00', '16.00', NULL, NULL, '5'),
('183', '12', 'body', '3XL', '42', '42.00', '36.00', '45.00', '16.50', NULL, NULL, '6'),
('184', '12', 'body', '4XL', '44', '44.00', '38.00', '47.00', '17.00', NULL, NULL, '7'),
('185', '12', 'body', '5XL', '46', '46.00', '40.00', '49.00', '17.50', NULL, NULL, '8'),
('186', '12', 'garment', 'XS', '30', '33.00', '27.00', '35.00', '14.00', '21.00', '42.00', '9'),
('187', '12', 'garment', 'S', '32', '35.00', '29.00', '37.00', '14.50', '21.50', '42.00', '10'),
('188', '12', 'garment', 'M', '34', '37.00', '31.00', '39.00', '15.00', '21.50', '43.00', '11'),
('189', '12', 'garment', 'L', '36', '39.00', '33.00', '41.00', '15.50', '21.50', '43.00', '12'),
('190', '12', 'garment', 'XL', '38', '41.00', '35.00', '43.00', '16.00', '22.00', '44.00', '13'),
('191', '12', 'garment', '2XL', '40', '43.00', '37.00', '45.00', '16.50', '22.00', '44.00', '14'),
('192', '12', 'garment', '3XL', '42', '45.00', '39.00', '47.00', '17.00', '22.00', '45.00', '15'),
('193', '12', 'garment', '4XL', '44', '47.00', '41.00', '49.00', '17.50', '22.50', '45.00', '16'),
('194', '12', 'garment', '5XL', '46', '49.00', '43.00', '51.00', '18.00', '22.50', '46.00', '17'),
('195', '13', 'body', '2XL', '46', '46.00', '38.00', '44.00', '19.00', NULL, NULL, '5'),
('196', '13', 'body', '3XL', '48', '48.00', '40.00', '46.00', '19.50', NULL, NULL, '6'),
('197', '13', 'body', '4XL', '50', '50.00', '42.00', '48.00', '20.00', NULL, NULL, '7'),
('198', '13', 'body', '5XL', '52', '52.00', '44.00', '50.00', '20.50', NULL, NULL, '8'),
('199', '13', 'body', 'L', '42', '42.00', '34.00', '40.00', '18.00', NULL, NULL, '3'),
('200', '13', 'body', 'M', '40', '40.00', '32.00', '38.00', '17.50', NULL, NULL, '2'),
('201', '13', 'body', 'S', '38', '38.00', '30.00', '36.00', '17.00', NULL, NULL, '1'),
('202', '13', 'body', 'XL', '44', '44.00', '36.00', '42.00', '18.50', NULL, NULL, '4'),
('203', '13', 'body', 'XS', '36', '36.00', '28.00', '34.00', '16.50', NULL, NULL, '0'),
('204', '13', 'garment', '2XL', '46', '46.00', '38.00', NULL, '19.00', '26.00', '30.50', '14'),
('205', '13', 'garment', '3XL', '48', '48.00', '40.00', NULL, '19.50', '26.50', '31.00', '15'),
('206', '13', 'garment', '4XL', '50', '50.00', '42.00', NULL, '20.00', '27.00', '31.50', '16'),
('207', '13', 'garment', '5XL', '52', '52.00', '44.00', NULL, '20.50', '27.50', '32.00', '17'),
('208', '13', 'garment', 'L', '42', '42.00', '34.00', NULL, '18.00', '25.00', '29.50', '12'),
('209', '13', 'garment', 'M', '40', '40.00', '32.00', NULL, '17.50', '24.50', '29.00', '11'),
('210', '13', 'garment', 'S', '38', '38.00', '30.00', NULL, '17.00', '24.00', '28.50', '10'),
('211', '13', 'garment', 'XL', '44', '44.00', '36.00', NULL, '18.50', '25.50', '30.00', '13'),
('212', '13', 'garment', 'XS', '36', '36.00', '28.00', NULL, '16.50', '23.50', '28.00', '9');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `size_guide_snapshots`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `size_guide_snapshots`;
-- @DIEVON_STMT_END
CREATE TABLE `size_guide_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chart_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `label` varchar(120) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sgs_chart` (`chart_id`),
  KEY `idx_sgs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `store_countries`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `store_countries`;
-- @DIEVON_STMT_END
CREATE TABLE `store_countries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_code` char(2) NOT NULL,
  `country_name` varchar(80) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `currency_symbol` varchar(8) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `is_home` tinyint(1) NOT NULL DEFAULT '0',
  `cod_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `shipping_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `free_shipping_min` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `country_code` (`country_code`),
  KEY `idx_country_enabled` (`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `store_countries` (`id`, `country_code`, `country_name`, `currency_code`, `currency_symbol`, `is_enabled`, `is_home`, `cod_allowed`, `shipping_fee`, `free_shipping_min`, `sort_order`) VALUES
('1', 'IN', 'India', 'INR', '₹', '1', '1', '1', '99.00', '2000.00', '1'),
('2', 'GB', 'United Kingdom', 'GBP', '£', '0', '0', '0', '0.00', '0.00', '2'),
('3', 'US', 'United States', 'USD', '$', '0', '0', '0', '30.00', '300.00', '3'),
('4', 'AE', 'United Arab Emirates', 'AED', 'AED ', '0', '0', '0', '90.00', '900.00', '4');
-- @DIEVON_STMT_END
-- --------------------------------------------------------
-- Table: `store_settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `store_settings`;
-- @DIEVON_STMT_END
CREATE TABLE `store_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- @DIEVON_STMT_END
INSERT INTO `store_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('company_cin', '', '2026-08-02 17:42:03'),
('contact_email', 'hello@dievon.com', '2026-08-01 22:58:09'),
('contact_phone', '+91 999 000 9992', '2026-08-07 00:10:28'),
('courier_partners', 'Blue Dart, Delhivery, India Post', '2026-08-04 23:57:49'),
('default_currency', 'INR', '2026-08-01 07:18:58'),
('enable_currency_switcher', '0', '2026-08-01 15:42:00'),
('free_shipping_min', '2000', '2026-08-01 07:18:58'),
('google_site_verification', '', '2026-08-02 15:03:18'),
('grievance_officer_email', '', '2026-08-02 17:58:36'),
('grievance_officer_name', '', '2026-08-02 17:58:36'),
('grievance_officer_phone', '', '2026-08-02 17:58:36'),
('home_country', 'IN', '2026-08-01 07:19:21'),
('international_shipping_fee', '2500', '2026-08-01 07:18:58'),
('jurisdiction_city', '', '2026-08-02 17:58:36'),
('legal_entity_name', '', '2026-08-02 17:58:36'),
('min_order_value', '0', '2026-08-01 16:42:48'),
('seller_address', '', '2026-08-02 17:58:36'),
('seller_gstin', '', '2026-08-06 12:29:10'),
('seller_state', '', '2026-08-02 17:58:36'),
('site_favicon', 'assets/images/logo/favicon_1785598968_c1c530.png', '2026-08-01 16:42:48'),
('site_favicon_dark', 'assets/images/logo/favicondark_1785598968_84f1f2.png', '2026-08-01 16:42:48'),
('site_logo', 'assets/images/logo/logo_1785598968_8d6934.png', '2026-08-02 00:23:52'),
('site_name', 'Dievon', '2026-07-31 23:18:28'),
('size_guide_illustration', 'howtomeasure_desktop.webp', '2026-08-04 21:26:13'),
('size_guide_illustration_mobile', 'howtomeasure_mobile.webp', '2026-08-04 21:26:13'),
('standard_shipping_fee', '99', '2026-08-07 21:50:36');
-- @DIEVON_STMT_END
SET FOREIGN_KEY_CHECKS=1;
-- @DIEVON_STMT_END
