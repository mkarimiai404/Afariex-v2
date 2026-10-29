<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$column = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
);
$column->execute(['mobile_recharges', 'total_cost']);
if ((int)$column->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE mobile_recharges ADD total_cost BIGINT UNSIGNED NULL AFTER amount');
}

$table = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
$table->execute(['service_fee_transactions']);
if ((int)$table->fetchColumn() > 0) {
    $pdo->exec("UPDATE mobile_recharges r
        INNER JOIN transactions t ON t.idempotency_key = r.order_id AND t.type = 'topup'
        LEFT JOIN service_fee_transactions sf ON sf.source_type = 'topup' AND sf.source_id = t.id
        SET r.total_cost = sf.total_amount
        WHERE r.total_cost IS NULL AND sf.total_amount IS NOT NULL");
}

$balanceColumns = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (?, ?)'
);
$balanceColumns->execute(['transactions', 'balance_before', 'balance_after']);
if ((int)$balanceColumns->fetchColumn() === 2) {
    $pdo->exec("UPDATE mobile_recharges r
        INNER JOIN transactions t ON t.idempotency_key = r.order_id AND t.type = 'topup'
        SET r.total_cost = GREATEST(0, t.balance_before - t.balance_after)
        WHERE r.total_cost IS NULL
          AND t.balance_before IS NOT NULL
          AND t.balance_after IS NOT NULL
          AND t.balance_before > t.balance_after");
}

$unresolved = (int)$pdo->query('SELECT COUNT(*) FROM mobile_recharges WHERE total_cost IS NULL')->fetchColumn();
if ($unresolved > 0) {
    throw new RuntimeException(
        'Cannot safely determine the wallet debit for ' . $unresolved
        . ' legacy top-up row(s); reconcile those rows before enabling top-up.'
    );
}

echo 'Top-up total cost migration complete.' . PHP_EOL;
