<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../API/lib/InaxClient.php';
require_login();
require_permission('view');

$operatorLabels = [
    'MCI' => 'همراه اول',
    'MTN' => 'ایرانسل',
    'RTL' => 'رایتل',
    'SHT' => 'شاتل موبایل',
];
$statusLabels = [
    'completed' => 'تکمیل‌شده',
    'pending' => 'در انتظار',
    'failed' => 'ناموفق',
    'refunded' => 'مستردشده',
];
$thresholdSetting = trim((string)(getenv('INAX_LOW_BALANCE_THRESHOLD_TOMAN') ?: '2000000'));
$lowBalanceThreshold = preg_match('/^\d{1,15}$/', $thresholdSetting) === 1 ? (int)$thresholdSetting : 2000000;

$vendorBalance = null;
$balanceError = null;
try {
    $creditResponse = (new InaxClient())->credit();
    $creditData = is_array($creditResponse['data'] ?? null) ? $creditResponse['data'] : [];
    $balanceCandidates = [
        $creditResponse['balance'] ?? null,
        $creditResponse['credit'] ?? null,
        $creditData['balance'] ?? null,
        $creditData['credit'] ?? null,
    ];
    foreach ($balanceCandidates as $candidate) {
        if (is_numeric($candidate) && is_finite((float)$candidate)) {
            $vendorBalance = (float)$candidate;
            break;
        }
    }
    if ($vendorBalance === null) {
        $balanceError = 'ساختار پاسخ موجودی فروشنده قابل تشخیص نبود.';
    }
} catch (Throwable $exception) {
    // Keep provider details out of the admin page; the client records sanitized diagnostics.
    $balanceError = 'دریافت موجودی کیف پول ایناکس در حال حاضر ممکن نیست.';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$search = trim((string)($_GET['mobile'] ?? ''));
$operator = strtoupper(trim((string)($_GET['operator'] ?? '')));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
if (!array_key_exists($operator, $operatorLabels)) $operator = '';
if (!array_key_exists($status, $statusLabels)) $status = '';
$rows = [];
$totalRows = 0;
$tableError = null;
$receiptRows = [];

try {
    $availableColumns = db()->query('SHOW COLUMNS FROM mobile_recharges')->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($availableColumns === []) {
        throw new RuntimeException('MOBILE_RECHARGES_TABLE_MISSING');
    }

    // Select only fields that exist on this deployment, so older installations remain usable.
    $knownColumns = [
        'id', 'user_id', 'mobile', 'operator', 'charge_type', 'amount', 'order_id',
        'trans_id', 'ref_code', 'status', 'error_code', 'error_msg', 'created_at', 'updated_at',
    ];
    $selectParts = [];
    foreach ($knownColumns as $column) {
        if (in_array($column, $availableColumns, true)) {
            $selectParts[] = 'r.`' . $column . '`';
        }
    }
    if (!in_array('id', $availableColumns, true)) {
        throw new RuntimeException('MOBILE_RECHARGES_ID_MISSING');
    }

    $transactionJoin = '';
    $transactionIdSelect = 'NULL AS transaction_id';
    if (in_array('order_id', $availableColumns, true) && table_exists('transactions')) {
        try {
            $transactionColumns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('idempotency_key', $transactionColumns, true) && in_array('id', $transactionColumns, true) && in_array('type', $transactionColumns, true)) {
                $transactionJoin = " LEFT JOIN (SELECT idempotency_key, MIN(id) AS id FROM transactions WHERE type = 'topup' GROUP BY idempotency_key) t ON t.idempotency_key = r.`order_id`";
                $transactionIdSelect = 't.`id` AS transaction_id';
            }
        } catch (Throwable $exception) {
            // Recharge history remains available when the companion transaction schema is older.
        }
    }

    $where = [];
    $params = [];
    if ($search !== '' && in_array('mobile', $availableColumns, true)) {
        $where[] = 'r.`mobile` LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($operator !== '' && in_array('operator', $availableColumns, true)) {
        $where[] = 'r.`operator` = ?';
        $params[] = $operator;
    }
    if ($status !== '' && in_array('status', $availableColumns, true)) {
        $where[] = 'r.`status` = ?';
        $params[] = $status;
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $count = db()->prepare('SELECT COUNT(*) FROM mobile_recharges r' . $transactionJoin . $whereSql);
    $count->execute($params);
    $totalRows = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $query = db()->prepare('SELECT ' . implode(', ', $selectParts) . ', ' . $transactionIdSelect . ' FROM mobile_recharges r' . $transactionJoin . $whereSql . ' ORDER BY r.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $receiptRows[(string)$row['id']] = [
            'id' => $row['id'] ?? null,
            'transaction_id' => $row['transaction_id'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'mobile' => $row['mobile'] ?? null,
            'operator' => $row['operator'] ?? null,
            'charge_type' => $row['charge_type'] ?? null,
            'amount' => $row['amount'] ?? null,
            'order_id' => $row['order_id'] ?? null,
            'trans_id' => $row['trans_id'] ?? null,
            'ref_code' => $row['ref_code'] ?? null,
            'status' => $row['status'] ?? null,
            'error_code' => $row['error_code'] ?? null,
            'error_msg' => $row['error_msg'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
} catch (Throwable $exception) {
    $tableError = str_contains($exception->getMessage(), 'MOBILE_RECHARGES_')
        ? 'جدول سفارش‌های شارژ یا ستون شناسه آن در این پایگاه داده موجود نیست.'
        : 'دریافت سفارش‌های شارژ انجام نشد.';
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$receiptJson = json_encode($receiptRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if (!is_string($receiptJson)) $receiptJson = '{}';
$queryForPage = static function (int $targetPage) use ($search, $operator, $status): string {
    return http_build_query(array_filter([
        'page' => $targetPage,
        'mobile' => $search,
        'operator' => $operator,
        'status' => $status,
    ], static fn($value): bool => $value !== '' && $value !== null));
};

render_page_start('سفارش‌های شارژ', 'topup_orders');
?>
<style>
.topup-page{display:flex;flex-direction:column;gap:18px}.topup-summary{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px 24px}.topup-balance-label{color:#64748b;font-size:13px}.topup-balance{font-size:28px;font-weight:800;color:#0f172a;margin-top:4px}.topup-alert{padding:14px 18px;border-radius:14px;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;font-weight:700}.topup-error{padding:14px 18px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b}.topup-tabs{display:flex;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:10px}.topup-tab{border:1px solid #dbe4ef;background:#fff;border-radius:10px;padding:10px 16px;font:inherit;font-weight:700;color:#475569;cursor:pointer}.topup-tab.active{background:#2563eb;color:white;border-color:#2563eb}.topup-panel{display:none}.topup-panel.active{display:block}.topup-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:16px}.topup-filters .input,.topup-filters .select{min-width:150px}.topup-table{width:100%;min-width:980px;border-collapse:collapse}.topup-table th,.topup-table td{padding:12px 14px;text-align:right;border-bottom:1px solid #edf2f7;font-size:13px;vertical-align:middle}.topup-table th{background:#f8fafc;color:#475569}.topup-muted{font-size:11px;color:#64748b;direction:ltr;text-align:right;overflow-wrap:anywhere}.topup-badge{display:inline-flex;padding:5px 10px;border-radius:99px;font-size:12px;font-weight:800;white-space:nowrap}.badge-success{background:#dcfce7;color:#166534}.badge-pending{background:#fef3c7;color:#92400e}.badge-failed{background:#fee2e2;color:#991b1b}.badge-refunded{background:#e0e7ff;color:#3730a3}.topup-actions{display:flex;gap:8px;align-items:center}.topup-pagination{display:flex;justify-content:center;gap:8px;padding:18px}.topup-pagination a,.topup-pagination span{padding:8px 12px;border:1px solid #dbe4ef;border-radius:9px;background:#fff}.topup-pagination .current{background:#2563eb;color:#fff}.topup-modal-body{padding:22px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.topup-meta{border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;min-width:0}.topup-meta span{display:block;color:#64748b;font-size:11px;margin-bottom:4px}.topup-meta strong{display:block;overflow-wrap:anywhere}.topup-modal-error{grid-column:1/-1;padding:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:12px}.topup-placeholder{padding:28px;text-align:center;color:#64748b}.topup-placeholder strong{display:block;color:#334155;margin-bottom:8px}@media(max-width:700px){.topup-summary{align-items:flex-start;flex-direction:column}.topup-modal-body{grid-template-columns:1fr}}
</style>
<div class="topup-page">
  <div class="card topup-summary">
    <div><div class="topup-balance-label">موجودی کیف پول فروشنده ایناکس</div>
      <?php if ($vendorBalance !== null): ?><div class="topup-balance"><?= e(number_format($vendorBalance)) ?> تومان</div><?php else: ?><div class="topup-error" style="margin-top:8px"><?= e($balanceError ?? 'موجودی در دسترس نیست.') ?></div><?php endif; ?>
    </div>
    <div class="topup-balance-label">آستانه هشدار: <?= e(number_format($lowBalanceThreshold)) ?> تومان</div>
  </div>
  <?php if ($vendorBalance !== null && $vendorBalance < $lowBalanceThreshold): ?>
    <div class="topup-alert" role="alert">موجودی ایناکس کمتر از حد تعیین‌شده است. برای جلوگیری از ناموفق شدن خرید شارژ، کیف پول فروشنده را شارژ کنید.</div>
  <?php endif; ?>
  <div class="topup-tabs" role="tablist" aria-label="نوع سفارش">
    <button type="button" class="topup-tab active" role="tab" aria-selected="true" data-topup-tab="mobile">شارژ موبایل</button>
    <button type="button" class="topup-tab" role="tab" aria-selected="false" data-topup-tab="internet">بسته اینترنت</button>
  </div>
  <section class="topup-panel active" id="topup-panel-mobile" role="tabpanel">
    <?php if ($tableError !== null): ?><div class="topup-error"><?= e($tableError) ?></div><?php else: ?>
      <form method="get" class="card topup-filters">
        <input class="input" type="search" name="mobile" value="<?= e($search) ?>" placeholder="جست‌وجو با شماره موبایل" aria-label="شماره موبایل">
        <select class="select" name="operator" aria-label="اپراتور"><option value="">همه اپراتورها</option><?php foreach ($operatorLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= $operator === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <select class="select" name="status" aria-label="وضعیت"><option value="">همه وضعیت‌ها</option><?php foreach ($statusLabels as $code => $label): ?><option value="<?= e($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary" type="submit">اعمال فیلتر</button><a class="btn btn-light" href="topup_orders.php">پاک‌کردن</a>
      </form>
      <div class="card" style="overflow:auto;margin-top:14px"><table class="topup-table"><thead><tr><th>شناسه</th><th>شماره موبایل</th><th>اپراتور</th><th>مبلغ</th><th>وضعیت</th><th>شناسه سفارش</th><th>کد پیگیری</th><th>زمان ثبت</th><th>رسید</th></tr></thead><tbody>
      <?php foreach ($rows as $row): $rowStatus = strtolower((string)($row['status'] ?? '')); $badgeClass = match ($rowStatus) { 'completed' => 'badge-success', 'failed' => 'badge-failed', 'refunded' => 'badge-refunded', default => 'badge-pending' }; $rowId = (string)($row['id'] ?? ''); ?>
        <tr><td><?= e($rowId) ?></td><td dir="ltr"><?= e((string)($row['mobile'] ?? '—')) ?></td><td><?= e($operatorLabels[(string)($row['operator'] ?? '')] ?? (string)($row['operator'] ?? '—')) ?></td><td><?= e(number_format((float)($row['amount'] ?? 0))) ?> تومان</td><td><span class="topup-badge <?= e($badgeClass) ?>"><?= e($statusLabels[$rowStatus] ?? ($rowStatus !== '' ? $rowStatus : 'نامشخص')) ?></span></td><td><span class="topup-muted"><?= e((string)($row['order_id'] ?? '—')) ?></span></td><td><span class="topup-muted"><?= e((string)($row['ref_code'] ?? $row['trans_id'] ?? '—')) ?></span></td><td><?= e((string)($row['created_at'] ?? '—')) ?></td><td><button type="button" class="btn btn-light btn-sm" data-receipt-id="<?= e($rowId) ?>">نمایش رسید</button></td></tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="9" style="text-align:center;padding:24px">سفارشی با این مشخصات پیدا نشد.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($totalPages > 1): ?><nav class="topup-pagination" aria-label="صفحه‌بندی"><?php if ($page > 1): ?><a href="?<?= e($queryForPage($page - 1)) ?>">قبلی</a><?php endif; ?><span class="current">صفحه <?= e((string)$page) ?> از <?= e((string)$totalPages) ?></span><?php if ($page < $totalPages): ?><a href="?<?= e($queryForPage($page + 1)) ?>">بعدی</a><?php endif; ?></nav><?php endif; ?>
      <div class="topup-balance-label" style="padding:8px 4px">تعداد سفارش‌های مطابق فیلتر: <?= e(number_format($totalRows)) ?></div>
    <?php endif; ?>
  </section>
  <section class="topup-panel" id="topup-panel-internet" role="tabpanel" hidden><div class="card topup-placeholder"><strong>مدیریت بسته‌های اینترنت</strong>این بخش برای سفارش‌های بسته اینترنت در توسعه‌های بعدی آماده شده است.</div></section>
</div>

<div class="modal-backdrop" id="topupReceiptModal" role="dialog" aria-modal="true" aria-labelledby="topupReceiptTitle"><div class="modal"><div class="modal-head"><h2 class="modal-title" id="topupReceiptTitle">رسید سفارش شارژ</h2><button type="button" class="icon-btn" data-modal-close="topupReceiptModal" aria-label="بستن">×</button></div><div class="topup-modal-body" id="topupReceiptContent"></div></div></div>
<script>
const topupReceiptRows = <?= $receiptJson ?>;
const topupLabels = {id:'شناسه سفارش داخلی',transaction_id:'شناسه تراکنش مالی',user_id:'شناسه کاربر',mobile:'شماره موبایل',operator:'اپراتور',charge_type:'نوع شارژ',amount:'مبلغ (تومان)',order_id:'شناسه سفارش فروشنده',trans_id:'شناسه تراکنش فروشنده',ref_code:'کد مرجع',status:'وضعیت',error_code:'کد خطا',created_at:'زمان ثبت',updated_at:'آخرین به‌روزرسانی'};
const topupOperatorLabels = {MCI:'همراه اول',MTN:'ایرانسل',RTL:'رایتل',SHT:'شاتل موبایل'};
const topupStatusLabels = {completed:'تکمیل‌شده',pending:'در انتظار',failed:'ناموفق',refunded:'مستردشده'};
function showReceipt(id) {
  const row = topupReceiptRows[String(id)];
  const content = document.getElementById('topupReceiptContent');
  if (!row || !content) return;
  content.replaceChildren();
  Object.keys(topupLabels).forEach((key) => {
    if (row[key] === null || row[key] === undefined || row[key] === '') return;
    const item = document.createElement('div'); item.className = 'topup-meta';
    const label = document.createElement('span'); label.textContent = topupLabels[key];
    const value = document.createElement('strong');
    value.textContent = key === 'operator' ? (topupOperatorLabels[row[key]] || row[key]) : key === 'status' ? (topupStatusLabels[row[key]] || row[key]) : key === 'amount' ? Number(row[key]).toLocaleString('fa-IR') : String(row[key]);
    item.append(label, value); content.append(item);
  });
  if (row.error_msg || row.error_code) {
    const error = document.createElement('div'); error.className = 'topup-modal-error';
    error.textContent = [row.error_code ? `کد خطا: ${row.error_code}` : '', row.error_msg ? `شرح خطا: ${row.error_msg}` : ''].filter(Boolean).join(' — ');
    content.append(error);
  }
  document.getElementById('topupReceiptModal')?.classList.add('open');
}
document.querySelectorAll('[data-receipt-id]').forEach((button) => button.addEventListener('click', () => showReceipt(button.dataset.receiptId)));
document.querySelectorAll('[data-topup-tab]').forEach((tab) => tab.addEventListener('click', () => {
  const selected = tab.dataset.topupTab;
  document.querySelectorAll('[data-topup-tab]').forEach((item) => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', String(active)); });
  document.querySelectorAll('.topup-panel').forEach((panel) => { const active = panel.id === `topup-panel-${selected}`; panel.classList.toggle('active', active); panel.hidden = !active; });
}));
</script>
<?php render_page_end(); ?>
