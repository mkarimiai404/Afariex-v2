<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();

$tableExists = static function (string $table) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() > 0;
};

$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() > 0;
};

$indexExists = static function (string $table, string $index) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $statement->execute([$table, $index]);
    return (int)$statement->fetchColumn() > 0;
};

$indexCoversColumns = static function (string $table, array $columns) use ($pdo): bool {
    $statement = $pdo->prepare(
        'SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS indexed_columns
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?
         GROUP BY index_name'
    );
    $statement->execute([$table]);
    $required = implode(',', $columns);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
        if (($index['indexed_columns'] ?? '') === $required) return true;
    }
    return false;
};

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_verification_levels (
    user_id INT NOT NULL PRIMARY KEY,
    level VARCHAR(32) NOT NULL DEFAULT 'initial',
    phone_verified TINYINT(1) NOT NULL DEFAULT 0,
    phone_verified_at DATETIME NULL,
    withdrawal_limit DECIMAL(20,2) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS verification_upgrade_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_type VARCHAR(16) NOT NULL DEFAULT 'silver',
    requested_level VARCHAR(32) NOT NULL DEFAULT 'verified',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    identity_document_path VARCHAR(255) NULL,
    selfie_path VARCHAR(255) NULL,
    video_path VARCHAR(255) NULL,
    admin_id INT NULL,
    admin_note TEXT NULL,
    rejection_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_upgrade_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$columns = [
    'user_verification_levels' => [
        'level' => "VARCHAR(32) NOT NULL DEFAULT 'initial' AFTER user_id",
        'phone_verified' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER level',
        'phone_verified_at' => 'DATETIME NULL AFTER phone_verified',
        'withdrawal_limit' => 'DECIMAL(20,2) NULL AFTER phone_verified_at',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER withdrawal_limit',
    ],
    'verification_upgrade_requests' => [
        'request_type' => "VARCHAR(16) NOT NULL DEFAULT 'silver' AFTER user_id",
        'requested_level' => "VARCHAR(32) NOT NULL DEFAULT 'verified' AFTER request_type",
        'status' => "ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER requested_level",
        'identity_document_path' => 'VARCHAR(255) NULL AFTER status',
        'selfie_path' => 'VARCHAR(255) NULL AFTER identity_document_path',
        'video_path' => 'VARCHAR(255) NULL AFTER selfie_path',
        'admin_id' => 'INT NULL AFTER video_path',
        'admin_note' => 'TEXT NULL AFTER admin_id',
        'rejection_reason' => 'TEXT NULL AFTER admin_note',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER rejection_reason',
        'reviewed_at' => 'DATETIME NULL AFTER created_at',
    ],
];

foreach ($columns as $table => $definitions) {
    if (!$tableExists($table)) throw new RuntimeException("Verification table {$table} could not be created.");
    foreach ($definitions as $column => $definition) {
        if (!$columnExists($table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!$indexCoversColumns('verification_upgrade_requests', ['user_id', 'status'])) {
    $indexName = $indexExists('verification_upgrade_requests', 'idx_upgrade_user_status')
        ? 'idx_verification_user_status_review'
        : 'idx_upgrade_user_status';
    if (!$indexExists('verification_upgrade_requests', $indexName)) {
        $pdo->exec("CREATE INDEX `{$indexName}` ON verification_upgrade_requests (user_id, status)");
    }
}

echo 'Verification review workflow migration complete for database ' . DB_NAME . PHP_EOL;
