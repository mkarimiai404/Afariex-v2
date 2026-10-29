<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();
$hasColumn = static function (string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute(['users', $column]);
    return (int)$stmt->fetchColumn() > 0;
};
$addColumn = static function (string $column, string $definition) use ($pdo, $hasColumn): void {
    if (!$hasColumn($column)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
    }
};

$addColumn('terms_accepted_at', 'DATETIME NULL');
$addColumn('terms_version', 'VARCHAR(16) NULL');
$addColumn('privacy_accepted_at', 'DATETIME NULL');
$addColumn('privacy_version', 'VARCHAR(16) NULL');

echo 'Registration consent migration complete for database ' . DB_NAME . PHP_EOL;
