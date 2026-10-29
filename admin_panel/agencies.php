<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_login();
require_permission('view');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$exportExcel = (string)($_GET['export'] ?? '') === 'excel';
$exportPrint = (string)($_GET['export'] ?? '') === 'print';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_permission('create');
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || $address === '') {
            flash('error', 'نام و آدرس نمایندگی الزامی است.');
        } else {
            $stmt = db()->prepare('INSERT INTO agencies (name, address, phone, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$name, $address, $phone]);
            log_activity('create', 'agencies', (int)db()->lastInsertId(), "ایجاد نمایندگی: {$name}");
            flash('success', 'نمایندگی جدید ثبت شد.');
        }
        header('Location: agencies.php?page=' . $page);
        exit;
    }

    if ($action === 'update') {
        require_permission('edit');
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($id <= 0 || $name === '' || $address === '') {
            flash('error', 'اطلاعات ویرایش کامل نیست.');
        } else {
            $stmt = db()->prepare('UPDATE agencies SET name = ?, address = ?, phone = ? WHERE id = ?');
            $stmt->execute([$name, $address, $phone, $id]);
            log_activity('update', 'agencies', $id, "ویرایش نمایندگی: {$name}");
            flash('success', 'نمایندگی ویرایش شد.');
        }
        header('Location: agencies.php?page=' . $page);
        exit;
    }

    if ($action === 'delete') {
        require_permission('delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM agencies WHERE id = ?');
            $stmt->execute([$id]);
            log_activity('delete', 'agencies', $id, "حذف نمایندگی #{$id}");
            flash('success', 'نمایندگی حذف شد.');
        } else {
            flash('error', 'شناسه نامعتبر است.');
        }
        header('Location: agencies.php?page=' . $page);
        exit;
    }
}

$where = [];
$params = [];

if ($startDate !== '') {
    $startDateSql = jalali_input_to_gregorian_datetime($startDate, false);
    if ($startDateSql) {
        $where[] = 'created_at >= ?';
        $params[] = $startDateSql;
    }
}

