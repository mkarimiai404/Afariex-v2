-- Registration OTP verification-token handoff.
-- Idempotent and safe for existing rows: all new fields are nullable.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'phone_verification_codes'
     AND column_name = 'verification_token_hash') = 0,
  'ALTER TABLE `phone_verification_codes` ADD COLUMN `verification_token_hash` CHAR(64) NULL AFTER `used_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'phone_verification_codes'
     AND column_name = 'verification_token_expires_at') = 0,
  'ALTER TABLE `phone_verification_codes` ADD COLUMN `verification_token_expires_at` DATETIME NULL AFTER `verification_token_hash`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'phone_verification_codes'
     AND column_name = 'consumed_at') = 0,
  'ALTER TABLE `phone_verification_codes` ADD COLUMN `consumed_at` DATETIME NULL AFTER `verification_token_expires_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
