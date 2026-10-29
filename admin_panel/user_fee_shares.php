<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/service_fee_service.php';
require_once __DIR__ . '/money_input_helpers.php';

require_login();
require_permission('view');

$schemaReady = service_fee_share_schema_ready();
$selectedParentId = max(0, (int)($_GET['parent_user_id'] ?? $_POST['parent_user_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('edit');
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);

    try {
        if (!$schemaReady) {
            throw new RuntimeException('SHARE_SCHEMA_MISSING');
        }

        $parentUserId = (int)($_POST['parent_user_id'] ?? 0);
        $shareType = trim((string)($_POST['share_type'] ?? ''));
        $shareValueInput = trim((string)($_POST['share_value'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $parentStmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $parentStmt->execute([$parentUserId]);
        if (!$parentStmt->fetchColumn()) {
            throw new InvalidArgumentException('INVALID_PARENT');
        }

        if (!in_array($shareType, ['fixed', 'percentage'], true)) {
            throw new InvalidArgumentException('INVALID_SHARE_TYPE');
        }

        $shareValue = $shareType === 'fixed'
            ? service_fee_normalize_money($shareValueInput)
            : service_fee_normalize_percent($shareValueInput);
        if ($shareValue === null || (float)$shareValue < 0) {
            throw new InvalidArgumentException('INVALID_SHARE_VALUE');
        }

        $stmt = db()->prepare("INSERT INTO user_fee_share_rules
            (parent_user_id, share_type, share_value, is_active, updated_by_admin_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE share_type = VALUES(share_type), share_value = VALUES(share_value), is_active = VALUES(is_active), updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = NOW()");
        $stmt->execute([$parentUserId, $shareType, $shareValue, $isActive, (int)($_SESSION['admin_id'] ?? 0)]);

        log_activity('update', 'user_fee_share_rules', $parentUserId, "تنظیم سهم مفاد کاربر بالادستی #{$parentUserId}");
        flash('success', 'تنظیمات سهم کاربر بالادستی ذخیره شد.');
        header('Location: user_fee_shares.php?parent_user_id=' . $parentUserId);
        exit;
    } catch (Throwable $e) {
        $message = match ($e->getMessage()) {
            'SHARE_SCHEMA_MISSING' => 'ابتدا migration سهم مفاد کاربران را اجرا کنید.',
            'INVALID_PARENT' => 'کاربر بالادستی معتبر نیست.',
            'INVALID_SHARE_TYPE', 'INVALID_SHARE_VALUE' => 'نوع یا مقدار سهم معتبر نیست.',
            default => 'ذخیره تنظیمات سهم انجام نشد.',
        };
        flash('error', $message);
        header('Location: user_fee_shares.php' . ($selectedParentId > 0 ? '?parent_user_id=' . $selectedParentId : ''));
        exit;
    }
}

$csrf = csrf_token();
$parents = [];
$parentCandidates = [];
$selectedRule = null;
$history = [];

if ($schemaReady) {
    $parentCandidates = db()->query("SELECT id, mobile, first_name, last_name
        FROM users
        ORDER BY id DESC")->fetchAll();

    $parents = db()->query("SELECT p.id, p.mobile, p.first_name, p.last_name, COUNT(c.id) AS child_count,
            r.share_type, r.share_value, r.is_active
        FROM users p
        INNER JOIN users c ON c.referred_by_user_id = p.id
        LEFT JOIN user_fee_share_rules r ON r.parent_user_id = p.id
        GROUP BY p.id, p.mobile, p.first_name, p.last_name, r.share_type, r.share_value, r.is_active
        ORDER BY p.id DESC")->fetchAll();

    if ($selectedParentId > 0) {
        $ruleStmt = db()->prepare('SELECT * FROM user_fee_share_rules WHERE parent_user_id = ? LIMIT 1');
        $ruleStmt->execute([$selectedParentId]);
        $selectedRule = $ruleStmt->fetch() ?: null;
    }

    $historyWhere = '';
    $historyParams = [];
    if ($selectedParentId > 0) {
        $historyWhere = 'WHERE ust.parent_user_id = ?';
        $historyParams[] = $selectedParentId;
    }
    $historyStmt = db()->prepare("SELECT ust.*, parent.mobile AS parent_mobile, parent.first_name AS parent_first_name, parent.last_name AS parent_last_name,
            child.mobile AS child_mobile, child.first_name AS child_first_name, child.last_name AS child_last_name
        FROM user_fee_share_transactions ust
        LEFT JOIN users parent ON parent.id = ust.parent_user_id
        LEFT JOIN users child ON child.id = ust.child_user_id
        {$historyWhere}
        ORDER BY ust.created_at DESC, ust.id DESC
        LIMIT 200");
    $historyStmt->execute($historyParams);
    $history = $historyStmt->fetchAll();
}

$selectedParent = null;
foreach ($parentCandidates as $parentRow) {
    if ((int)$parentRow['id'] === $selectedParentId) {
        $selectedParent = $parentRow;
        break;
    }
}

render_page_start('سهم مفاد کاربران بالادستی', 'user_fee_shares');
?>
<style>
  .share-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end}
  .share-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}
  .share-active{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.share-inactive{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
</style>

<?php if (!$schemaReady): ?>
  <div class="alert error">جدول‌های سهم مفاد کاربران پیدا نشد. migration شماره 014 را اجرا کنید.</div>
<?php endif; ?>

<div class="card">
  <div class="actions" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">سهم مفاد کاربران بالادستی</h2>
    <a class="btn btn-light" href="users.php">بازگشت به کاربران</a>
  </div>
</div>

<?php if ($schemaReady): ?>
<div class="card">
  <h3 style="margin-top:0">تنظیم سهم</h3>
  <form method="get" class="share-grid" style="margin-bottom:16px">
    <label class="field-label">کاربر بالادستی
      <select class="select" name="parent_user_id" onchange="this.form.submit()">
        <option value="">انتخاب کنید</option>
        <?php foreach ($parentCandidates as $parent): ?>
          <?php $label = '#' . (string)$parent['id'] . ' - ' . (trim((string)($parent['first_name'] ?? '') . ' ' . (string)($parent['last_name'] ?? '')) ?: (string)$parent['mobile']); ?>
          <option value="<?= e((string)$parent['id']) ?>" <?= (int)$parent['id'] === $selectedParentId ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if ($selectedParentId > 0): ?>
    <form method="post" class="share-grid">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="parent_user_id" value="<?= e((string)$selectedParentId) ?>">
      <label class="field-label">نوع سهم
        <select class="select" name="share_type">
          <option value="percentage" <?= (string)($selectedRule['share_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>درصدی از کارمزد</option>
          <option value="fixed" <?= (string)($selectedRule['share_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>مبلغ ثابت</option>
        </select>
      </label>
      <label class="field-label">مقدار
        <input class="input" name="share_value" dir="ltr" value="<?= e((string)($selectedRule['share_value'] ?? '0')) ?>" <?= (string)($selectedRule['share_type'] ?? 'percentage') === 'fixed' ? 'data-money-input data-money-words-target="shareValueWords"' : '' ?> required>
        <div class="money-amount-words" id="shareValueWords"></div>
      </label>
      <label class="field-label">وضعیت
        <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="is_active" <?= (int)($selectedRule['is_active'] ?? 0) === 1 ? 'checked' : '' ?>> فعال</label>
      </label>
      <button class="btn btn-primary">ذخیره</button>
    </form>
  <?php else: ?>
    <p style="color:#64748b;margin-bottom:0">برای تنظیم سهم، یک کاربر بالادستی را انتخاب کنید.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0">کاربران دارای زیرمجموعه</h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th>کاربر بالادستی</th><th>موبایل</th><th>تعداد زیرمجموعه</th><th>نوع سهم</th><th>مقدار سهم</th><th>وضعیت</th><th>تنظیم</th></tr></thead>
      <tbody>
      <?php if (!$parents): ?><tr><td colspan="7" style="text-align:center;color:#64748b;padding:18px">کاربر بالادستی دارای زیرمجموعه یافت نشد.</td></tr><?php endif; ?>
      <?php foreach ($parents as $parent): ?>
        <tr>
          <td>#<?= e((string)$parent['id']) ?> - <?= e(trim((string)($parent['first_name'] ?? '') . ' ' . (string)($parent['last_name'] ?? '')) ?: 'بدون نام') ?></td>
          <td dir="ltr"><?= e((string)$parent['mobile']) ?></td>
          <td><?= e((string)$parent['child_count']) ?></td>
          <td><?= $parent['share_type'] ? e(service_fee_share_type_fa((string)$parent['share_type'])) : '—' ?></td>
          <td><?= $parent['share_type'] === 'percentage' ? e(rtrim(rtrim((string)$parent['share_value'], '0'), '.')) . '%' : ($parent['share_value'] !== null ? e(number_format((float)$parent['share_value'])) . ' تومان' : '—') ?></td>
          <td><span class="share-badge <?= (int)($parent['is_active'] ?? 0) === 1 ? 'share-active' : 'share-inactive' ?>"><?= (int)($parent['is_active'] ?? 0) === 1 ? 'فعال' : 'غیرفعال' ?></span></td>
          <td><a class="btn btn-light btn-sm" href="user_fee_shares.php?parent_user_id=<?= e((string)$parent['id']) ?>">تنظیم سهم</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">تاریخچه پرداخت سهم</h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th>تاریخ</th><th>کاربر زیرمجموعه</th><th>کاربر بالادستی</th><th>منبع</th><th>کارمزد اصلی</th><th>سهم پرداختی</th><th>نوع سهم</th></tr></thead>
      <tbody>
      <?php if (!$history): ?><tr><td colspan="7" style="text-align:center;color:#64748b;padding:18px">پرداخت سهمی ثبت نشده است.</td></tr><?php endif; ?>
      <?php foreach ($history as $row): ?>
        <tr>
          <td><?= e(to_jalali_datetime((string)$row['created_at'])) ?></td>
          <td>#<?= e((string)$row['child_user_id']) ?> - <?= e(trim((string)($row['child_first_name'] ?? '') . ' ' . (string)($row['child_last_name'] ?? '')) ?: (string)($row['child_mobile'] ?? '—')) ?></td>
          <td>#<?= e((string)$row['parent_user_id']) ?> - <?= e(trim((string)($row['parent_first_name'] ?? '') . ' ' . (string)($row['parent_last_name'] ?? '')) ?: (string)($row['parent_mobile'] ?? '—')) ?></td>
          <td dir="ltr"><?= e((string)$row['source_type']) ?> #<?= e((string)$row['source_id']) ?></td>
          <td><?= e(number_format((float)$row['original_fee_amount'])) ?> تومان</td>
          <td><?= e(number_format((float)$row['share_amount'])) ?> تومان</td>
          <td><?= e(service_fee_share_type_fa((string)$row['share_type'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php render_money_input_helpers(); ?>
<script>
const shareTypeInput = document.querySelector('[name="share_type"]');
const shareValueInput = document.querySelector('[name="share_value"]');
const shareValueWords = document.getElementById('shareValueWords');
if (shareTypeInput && shareValueInput) {
  shareTypeInput.addEventListener('change', function () {
    if (shareTypeInput.value === 'fixed') {
      shareValueInput.setAttribute('data-money-input', '');
      shareValueInput.dataset.moneyWordsTarget = 'shareValueWords';
      if (window.AfariexMoneyInputs) window.AfariexMoneyInputs.apply(shareValueInput.form);
    } else {
      shareValueInput.value = shareValueInput.value.replace(/,/g, '');
      shareValueInput.removeAttribute('data-money-input');
      if (shareValueWords) shareValueWords.textContent = '';
    }
  });
}
</script>
<?php render_page_end(); ?>
