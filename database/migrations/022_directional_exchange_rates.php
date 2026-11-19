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

foreach ([
    'source_currency' => 'VARCHAR(8) NULL',
    'destination_currency' => 'VARCHAR(8) NULL',
    'exchange_rate' => 'DECIMAL(30,12) NULL',
    'commission_percentage' => 'DECIMAL(7,4) NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (!$hasColumn('exchange_rates', $column)) {
        $pdo->exec('ALTER TABLE exchange_rates ADD COLUMN `' . $column . '` ' . $definition);
    }
}

// Legacy rates represent Toman per Afghani. Store the equivalent direction
// explicitly as IRT per unit of AFN (AFN per IRT) without changing old columns.
$legacyRateColumn = $hasColumn('exchange_rates', 'afn_to_toman') ? 'afn_to_toman' : 'rate';
$pdo->exec("UPDATE exchange_rates SET source_currency = 'IRT', destination_currency = 'AFN', exchange_rate = CASE WHEN `{$legacyRateColumn}` > 0 THEN 1 / `{$legacyRateColumn}` ELSE NULL END WHERE source_currency IS NULL AND destination_currency IS NULL AND exchange_rate IS NULL");
$index = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
$index->execute(['exchange_rates', 'idx_exchange_rates_pair_effective']);
if ((int)$index->fetchColumn() === 0) {
    $pdo->exec('CREATE INDEX idx_exchange_rates_pair_effective ON exchange_rates (source_currency, destination_currency, is_active, effective_date, id)');
}

foreach ([
    'source_currency' => 'VARCHAR(8) NULL',
    'destination_currency' => 'VARCHAR(8) NULL',
    'exchange_rate_id' => 'BIGINT UNSIGNED NULL',
    'exchange_rate_value' => 'DECIMAL(30,12) NULL',
    'exchange_commission_percentage' => 'DECIMAL(7,4) NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (!$hasColumn('remittances', $column)) {
        $pdo->exec('ALTER TABLE remittances ADD COLUMN `' . $column . '` ' . $definition);
    }
}

// Existing remittances retain their original amounts; only annotate their
// known historical currency pair for future receipts and reporting.
$pdo->exec("UPDATE remittances SET source_currency = 'IRT', destination_currency = 'AFN' WHERE source_currency IS NULL AND destination_currency IS NULL");

echo 'Directional exchange rate columns added; historical data preserved.' . PHP_EOL;
