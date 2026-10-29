-- Secure API PIN storage required by registration and PIN-change flows.
-- Idempotent; existing users and PIN values are preserved.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'users'
     AND column_name = 'pin_hash') = 0,
  'ALTER TABLE `users` ADD COLUMN `pin_hash` VARCHAR(255) NULL AFTER `pin`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
