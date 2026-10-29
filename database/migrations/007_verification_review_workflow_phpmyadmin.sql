-- Idempotent verification-only migration for phpMyAdmin / MySQL / MariaDB.
-- This file does not change or remove existing verification rows or evidence paths.

CREATE TABLE IF NOT EXISTS `user_verification_levels` (
  `user_id` INT NOT NULL,
  `level` VARCHAR(32) NOT NULL DEFAULT 'initial',
  `phone_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `phone_verified_at` DATETIME NULL,
  `withdrawal_limit` DECIMAL(20,2) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `verification_upgrade_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `request_type` VARCHAR(16) NOT NULL DEFAULT 'silver',
  `requested_level` VARCHAR(32) NOT NULL DEFAULT 'verified',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `identity_document_path` VARCHAR(255) NULL,
  `selfie_path` VARCHAR(255) NULL,
  `video_path` VARCHAR(255) NULL,
  `admin_id` INT NULL,
  `admin_note` TEXT NULL,
  `rejection_reason` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_upgrade_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_verification_levels' AND column_name='level')=0, 'ALTER TABLE `user_verification_levels` ADD COLUMN `level` VARCHAR(32) NOT NULL DEFAULT ''initial'' AFTER `user_id`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_verification_levels' AND column_name='phone_verified')=0, 'ALTER TABLE `user_verification_levels` ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `level`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_verification_levels' AND column_name='phone_verified_at')=0, 'ALTER TABLE `user_verification_levels` ADD COLUMN `phone_verified_at` DATETIME NULL AFTER `phone_verified`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_verification_levels' AND column_name='withdrawal_limit')=0, 'ALTER TABLE `user_verification_levels` ADD COLUMN `withdrawal_limit` DECIMAL(20,2) NULL AFTER `phone_verified_at`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_verification_levels' AND column_name='updated_at')=0, 'ALTER TABLE `user_verification_levels` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `withdrawal_limit`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='request_type')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `request_type` VARCHAR(16) NOT NULL DEFAULT ''silver'' AFTER `user_id`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='requested_level')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `requested_level` VARCHAR(32) NOT NULL DEFAULT ''verified'' AFTER `request_type`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='status')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `status` ENUM(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending'' AFTER `requested_level`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='identity_document_path')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `identity_document_path` VARCHAR(255) NULL AFTER `status`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='selfie_path')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `selfie_path` VARCHAR(255) NULL AFTER `identity_document_path`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='video_path')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `video_path` VARCHAR(255) NULL AFTER `selfie_path`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='admin_id')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `admin_id` INT NULL AFTER `video_path`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='admin_note')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `admin_note` TEXT NULL AFTER `admin_id`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='rejection_reason')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `rejection_reason` TEXT NULL AFTER `admin_note`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='created_at')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `rejection_reason`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND column_name='reviewed_at')=0, 'ALTER TABLE `verification_upgrade_requests` ADD COLUMN `reviewed_at` DATETIME NULL AFTER `created_at`', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has_exact_index := (SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)='user_id,status') exact_indexes);
SET @has_named_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='verification_upgrade_requests' AND index_name='idx_upgrade_user_status');
SET @sql := IF(@has_exact_index > 0, 'SELECT 1', IF(@has_named_index = 0, 'CREATE INDEX `idx_upgrade_user_status` ON `verification_upgrade_requests` (`user_id`,`status`)', 'CREATE INDEX `idx_verification_user_status_review` ON `verification_upgrade_requests` (`user_id`,`status`)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
