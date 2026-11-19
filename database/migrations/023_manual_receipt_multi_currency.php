<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$columns = $pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
foreach ([
    'currency_code' => "ALTER TABLE transactions ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT 'IRT' AFTER amount",
    'created_by_admin_id' => 'ALTER TABLE transactions ADD COLUMN created_by_admin_id INT NULL',
    'approved_by_admin_id' => 'ALTER TABLE transactions ADD COLUMN approved_by_admin_id INT NULL',
    'approved_at' => 'ALTER TABLE transactions ADD COLUMN approved_at DATETIME NULL',
    'balance_applied' => 'ALTER TABLE transactions ADD COLUMN balance_applied TINYINT(1) NOT NULL DEFAULT 0',
    'receipt_paid_at' => 'ALTER TABLE transactions ADD COLUMN receipt_paid_at DATETIME NULL',
] as $column => $sql) {
    if (!in_array($column, $columns, true)) $pdo->exec($sql);
}
// Expanding decimal scale preserves historical values while allowing currencies with three minor digits.
$amountColumn = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'amount'")->fetch(PDO::FETCH_ASSOC);
if ($amountColumn && preg_match('/^decimal\\(\\d+,([0-9]+)\\)/i', (string)$amountColumn['Type'], $matches) === 1 && (int)$matches[1] < 4) {
    $pdo->exec('ALTER TABLE transactions MODIFY amount DECIMAL(20,4) NOT NULL');
} elseif ($amountColumn && preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\\(|$)/i', (string)$amountColumn['Type']) === 1) {
    $pdo->exec('ALTER TABLE transactions MODIFY amount DECIMAL(20,4) NOT NULL');
}
$pdo->exec("UPDATE transactions SET balance_applied = 1 WHERE type = 'deposit' AND status = 'approved'");
$pdo->exec("CREATE TABLE IF NOT EXISTS currency_wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    currency_code VARCHAR(8) NOT NULL,
    balance DECIMAL(20,4) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_currency_wallet_user_currency (user_id, currency_code),
    KEY idx_currency_wallet_currency (currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo 'Manual receipt multi-currency migration complete.' . PHP_EOL;