if ($endDate !== '') {
    $endDateSql = jalali_input_to_gregorian_datetime($endDate, true);
    if ($endDateSql) {
        $where[] = 'created_at <= ?';
        $params[] = $endDateSql;
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM agencies {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$limit = (int)$perPage;
$offset = (int)$offset;

$stmt = db()->prepare("SELECT id, name, address, phone, created_at FROM agencies {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($exportExcel) {
    $exportStmt = db()->prepare("SELECT id, name, address, phone, created_at FROM agencies {$whereSql} ORDER BY id DESC");
    $exportStmt->execute($params);
    $exportRows = [];
    while ($row = $exportStmt->fetch()) {
        $exportRows[] = [
            '#' . (string)$row['id'],
            (string)$row['name'],
            (string)$row['address'],
            (string)($row['phone'] ?? '-'),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    export_xls_table(
        'Agencies_Report_' . date('Ymd_His') . '.xls',
        ['شناسه', 'نام', 'آدرس', 'شماره تماس', 'تاریخ ایجاد'],
        $exportRows
    );
}

if ($exportPrint) {
    $printStmt = db()->prepare("SELECT id, name, address, phone, created_at FROM agencies {$whereSql} ORDER BY id DESC");
    $printStmt->execute($params);
    $printRows = [];
    while ($row = $printStmt->fetch()) {
        $printRows[] = [
            '#' . (string)$row['id'],
            (string)$row['name'],
            (string)$row['address'],
            (string)($row['phone'] ?? '-'),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    render_print_table_view(
        'گزارش نمایندگی‌ها',
        ['شناسه', 'نام', 'آدرس', 'شماره تماس', 'تاریخ ایجاد'],
        $printRows,
        'فیلتر بازه تاریخ اعمال شده است.'
    );
}

$csrf = csrf_token();

render_page_start('مدیریت نمایندگی‌ها', 'agencies');
?>

<style>
  /* استایل‌های مدرن‌سازی بدون تداخل با layout اصلی */
  .modern-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
  }
  .modern-header h3 {
    margin: 0;
    font-weight: 800;
    color: var(--heading, #1e293b);
  }
  .filter-row th {
    padding: 8px !important;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
  }
  .filter-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    transition: all 0.2s;
    background: #fff;
  }
  .filter-input:focus {
    border-color: var(--primary, #3b82f6);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
  }
  .datepicker-plot-area, .pwt-datepicker, .datepicker-container { z-index: 99999 !important; }
  .agencies-filter-shell { overflow: visible !important; position: relative; }
  .badge-id {
    background: #f1f5f9;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    display: inline-block;
  }
  #agenciesTable th {
    background: #f1f5f9;
    color: #475569;
    font-weight: 700;
    padding: 14px 12px;
    text-align: right;
  }
  #agenciesTable td {
    padding: 14px 12px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
  }
  #agenciesTable tbody tr:hover {
    background-color: #f8fafc;
  }
  .actions-group {
    display: flex;
    gap: 8px;
    align-items: center;
  }
  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
  }
  .pagination {
    display: flex;
    gap: 8px;
    margin-top: 20px;
    justify-content: center;
  }
  .page-link {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: var(--text, #334155);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
  }
  .page-link:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
  }
  .page-link.active {
    background: var(--primary, #3b82f6);
    color: #fff;
    border-color: var(--primary, #3b82f6);
    box-shadow: 0 4px 10px rgba(59, 130, 246, 0.2);
  }
</style>
<style>
  .card {
    border-radius: 18px !important;
    border: 1px solid #dbe4ef !important;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06) !important;
  }
  #agenciesTable {
    border-radius: 16px !important;
    overflow: hidden !important;
  }
  #agenciesTable th,
  #agenciesTable td {
    padding: 16px 18px !important;
    border-bottom: 1px solid #edf2f7 !important;
  }
  #agenciesTable th {
    background: #f8fafc !important;
    color: #475569 !important;
  }
  #agenciesTable tbody tr:hover {
    background-color: #fcfdff !important;
  }
  .filter-input,
  .btn-sm,
  .page-link {
    border-radius: 12px !important;
  }
</style>

<!-- کارت هدر -->
<div class="card">
  <div class="actions agencies-filter-shell" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin-bottom:1.5rem; overflow:visible !important;">
    <form method="get" class="actions" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin:0; overflow:visible !important;">
      <input type="hidden" name="page" value="1">
      <input type="text" class="filter-input js-persian-date" name="start_date" value="<?= e($startDate) ?>" placeholder="از تاریخ..." aria-label="از تاریخ" style="width:200px; max-width:200px;">
      <input type="text" class="filter-input js-persian-date" name="end_date" value="<?= e($endDate) ?>" placeholder="تا تاریخ..." aria-label="تا تاریخ" style="width:200px; max-width:200px;">
      <button type="submit" class="btn btn-primary" style="height:36px;">فیلتر</button>
    </form>
    <a class="btn btn-light" style="color:#2563eb;border-color:#bfdbfe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="agencies.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>">خروجی Excel</a>
    <a class="btn btn-light" style="color:#7c3aed;border-color:#ddd6fe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="agencies.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'print']))) ?>">خروجی PDF</a>
  </div>
  <div class="actions modern-header">
    <h3>لیست نمایندگی‌ها</h3>
    <?php if (can('create')): ?>
      <button class="btn btn-primary" data-modal-open="createAgencyModal">
        <span style="margin-left: 4px; font-weight: bold;">+</span> افزودن نمایندگی
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- کارت جدول -->
<div class="card">
  <div class="table-wrap" style="border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
    <table id="agenciesTable" style="width: 100%; border-collapse: collapse;">
      <thead>
        <tr>
          <th width="5%">#</th>
          <th width="20%">نام</th>
          <th width="30%">آدرس</th>
          <th width="15%">شماره تماس</th>
          <th width="15%">تاریخ ایجاد</th>
          <th width="15%">عملیات</th>
        </tr>
        <tr class="filter-row">
          <th><input type="text" class="filter-input" data-col="0" placeholder="شناسه"></th>
          <th><input type="text" class="filter-input" data-col="1" placeholder="جستجوی نام..."></th>
          <th><input type="text" class="filter-input" data-col="2" placeholder="جستجوی آدرس..."></th>
          <th><input type="text" class="filter-input" data-col="3" placeholder="جستجوی تماس..."></th>
          <th><input type="text" class="filter-input" data-col="4" placeholder="تاریخ..."></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><span class="badge-id"><?= e((string)$row['id']) ?></span></td>
          <td style="font-weight: 600; color: #1e293b;"><?= e($row['name']) ?></td>
          <td style="color: #64748b;"><?= e($row['address']) ?></td>
          <td dir="ltr" style="text-align: right; color: #475569;"><?= e($row['phone'] ?? '-') ?></td>
          <td dir="ltr" style="text-align: right; color: #475569;"><?= e(to_jalali_datetime((string)$row['created_at'])) ?></td>
          <td>
            <div class="actions-group">
              <?php if (can('edit')): ?>
                <button
                  class="btn btn-light btn-sm"
                  data-modal-open="editAgencyModal"
                  data-id="<?= e((string)$row['id']) ?>"
                  data-name="<?= e($row['name']) ?>"
                  data-address="<?= e($row['address']) ?>"
                  data-phone="<?= e($row['phone'] ?? '') ?>"
                  onclick="openEditAgency(this)">ویرایش</button>
              <?php endif; ?>
              <?php if (can('delete')): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('آیا از حذف این نمایندگی اطمینان دارید؟');" style="margin: 0;">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                  <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="agencies.php?<?= e(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (can('create')): ?>
<div class="modal-backdrop" id="createAgencyModal">
  <div class="modal">
    <div class="modal-head">
      <h4 class="modal-title">افزودن نمایندگی</h4>
      <button class="icon-btn" data-modal-close="createAgencyModal">×</button>
    </div>
    <div class="modal-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-4"><label class="field-label">نام نمایندگی</label><input class="input" name="name" placeholder="نام"></div>
        <div class="col-5"><label class="field-label">آدرس</label><input class="input" name="address" placeholder="آدرس دقیق"></div>
        <div class="col-3"><label class="field-label">شماره تماس</label><input class="input" name="phone" placeholder="021..." dir="ltr"></div>
        <div class="col-12 actions" style="margin-top: 15px;"><button class="btn btn-primary" style="width: 100%;">ثبت اطلاعات</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can('edit')): ?>
