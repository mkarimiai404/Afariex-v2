<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';
$pdo = db();
$has = static function (string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute(['phone_verification_codes', $column]);
    return (int)$stmt->fetchColumn() > 0;
};
if (!$has('purpose')) $pdo->exec("ALTER TABLE phone_verification_codes ADD purpose VARCHAR(32) NOT NULL DEFAULT 'phone_verification' AFTER mobile");
$userIdStatement = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'phone_verification_codes' AND column_name = 'user_id'");
if (strtoupper((string)$userIdStatement->fetchColumn()) !== 'YES') $pdo->exec('ALTER TABLE phone_verification_codes MODIFY user_id INT NULL');
if (!$has('last_sent_at')) $pdo->exec('ALTER TABLE phone_verification_codes ADD last_sent_at DATETIME NULL AFTER created_at');
if (!$has('resend_count')) $pdo->exec('ALTER TABLE phone_verification_codes ADD resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sent_at');
if (!$has('requester_ip_hash')) $pdo->exec('ALTER TABLE phone_verification_codes ADD requester_ip_hash CHAR(64) NULL AFTER resend_count');
if (!$has('verified_at')) $pdo->exec('ALTER TABLE phone_verification_codes ADD verified_at DATETIME NULL AFTER used_at');
if (!$has('consumed_at')) $pdo->exec('ALTER TABLE phone_verification_codes ADD consumed_at DATETIME NULL AFTER verified_at');
if (!$has('verification_token_hash')) $pdo->exec('ALTER TABLE phone_verification_codes ADD verification_token_hash CHAR(64) NULL AFTER consumed_at');
if (!$has('verification_token_expires_at')) $pdo->exec('ALTER TABLE phone_verification_codes ADD verification_token_expires_at DATETIME NULL AFTER verification_token_hash');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_phone_purpose_created ON phone_verification_codes (mobile, purpose, created_at)');
echo "OTP challenge migration complete for database " . DB_NAME . PHP_EOL;
