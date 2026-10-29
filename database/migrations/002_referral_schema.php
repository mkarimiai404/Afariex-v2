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

if (!$hasColumn('users', 'referral_code')) {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(32) NULL UNIQUE");
}
if (!$hasColumn('users', 'referred_by_user_id')) {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `referred_by_user_id` INT NULL");
}

$indexStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
);
$indexStatement->execute(['users', 'idx_users_referred_by_user_id']);
if ((int)$indexStatement->fetchColumn() === 0) {
    $pdo->exec("CREATE INDEX idx_users_referred_by_user_id ON users (referred_by_user_id)");
}
echo "Referral migration complete for database " . DB_NAME . PHP_EOL;
