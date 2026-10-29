<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/withdrawal_service.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/withdrawal_admin_status.php';
require_once __DIR__ . '/money_input_helpers.php';

require_login();
require_permission('view');

if (empty($_SESSION['withdrawal_create_nonce'])) {
    $_SESSION['withdrawal_create_nonce'] = bin2hex(random_bytes(24));
}

$redirect = static function (): void {
    header('Location: withdrawals.php');
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create') {
            require_permission('create');
            $nonce = trim((string)($_POST['create_nonce'] ?? ''));
            if ($nonce === '' || !hash_equals((string)$_SESSION['withdrawal_create_nonce'], $nonce)) {
                throw new DomainException('DUPLICATE_REQUEST');
            }
            $result = withdrawal_create_request(
                (int)($_POST['user_id'] ?? 0),
                (string)($_POST['amount'] ?? ''),
                (string)($_POST['card_number'] ?? ''),
                (string)($_POST['cardholder_name'] ?? ''),
                'admin-' . $nonce,
                'admin',
                (int)$_SESSION['admin_id']
            );
            unset($_SESSION['withdrawal_create_nonce']);
            log_activity('create', 'withdrawal', $result['id'], 'ثبت دستی درخواست برداشت');
            flash('success', 'درخواست برداشت جدید با وضعیت در انتظار بررسی ثبت شد.');
        } elseif ($action === 'set_status') {
            require_permission('edit');
            $requestId = (int)($_POST['request_id'] ?? 0);
            $requestedStatus = trim((string)($_POST['withdrawal_status'] ?? ''));
            $currentStmt = db()->prepare("SELECT status, request_source FROM transactions WHERE id = ? AND type IN ('withdraw', 'withdrawal') LIMIT 1");
            $currentStmt->execute([$requestId]);
            $currentRequest = $currentStmt->fetch();
            if (!$currentRequest || !in_array((string)$currentRequest['request_source'], ['customer', 'admin'], true)) {
                throw new DomainException('REQUEST_NOT_FOUND');
            }
            $currentStatus = (string)$currentRequest['status'];
            $transitionAction = withdrawal_admin_transition_action($currentStatus, $requestedStatus);
            if (in_array($requestedStatus, ['paid', 'rejected'], true)
                && !hash_equals($requestedStatus, trim((string)($_POST['confirmed_transition'] ?? '')))) {
                throw new DomainException('TRANSITION_CONFIRMATION_REQUIRED');
            }
            if ($transitionAction === null) {
                flash('success', 'وضعیت برداشت بدون تغییر باقی ماند.');
            } else {
                $target = withdrawal_transition($requestId, $transitionAction, (int)$_SESSION['admin_id']);
                log_activity('update', 'withdrawal', $requestId, 'تغییر وضعیت درخواست برداشت به ' . $target);
                $messages = [
                    'approved' => 'درخواست برداشت تأیید شد و آماده پرداخت است.',
                    'rejected' => 'درخواست رد و مبلغ رزروشده دقیقاً یک‌بار آزاد شد.',
                    'paid' => 'پرداخت کارت‌به‌کارت ثبت و اعلان کاربر ایجاد شد.',
                ];
                flash('success', $messages[$target]);
            }
        } else {
            throw new InvalidArgumentException('INVALID_ACTION');
        }
    } catch (Throwable $e) {
        $messages = [
            'INVALID_AMOUNT' => 'مبلغ برداشت معتبر نیست.',
            'INVALID_CARD_NUMBER' => 'شماره کارت بانکی معتبر نیست.',
            'INVALID_CARDHOLDER_NAME' => 'نام کامل صاحب کارت معتبر نیست.',
            'INVALID_USER' => 'کاربر انتخاب‌شده معتبر نیست.',
            'INSUFFICIENT_BALANCE' => 'موجودی قابل برداشت کاربر کافی نیست.',
            'DAILY_TRANSACTION_LIMIT_EXCEEDED' => 'این درخواست از سقف تراکنش روزانه کاربر بیشتر است.',
            'SERVICE_FEE_SCHEMA_MISSING' => 'ابتدا migration کارمزد خدمات را اجرا کنید.',
            'INVALID_STATE_TRANSITION' => 'وضعیت درخواست تغییر کرده یا این عملیات برای وضعیت فعلی مجاز نیست.',
            'REQUEST_NOT_FOUND' => 'درخواست برداشت پیدا نشد.',
            'TRANSITION_CONFIRMATION_REQUIRED' => 'تأیید صریح عملیات رد یا پرداخت الزامی است.',
            'DUPLICATE_REQUEST' => 'این فرم قبلاً پردازش شده است.',
        ];
        flash('error', $messages[$e->getMessage()] ?? 'عملیات برداشت انجام نشد. ابتدا از اجرای migration 005 مطمئن شوید.');
    }
    $redirect();
}

