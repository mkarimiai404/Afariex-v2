<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/balance_view_helpers.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$assert(admin_balance_with_unit('1250000') === '1,250,000 تومان', 'positive balance formatting');
$assert(admin_balance_with_unit('0') === '0 تومان', 'zero balance formatting');
$assert(admin_balance_with_unit('123456789012345678') === '123,456,789,012,345,678 تومان', 'large balance formatting without float precision loss');
$assert(admin_balance_with_unit(null) === '—', 'missing legacy owner is not displayed as zero');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile TEXT, balance NUMERIC NOT NULL)');
$pdo->exec('CREATE TABLE remittances (id INTEGER PRIMARY KEY, user_id INTEGER, amount_toman NUMERIC, status TEXT)');
$pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INTEGER, amount NUMERIC, status TEXT, balance_before NUMERIC NULL, balance_after NUMERIC NULL, balance_applied INTEGER, refund_applied INTEGER)');
$pdo->exec("INSERT INTO users VALUES (1, '09120000001', 900), (2, '09120000002', 100), (3, '09120000003', 1234567890123), (4, '09120000004', 0)");
$pdo->exec("INSERT INTO remittances VALUES (11, 1, 250, 'pending'), (12, 99, 50, 'pending')");
$pdo->exec("INSERT INTO transactions VALUES (21, 1, 100, 'pending', 1000, 900, 1, 0), (22, 2, 100, 'rejected', 100, 0, 1, 1), (23, 3, 500, 'paid', 1234567890623, 1234567890123, 1, 0)");
$fixtureChanges = $pdo->query('SELECT total_changes()')->fetchColumn();

$users = $pdo->query('SELECT id, balance FROM users ORDER BY id')->fetchAll();
$assert(admin_balance_with_unit($users[0]['balance']) === '900 تومان', 'users list reads users.balance');
$assert(admin_balance_with_unit($users[3]['balance']) === '0 تومان', 'users list preserves zero balance');

$remittances = $pdo->query('SELECT r.id, r.user_id, r.amount_toman, u.balance AS user_balance FROM remittances r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id')->fetchAll();
$assert((int)$remittances[0]['user_id'] === 1 && admin_balance_with_unit($remittances[0]['user_balance']) === '900 تومان', 'remittance balance belongs to its user_id');
$assert(admin_balance_with_unit($remittances[0]['amount_toman']) === '250 تومان', 'remittance amount remains separate from current balance');
$assert(admin_balance_with_unit($remittances[1]['user_balance']) === '—', 'orphaned legacy remittance remains safe');

$withdrawals = $pdo->query('SELECT t.*, u.balance AS current_balance FROM transactions t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id')->fetchAll();
$pending = admin_withdrawal_balance_summary($withdrawals[0]);
$assert($pending['مبلغ درخواست برداشت'] === '100 تومان', 'pending withdrawal amount display');
$assert($pending['موجودی قبل از ثبت درخواست'] === '1,000 تومان', 'pending withdrawal balance_before display');
$assert($pending['موجودی پس از رزرو مبلغ'] === '900 تومان', 'pending withdrawal balance_after display');
$assert($pending['موجودی فعلی حساب'] === '900 تومان', 'pending withdrawal canonical current balance');
$assert(admin_withdrawal_reservation_note_is_accurate($withdrawals[0]), 'pending reserved withdrawal note is shown');

$rejected = admin_withdrawal_balance_summary($withdrawals[1]);
$assert($rejected['موجودی فعلی حساب'] === '100 تومان' && (int)$withdrawals[1]['refund_applied'] === 1, 'rejected withdrawal shows post-refund canonical balance');
$assert(!admin_withdrawal_reservation_note_is_accurate($withdrawals[1]), 'rejected/refunded withdrawal does not imply funds remain reserved');

$paid = admin_withdrawal_balance_summary($withdrawals[2]);
$assert($paid['موجودی فعلی حساب'] === '1,234,567,890,123 تومان', 'paid withdrawal displays current owner balance');
$assert(admin_withdrawal_reservation_note_is_accurate($withdrawals[2]), 'paid withdrawal accurately records reservation at creation');
$assert((int)$withdrawals[0]['user_id'] === 1 && (float)$withdrawals[0]['current_balance'] === 900.0, 'withdrawal balance ownership follows t.user_id');
$assert((string)$pdo->query('SELECT total_changes()')->fetchColumn() === (string)$fixtureChanges, 'all post-fixture display checks are read-only');

$layout = (string)file_get_contents(__DIR__ . '/../admin_panel/layout.php');
$usersSource = (string)file_get_contents(__DIR__ . '/../admin_panel/users.php');
$remittanceSource = (string)file_get_contents(__DIR__ . '/../admin_panel/remittances.php');
$withdrawalSource = (string)file_get_contents(__DIR__ . '/../admin_panel/withdrawals.php');
$receiptsSource = (string)file_get_contents(__DIR__ . '/../admin_panel/receipts.php');
$remittanceDetailSource = (string)file_get_contents(__DIR__ . '/../admin_panel/view_receipt.php');
$assert(str_contains($layout, '<html lang="fa" dir="rtl">'), 'admin rendering remains RTL');
$assert(str_contains($usersSource, '@media (max-width: 840px)') && str_contains($remittanceSource, '@media (max-width: 840px)') && str_contains($withdrawalSource, '@media (max-width:840px)'), 'balance presentation retains responsive rules');
$assert(str_contains($usersSource, 'SELECT id, mobile, first_name, last_name, balance') && str_contains($remittanceSource, 'u.balance AS user_balance') && str_contains($withdrawalSource, 'u.balance AS current_balance'), 'every balance display selects canonical users.balance');
$assert(str_contains($receiptsSource, 'u.balance AS user_balance') && str_contains($remittanceDetailSource, 'u.balance AS user_balance'), 'other relevant financial review views use users.balance');
$assert(strpos($remittanceDetailSource, 'require_permission(\'view\')') < strpos($remittanceDetailSource, 'u.balance AS user_balance'), 'remittance detail authenticates before querying balance');
$assert(str_contains($remittanceDetailSource, '.admin-current-balance{max-width:210mm') && str_contains($remittanceDetailSource, '@media(max-width:900px)'), 'remittance detail balance is distinct and responsive');

echo "Admin balance visibility fixtures passed ({$assertions} assertions)." . PHP_EOL;
