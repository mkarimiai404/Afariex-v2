<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};
$addColumn = static function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
    if (!$hasColumn($table, $column)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
};
$hasIndex = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

$addColumn('transactions', 'balance_before', 'DECIMAL(20,2) NULL');
$addColumn('transactions', 'balance_after', 'DECIMAL(20,2) NULL');
$addColumn('transactions', 'card_number', 'VARCHAR(16) NULL');
$addColumn('transactions', 'cardholder_name', 'VARCHAR(150) NULL');
$addColumn('transactions', 'request_source', 'VARCHAR(16) NULL');
$addColumn('transactions', 'idempotency_key', 'VARCHAR(64) NULL');
$addColumn('transactions', 'operator_admin_id', 'INT NULL');
$addColumn('transactions', 'approved_at', 'DATETIME NULL');
$addColumn('transactions', 'paid_at', 'DATETIME NULL');
$addColumn('transactions', 'rejected_at', 'DATETIME NULL');
$addColumn('transactions', 'refund_applied', 'TINYINT(1) NOT NULL DEFAULT 0');

// MySQL/MariaDB unique indexes allow multiple NULL values, so all legacy rows
// remain valid after the nullable idempotency_key column is introduced.
if (!$hasIndex('transactions', 'uq_withdrawal_idempotency')) {
    $pdo->exec('CREATE UNIQUE INDEX uq_withdrawal_idempotency ON transactions (user_id, type, idempotency_key)');
}
if (!$hasIndex('transactions', 'idx_withdrawal_status_created')) {
    $pdo->exec('CREATE INDEX idx_withdrawal_status_created ON transactions (type, status, created_at)');
}

$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mobile VARCHAR(15) NOT NULL,
    user_id INT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$addColumn('notifications', 'user_id', 'INT NULL');
if (!$hasIndex('notifications', 'idx_notifications_user_created')) {
    $pdo->exec('CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at)');
}

// Backfill only exact mobile values that identify exactly one user. Existing
// user_id values and unmatched or ambiguous legacy notifications are untouched.
$pdo->exec("UPDATE notifications n
    INNER JOIN (
        SELECT mobile, MIN(id) AS user_id
        FROM users
        WHERE mobile IS NOT NULL AND mobile <> ''
        GROUP BY mobile
        HAVING COUNT(*) = 1
    ) matched_user ON BINARY matched_user.mobile = BINARY n.mobile
    SET n.user_id = matched_user.user_id
    WHERE n.user_id IS NULL");

echo 'Withdrawal workflow migration complete for database ' . DB_NAME . PHP_EOL;
