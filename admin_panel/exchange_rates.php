<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/exchange_rate_service.php';
require_login();
require_permission('view');

$supportedCurrencies = exchange_rate_supported_currencies();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        require_permission('delete');
        if ($id <= 0) {
            flash('error', 'شناسه نرخ معتبر نیست.');
        } else {
            $stmt = db()->prepare('DELETE FROM exchange_rates WHERE id = ?');
            $stmt->execute([$id]);
            log_activity('delete', 'exchange_rates', $id, "حذف نرخ ارز #{$id}");
            flash('success', 'نرخ ارز حذف شد.');
        }
        header('Location: exchange_rates.php?page=' . $page);
        exit;
    }

    if ($action === 'toggle') {
        require_permission('edit');
        $stmt = db()->prepare('SELECT source_currency, destination_currency, effective_date, is_active FROM exchange_rates WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            flash('error', 'نرخ مورد نظر پیدا نشد.');
        } elseif ((int)$existing['is_active'] === 1) {
            db()->prepare('UPDATE exchange_rates SET is_active = 0 WHERE id = ?')->execute([$id]);
            log_activity('update', 'exchange_rates', $id, 'غیرفعال‌سازی نرخ ارز');
            flash('success', 'نرخ غیرفعال شد.');
        } else {
            db()->beginTransaction();
            try {
                exchange_rate_lock_pair((string)$existing['source_currency'], (string)$existing['destination_currency']);
                db()->prepare('UPDATE exchange_rates SET is_active = 0 WHERE source_currency = ? AND destination_currency = ? AND is_active = 1 AND effective_date >= ?')->execute([$existing['source_currency'], $existing['destination_currency'], $existing['effective_date']]);
                db()->prepare('UPDATE exchange_rates SET is_active = 1 WHERE id = ?')->execute([$id]);
                db()->commit();
                log_activity('update', 'exchange_rates', $id, 'فعال‌سازی نرخ ارز');
                flash('success', 'نرخ برای همین جفت ارز فعال شد.');
            } catch (Throwable $error) {
                if (db()->inTransaction()) db()->rollBack();
                flash('error', 'تغییر وضعیت نرخ انجام نشد.');
            }
        }
        header('Location: exchange_rates.php?page=' . $page);
        exit;
    }

    if (!in_array($action, ['create', 'update'], true)) {
        flash('error', 'عملیات نرخ ارز معتبر نیست.');
        header('Location: exchange_rates.php?page=' . $page);
        exit;
    }
    require_permission($action === 'create' ? 'create' : 'edit');

    $source = strtoupper(trim((string)($_POST['source_currency'] ?? '')));
    $destination = strtoupper(trim((string)($_POST['destination_currency'] ?? '')));
    $rate = trim((string)($_POST['exchange_rate'] ?? ''));
    $commission = trim((string)($_POST['commission_percentage'] ?? '0'));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $isValidDate = $validDate !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $validDate->format('Y-m-d') === $effectiveDate;

    if (!isset($supportedCurrencies[$source]) || !isset($supportedCurrencies[$destination])) {
        flash('error', 'ارز مبدأ یا مقصد پشتیبانی نمی‌شود.');
    } elseif ($source === $destination) {
        flash('error', 'ارز مبدأ و مقصد نمی‌توانند یکسان باشند.');
    } elseif (!exchange_rate_decimal_is_valid($rate)) {
        flash('error', 'نرخ باید عددی مثبت و حداکثر با ۱۲ رقم اعشار باشد.');
    } elseif (!exchange_rate_decimal_is_valid($commission, true) || (float)$commission > 100) {
        flash('error', 'درصد کارمزد باید بین صفر تا ۱۰۰ باشد.');
    } elseif (!$isValidDate) {
        flash('error', 'تاریخ موثر معتبر نیست.');
    } elseif ($action === 'update' && $id <= 0) {
        flash('error', 'شناسه نرخ برای ویرایش معتبر نیست.');
    } else {
        try {
            db()->beginTransaction();
            if ($isActive === 1) {
                exchange_rate_lock_pair($source, $destination);
                db()->prepare('UPDATE exchange_rates SET is_active = 0 WHERE source_currency = ? AND destination_currency = ? AND is_active = 1 AND effective_date >= ? AND id <> ?')->execute([$source, $destination, $effectiveDate, $id]);
            }
            // Keep the legacy columns populated for older readers. Their historical
            // IRT/AFN meaning is preserved; new consumers use exchange_rate + pair.
            $legacyReciprocal = $source === 'IRT' && $destination === 'AFN' ? (1 / (float)$rate) : (float)$rate;
            $legacyRate = $source === 'IRT' && $destination === 'AFN' ? $legacyReciprocal : (float)$rate;
            if ($action === 'create') {
                $stmt = db()->prepare('INSERT INTO exchange_rates (rate, afn_to_toman, toman_to_afn, effective_date, is_active, created_at, source_currency, destination_currency, exchange_rate, commission_percentage) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)');
                $stmt->execute([$legacyRate, $legacyReciprocal, $rate, $effectiveDate, $isActive, $source, $destination, $rate, $commission]);
                $savedId = (int)db()->lastInsertId();
                log_activity('create', 'exchange_rates', $savedId, "ثبت نرخ {$source} به {$destination}: {$rate}");
                flash('success', 'نرخ ارز با موفقیت ثبت شد.');
            } else {
                $stmt = db()->prepare('UPDATE exchange_rates SET rate = ?, afn_to_toman = ?, toman_to_afn = ?, effective_date = ?, is_active = ?, source_currency = ?, destination_currency = ?, exchange_rate = ?, commission_percentage = ? WHERE id = ?');
                $stmt->execute([$legacyRate, $legacyReciprocal, $rate, $effectiveDate, $isActive, $source, $destination, $rate, $commission, $id]);
                log_activity('update', 'exchange_rates', $id, "ویرایش نرخ {$source} به {$destination}: {$rate}");
                flash('success', 'نرخ ارز ویرایش شد.');
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('Exchange rate save failed: ' . $error->getMessage());
            flash('error', 'ثبت نرخ انجام نشد. پایگاه‌داده را بررسی کنید و دوباره تلاش کنید.');
        }
    }
    header('Location: exchange_rates.php?page=' . $page);
    exit;
}

