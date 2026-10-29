<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
$tableCheck->execute(['remittances']);
if ((int)$tableCheck->fetchColumn() !== 1) {
    throw new RuntimeException('The remittances table is not present in the selected database.');
}

$columnCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$columnCheck->execute(['remittances', 'destination_country']);
if ((int)$columnCheck->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE `remittances` ADD COLUMN `destination_country` CHAR(2) NULL');
}

echo 'Remittance destination country migration complete.' . PHP_EOL;
