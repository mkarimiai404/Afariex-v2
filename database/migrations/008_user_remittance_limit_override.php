<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$table = 'user_verification_levels';
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
$exists->execute([$table]);
if ((int)$exists->fetchColumn() === 0) {
    throw new RuntimeException('Required table user_verification_levels does not exist. Run the verification schema migration first.');
}
$column = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$column->execute([$table, 'custom_remittance_limit']);
if ((int)$column->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE user_verification_levels ADD COLUMN custom_remittance_limit DECIMAL(20,2) NULL AFTER withdrawal_limit');
}
echo 'User remittance limit override migration complete.' . PHP_EOL;
