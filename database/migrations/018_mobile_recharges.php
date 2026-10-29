<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `mobile_recharges` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `operator` ENUM('MCI', 'MTN', 'RTL', 'SHT') NOT NULL,
  `charge_type` ENUM('normal', 'amazing', 'permanent') NOT NULL DEFAULT 'normal',
  `amount` DECIMAL(15, 0) NOT NULL COMMENT 'Amount in Toman',
  `order_id` VARCHAR(50) NOT NULL UNIQUE,
  `trans_id` VARCHAR(50) NULL DEFAULT NULL,
  `ref_code` VARCHAR(100) NULL DEFAULT NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `error_code` INT NULL DEFAULT NULL,
  `error_msg` VARCHAR(255) NULL DEFAULT NULL,
  `raw_response` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo 'Mobile recharges table migration complete.' . PHP_EOL;
