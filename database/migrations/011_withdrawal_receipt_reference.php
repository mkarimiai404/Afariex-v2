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

$hasIndex = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

$generateReference = static function () use ($pdo): string {
    do {
        $reference = 'WDR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE withdrawal_reference = ?');
        $stmt->execute([$reference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $reference;
};

if (!$hasColumn('transactions', 'withdrawal_reference')) {
    $pdo->exec('ALTER TABLE transactions ADD COLUMN withdrawal_reference VARCHAR(40) NULL AFTER tracking_code');
}

if (!$hasIndex('transactions', 'uq_withdrawal_reference')) {
    $pdo->exec('CREATE UNIQUE INDEX uq_withdrawal_reference ON transactions (withdrawal_reference)');
}

$stmt = $pdo->query("SELECT id FROM transactions WHERE type IN ('withdraw', 'withdrawal') AND (withdrawal_reference IS NULL OR withdrawal_reference = '')");
$update = $pdo->prepare('UPDATE transactions SET withdrawal_reference = ? WHERE id = ? AND (withdrawal_reference IS NULL OR withdrawal_reference = \'\')');
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
    $update->execute([$generateReference(), (int)$id]);
}

echo 'Withdrawal receipt reference migration complete for database ' . DB_NAME . PHP_EOL;
