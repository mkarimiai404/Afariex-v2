<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/service_fee_service.php';
require_once __DIR__ . '/money_input_helpers.php';

require_login();
require_permission('view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('edit');
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    try {
        service_fee_require_schema();
        $id = (int)($_POST['id'] ?? 0);
        $feeType = trim((string)($_POST['fee_type'] ?? ''));
        $feeValue = service_fee_normalize_percent((string)($_POST['fee_value'] ?? ''));
        $minFee = trim((string)($_POST['min_fee'] ?? ''));
        $maxFee = trim((string)($_POST['max_fee'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $minFeeValue = $minFee === '' ? null : service_fee_normalize_money($minFee);
        $maxFeeValue = $maxFee === '' ? null : service_fee_normalize_money($maxFee);

        if ($id <= 0 || !in_array($feeType, ['fixed', 'percentage'], true) || $feeValue === null) {
            throw new InvalidArgumentException('INVALID_FEE');
        }
        if (($minFee !== '' && $minFeeValue === null) || ($maxFee !== '' && $maxFeeValue === null)) {
            throw new InvalidArgumentException('INVALID_LIMIT');
        }
        if ($maxFeeValue !== null && $minFeeValue !== null && $maxFeeValue < $minFeeValue) {
            throw new InvalidArgumentException('INVALID_LIMIT_RANGE');
        }

        $stmt = db()->prepare('UPDATE service_fees SET fee_type = ?, fee_value = ?, min_fee = ?, max_fee = ?, is_active = ?, description = ?, updated_by_admin_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$feeType, $feeValue, $minFeeValue, $maxFeeValue, $isActive, $description !== '' ? $description : null, (int)($_SESSION['admin_id'] ?? 0), $id]);
        log_activity('update', 'service_fees', $id, 'ویرایش کارمزد خدمت');
        flash('success', 'کارمزد خدمت ذخیره شد.');
    } catch (Throwable $e) {
        $message = match ($e->getMessage()) {
            'SERVICE_FEE_SCHEMA_MISSING' => 'ابتدا migration کارمزد خدمات را اجرا کنید.',
            'INVALID_LIMIT', 'INVALID_LIMIT_RANGE' => 'حداقل و حداکثر کارمزد معتبر نیست.',
            default => 'ذخیره کارمزد انجام نشد.',
        };
        flash('error', $message);
    }
    header('Location: service_fees.php');
    exit;
}

$schemaReady = service_fee_schema_ready();
$rows = [];
if ($schemaReady) {
    $rows = db()->query('SELECT sf.*, u.mobile AS admin_mobile FROM service_fees sf LEFT JOIN users u ON u.id = sf.updated_by_admin_id ORDER BY sf.id ASC')->fetchAll();
}

render_page_start('مدیریت کارمزد خدمات', 'service_fees');
?>
<style>
  .fee-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}
  .fee-on{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.fee-off{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
  .fee-form{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;align-items:end;min-width:760px}
  .fee-form .field-label{font-size:11px}
</style>
<?php if (!$schemaReady): ?>
  <div class="alert error">جدول‌های کارمزد خدمات پیدا نشد. فایل migration شماره 013 را اجرا کنید.</div>
<?php endif; ?>
<div class="card">
  <h2 style="margin-top:0">مدیریت کارمزد خدمات</h2>
  <p style="color:#64748b;margin-bottom:0">کارمزدها سرورمحور محاسبه می‌شوند و مقدار دریافت‌شده برای هر تراکنش در دفتر کارمزد ذخیره می‌شود. برای تعیین سهم آفاریکس از خرید شارژ، ردیف «خرید شارژ موبایل» را روی مبلغ ثابت یا درصد تنظیم و فعال کنید؛ کارمزد فقط پس از تأیید موفق سرویس به‌عنوان درآمد ثبت می‌شود.</p>
</div>
<?php if ($schemaReady): ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>خدمت</th><th>نوع</th><th>مبلغ / درصد</th><th>حداقل</th><th>حداکثر</th><th>وضعیت</th><th>آخرین ویرایش</th><th>ویرایش</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><strong><?= e(service_fee_name((string)$row['service_code'])) ?></strong><br><small dir="ltr"><?= e((string)$row['service_code']) ?></small></td>
          <td><?= e(service_fee_type_fa((string)$row['fee_type'])) ?></td>
          <td><?= (string)$row['fee_type'] === 'percentage' ? e(rtrim(rtrim((string)$row['fee_value'], '0'), '.')) . '٪' : e(number_format((float)$row['fee_value'])) . ' تومان' ?></td>
          <td><?= $row['min_fee'] === null ? '—' : e(number_format((float)$row['min_fee'])) . ' تومان' ?></td>
          <td><?= $row['max_fee'] === null ? '—' : e(number_format((float)$row['max_fee'])) . ' تومان' ?></td>
          <td><span class="fee-badge <?= (int)$row['is_active'] === 1 ? 'fee-on' : 'fee-off' ?>"><?= (int)$row['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
          <td><?= e(to_jalali_datetime((string)$row['updated_at'])) ?><br><small><?= e((string)($row['admin_mobile'] ?? '—')) ?></small></td>
          <td>
            <?php if (can('edit')): ?>
            <form method="post" class="fee-form">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
              <div class="col-2"><label class="field-label">نوع</label><select class="select" name="fee_type"><option value="fixed" <?= $row['fee_type'] === 'fixed' ? 'selected' : '' ?>>ثابت</option><option value="percentage" <?= $row['fee_type'] === 'percentage' ? 'selected' : '' ?>>درصدی</option></select></div>
              <div class="col-2"><label class="field-label">مقدار</label><input class="input" name="fee_value" value="<?= e((string)$row['fee_value']) ?>" dir="ltr" <?= (string)$row['fee_type'] === 'fixed' ? 'data-money-input data-money-words-target="feeValueWords' . (int)$row['id'] . '"' : '' ?> required><div class="money-amount-words" id="feeValueWords<?= (int)$row['id'] ?>"></div></div>
              <div class="col-2"><label class="field-label">حداقل (تومان)</label><input class="input" name="min_fee" value="<?= $row['min_fee'] === null ? '' : e((string)$row['min_fee']) ?>" dir="ltr" data-money-input data-money-words-target="minFeeWords<?= (int)$row['id'] ?>"><div class="money-amount-words" id="minFeeWords<?= (int)$row['id'] ?>"></div></div>
              <div class="col-2"><label class="field-label">حداکثر (تومان)</label><input class="input" name="max_fee" value="<?= $row['max_fee'] === null ? '' : e((string)$row['max_fee']) ?>" dir="ltr" data-money-input data-money-words-target="maxFeeWords<?= (int)$row['id'] ?>"><div class="money-amount-words" id="maxFeeWords<?= (int)$row['id'] ?>"></div></div>
              <div class="col-2"><label class="field-label">فعال</label><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" <?= (int)$row['is_active'] === 1 ? 'checked' : '' ?>> فعال</label></div>
              <div class="col-12"><label class="field-label">توضیحات</label><input class="input" name="description" value="<?= e((string)($row['description'] ?? '')) ?>"></div>
              <div class="col-2"><button class="btn btn-primary btn-sm">ذخیره</button></div>
            </form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_money_input_helpers(); ?>
<script>
document.querySelectorAll('.fee-form').forEach(function (form) {
  const type = form.querySelector('[name="fee_type"]');
  const input = form.querySelector('[name="fee_value"]');
  const words = form.querySelector('.money-amount-words[id^="feeValueWords"]');
  if (!type || !input) return;
  type.addEventListener('change', function () {
    if (type.value === 'fixed') {
      input.setAttribute('data-money-input', '');
      if (words) input.dataset.moneyWordsTarget = words.id;
      if (window.AfariexMoneyInputs) window.AfariexMoneyInputs.apply(form);
    } else {
      input.value = input.value.replace(/,/g, '');
      input.removeAttribute('data-money-input');
      if (words) words.textContent = '';
    }
  });
});
</script>
<?php render_page_end(); ?>
