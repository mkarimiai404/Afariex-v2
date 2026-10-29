<?php
declare(strict_types=1);

if ((string)getenv('DB_NAME') !== 'afariex_withdrawal_test' || (string)getenv('WITHDRAWAL_TEST_MODE') !== '1') {
    fwrite(STDERR, "Refusing to run outside the isolated withdrawal test database.\n");
    exit(2);
}

require_once __DIR__ . '/../admin_panel/withdrawal_service.php';
$pdo = db();
$pdo->exec("CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mobile VARCHAR(32) NOT NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    balance DECIMAL(20,2) NOT NULL DEFAULT 0,
    api_token VARCHAR(128) NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(20,2) NOT NULL,
    type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    balance_applied TINYINT(1) NOT NULL DEFAULT 0,
    balance_applied_at DATETIME NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
require __DIR__ . '/../database/migrations/005_withdrawal_workflow.php';
ensure_verification_schema();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$pdo->exec("INSERT INTO users (mobile, first_name, last_name, balance, api_token) VALUES
    ('09120000001', 'کاربر', 'اول', 1000000, 'token-one'),
    ('09120000002', 'کاربر', 'دوم', 50000, 'token-two'),
    ('09120000003', 'کاربر', 'سوم', 1000000, 'token-three')");
$pdo->exec("INSERT INTO user_verification_levels (user_id, level, phone_verified, phone_verified_at) VALUES
    (1, 'bronze', 1, NOW()), (2, 'bronze', 1, NOW()), (3, 'bronze', 1, NOW())");

$first = withdrawal_create_request(1, '100000', '6037997519254321', 'کاربر اول', 'customer-request-0001', 'customer');
$assert($first['status'] === 'pending', 'customer request must be pending');
$repeat = withdrawal_create_request(1, '100000', '6037997519254321', 'کاربر اول', 'customer-request-0001', 'customer');
$assert($repeat['id'] === $first['id'], 'duplicate must return the original request');
$assert((int)$pdo->query('SELECT COUNT(*) FROM transactions WHERE user_id = 1')->fetchColumn() === 1, 'duplicate request inserted twice');
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 1')->fetchColumn() === 900000.0, 'duplicate request debited twice');

try {
    withdrawal_create_request(2, '100000', '6037997519254321', 'کاربر دوم', 'customer-request-0002', 'customer');
    throw new RuntimeException('insufficient balance was accepted');
} catch (DomainException $e) {
    $assert($e->getMessage() === 'INSUFFICIENT_BALANCE', 'wrong insufficient-balance result');
}
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 2')->fetchColumn() === 50000.0, 'failed request changed balance');

$assert(withdrawal_transition($first['id'], 'approve', 10) === 'approved', 'approve transition failed');
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 1')->fetchColumn() === 900000.0, 'approve changed balance');
try { withdrawal_transition($first['id'], 'approve', 10); throw new RuntimeException('approve replay succeeded'); }
catch (DomainException $e) { $assert($e->getMessage() === 'INVALID_STATE_TRANSITION', 'approve replay returned wrong error'); }
$assert(withdrawal_transition($first['id'], 'paid', 10) === 'paid', 'paid transition failed');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 1')->fetchColumn() === 1, 'paid notification missing or duplicated');
try { withdrawal_transition($first['id'], 'paid', 10); throw new RuntimeException('paid replay succeeded'); }
catch (DomainException $e) { $assert($e->getMessage() === 'INVALID_STATE_TRANSITION', 'paid replay returned wrong error'); }
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 1')->fetchColumn() === 1, 'paid replay duplicated notification');

$rejected = withdrawal_create_request(3, '200000', '6037997519254321', 'کاربر سوم', 'customer-request-0003', 'customer');
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 3')->fetchColumn() === 800000.0, 'pending request was not reserved');
$assert(withdrawal_transition($rejected['id'], 'reject', 10) === 'rejected', 'reject transition failed');
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 3')->fetchColumn() === 1000000.0, 'reject did not refund exactly once');
try { withdrawal_transition($rejected['id'], 'reject', 10); throw new RuntimeException('reject replay succeeded'); }
catch (DomainException $e) { $assert($e->getMessage() === 'INVALID_STATE_TRANSITION', 'reject replay returned wrong error'); }
$assert((float)$pdo->query('SELECT balance FROM users WHERE id = 3')->fetchColumn() === 1000000.0, 'reject replay refunded twice');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 3')->fetchColumn() === 1, 'reject notification missing or duplicated');

$manual = withdrawal_create_request(1, '100000', '6037997519254321', 'کاربر اول', 'admin-request-00001', 'admin', 10);
$manualStmt = $pdo->prepare('SELECT status, request_source, operator_admin_id FROM transactions WHERE id = ?');
$manualStmt->execute([$manual['id']]);
$manualRow = $manualStmt->fetch();
$assert($manualRow['status'] === 'pending' && $manualRow['request_source'] === 'admin' && (int)$manualRow['operator_admin_id'] === 10, 'manual request bypassed workflow or lost its source');
try { withdrawal_transition($manual['id'], 'paid', 10); throw new RuntimeException('pending request was paid directly'); }
catch (DomainException $e) { $assert($e->getMessage() === 'INVALID_STATE_TRANSITION', 'direct paid returned wrong error'); }

echo "Withdrawal integration tests passed.\n";
