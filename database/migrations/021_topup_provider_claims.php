<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};

$columns = [
    // Keep this numbered migration self-contained for fresh installs: the
    // historical top-up fields were originally added by an unnumbered SQL file.
    'topup_mobile' => 'VARCHAR(15) NULL',
    'topup_operator' => 'VARCHAR(10) NULL',
    'provider_ref_code' => 'VARCHAR(50) NULL',
    // Legacy rows stay unverified until manually reconciled; new purchases set this to 1
    // in the same transaction as the wallet debit and transaction snapshot.
    'balance_applied' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'topup_charge_type' => "VARCHAR(16) NULL AFTER topup_operator",
    'provider_operation_token' => 'CHAR(32) NULL',
    'provider_operation_lease_until' => 'DATETIME NULL',
    'provider_operation_type' => "VARCHAR(16) NULL",
];
foreach ($columns as $name => $definition) {
    if (!$hasColumn('transactions', $name)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN `' . $name . '` ' . $definition);
    }
}

echo 'Top-up provider claims and charge type migration complete.' . PHP_EOL;
