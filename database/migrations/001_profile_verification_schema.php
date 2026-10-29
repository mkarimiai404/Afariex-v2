<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();

$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() > 0;
};

$addColumn = static function (string $table, string $column, string $definition) use ($hasColumn, $pdo): void {
    if (!$hasColumn($table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};

$addColumn('users', 'first_name', "VARCHAR(100) NOT NULL DEFAULT ''");
$addColumn('users', 'last_name', "VARCHAR(100) NOT NULL DEFAULT ''");
$addColumn('users', 'email', 'VARCHAR(255) NULL');
$addColumn('users', 'account_status', 'VARCHAR(32) NULL');
$addColumn('users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
$addColumn('users', 'email_verified', 'TINYINT(1) NOT NULL DEFAULT 0');
$addColumn('users', 'overdraft_limit', 'DECIMAL(20,2) NOT NULL DEFAULT 0');

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_verification_levels (
    user_id INT NOT NULL PRIMARY KEY,
    level VARCHAR(32) NOT NULL DEFAULT 'initial',
    phone_verified TINYINT(1) NOT NULL DEFAULT 0,
    phone_verified_at DATETIME NULL,
    withdrawal_limit DECIMAL(20,2) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS verification_upgrade_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_type VARCHAR(16) NOT NULL DEFAULT 'silver',
    requested_level VARCHAR(32) NOT NULL DEFAULT 'verified',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    identity_document_path VARCHAR(255) NULL,
    selfie_path VARCHAR(255) NULL,
    video_path VARCHAR(255) NULL,
    admin_id INT NULL,
    admin_note TEXT NULL,
    rejection_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_upgrade_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS phone_verification_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    mobile VARCHAR(32) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone_code_user (user_id, created_at),
    INDEX idx_phone_code_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Migration complete for database " . DB_NAME . PHP_EOL;
