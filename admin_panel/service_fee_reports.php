<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/service_fee_service.php';

require_login();
require_permission('view');

$schemaReady = service_fee_schema_ready();
$period = trim((string)($_GET['period'] ?? 'today'));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$exportCsv = (string)($_GET['export'] ?? '') === 'csv';

$rangeStart = null;
$rangeEnd = null;
if ($period === 'month') {
    $rangeStart = date('Y-m-01 00:00:00');
    $rangeEnd = date('Y-m-t 23:59:59');
} elseif ($period === 'custom') {
    $rangeStart = $startDate !== '' ? jalali_input_to_gregorian_datetime($startDate, false) : null;
    $rangeEnd = $endDate !== '' ? jalali_input_to_gregorian_datetime($endDate, true) : null;
} else {
    $period = 'today';
    $rangeStart = date('Y-m-d 00:00:00');
    $rangeEnd = date('Y-m-d 23:59:59');
}

$where = ["sft.status = 'collected'"];
$params = [];
if ($rangeStart !== null) {
    $where[] = 'sft.status_updated_at >= ?';
    $params[] = $rangeStart;
}
if ($rangeEnd !== null) {
    $where[] = 'sft.status_updated_at <= ?';
    $params[] = $rangeEnd;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

if ($schemaReady && $exportCsv) {
    $stmt = db()->prepare("SELECT sft.status_updated_at AS created_at, u.mobile, sft.service_code, sft.original_amount, sft.fee_amount, sft.total_amount, sft.source_type, sft.source_id
        FROM service_fee_transactions sft
        LEFT JOIN users u ON u.id = sft.user_id
        {$whereSql}
        ORDER BY sft.status_updated_at DESC, sft.id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="afirex-fee-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['created_at', 'mobile', 'service', 'original_amount_toman', 'fee_amount_toman', 'total_amount_toman', 'source_type', 'source_id']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['created_at'],
            $row['mobile'],
            $row['service_code'],
            $row['original_amount'],
            $row['fee_amount'],
            $row['total_amount'],
            $row['source_type'],
            $row['source_id'],
        ]);
    }
    exit;
}