<div class="modal-backdrop" id="editAgencyModal">
  <div class="modal">
    <div class="modal-head">
      <h4 class="modal-title">ویرایش نمایندگی</h4>
      <button class="icon-btn" data-modal-close="editAgencyModal">×</button>
    </div>
    <div class="modal-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editAgencyId">
        <div class="col-4"><label class="field-label">نام نمایندگی</label><input class="input" id="editAgencyName" name="name"></div>
        <div class="col-5"><label class="field-label">آدرس</label><input class="input" id="editAgencyAddress" name="address"></div>
        <div class="col-3"><label class="field-label">شماره تماس</label><input class="input" id="editAgencyPhone" name="phone" dir="ltr"></div>
        <div class="col-12 actions" style="margin-top: 15px;"><button class="btn btn-primary" style="width: 100%;">ذخیره تغییرات</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  function openEditAgency(btn) {
    document.getElementById('editAgencyId').value = btn.dataset.id || '';
    document.getElementById('editAgencyName').value = btn.dataset.name || '';
    document.getElementById('editAgencyAddress').value = btn.dataset.address || '';
    document.getElementById('editAgencyPhone').value = btn.dataset.phone || '';
  }
  
  (function () {
    const table = document.getElementById('agenciesTable');
    if (!table) return;
    const filters = table.querySelectorAll('.filter-row input[data-col]');
    const rows = Array.from(table.querySelectorAll('tbody tr:not(.filter-row)')); // فقط ردیف‌های دیتا
    
    function applyFilters() {
      rows.forEach((row) => {
        let visible = true;
        filters.forEach((input) => {
          const col = Number(input.dataset.col);
          const text = (row.cells[col]?.innerText || '').toLowerCase();
          const value = input.value.trim().toLowerCase();
          if (value && !text.includes(value)) visible = false;
        });
        row.style.display = visible ? '' : 'none';
      });
    }
    filters.forEach((input) => input.addEventListener('input', applyFilters));
  })();
</script>
<?php render_page_end(); ?>
