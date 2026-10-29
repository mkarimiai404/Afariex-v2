<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/debt_service.php';
require_once __DIR__ . '/money_input_helpers.php';

require_login();
require_permission('view');

$selectedUserId = max(0, (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_debt') {
            require_permission('edit');
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = trim((string)($_POST['amount'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($description === '') {
                flash('error', 'توضیحات بدهی اجباری است.');
                header('Location: user_debts.php' . ($userId > 0 ? '?user_id=' . $userId : ''));
                exit;
            }
            $debtId = debt_create($userId, $amount, $description, (int)($_SESSION['admin_id'] ?? 0));
            log_activity('create', 'user_debts', $debtId, "ثبت بدهی برای کاربر #{$userId}");
            flash('success', 'بدهی کاربر با موفقیت ثبت شد.');
            header('Location: user_debts.php?user_id=' . $userId);
            exit;
        }

        if ($action === 'cancel_debt') {
            require_permission('edit');
            $debtId = (int)($_POST['debt_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            debt_cancel_unpaid($debtId, (int)($_SESSION['admin_id'] ?? 0));
            log_activity('cancel', 'user_debts', $debtId, "لغو بدهی کاربر #{$userId}");
            flash('success', 'بدهی پرداخت‌نشده لغو شد.');
            header('Location: user_debts.php' . ($userId > 0 ? '?user_id=' . $userId : ''));
            exit;
        }
    } catch (Throwable $e) {
        $message = match ($e->getMessage()) {
            'DEBT_SCHEMA_MISSING' => 'ابتدا migration بدهی کاربران را اجرا کنید.',
            'INVALID_DEBT_INPUT' => 'مبلغ بدهی معتبر نیست.',
            'USER_NOT_FOUND' => 'کاربر مورد نظر یافت نشد.',
            'DEBT_ALREADY_PARTIALLY_SETTLED' => 'بدهی دارای پرداخت است و قابل لغو ساده نیست.',
            'DEBT_NOT_CANCELLABLE' => 'این بدهی در وضعیت فعلی قابل لغو نیست.',
            default => 'عملیات بدهی انجام نشد.',
        };
        flash('error', $message);
        header('Location: user_debts.php' . ($selectedUserId > 0 ? '?user_id=' . $selectedUserId : ''));
        exit;
    }
}

$schemaReady = debt_schema_ready();
$csrf = csrf_token();
$user = null;
$debts = [];
$payments = [];
$outstandingDebt = 0.0;

if ($schemaReady && $selectedUserId > 0) {
    $userStmt = db()->prepare('SELECT id, mobile, first_name, last_name, balance FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$selectedUserId]);
    $user = $userStmt->fetch() ?: null;
    if ($user) {
        $outstandingDebt = debt_outstanding_amount($selectedUserId);
        $debtStmt = db()->prepare("SELECT d.*, admin.mobile AS created_by_mobile, cancel_admin.mobile AS cancelled_by_mobile
            FROM user_debts d
            LEFT JOIN users admin ON admin.id = d.created_by_admin_id
            LEFT JOIN users cancel_admin ON cancel_admin.id = d.cancelled_by_admin_id
            WHERE d.user_id = ?
            ORDER BY d.created_at DESC, d.id DESC");
        $debtStmt->execute([$selectedUserId]);
        $debts = $debtStmt->fetchAll();

        $paymentStmt = db()->prepare("SELECT p.*, d.description
            FROM user_debt_payments p
            INNER JOIN user_debts d ON d.id = p.debt_id
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC, p.id DESC");
        $paymentStmt->execute([$selectedUserId]);
        $payments = $paymentStmt->fetchAll();
    }
} elseif ($schemaReady) {
    $debtStmt = db()->query("SELECT d.*, u.mobile, u.first_name, u.last_name, admin.mobile AS created_by_mobile
        FROM user_debts d
        INNER JOIN users u ON u.id = d.user_id
        LEFT JOIN users admin ON admin.id = d.created_by_admin_id
        ORDER BY d.created_at DESC, d.id DESC
        LIMIT 200");
    $debts = $debtStmt->fetchAll();
}

render_page_start('بدهی کاربران', 'debts');
?>
<style>
  .debt-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px; }
  .debt-card { background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;box-shadow:0 4px 14px rgba(15,23,42,.03); }
  .debt-label { color:#64748b;font-size:13px;margin-bottom:8px; }
  .debt-value { color:#0f172a;font-weight:900;font-size:20px; }
  .debt-danger { color:#b91c1c; }
  .debt-form { display:grid;grid-template-columns:repeat(12,1fr);gap:14px;align-items:end; }
  .debt-status { display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap; }
  .debt-status.active { background:#fef2f2;color:#b91c1c;border:1px solid #fecaca; }
  .debt-status.partially_paid { background:#fff7ed;color:#c2410c;border:1px solid #fed7aa; }
  .debt-status.settled { background:#ecfdf5;color:#047857;border:1px solid #a7f3d0; }
  .debt-status.cancelled { background:#f1f5f9;color:#475569;border:1px solid #cbd5e1; }
  @media(max-width:900px){.debt-form{grid-template-columns:1fr}.debt-form .col-3,.debt-form .col-6,.debt-form .col-12{grid-column:auto!important}}
</style>

<?php if (!$schemaReady): ?>
  <div class="alert error">جدول‌های بدهی کاربران پیدا نشد. فایل migration شماره 012 را اجرا کنید.</div>
<?php endif; ?>

<div class="card">
  <div class="actions" style="justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h3 style="margin:0;">بدهی کاربران</h3>
    <a class="btn btn-light" href="users.php">بازگشت به کاربران</a>
  </div>
</div>

<?php if ($schemaReady): ?>
  <?php if ($selectedUserId > 0 && !$user): ?>
    <div class="alert error">کاربر مورد نظر یافت نشد.</div>
  <?php endif; ?>

  <?php if ($user): ?>
    <div class="debt-summary">
      <div class="debt-card"><div class="debt-label">کاربر</div><div class="debt-value"><?= e(trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')) ?: (string)$user['mobile']) ?></div></div>
      <div class="debt-card"><div class="debt-label">موجودی قابل استفاده</div><div class="debt-value"><?= e(admin_balance_with_unit($user['balance'] ?? null)) ?></div></div>
      <div class="debt-card"><div class="debt-label">مانده بدهی</div><div class="debt-value debt-danger"><?= e(number_format($outstandingDebt)) ?> تومان</div></div>
    </div>

    <?php if (can('edit')): ?>
      <div class="card">
        <h3 style="margin-top:0;">ثبت بدهی</h3>
        <form method="post" class="debt-form">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="create_debt">
          <input type="hidden" name="user_id" value="<?= e((string)$selectedUserId) ?>">
          <div class="col-3"><label class="field-label">مبلغ بدهی (تومان)</label><input class="input" name="amount" type="text" inputmode="numeric" dir="ltr" data-money-input data-money-words-target="debtAmountWords" required><div class="money-amount-words" id="debtAmountWords"></div></div>
          <div class="col-6"><label class="field-label">توضیحات</label><input class="input" name="description" required></div>
          <div class="col-3"><button class="btn btn-primary">ثبت بدهی</button></div>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin-top:0;">تاریخچه بدهی</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><?php if (!$user): ?><th>کاربر</th><?php endif; ?><th>مبلغ اولیه</th><th>مانده بدهی</th><th>پرداخت شده</th><th>وضعیت</th><th>تاریخ ثبت</th><th>تاریخ تسویه</th><th>توضیحات</th><th>ثبت‌کننده</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php if (!$debts): ?>
          <tr><td colspan="<?= $user ? 10 : 11 ?>" style="text-align:center;color:#64748b;padding:20px;">بدهی ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($debts as $debt): ?>
          <?php $paid = max(0, (float)$debt['original_amount'] - (float)$debt['remaining_amount']); ?>
          <tr>
            <td><?= e((string)$debt['id']) ?></td>
            <?php if (!$user): ?><td><?= e(trim((string)($debt['first_name'] ?? '') . ' ' . (string)($debt['last_name'] ?? '')) ?: (string)($debt['mobile'] ?? '')) ?></td><?php endif; ?>
            <td><?= e(number_format((float)$debt['original_amount'])) ?> تومان</td>
            <td><?= e(number_format((float)$debt['remaining_amount'])) ?> تومان</td>
            <td><?= e(number_format($paid)) ?> تومان</td>
            <td><span class="debt-status <?= e((string)$debt['status']) ?>"><?= e(debt_status_fa((string)$debt['status'])) ?></span></td>
            <td><?= e(to_jalali_datetime((string)$debt['created_at'])) ?></td>
            <td><?= $debt['settled_at'] ? e(to_jalali_datetime((string)$debt['settled_at'])) : '—' ?></td>
            <td><?= e((string)($debt['description'] ?? '')) ?></td>
            <td><?= e((string)($debt['created_by_mobile'] ?? '—')) ?></td>
            <td>
              <?php if (can('edit') && in_array((string)$debt['status'], ['active', 'partially_paid'], true) && $paid <= 0): ?>
                <form method="post" onsubmit="return confirm('این بدهی پرداخت‌نشده لغو شود؟');">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="cancel_debt">
                  <input type="hidden" name="debt_id" value="<?= e((string)$debt['id']) ?>">
                  <input type="hidden" name="user_id" value="<?= e((string)($debt['user_id'] ?? $selectedUserId)) ?>">
                  <button class="btn btn-danger btn-sm">لغو بدهی</button>
                </form>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($user): ?>
    <div class="card">
      <h3 style="margin-top:0;">پرداخت‌های بدهی</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>شناسه بدهی</th><th>مبلغ تسویه</th><th>منبع</th><th>تراکنش منبع</th><th>تاریخ</th></tr></thead>
          <tbody>
          <?php if (!$payments): ?>
            <tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px;">پرداخت بدهی ثبت نشده است.</td></tr>
          <?php endif; ?>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td><?= e((string)$payment['id']) ?></td>
              <td><?= e((string)$payment['debt_id']) ?></td>
              <td><?= e(number_format((float)$payment['amount'])) ?> تومان</td>
              <td><?= e((string)$payment['source_type']) ?></td>
              <td><?= $payment['source_transaction_id'] ? e((string)$payment['source_transaction_id']) : '—' ?></td>
              <td><?= e(to_jalali_datetime((string)$payment['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php render_money_input_helpers(); ?>
<?php render_page_end(); ?>