$summary = ['fee_total' => 0, 'original_total' => 0, 'charged_total' => 0, 'count_total' => 0];
$breakdown = [];
$rows = [];
$totalRows = 0;
if ($schemaReady) {
    $summaryStmt = db()->prepare("SELECT COALESCE(SUM(fee_amount),0) AS fee_total, COALESCE(SUM(original_amount),0) AS original_total, COALESCE(SUM(total_amount),0) AS charged_total, COUNT(*) AS count_total FROM service_fee_transactions sft {$whereSql}");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: $summary;

    $breakdownStmt = db()->prepare("SELECT service_code, COUNT(*) AS count_total, COALESCE(SUM(original_amount),0) AS original_total, COALESCE(SUM(fee_amount),0) AS fee_total, COALESCE(SUM(total_amount),0) AS charged_total FROM service_fee_transactions sft {$whereSql} GROUP BY service_code ORDER BY fee_total DESC");
    $breakdownStmt->execute($params);
    $breakdown = $breakdownStmt->fetchAll();

    $countStmt = db()->prepare("SELECT COUNT(*) FROM service_fee_transactions sft {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $listStmt = db()->prepare("SELECT sft.*, u.mobile, u.first_name, u.last_name
        FROM service_fee_transactions sft
        LEFT JOIN users u ON u.id = sft.user_id
        {$whereSql}
        ORDER BY sft.status_updated_at DESC, sft.id DESC
        LIMIT {$perPage} OFFSET {$offset}");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

render_page_start('گزارش کارمزدها', 'fee_reports');
?>
<style>
  .fee-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px}
  .fee-report-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;box-shadow:0 4px 14px rgba(15,23,42,.03)}
  .fee-report-label{font-size:13px;color:#64748b;margin-bottom:8px}.fee-report-value{font-size:22px;font-weight:900;color:#0f172a}
  .fee-filter{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.fee-filter label{display:grid;gap:7px;font-size:12px;color:#475569;font-weight:800}
</style>
<?php if (!$schemaReady): ?>
  <div class="alert error">جدول‌های کارمزد خدمات پیدا نشد. فایل migration شماره 013 را اجرا کنید.</div>
<?php endif; ?>
<div class="card">
  <h2 style="margin-top:0">گزارش کارمزدها</h2>
  <form method="get" class="fee-filter">
    <label>بازه
      <select class="select" name="period">
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>امروز</option>
        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>این ماه</option>
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>بازه دلخواه</option>
      </select>
    </label>
    <label>از تاریخ <input class="input js-persian-date" name="start_date" value="<?= e($startDate) ?>"></label>
    <label>تا تاریخ <input class="input js-persian-date" name="end_date" value="<?= e($endDate) ?>"></label>
    <button class="btn btn-primary">نمایش</button>
    <a class="btn btn-light" href="service_fee_reports.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">خروجی CSV</a>
  </form>
</div>
<?php if ($schemaReady): ?>
<div class="fee-report-grid">
  <div class="fee-report-card"><div class="fee-report-label">درآمد کارمزد</div><div class="fee-report-value"><?= e(number_format((float)$summary['fee_total'])) ?> تومان</div></div>
  <div class="fee-report-card"><div class="fee-report-label">تعداد تراکنش کارمزد</div><div class="fee-report-value"><?= e(number_format((float)$summary['count_total'])) ?></div></div>
  <div class="fee-report-card"><div class="fee-report-label">جمع مبلغ اصلی</div><div class="fee-report-value"><?= e(number_format((float)$summary['original_total'])) ?> تومان</div></div>
  <div class="fee-report-card"><div class="fee-report-label">جمع مبلغ کسرشده</div><div class="fee-report-value"><?= e(number_format((float)$summary['charged_total'])) ?> تومان</div></div>
</div>
<div class="card">
  <h3 style="margin-top:0">تفکیک بر اساس خدمت</h3>
  <div class="table-wrap"><table><thead><tr><th>خدمت</th><th>تعداد</th><th>مبلغ اصلی</th><th>کارمزد</th><th>مبلغ کسرشده</th></tr></thead><tbody>
    <?php foreach ($breakdown as $row): ?><tr><td><?= e(service_fee_name((string)$row['service_code'])) ?></td><td><?= e(number_format((float)$row['count_total'])) ?></td><td><?= e(number_format((float)$row['original_total'])) ?> تومان</td><td><?= e(number_format((float)$row['fee_total'])) ?> تومان</td><td><?= e(number_format((float)$row['charged_total'])) ?> تومان</td></tr><?php endforeach; ?>
    <?php if (!$breakdown): ?><tr><td colspan="5" style="text-align:center;color:#64748b;padding:18px">رکوردی یافت نشد.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="card">
  <h3 style="margin-top:0">جزئیات کارمزدها</h3>
  <div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>کاربر</th><th>خدمت</th><th>مبلغ اصلی</th><th>کارمزد</th><th>مبلغ نهایی</th><th>نوع</th><th>منبع</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr>
      <td><?= e(to_jalali_datetime((string)$row['status_updated_at'])) ?></td>
      <td><?= e(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) ?: (string)($row['mobile'] ?? '—')) ?></td>
      <td><?= e(service_fee_name((string)$row['service_code'])) ?></td>
      <td><?= e(number_format((float)$row['original_amount'])) ?> تومان</td>
      <td><?= e(number_format((float)$row['fee_amount'])) ?> تومان</td>
      <td><?= e(number_format((float)$row['total_amount'])) ?> تومان</td>
      <td><?= e(service_fee_type_fa((string)$row['configured_fee_type'])) ?> <?= (string)$row['configured_fee_type'] === 'percentage' ? e(rtrim(rtrim((string)$row['configured_fee_value'], '0'), '.')) . '٪' : '' ?></td>
      <td dir="ltr"><?= e((string)$row['source_type']) ?> #<?= e((string)$row['source_id']) ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:#64748b;padding:18px">رکوردی یافت نشد.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?><a class="page-link <?= $i === $page ? 'active' : '' ?>" href="service_fee_reports.php?<?= e(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a><?php endfor; ?>
  </div>
</div>
<?php endif; ?>
<?php render_page_end(); ?>