$where = [];
$params = [];
if ($startDate !== '') {
    $date = jalali_input_to_gregorian_datetime($startDate, false);
    if ($date) { $where[] = 'created_at >= ?'; $params[] = $date; }
}
if ($endDate !== '') {
    $date = jalali_input_to_gregorian_datetime($endDate, true);
    if ($date) { $where[] = 'created_at <= ?'; $params[] = $date; }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$count = db()->prepare("SELECT COUNT(*) FROM exchange_rates {$whereSql}");
$count->execute($params);
$totalRows = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$stmt = db()->prepare("SELECT id, rate, afn_to_toman, toman_to_afn, effective_date, is_active, created_at, source_currency, destination_currency, exchange_rate, commission_percentage FROM exchange_rates {$whereSql} ORDER BY effective_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$export = (string)($_GET['export'] ?? '');
if ($export === 'excel' || $export === 'print') {
    $reportStmt = db()->prepare("SELECT id, source_currency, destination_currency, exchange_rate, commission_percentage, effective_date, is_active, created_at FROM exchange_rates {$whereSql} ORDER BY effective_date DESC, id DESC");
    $reportStmt->execute($params);
    $reportRows = [];
    foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reportRows[] = ['#' . (string)$row['id'], '1 ' . (string)$row['source_currency'] . ' = ' . (string)$row['exchange_rate'] . ' ' . (string)$row['destination_currency'], (string)$row['commission_percentage'] . '%', (string)$row['effective_date'], (int)$row['is_active'] === 1 ? 'فعال' : 'غیرفعال', to_jalali_datetime((string)$row['created_at'])];
    }
    $headers = ['شناسه', 'جهت و نرخ', 'کارمزد', 'تاریخ موثر', 'وضعیت', 'تاریخ ثبت'];
    if ($export === 'excel') export_xls_table('Exchange_Rates_Report_' . date('Ymd_His') . '.xls', $headers, $reportRows);
    render_print_table_view('گزارش نرخ ارز', $headers, $reportRows, 'فیلتر بازه تاریخ اعمال شده است.');
}
$today = date('Y-m-d');
$current = db()->query("SELECT id, source_currency, destination_currency, exchange_rate, commission_percentage, effective_date FROM exchange_rates WHERE is_active = 1 AND effective_date <= CURRENT_DATE ORDER BY effective_date DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

render_page_start('مدیریت نرخ ارز', 'rates');
?>
<style>
.rate-page{display:grid;gap:18px}.rate-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.rate-direction{direction:ltr;text-align:right;font-weight:700;color:#0f172a}.rate-table{width:100%;border-collapse:collapse;min-width:850px}.rate-table th,.rate-table td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:right;vertical-align:middle}.rate-table th{background:#f8fafc;color:#475569}.rate-pair{font-weight:800;color:#0f172a}.rate-actions{display:flex;gap:6px;flex-wrap:wrap}.rate-actions form{margin:0}.rate-status{display:inline-block;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700}.rate-on{background:#dcfce7;color:#166534}.rate-off{background:#f1f5f9;color:#64748b}.rate-current{display:flex;gap:20px;flex-wrap:wrap;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px}.rate-current span{display:block}.rate-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.rate-form-grid .wide{grid-column:1/-1}@media(max-width:640px){.rate-form-grid{grid-template-columns:1fr}.rate-form-grid .wide{grid-column:auto}}
</style>
<div class="rate-page">
  <section class="card">
    <div class="rate-head"><h3 style="margin:0">مدیریت نرخ ارز</h3><?php if(can('create')): ?><button class="btn btn-primary" data-modal-open="rateModal" onclick="resetRateForm()">ثبت نرخ جدید</button><?php endif; ?></div>
    <?php if($current): ?><div class="rate-current" style="margin-top:16px"><div><span>جفت فعال</span><strong class="rate-direction">1 <?= e((string)$current['source_currency']) ?> = <?= e((string)$current['exchange_rate']) ?> <?= e((string)$current['destination_currency']) ?></strong></div><div><span>کارمزد تبدیل</span><strong><?= e((string)$current['commission_percentage']) ?>٪</strong></div><div><span>تاریخ موثر</span><strong><?= e((string)$current['effective_date']) ?></strong></div></div><?php endif; ?>
  </section>
  <section class="card">
    <form method="get" class="actions" style="display:flex;gap:10px;flex-wrap:wrap"><input type="hidden" name="page" value="1"><input class="input js-persian-date" name="start_date" value="<?= e($startDate) ?>" placeholder="از تاریخ"><input class="input js-persian-date" name="end_date" value="<?= e($endDate) ?>" placeholder="تا تاریخ"><button class="btn btn-primary">فیلتر</button></form><div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"><a class="btn btn-light" href="exchange_rates.php?<?= e(http_build_query(array_merge($_GET,['export'=>'excel']))) ?>">خروجی Excel</a><a class="btn btn-light" href="exchange_rates.php?<?= e(http_build_query(array_merge($_GET,['export'=>'print']))) ?>">خروجی PDF</a></div>
    <div class="table-wrap" style="overflow:auto;margin-top:14px"><table class="rate-table" id="ratesTable"><thead><tr><th>#</th><th>جفت ارز / جهت نرخ</th><th>درصد کارمزد</th><th>تاریخ موثر</th><th>وضعیت</th><th>ثبت</th><th>عملیات</th></tr><tr class="rate-filter-row"><?php foreach(['شناسه','جفت ارز','کارمزد','تاریخ موثر','وضعیت','تاریخ ثبت'] as $column => $placeholder): ?><th><input class="input" type="search" data-rate-filter="<?= $column ?>" placeholder="<?= e($placeholder) ?>"></th><?php endforeach; ?><th></th></tr></thead><tbody>
    <?php foreach($rows as $row): $source = (string)($row['source_currency'] ?? 'IRT'); $destination = (string)($row['destination_currency'] ?? 'AFN'); $value = (string)($row['exchange_rate'] ?? ''); if ($value === '' && (float)$row['afn_to_toman'] > 0) $value = (string)(1 / (float)$row['afn_to_toman']); ?>
      <tr><td>#<?= (int)$row['id'] ?></td><td><div class="rate-pair"><?= e($supportedCurrencies[$source]['name'] ?? $source) ?> (<?= e($source) ?>) → <?= e($supportedCurrencies[$destination]['name'] ?? $destination) ?> (<?= e($destination) ?>)</div><div class="rate-direction">1 <?= e($source) ?> = <?= e($value) ?> <?= e($destination) ?></div></td><td><?= e((string)($row['commission_percentage'] ?? '0')) ?>٪</td><td><?= e((string)$row['effective_date']) ?></td><td><span class="rate-status <?= (int)$row['is_active'] === 1 ? 'rate-on' : 'rate-off' ?>"><?= (int)$row['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></span></td><td><?= e(to_jalali_datetime((string)$row['created_at'])) ?></td><td><div class="rate-actions">
        <?php if(can('edit')): ?><button class="btn btn-sm" type="button" onclick='editRate(<?= json_encode(['id'=>(int)$row['id'],'source'=>$source,'destination'=>$destination,'rate'=>$value,'commission'=>(string)($row['commission_percentage'] ?? '0'),'date'=>(string)$row['effective_date'],'active'=>(int)$row['is_active']], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>ویرایش</button><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm" type="submit"><?= (int)$row['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button></form><?php endif; ?>
        <?php if(can('delete')): ?><form method="post" onsubmit="return confirm('این نرخ حذف شود؟')"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form><?php endif; ?>
      </div></td></tr>
    <?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="7" style="text-align:center">نرخی برای نمایش وجود ندارد.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($totalPages>1): ?><div class="pagination"><?php for($i=1;$i<=$totalPages;$i++): ?><a class="page-link <?= $i===$page?'active':'' ?>" href="exchange_rates.php?<?= e(http_build_query(array_merge($_GET,['page'=>$i]))) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  </section>
</div>
<?php if(can('create') || can('edit')): ?>
<div class="modal-backdrop" id="rateModal"><div class="modal"><div class="modal-head"><h4 class="modal-title" id="rateModalTitle">ثبت نرخ جدید</h4><button class="icon-btn" type="button" data-modal-close="rateModal">×</button></div><div class="modal-body"><form method="post" class="rate-form-grid" id="rateForm"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" id="rateAction" value="create"><input type="hidden" name="id" id="rateId"><div><label class="field-label">ارز مبدأ</label><select class="select" name="source_currency" id="rateSource" required><?php foreach($supportedCurrencies as $currency): ?><option value="<?= e($currency['code']) ?>"><?= e($currency['name']) ?> (<?= e($currency['code']) ?>)</option><?php endforeach; ?></select></div><div><label class="field-label">ارز مقصد</label><select class="select" name="destination_currency" id="rateDestination" required><?php foreach($supportedCurrencies as $currency): ?><option value="<?= e($currency['code']) ?>"><?= e($currency['name']) ?> (<?= e($currency['code']) ?>)</option><?php endforeach; ?></select></div><div class="wide"><label class="field-label" id="rateDirectionLabel">۱ IRT = نرخ AFN</label><input class="input" type="number" name="exchange_rate" id="rateValue" min="0.000000000001" step="any" dir="ltr" required></div><div><label class="field-label">کارمزد تبدیل (٪)</label><input class="input" type="number" name="commission_percentage" id="rateCommission" min="0" max="100" step="0.0001" value="0" dir="ltr" required></div><div><label class="field-label">تاریخ موثر</label><input class="input" type="date" name="effective_date" id="rateDate" value="<?= e($today) ?>" required dir="ltr"></div><div class="wide"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" id="rateActive" checked> به‌عنوان نرخ فعال این جفت ثبت شود</label></div><div class="wide actions"><button class="btn btn-primary" type="submit">ذخیره</button><button class="btn btn-light" type="button" data-modal-close="rateModal">انصراف</button></div></form></div></div></div>
<script>
function updateRateDirection(){const s=document.getElementById('rateSource'),d=document.getElementById('rateDestination'),label=document.getElementById('rateDirectionLabel');label.textContent=`۱ ${s.value} = نرخ ${d.value}`;}
function resetRateForm(){const f=document.getElementById('rateForm');f.reset();document.getElementById('rateAction').value='create';document.getElementById('rateId').value='';document.getElementById('rateSource').value='IRT';document.getElementById('rateDestination').value='AFN';document.getElementById('rateDate').value='<?= e($today) ?>';document.getElementById('rateCommission').value='0';document.getElementById('rateActive').checked=true;document.getElementById('rateModalTitle').textContent='ثبت نرخ جدید';updateRateDirection();}
function editRate(row){document.getElementById('rateAction').value='update';document.getElementById('rateId').value=row.id;document.getElementById('rateSource').value=row.source;document.getElementById('rateDestination').value=row.destination;document.getElementById('rateValue').value=row.rate;document.getElementById('rateCommission').value=row.commission;document.getElementById('rateDate').value=row.date;document.getElementById('rateActive').checked=row.active===1;document.getElementById('rateModalTitle').textContent='ویرایش نرخ ارز';updateRateDirection();document.getElementById('rateModal').classList.add('open');}
document.getElementById('rateSource').addEventListener('change',updateRateDirection);document.getElementById('rateDestination').addEventListener('change',updateRateDirection);document.getElementById('rateForm').addEventListener('submit',e=>{if(document.getElementById('rateSource').value===document.getElementById('rateDestination').value){e.preventDefault();alert('ارز مبدأ و مقصد نمی‌توانند یکسان باشند.');}});
const rateRows=Array.from(document.querySelectorAll('#ratesTable tbody tr'));document.querySelectorAll('[data-rate-filter]').forEach(input=>input.addEventListener('input',()=>{const active=Array.from(document.querySelectorAll('[data-rate-filter]'));rateRows.forEach(row=>{row.hidden=active.some(filter=>filter.value.trim()&&!((row.cells[Number(filter.dataset.rateFilter)]?.innerText||'').toLocaleLowerCase('fa').includes(filter.value.trim().toLocaleLowerCase('fa'))));});}));
</script>
<?php endif; ?>
<?php render_page_end(); ?>
