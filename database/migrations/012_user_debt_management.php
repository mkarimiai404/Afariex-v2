<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();

$hasIndex = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_debts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        original_amount DECIMAL(20,2) NOT NULL,
        remaining_amount DECIMAL(20,2) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        description TEXT NULL,
        created_by_admin_id INT NULL,
        cancelled_by_admin_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        settled_at DATETIME NULL,
        cancelled_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_debt_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        debt_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        amount DECIMAL(20,2) NOT NULL,
        source_transaction_id INT NULL,
        source_type VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!$hasIndex('user_debts', 'idx_user_debts_user_status_created')) {
    $pdo->exec('CREATE INDEX idx_user_debts_user_status_created ON user_debts (user_id, status, created_at)');
}
if (!$hasIndex('user_debts', 'idx_user_debts_status')) {
    $pdo->exec('CREATE INDEX idx_user_debts_status ON user_debts (status)');
}
if (!$hasIndex('user_debt_payments', 'idx_debt_payments_debt_created')) {
    $pdo->exec('CREATE INDEX idx_debt_payments_debt_created ON user_debt_payments (debt_id, created_at)');
}
if (!$hasIndex('user_debt_payments', 'idx_debt_payments_user_created')) {
    $pdo->exec('CREATE INDEX idx_debt_payments_user_created ON user_debt_payments (user_id, created_at)');
}
if (!$hasIndex('user_debt_payments', 'idx_debt_payments_source')) {
    $pdo->exec('CREATE INDEX idx_debt_payments_source ON user_debt_payments (source_transaction_id, source_type)');
}

echo 'User debt management migration complete for database ' . DB_NAME . PHP_EOL;
