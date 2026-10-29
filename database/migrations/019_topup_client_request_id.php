<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() > 0;
};
$hasIndex = static function (string $table, string $index) use ($pdo): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $statement->execute([$table, $index]);
    return (int)$statement->fetchColumn() > 0;
};

if (!$hasColumn('transactions', 'client_request_id')) {
    $pdo->exec('ALTER TABLE `transactions` ADD COLUMN `client_request_id` CHAR(36) NULL');
}
if (!$hasColumn('mobile_recharges', 'client_request_id')) {
    $pdo->exec('ALTER TABLE `mobile_recharges` ADD COLUMN `client_request_id` CHAR(36) NULL');
}
if (!$hasIndex('transactions', 'uq_topup_client_request')) {
    $pdo->exec("CREATE UNIQUE INDEX `uq_topup_client_request` ON `transactions` (`user_id`, `type`, `client_request_id`)");
}
if (!$hasIndex('mobile_recharges', 'uq_recharge_client_request')) {
    $pdo->exec('CREATE UNIQUE INDEX `uq_recharge_client_request` ON `mobile_recharges` (`user_id`, `client_request_id`)');
}

echo 'Top-up client request idempotency migration complete.' . PHP_EOL;
