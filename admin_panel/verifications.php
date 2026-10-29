<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/verification_service.php';
require_login();
require_permission('view');
ensure_verification_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('edit');
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $note = trim((string)($_POST['admin_note'] ?? ''));
    try {
        $finalLevel = trim((string)($_POST['final_level_override'] ?? $_POST['final_level'] ?? ''));
        review_verification_request(db(), $requestId, $decision, $note, (int)$_SESSION['admin_id'], __DIR__ . '/private_verifications', $finalLevel !== '' ? $finalLevel : null);
        flash('success', $decision === 'approved' ? 'درخواست با موفقیت تأیید شد.' : 'درخواست رد شد.');
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    header('Location: verifications.php'); exit;
}

$status = trim((string)($_GET['status'] ?? 'all'));
$status = $status === 'all' ? 'all' : (in_array($status, ['pending', 'approved', 'rejected', 'needs_more_info'], true) ? $status : 'pending');
$where = $status === 'all' ? '' : ' WHERE r.status = ' . db()->quote($status);
$requestCounts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'needs_more_info' => 0];
$countRows = db()->query('SELECT status, COUNT(*) AS total FROM verification_upgrade_requests GROUP BY status')->fetchAll();
foreach ($countRows as $countRow) {
    $countStatus = (string)($countRow['status'] ?? '');
    if (array_key_exists($countStatus, $requestCounts)) {
        $requestCounts[$countStatus] = (int)$countRow['total'];
        $requestCounts['total'] += (int)$countRow['total'];
    }
}
$rows = db()->query("SELECT r.*, u.mobile, u.first_name, u.last_name, v.level FROM verification_upgrade_requests r LEFT JOIN users u ON u.id = r.user_id LEFT JOIN user_verification_levels v ON v.user_id = r.user_id {$where} ORDER BY r.id DESC")->fetchAll();
error_log('[KYC_DEBUG] total verification requests=' . $requestCounts['total']);
error_log('[KYC_DEBUG] pending requests=' . $requestCounts['pending']);
error_log('[KYC_DEBUG] approved requests=' . $requestCounts['approved']);
error_log('[KYC_DEBUG] rejected requests=' . $requestCounts['rejected']);
error_log('[KYC_DEBUG] admin query rows=' . count($rows));
if ($rows !== []) {
    $debugRequest = $rows[0];
    error_log('[KYC_DEBUG] request id=' . (int)$debugRequest['id'] . ' user_id=' . (int)$debugRequest['user_id']
        . ' identity_document_path present=' . (!empty($debugRequest['identity_document_path']) ? 'true' : 'false')
        . ' selfie_path present=' . (!empty($debugRequest['selfie_path']) ? 'true' : 'false')
        . ' video_path present=' . (!empty($debugRequest['video_path']) ? 'true' : 'false'));
}
$csrf = csrf_token();
$definitions = verification_level_definitions();
render_page_start('مدیریت احراز هویت', 'verifications');
?>
<div class="kyc-dashboard" dir="rtl">
  <div class="kyc-dashboard-header">
    <div>
      <span class="kyc-eyebrow">مرکز بررسی</span>
      <h1>درخواست‌های احراز هویت</h1>
      <p>بررسی مدارک، یادداشت ادمین و تعیین سطح دسترسی در یک صفحه</p>
    </div>
    <div class="kyc-filters">
      <a class="kyc-filter <?= $status === 'all' ? 'active' : '' ?>" href="verifications.php?status=all">همه</a>
      <a class="kyc-filter <?= $status === 'pending' ? 'active' : '' ?>" href="verifications.php?status=pending">در انتظار</a>
      <a class="kyc-filter <?= $status === 'needs_more_info' ? 'active' : '' ?>" href="verifications.php?status=needs_more_info">نیاز به تکمیل</a>
      <a class="kyc-filter <?= $status === 'approved' ? 'active' : '' ?>" href="verifications.php?status=approved">تأیید شده</a>
      <a class="kyc-filter <?= $status === 'rejected' ? 'active' : '' ?>" href="verifications.php?status=rejected">رد شده</a>
    </div>
  </div>
  <?php if ($rows === []): ?>
    <div class="kyc-empty">درخواستی برای نمایش وجود ندارد.</div>
  <?php endif; ?>
  <div class="kyc-request-list">
  <?php
  $statusLabels = [
      'pending' => ['در انتظار بررسی', 'pending'],
      'approved' => ['تأیید شده', 'approved'],
      'rejected' => ['رد شده', 'rejected'],
      'needs_more_info' => ['نیاز به تکمیل مدارک', 'needs-more-info'],
  ];
  foreach ($rows as $row):
      $rowLevel = normalize_verification_level((string)($row['level'] ?? 'bronze'));
      $requestedLevel = normalize_verification_level((string)($row['request_type'] ?? $row['requested_level'] ?? 'silver'));
      $statusInfo = $statusLabels[(string)$row['status']] ?? [(string)$row['status'], 'pending'];
      $userName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
      $mediaFields = [
          'identity_document_path' => ['مدرک هویتی', 'مشاهده مدرک', 'دانلود مدرک'],
          'selfie_path' => ['سلفی', 'مشاهده سلفی', 'دانلود سلفی'],
          'video_path' => ['ویدیو احراز هویت', 'مشاهده ویدیو', 'دانلود ویدیو'],
      ];
  ?>
    <article class="kyc-request-card">
      <header class="kyc-card-header">
        <div class="kyc-user">
          <div class="kyc-avatar"><?= e(function_exists('mb_substr') ? mb_substr($userName !== '' ? $userName : '؟', 0, 1) : substr($userName !== '' ? $userName : '?', 0, 1)) ?></div>
          <div><h2><?= e($userName !== '' ? $userName : 'کاربر #' . (int)$row['user_id']) ?></h2><span dir="ltr"><?= e((string)($row['mobile'] ?? '')) ?></span></div>
        </div>
        <span class="kyc-status <?= e($statusInfo[1]) ?>"><?= e($statusInfo[0]) ?></span>
      </header>
      <div class="kyc-meta-grid">
        <div><span>سطح فعلی</span><strong><?= e((string)($definitions[$rowLevel]['title'] ?? $rowLevel)) ?></strong></div>
        <div><span>سطح درخواستی</span><strong><?= e((string)($definitions[$requestedLevel]['title'] ?? $requestedLevel)) ?></strong></div>
        <div><span>تاریخ ارسال</span><strong><?= e((string)$row['created_at']) ?></strong></div>
        <?php if (!empty($row['reviewed_at'])): ?><div><span>تاریخ بررسی</span><strong><?= e((string)$row['reviewed_at']) ?></strong></div><?php endif; ?>
      </div>
      <section class="kyc-section">
        <h3>مدارک ارسالی</h3>
        <div class="kyc-evidence-grid">
        <?php foreach ($mediaFields as $field => [$label, $viewLabel, $downloadLabel]): ?>
          <?php $mediaFile = verification_secure_media_path(__DIR__ . '/private_verifications', $row[$field] ?? null); ?>
          <div class="kyc-evidence">
            <div class="kyc-evidence-title"><span><?= e($label) ?></span><span class="<?= $mediaFile !== null ? 'evidence-ok' : 'evidence-missing' ?>"><?= $mediaFile !== null ? 'موجود' : 'بارگذاری نشده' ?></span></div>
            <?php if ($mediaFile !== null): ?>
              <div class="kyc-evidence-actions"><a class="kyc-button kyc-button-view" target="_blank" rel="noopener" href="verification-media.php?request_id=<?= (int)$row['id'] ?>&field=<?= e($field) ?>"><?= e($viewLabel) ?></a><a class="kyc-button kyc-button-download" href="verification-media.php?request_id=<?= (int)$row['id'] ?>&field=<?= e($field) ?>&download=1"><?= e($downloadLabel) ?></a></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      </section>
      <?php $existingNote = trim((string)($row['admin_note'] ?? $row['rejection_reason'] ?? '')); ?>
      <?php if ($row['status'] !== 'pending' && $row['status'] !== 'needs_more_info'): ?>
        <section class="kyc-review-summary">
          <div><span>یادداشت ادمین</span><p><?= e($existingNote !== '' ? $existingNote : 'یادداشتی ثبت نشده است.') ?></p></div>
          <div><span>شناسه بررسی‌کننده</span><p><?= !empty($row['admin_id']) ? '#' . (int)$row['admin_id'] : 'ثبت نشده' ?><?= !empty($row['reviewed_at']) ? ' · ' . e((string)$row['reviewed_at']) : '' ?></p></div>
        </section>
      <?php else: ?>
        <form method="post" class="kyc-review-form">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
          <label>سطح دسترسی نهایی<select name="final_level" required><option value="bronze" <?= $requestedLevel === 'bronze' ? 'selected' : '' ?>>برنزی</option><option value="silver" <?= $requestedLevel === 'silver' ? 'selected' : '' ?>>نقره‌ای</option><option value="gold" <?= $requestedLevel === 'gold' ? 'selected' : '' ?>>طلایی</option></select></label>
          <label class="kyc-note-field">یادداشت ادمین<textarea name="admin_note" placeholder="توضیحات بررسی، دلیل رد یا مدارک مورد نیاز را بنویسید..." <?= $row['status'] === 'pending' ? '' : 'required' ?>><?= e($existingNote) ?></textarea></label>
          <div class="kyc-review-actions"><button class="kyc-button kyc-button-approve" name="decision" value="approved">تأیید احراز هویت</button><button class="kyc-button kyc-button-reject" name="decision" value="rejected">رد درخواست</button><button class="kyc-button kyc-button-more" name="decision" value="needs_more_info">درخواست تکمیل مدارک</button></div>
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
</div>
<style>
.kyc-dashboard { max-width: 1180px; margin: 0 auto; }
.kyc-dashboard-header { display:flex; align-items:flex-end; justify-content:space-between; gap:24px; margin-bottom:24px; }
.kyc-eyebrow { color:#0f9f76; font-size:12px; font-weight:800; }
.kyc-dashboard h1 { margin:5px 0 4px; color:#102a43; font-size:26px; }
.kyc-dashboard-header p { margin:0; color:#64748b; font-size:13px; }
.kyc-filters { display:flex; flex-wrap:wrap; gap:7px; }
.kyc-filter { padding:9px 13px; border:1px solid #dbe4ef; border-radius:9px; color:#475569; background:#fff; text-decoration:none; font-size:12px; }
.kyc-filter.active { border-color:#0e9471; background:#ecfdf5; color:#047857; font-weight:800; }
.kyc-request-list { display:grid; gap:18px; }
.kyc-request-card { background:#fff; border:1px solid #dbe4ef; border-radius:14px; box-shadow:0 8px 22px rgba(15,23,42,.06); overflow:hidden; }
.kyc-card-header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:20px 22px; border-bottom:1px solid #edf2f7; }
.kyc-user { display:flex; align-items:center; gap:12px; }
.kyc-avatar { width:42px; height:42px; border-radius:50%; display:grid; place-items:center; background:#e8f7f1; color:#087f62; font-weight:900; }
.kyc-user h2 { margin:0 0 4px; color:#102a43; font-size:16px; }
.kyc-user span { color:#64748b; font-size:12px; }
.kyc-status { padding:7px 11px; border-radius:999px; font-size:11px; font-weight:900; white-space:nowrap; }
.kyc-status.pending { color:#a16207; background:#fef3c7; }.kyc-status.approved { color:#047857; background:#d1fae5; }.kyc-status.rejected { color:#b91c1c; background:#fee2e2; }.kyc-status.needs-more-info { color:#1d4ed8; background:#dbeafe; }
.kyc-meta-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:18px 22px; background:#fbfdff; }
.kyc-meta-grid div { padding-left:12px; border-left:1px solid #e2e8f0; }.kyc-meta-grid div:last-child { border-left:0; }
.kyc-meta-grid span, .kyc-review-summary span { display:block; color:#94a3b8; font-size:11px; margin-bottom:5px; }.kyc-meta-grid strong { color:#334155; font-size:13px; }
.kyc-section { padding:18px 22px 4px; }.kyc-section h3 { margin:0 0 12px; color:#334155; font-size:14px; }
.kyc-evidence-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }.kyc-evidence { border:1px solid #e2e8f0; border-radius:10px; padding:12px; }
.kyc-evidence-title { display:flex; justify-content:space-between; gap:8px; color:#334155; font-size:12px; font-weight:800; }.evidence-ok { color:#059669; font-size:10px; }.evidence-missing { color:#94a3b8; font-size:10px; }
.kyc-evidence-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }.kyc-button { border:1px solid transparent; border-radius:8px; padding:9px 12px; font:inherit; font-size:11px; font-weight:800; cursor:pointer; text-decoration:none; text-align:center; }
.kyc-button-view { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; }.kyc-button-download { color:#475569; background:#f8fafc; border-color:#cbd5e1; }
.kyc-review-form { display:grid; grid-template-columns:180px 1fr; gap:12px; padding:18px 22px 22px; margin-top:14px; border-top:1px solid #edf2f7; background:#fcfefe; }.kyc-review-form label { color:#334155; font-size:12px; font-weight:800; }.kyc-review-form select, .kyc-review-form textarea { display:block; width:100%; box-sizing:border-box; margin-top:7px; padding:10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; font:inherit; font-size:12px; }.kyc-review-form textarea { min-height:76px; resize:vertical; }.kyc-note-field { grid-column:2; }.kyc-review-actions { grid-column:1 / -1; display:flex; gap:8px; flex-wrap:wrap; }.kyc-button-approve { color:#fff; background:#059669; }.kyc-button-reject { color:#fff; background:#dc2626; }.kyc-button-more { color:#92400e; background:#fef3c7; border-color:#fde68a; }
.kyc-review-summary { display:grid; grid-template-columns:2fr 1fr; gap:18px; margin:18px 22px 22px; padding:14px; border-radius:10px; background:#f8fafc; }.kyc-review-summary p { margin:0; color:#475569; font-size:12px; line-height:1.8; }.kyc-empty { padding:40px; text-align:center; color:#64748b; background:#fff; border:1px solid #dbe4ef; border-radius:14px; }
@media (max-width:760px) { .kyc-dashboard-header { align-items:stretch; flex-direction:column; }.kyc-meta-grid { grid-template-columns:repeat(2,1fr); }.kyc-evidence-grid { grid-template-columns:1fr; }.kyc-review-form { grid-template-columns:1fr; }.kyc-note-field { grid-column:1; }.kyc-review-summary { grid-template-columns:1fr; } }
</style>
<?php render_page_end(); exit; ?>
render_page_start('مدیریت احراز هویت', 'verifications');
?>
<div class="card"><div class="actions" style="justify-content:space-between"><h3 style="margin:0">درخواست‌های احراز هویت</h3><div><a class="btn btn-sm" href="verifications.php?status=pending">در انتظار</a> <a class="btn btn-sm" href="verifications.php?status=all">همه</a></div></div>
<div class="verification-table-wrap"><table class="verification-table"><thead><tr><th>کاربر</th><th>سطح فعلی</th><th>نوع</th><th>فایل‌ها</th><th>وضعیت</th><th>تاریخ</th><th class="verification-actions-heading">عملیات</th></tr></thead><tbody>
<?php foreach ($rows as $row): $rowLevel = normalize_verification_level((string)($row['level'] ?? 'bronze')); ?><tr><td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?><br><small dir="ltr"><?= e((string)$row['mobile']) ?></small></td><td><?= e((string)($definitions[$rowLevel]['title'] ?? $rowLevel)) ?></td><td><?= e((string)$row['request_type']) ?></td><td class="verification-files"><?php foreach (['identity_document_path' => 'مشاهده مدرک هویتی', 'selfie_path' => 'مشاهده تصویر سلفی', 'video_path' => 'مشاهده ویدیو'] as $field => $label): $mediaFile = verification_secure_media_path(__DIR__ . '/private_verifications', $row[$field] ?? null); if ($mediaFile !== null): ?><a class="btn btn-sm" target="_blank" rel="noopener" href="verification-media.php?request_id=<?= (int)$row['id'] ?>&field=<?= e($field) ?>"><?= $label ?></a><?php else: ?><span class="missing-media">مدرک بارگذاری نشده</span><?php endif; endforeach; ?></td><td><?= e((string)$row['status']) ?><br><small><?= e((string)($row['reviewed_at'] ?? '')) ?></small></td><td><?= e((string)$row['created_at']) ?></td><td class="verification-actions"><?php if ($row['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><input name="admin_note" placeholder="یادداشت یا دلیل رد" class="input"><div class="verification-action-buttons"><button name="decision" value="approved" class="btn btn-primary btn-sm">تأیید</button> <button name="decision" value="rejected" class="btn btn-danger btn-sm">رد</button></div></form><?php else: ?><?= e((string)($row['rejection_reason'] ?? $row['admin_note'] ?? '')) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<style>
.verification-table-wrap { overflow-x: auto; overflow-y: visible; max-width: 100%; border: 1px solid var(--line); border-radius: 12px; direction: rtl; -webkit-overflow-scrolling: touch; }
.verification-table { min-width: 1080px; direction: rtl; }
.verification-table th, .verification-table td { vertical-align: top; }
.verification-table tbody tr { background: #fff; box-shadow: 0 4px 14px rgba(15, 23, 42, .05); }
.verification-table tbody td { padding-top: 15px; padding-bottom: 15px; }
.verification-table tbody td:first-child { font-weight: 700; color: #172b3a; }
.verification-table tbody td:nth-child(5) { font-weight: 800; }
.verification-table tbody td:nth-child(5).status-pending { color: #a16207; background: #fffbeb; }
.verification-table tbody td:nth-child(5).status-approved { color: #047857; background: #ecfdf5; }
.verification-table tbody td:nth-child(5).status-rejected { color: #b91c1c; background: #fef2f2; }
.verification-table tbody td:nth-child(5).status-needs-more-info { color: #1d4ed8; background: #eff6ff; }
.verification-more-button { color: #1d4ed8 !important; border-color: #bfdbfe !important; background: #eff6ff !important; }
.verification-final-level { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; color: #334155; font-size: 13px; font-weight: 700; }
.verification-final-level .input { min-height: 40px; }
.verification-files { min-width: 270px; }
.verification-files .btn, .missing-media { display: inline-block; margin: 0 0 6px 4px; white-space: nowrap; }
.missing-media { color: #94a3b8; font-size: .85rem; }
.verification-actions-heading, .verification-actions { position: sticky; right: 0; z-index: 2; min-width: 245px; background: #fff; box-shadow: -4px 0 8px rgba(15, 23, 42, .08); }
.verification-actions-heading { z-index: 3; background: #f8fafc; }
.verification-actions form { min-width: 220px; }
.verification-action-buttons { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 7px; }
.verification-actions form { padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; }
.verification-actions form .input { min-height: 78px; resize: vertical; }
.verification-files .btn { border: 1px solid #cbd5e1; background: #fff; color: #1d4ed8; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); }
.verification-files .btn:hover { background: #eff6ff; }
@media (max-width: 700px) { .verification-table { min-width: 940px; } .verification-actions-heading, .verification-actions { min-width: 215px; } }
</style>
<script>
document.querySelectorAll('.verification-files a.btn').forEach(function (viewLink) {
  var field = new URL(viewLink.href, window.location.href).searchParams.get('field');
  var labels = {
    identity_document_path: '\u062f\u0627\u0646\u0644\u0648\u062f \u0645\u062f\u0631\u06a9',
    selfie_path: '\u062f\u0627\u0646\u0644\u0648\u062f \u062a\u0635\u0648\u06cc\u0631',
    video_path: '\u062f\u0627\u0646\u0644\u0648\u062f \u0648\u06cc\u062f\u06cc\u0648'
  };
  if (!field || !labels[field]) return;
  var downloadLink = viewLink.cloneNode(false);
  downloadLink.href = viewLink.href + '&download=1';
  downloadLink.target = '';
  downloadLink.textContent = labels[field];
  viewLink.parentNode.insertBefore(downloadLink, viewLink.nextSibling);
});
</script>
<script>
document.querySelectorAll('.verification-actions form').forEach(function (form) {
  var buttons = form.querySelector('.verification-action-buttons');
  if (!buttons) return;
  var moreButton = document.createElement('button');
  moreButton.type = 'submit';
  moreButton.name = 'decision';
  moreButton.value = 'needs_more_info';
  moreButton.className = 'btn btn-sm verification-more-button';
  moreButton.textContent = '\u062f\u0631\u062e\u0648\u0627\u0633\u062a \u062a\u06a9\u0645\u06cc\u0644 \u0645\u062f\u0627\u0631\u06a9';
  buttons.appendChild(moreButton);
});
if (false) document.querySelectorAll('.verification-table tbody tr').forEach(function (row) {
  var statusCell = row.cells[4];
  if (!statusCell) return;
  var status = (statusCell.firstChild && statusCell.firstChild.nodeValue || '').trim();
  var labels = {
    pending: '\\u062f\\u0631 \\u0627\\u0646\\u062a\\u0638\\u0627\\u0631',
    approved: '\\u062a\\u0623\\u06cc\\06cc\\u062f \\u0634\\u062f\\u0647',
    rejected: '\\u0631\\u062f \\u0634\\u062f\\u0647',
    needs_more_info: '\\u0646\\06cc\\0627\\0632 \\u0628\\0647 \\062a\\06a9\\0645\\06cc\\0644 \\0645\\062f\\0627\\0631\\06a9'
  };
  if (!labels[status]) return;
  statusCell.firstChild.nodeValue = labels[status] + ' ';
  statusCell.classList.add('status-' + status.replace(/_/g, '-'));
});
</script>
<script>
document.querySelectorAll('.verification-table tbody tr').forEach(function (row) {
  var cell = row.cells[4];
  if (!cell || !cell.firstChild) return;
  var status = cell.firstChild.nodeValue.trim();
  var labels = {
    pending: String.fromCharCode(1583, 1585, 32, 1575, 1606, 1578, 1592, 1575, 1585),
    approved: String.fromCharCode(1578, 1575, 1740, 1740, 1583, 32, 1588, 1583, 1607),
    rejected: String.fromCharCode(1585, 1583, 32, 1588, 1583, 1607),
    needs_more_info: String.fromCharCode(1606, 1740, 1575, 1586, 32, 1576, 1607, 32, 1578, 1705, 1605, 1740, 1604, 32, 1605, 1583, 1575, 1585, 1705)
  };
  if (labels[status]) cell.firstChild.nodeValue = labels[status] + ' ';
});
</script>
<script>
var kycCsrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var verificationTable = document.querySelector('.verification-table');
if (verificationTable) {
  var headers = verificationTable.querySelectorAll('thead th');
  if (headers[2]) headers[2].textContent = String.fromCharCode(1587, 1591, 1581, 32, 1583, 1585, 1582, 1608, 1575, 1587, 1578, 1740);
  var requestedLabels = {
    silver: String.fromCharCode(1606, 1602, 1585, 1607, 8204, 1575, 1740),
    gold: String.fromCharCode(1591, 1604, 1575, 1740)
  };
  verificationTable.querySelectorAll('tbody tr').forEach(function (row) {
    var requestedCell = row.cells[2];
    if (requestedCell && requestedLabels[requestedCell.textContent.trim()]) {
      requestedCell.textContent = requestedLabels[requestedCell.textContent.trim()];
      requestedCell.classList.add('verification-level-badge');
    }
  });
}
document.querySelectorAll('.verification-actions form input[name="admin_note"]').forEach(function (input) {
  var textarea = document.createElement('textarea');
  textarea.name = input.name;
  textarea.className = input.className;
  textarea.placeholder = 'توضیحات ادمین';
  textarea.setAttribute('aria-label', 'توضیحات ادمین');
  textarea.required = input.required;
  textarea.value = input.value;
  input.replaceWith(textarea);
});
document.querySelectorAll('.verification-table tbody tr').forEach(function (row) {
  var statusCell = row.cells[4];
  if (!statusCell || !statusCell.classList.contains('status-needs-more-info')) return;
  var actionCell = row.querySelector('.verification-actions');
  if (!actionCell || actionCell.querySelector('form')) return;
  var mediaLink = row.querySelector('.verification-files a[href*="request_id="]');
  if (!mediaLink) return;
  var requestId = new URL(mediaLink.href, window.location.href).searchParams.get('request_id');
  if (!requestId) return;
  var form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="csrf_token"><input type="hidden" name="request_id"><textarea name="admin_note" class="input" placeholder="توضیحات ادمین" aria-label="توضیحات ادمین" required></textarea><div class="verification-action-buttons"><button name="decision" value="approved" class="btn btn-primary btn-sm">تأیید احراز هویت</button><button name="decision" value="rejected" class="btn btn-danger btn-sm">رد درخواست</button><button name="decision" value="needs_more_info" class="btn btn-sm verification-more-button">درخواست تکمیل مدارک</button></div>';
  form.elements.csrf_token.value = kycCsrf;
  form.elements.request_id.value = requestId;
  actionCell.appendChild(form);
});
</script>
<script>
document.querySelectorAll('.verification-actions form').forEach(function (form) {
  if (form.querySelector('select[name="final_level"]')) return;
  var row = form.closest('tr');
  var requested = row && row.cells[2] ? row.cells[2].textContent.trim() : '';
  var goldLabel = String.fromCharCode(1591, 1604, 1575, 1740, 1740);
  var levels = [
    { value: 'silver', label: String.fromCharCode(1606, 1602, 1585, 1607, 8204, 1575, 1740) },
    { value: 'gold', label: goldLabel }
  ];
  var select = document.createElement('select');
  select.name = 'final_level';
  select.className = 'input';
  select.setAttribute('aria-label', 'سطح تأیید');
  levels.forEach(function (level) {
    var option = document.createElement('option');
    option.value = level.value;
    option.textContent = level.label;
    option.selected = requested === level.label;
    select.appendChild(option);
  });
  var label = document.createElement('label');
  label.className = 'verification-final-level';
  label.textContent = 'سطح تأیید';
  label.appendChild(select);
  var note = form.querySelector('textarea[name="admin_note"], input[name="admin_note"]');
  if (note) form.insertBefore(label, note);
  else form.insertBefore(label, form.firstChild);
});
</script>
<?php render_page_end();
