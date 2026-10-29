<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin_panel/config.php';

$pdo = db();

$hasIndex = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

$pdo->exec("
    CREATE TABLE IF NOT EXISTS service_fees (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        service_code VARCHAR(64) NOT NULL,
        service_name VARCHAR(150) NOT NULL,
        fee_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
        fee_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        min_fee BIGINT UNSIGNED NULL,
        max_fee BIGINT UNSIGNED NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        description TEXT NULL,
        updated_by_admin_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_service_fees_code (service_code),
        INDEX idx_service_fees_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS service_fee_transactions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        service_code VARCHAR(64) NOT NULL,
        source_type VARCHAR(64) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        original_amount BIGINT UNSIGNED NOT NULL,
        configured_fee_type VARCHAR(20) NOT NULL,
        configured_fee_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        fee_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_amount BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fee_source (source_type, source_id),
        INDEX idx_fee_user_created (user_id, created_at),
        INDEX idx_fee_service_status_created (service_code, status, created_at),
        INDEX idx_fee_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!$hasIndex('service_fee_transactions', 'idx_fee_user_created')) {
    $pdo->exec('CREATE INDEX idx_fee_user_created ON service_fee_transactions (user_id, created_at)');
}

$defaults = [
    ['remittance', 'حواله'],
    ['withdrawal', 'انتقال وجه / برداشت'],
    ['mobile_recharge', 'خرید کارت شارژ'],
    ['internet_package', 'خرید بسته اینترنت'],
    ['money_transfer', 'انتقال وجه'],
    ['other', 'سایر خدمات'],
];
$stmt = $pdo->prepare("INSERT INTO service_fees (service_code, service_name, fee_type, fee_value, is_active, created_at, updated_at)
    VALUES (?, ?, 'fixed', 0, 0, NOW(), NOW())
    ON DUPLICATE KEY UPDATE service_name = VALUES(service_name)");
foreach ($defaults as $default) {
    $stmt->execute($default);
}

echo 'Service fee management migration complete for database ' . DB_NAME . PHP_EOL;
