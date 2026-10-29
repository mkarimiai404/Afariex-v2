<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$statement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
);
$statement->execute(['users', 'pin_hash']);
if ((int)$statement->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `pin_hash` VARCHAR(255) NULL");
}

echo "PIN hash migration complete for database " . DB_NAME . PHP_EOL;
