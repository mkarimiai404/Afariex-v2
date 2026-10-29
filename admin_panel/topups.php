<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_permission('view');
$rows = [];
$error = null;
try {
    $columns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach (['topup_mobile', 'topup_operator', 'provider_ref_code', 'idempotency_key'] as $required) if (!in_array($required, $columns, true)) throw new RuntimeException('TOPUP_SCHEMA_MISSING');
    $descriptionSelect = in_array('description', $columns, true) ? 't.description' : "'' AS description";
    $stmt = db()->query("SELECT t.id, t.amount, t.status, t.topup_mobile, t.topup_operator, t.provider_ref_code, t.idempotency_key, t.created_at, {$descriptionSelect}, u.mobile AS user_mobile FROM transactions t INNER JOIN users u ON u.id = t.user_id WHERE t.type = 'topup' ORDER BY t.id DESC LIMIT 200");
    $rows = $stmt->fetchAll();
} catch (Throwable $errorObject) {
    $error = $errorObject->getMessage() === 'TOPUP_SCHEMA_MISSING' ? 'ابتدا migration خرید شارژ را روی دیتابیس production اجرا کنید.' : 'دریافت گزارش خرید شارژ انجام نشد.';
}
$operatorLabels = ['MCI' => 'همراه اول', 'MTN' => 'ایرانسل', 'RTL' => 'رایتل'];
$statusLabels = ['pending' => 'در انتظار', 'success' => 'موفق', 'failed' => 'ناموفق'];
render_page_start('پایش خرید شارژ موبایل', 'topups');
?>
<style>
.topup-table{width:100%;border-collapse:collapse;background:#fff}.topup-table th,.topup-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:right;font-size:12px;vertical-align:top}.topup-table th{background:#f8fafc;color:#475569}.topup-status{display:inline-block;border-radius:8px;padding:4px 8px;background:#fef3c7;color:#92400e}.topup-status.success{background:#dcfce7;color:#166534}.topup-status.failed{background:#fee2e2;color:#991b1b}.topup-muted{color:#64748b;font-size:11px;word-break:break-all}
</style>
<?php if ($error): ?>
<div class="alert error"><?= e($error) ?></div>
<?php else: ?>
<div class="card" style="overflow:auto"><table class="topup-table"><thead><tr><th>شناسه</th><th>کاربر</th><th>شماره شارژ</th><th>اپراتور</th><th>مبلغ</th><th>وضعیت</th><th>کد مرجع</th><th>زمان</th></tr></thead><tbody>
<?php foreach ($rows as $row): $status = strtolower((string)$row['status']); ?>
<tr><td><?= e((string)$row['id']) ?><div class="topup-muted"><?= e((string)$row['idempotency_key']) ?></div></td><td><?= e((string)$row['user_mobile']) ?></td><td><?= e((string)$row['topup_mobile']) ?></td><td><?= e($operatorLabels[(string)$row['topup_operator']] ?? (string)$row['topup_operator']) ?></td><td><?= e(number_format((float)$row['amount'])) ?> تومان</td><td><span class="topup-status <?= e($status) ?>"><?= e($statusLabels[$status] ?? $status) ?></span></td><td><?= e((string)($row['provider_ref_code'] ?? '—')) ?></td><td><?= e((string)$row['created_at']) ?><div class="topup-muted"><?= e((string)($row['description'] ?? '')) ?></div></td></tr>
<?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="8">هنوز تراکنش خرید شارژی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php render_page_end(); ?>