$users = db()->query("SELECT id, mobile, first_name, last_name FROM users ORDER BY id DESC LIMIT 1000")->fetchAll();
$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowedFilters = ['pending', 'approved', 'paid', 'rejected'];
$where = "WHERE t.type IN ('withdraw', 'withdrawal')";
$params = [];
if (in_array($statusFilter, $allowedFilters, true)) {
    $where .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
$stmt = db()->prepare("SELECT t.id, t.user_id, t.amount, t.card_number, t.cardholder_name, t.request_source,
        t.status, t.created_at, t.approved_at, t.paid_at, t.rejected_at,
        t.balance_before, t.balance_after, t.balance_applied, t.refund_applied,
        u.mobile, u.first_name, u.last_name, u.balance AS current_balance
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    {$where}
    ORDER BY t.id DESC
    LIMIT 500");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$csrf = csrf_token();
$withdrawalStatusLabels = withdrawal_admin_status_labels();

render_page_start('مدیریت برداشت‌ها', 'withdrawals');
?>
<style>
  .withdrawal-toolbar { display:flex; gap:12px; justify-content:space-between; align-items:center; flex-wrap:wrap; }
  .withdrawal-status { display:inline-flex; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:800; }
  .withdrawal-status.pending { background:#fff7ed; color:#c2410c; }
  .withdrawal-status.approved { background:#eff6ff; color:#1d4ed8; }
  .withdrawal-status.paid { background:#ecfdf5; color:#047857; }
  .withdrawal-status.rejected { background:#fef2f2; color:#b91c1c; }
  .withdrawal-help { color:#64748b; font-size:12px; margin:8px 0 0; }
  .withdrawal-confirm { background:#047857; color:#fff; }
  .withdrawal-status-form { display:grid;gap:8px;min-width:220px; }
  .withdrawal-status-form label { color:#475569;font-size:12px;font-weight:800; }
  .withdrawal-status-form .select { width:100%; }
  .withdrawal-financial-summary { min-width:235px;display:grid;gap:7px;padding:12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0; }
  .withdrawal-financial-row { display:flex;justify-content:space-between;gap:12px;align-items:center;font-size:12px;color:#475569; }
  .withdrawal-financial-row strong { color:#0f172a;white-space:nowrap;direction:rtl; }
  .withdrawal-financial-row.current { margin-top:3px;padding-top:8px;border-top:1px solid #cbd5e1;font-size:13px;color:#047857;font-weight:800; }
  .withdrawal-financial-row.current strong { color:#047857;font-size:14px; }
  .withdrawal-reservation-note { margin:8px 0 0;color:#92400e;background:#fffbeb;border-radius:8px;padding:7px;font-size:11px;line-height:1.7; }
  /* The table is RTL, so its final (operations) column is on the left edge. */
  .withdrawal-table-wrap { overflow-x:auto !important; overflow-y:visible; max-width:100%; direction:rtl; -webkit-overflow-scrolling:touch; scrollbar-width:auto; }
  .withdrawal-table { min-width:1550px; direction:rtl; }
  .withdrawal-table th, .withdrawal-table td { vertical-align:top; }
  .withdrawal-operations-heading, .withdrawal-operations { position:sticky; left:0; z-index:4; min-width:270px; width:270px; background:#fff !important; box-shadow:4px 0 10px rgba(15,23,42,.10); }
  .withdrawal-operations-heading { z-index:5; background:#f8fafc !important; }
  .withdrawal-operations .withdrawal-status-form { min-width:238px; }
  .withdrawal-operations .btn, .withdrawal-operations .select { max-width:100%; }
  @media (max-width:1100px) { .withdrawal-table { min-width:1450px; } .withdrawal-operations-heading, .withdrawal-operations { min-width:255px; width:255px; } }
  @media (max-width:840px) { .withdrawal-toolbar > * { width:100%; } .withdrawal-financial-summary { min-width:210px; } }
</style>

<div class="card">
  <div class="withdrawal-toolbar">
    <div>
      <h2 style="margin:0">درخواست‌های برداشت</h2>
      <p class="withdrawal-help">وجه هنگام ثبت درخواست رزرو می‌شود؛ تأیید و پرداخت دوباره از موجودی کسر نمی‌کنند.</p>
    </div>
    <?php if (can('create')): ?><button class="btn btn-primary" type="button" data-modal-open="new-withdrawal">ثبت درخواست برداشت جدید</button><?php endif; ?>
  </div>
</div>

<div class="card">
  <form method="get" class="withdrawal-toolbar" style="justify-content:flex-start;margin-bottom:16px">
    <select class="select" name="status" style="max-width:240px">
      <option value="">همه</option>
      <?php foreach ($allowedFilters as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($withdrawalStatusLabels[$status]) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-light" type="submit">فیلتر</button>
  </form>
  <div class="withdrawal-table-wrap">
    <table class="withdrawal-table">
      <thead><tr><th>شناسه</th><th>کاربر</th><th>موبایل</th><th>مبلغ درخواست برداشت</th><th>خلاصه مالی حساب</th><th>شماره کارت</th><th>صاحب کارت</th><th>تاریخ ثبت</th><th>منبع</th><th>وضعیت</th><th class="withdrawal-operations-heading">عملیات</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="11" style="text-align:center">درخواست برداشتی یافت نشد.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): $managedRequest = in_array((string)($row['request_source'] ?? ''), ['customer', 'admin'], true); ?>
        <tr>
          <td>#<?= (int)$row['id'] ?></td>
          <td><?= e(trim((string)$row['first_name'] . ' ' . (string)$row['last_name']) ?: 'بدون نام') ?></td>
          <td dir="ltr"><?= e((string)$row['mobile']) ?></td>
          <td><?= e(number_format((float)$row['amount'])) ?> تومان</td>
          <td><div class="withdrawal-financial-summary">
            <?php foreach (admin_withdrawal_balance_summary($row) as $label => $value): ?><div class="withdrawal-financial-row <?= $label === 'موجودی فعلی حساب' ? 'current' : '' ?>"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?>
            <?php if (admin_withdrawal_reservation_note_is_accurate($row)): ?><p class="withdrawal-reservation-note">مبلغ درخواست برداشت هنگام ثبت درخواست از موجودی قابل استفاده کاربر رزرو شده است.</p><?php endif; ?>
          </div></td>
          <td dir="ltr"><?= e((string)$row['card_number']) ?></td>
          <td><?= e((string)$row['cardholder_name']) ?></td>
          <td><?= e(jalali_date((string)$row['created_at'])) ?></td>
          <td><?= ($row['request_source'] ?? '') === 'admin' ? 'مدیریت / دستی' : (($row['request_source'] ?? '') === 'customer' ? 'کاربر' : 'قدیمی / نامشخص') ?></td>
          <td><span class="withdrawal-status <?= e((string)$row['status']) ?>"><?= e($withdrawalStatusLabels[(string)$row['status']] ?? (string)$row['status']) ?></span></td>
          <td class="withdrawal-operations">
            <?php $statusOptions = withdrawal_admin_status_options((string)$row['status']); ?>
            <?php if ($managedRequest && $statusOptions !== []): ?>
              <form method="post" class="withdrawal-status-form" data-current-status="<?= e((string)$row['status']) ?>" onsubmit="return confirmWithdrawalStatusChange(this)">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="confirmed_transition" value="">
                <label for="withdrawal-status-<?= (int)$row['id'] ?>">وضعیت برداشت:</label>
                <select class="select" id="withdrawal-status-<?= (int)$row['id'] ?>" name="withdrawal_status" <?= count($statusOptions) === 1 || !can('edit') ? 'disabled' : '' ?>>
                  <?php foreach ($statusOptions as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= (string)$row['status'] === $statusOption ? 'selected' : '' ?>><?= e($withdrawalStatusLabels[$statusOption]) ?></option><?php endforeach; ?>
                </select>
                <?php if (count($statusOptions) > 1 && can('edit')): ?><button class="btn btn-primary" type="submit">ثبت وضعیت</button><?php else: ?><small class="withdrawal-help">این وضعیت نهایی یا فقط‌خواندنی است.</small><?php endif; ?>
              </form>
            <?php else: ?><span class="withdrawal-help">رکورد قدیمی / فقط‌خواندنی</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  function confirmWithdrawalStatusChange(form) {
    const selector = form.querySelector('select[name="withdrawal_status"]');
    const confirmation = form.querySelector('input[name="confirmed_transition"]');
    if (!selector || !confirmation) return false;
    const target = selector.value;
    confirmation.value = '';
    if (target === 'paid') {
      if (!window.confirm('آیا مبلغ این برداشت واقعاً به کارت مشتری پرداخت شده است؟ پس از ثبت وضعیت پرداخت شده، این عملیات قابل بازگشت نیست.')) return false;
      confirmation.value = 'paid';
    } else if (target === 'rejected') {
      if (!window.confirm('با رد این درخواست، برداشت لغو می‌شود و منطق موجود سامانه مبلغ رزروشده را در صورت لزوم دقیقاً یک‌بار به موجودی کاربر بازمی‌گرداند. ادامه می‌دهید؟')) return false;
      confirmation.value = 'rejected';
    }
    return true;
  }
</script>

<?php if (can('create')): ?>
<div class="modal-backdrop" id="new-withdrawal">
  <div class="modal">
    <div class="modal-head"><h3 class="modal-title">ثبت درخواست برداشت جدید</h3><button class="icon-btn" type="button" data-modal-close="new-withdrawal">×</button></div>
    <div class="modal-body">
      <form method="post" id="manual-withdrawal-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="create_nonce" value="<?= e((string)$_SESSION['withdrawal_create_nonce']) ?>">
        <div class="form-grid">
          <div class="col-12"><label class="field-label" for="user-search">جستجوی کاربر</label><input class="input" id="user-search" placeholder="نام یا شماره موبایل"></div>
          <div class="col-12"><label class="field-label" for="withdrawal-user">انتخاب کاربر</label><select class="select" id="withdrawal-user" name="user_id" required><option value="">انتخاب کنید</option><?php foreach ($users as $user): $label = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) . ' — ' . (string)$user['mobile']; ?><option value="<?= (int)$user['id'] ?>" data-search="<?= e($label) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-4"><label class="field-label">مبلغ (تومان)</label><input class="input" name="amount" inputmode="numeric" data-money-input data-money-words-target="withdrawalAmountWords" required><div class="money-amount-words" id="withdrawalAmountWords"></div></div>
          <div class="col-4"><label class="field-label">شماره کارت</label><input class="input" name="card_number" inputmode="numeric" maxlength="22" required></div>
          <div class="col-4"><label class="field-label">نام کامل صاحب کارت</label><input class="input" name="cardholder_name" maxlength="150" required></div>
          <div class="col-12"><button class="btn btn-primary" type="submit">ثبت با وضعیت در انتظار بررسی</button></div>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
  document.getElementById('user-search')?.addEventListener('input', function () {
    const query = this.value.trim().toLocaleLowerCase('fa');
    document.querySelectorAll('#withdrawal-user option[data-search]').forEach(function (option) {
      option.hidden = query !== '' && !option.dataset.search.toLocaleLowerCase('fa').includes(query);
    });
  });
  document.getElementById('manual-withdrawal-form')?.addEventListener('submit', function () {
    this.querySelector('button[type="submit"]').disabled = true;
  });
</script>
<?php endif; ?>
<?php render_money_input_helpers(); ?>
<?php render_page_end(); ?>
