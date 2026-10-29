<?php
declare(strict_types=1);

$page = (string)file_get_contents(__DIR__ . '/../admin_panel/withdrawals.php');
$service = (string)file_get_contents(__DIR__ . '/../admin_panel/withdrawal_service.php');
$adminStatusSource = (string)file_get_contents(__DIR__ . '/../admin_panel/withdrawal_admin_status.php');
$sessionDirectory = __DIR__ . DIRECTORY_SEPARATOR . '.withdrawal-ui-session-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) throw new RuntimeException('Could not create isolated session directory.');
session_save_path($sessionDirectory);
register_shutdown_function(static function () use ($sessionDirectory): void {
    foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $sessionFile) @unlink($sessionFile);
    @rmdir($sessionDirectory);
});
putenv('APP_ENV=local');
require_once __DIR__ . '/../admin_panel/withdrawal_service.php';
require_once __DIR__ . '/../admin_panel/withdrawal_admin_status.php';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

foreach ([
    "'pending' => 'در انتظار بررسی'",
    "'approved' => 'تأیید شده / آماده پرداخت'",
    "'paid' => 'پرداخت شده'",
    "'rejected' => 'رد شده'",
] as $statusLabel) {
    $assert(str_contains($adminStatusSource, $statusLabel), 'required status label: ' . $statusLabel);
}
$assert(str_contains($page, '<option value="">همه</option>'), 'all-status filter');
$assert(str_contains($page, "['pending', 'approved', 'paid', 'rejected']"), 'filter allowlist');
$assert(str_contains($page, '$statusFilter === $status'), 'selected filter state');

$assert(withdrawal_admin_status_options('pending') === ['pending', 'approved', 'rejected'], 'pending selector fixture');
$assert(withdrawal_admin_status_options('approved') === ['approved', 'paid'], 'approved selector fixture');
$assert(withdrawal_admin_status_options('paid') === ['paid'], 'paid selector fixture');
$assert(withdrawal_admin_status_options('rejected') === ['rejected'], 'rejected selector fixture');
$assert(withdrawal_admin_status_options('legacy') === [], 'legacy status is read-only');
$assert(str_contains($page, 'وضعیت برداشت:'), 'explicit withdrawal status label');
$assert(str_contains($page, 'name="withdrawal_status"'), 'manual status selector');
$assert(str_contains($page, '>ثبت وضعیت</button>'), 'explicit save-status button');
$assert(str_contains($page, "(string)\$row['status'] === \$statusOption ? 'selected'"), 'selector reflects stored row status');
$assert(str_contains($page, "count(\$statusOptions) === 1 || !can('edit') ? 'disabled'"), 'terminal and unauthorized selector is disabled');
$assert(str_contains($page, "if (\$managedRequest && \$statusOptions !== [])"), 'legacy rows receive no selector');
$assert(str_contains($page, 'آیا مبلغ این برداشت واقعاً به کارت مشتری پرداخت شده است؟ پس از ثبت وضعیت پرداخت شده، این عملیات قابل بازگشت نیست.'), 'exact irreversible paid confirmation');
$assert(str_contains($page, "confirmation.value = 'rejected'"), 'reject confirmation flag');

$assert(str_contains($page, "if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST')"), 'mutations are POST-only');
$assert(str_contains($page, "verify_csrf_or_fail(\$_POST['csrf_token'] ?? null)"), 'CSRF validation');
$assert(strpos($page, "verify_csrf_or_fail(\$_POST['csrf_token'] ?? null)") < strpos($page, "\$action = trim"), 'CSRF is checked before dispatch');
$assert(str_contains($page, "require_login()") && str_contains($page, "require_permission('view')"), 'unauthenticated admin is rejected');
$assert(str_contains($page, "require_permission('edit')"), 'edit permission for transitions');
$assert(str_contains($page, "SELECT status, request_source FROM transactions WHERE id = ?"), 'server reloads actual database status');
$assert(str_contains($page, 'withdrawal_admin_transition_action($currentStatus, $requestedStatus)'), 'server validates selected next status');
$assert(str_contains($page, 'withdrawal_transition($requestId, $transitionAction'), 'page reuses withdrawal state machine');
$assert(str_contains($page, "hash_equals(\$requestedStatus"), 'paid/rejected confirmation is server-required');

$transitionStart = strpos($service, 'function withdrawal_transition(');
$assert($transitionStart !== false, 'withdrawal transition service exists');
$transition = $transitionStart === false ? '' : substr($service, $transitionStart);
$assert(str_contains($service, "'approve' => ['from' => 'pending', 'to' => 'approved']"), 'pending to approved transition');
$assert(str_contains($service, "'paid' => ['from' => 'approved', 'to' => 'paid']"), 'approved to paid transition');
$assert(str_contains($service, "'reject' => ['from' => 'pending', 'to' => 'rejected']"), 'pending to rejected transition');
$assert(withdrawal_target_status('pending', 'approve') === 'approved', 'live pending to approved state-machine result');
$assert(withdrawal_target_status('approved', 'paid') === 'paid', 'live approved to paid state-machine result');
$assert(withdrawal_target_status('pending', 'reject') === 'rejected', 'live pending to rejected state-machine result');
$assert(withdrawal_admin_transition_action('pending', 'approved') === 'approve', 'pending selector maps to approve action');
$assert(withdrawal_admin_transition_action('pending', 'rejected') === 'reject', 'pending selector maps to reject action');
$assert(withdrawal_admin_transition_action('approved', 'paid') === 'paid', 'approved selector maps to paid action');
$assert(withdrawal_admin_transition_action('approved', 'approved') === null, 'unchanged selected status is a no-op');
try {
    withdrawal_target_status('pending', 'paid');
    $assert(false, 'direct pending to paid must fail');
} catch (DomainException $error) {
    $assert($error->getMessage() === 'INVALID_STATE_TRANSITION', 'direct pending to paid is server-rejected');
}
foreach ([['pending', 'paid'], ['approved', 'pending'], ['approved', 'rejected'], ['paid', 'pending'], ['rejected', 'approved']] as [$current, $target]) {
    try {
        withdrawal_admin_transition_action($current, $target);
        $assert(false, "invalid selector transition {$current} -> {$target} must fail");
    } catch (DomainException $error) {
        $assert($error->getMessage() === 'INVALID_STATE_TRANSITION', "invalid selector transition {$current} -> {$target} is rejected");
    }
}
try {
    withdrawal_target_status('paid', 'reject');
    $assert(false, 'terminal paid action must fail');
} catch (DomainException $error) {
    $assert($error->getMessage() === 'INVALID_STATE_TRANSITION', 'terminal paid state is server-protected');
}
$assert(!str_contains($transition, 'balance = balance -'), 'approve/paid transition does not deduct again');
$assert(str_contains($transition, 'refund_applied = 1 WHERE id = ? AND refund_applied = 0'), 'reject refund is idempotently marked');
$assert(substr_count($transition, 'balance = balance + ?') === 1, 'reject contains exactly one refund operation');
$assert(str_contains($transition, "WHERE id = ? AND status = ?"), 'server validates current state during transition');

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $sessionFile) @unlink($sessionFile);
@rmdir($sessionDirectory);
echo "Withdrawal admin UI audit passed ({$assertions} assertions)." . PHP_EOL;
