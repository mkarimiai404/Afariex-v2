<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/debt_service.php';
require_once __DIR__ . '/receipt_image_helper.php';
require_once __DIR__ . '/money_input_helpers.php';

require_login();
require_permission('view');

$flash = get_flash();

if (!table_exists('transactions')) {
    render_page_start('فیش های واریزی', 'receipts');
    echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;color:#b91c1c">جدول transactions پیدا نشد.</div>';
    render_page_end();
    exit;
}

function receipt_status_class(string $status): string
{
    return match ($status) {
        'pending' => 'status-pending',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'paid' => 'status-paid',
        default => 'status-default',
    };
}

function receipt_image_view_url(array $row): ?string
{
    return receipt_image_public_url($row['receipt_image'] ?? null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('edit');
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);

    $action = $_POST['action'] ?? '';

    if ($action === 'change_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));

        $allowedStatuses = ['pending', 'approved', 'rejected', 'paid'];

        if ($id <= 0) {
            flash('error', 'شناسه نامعتبر است.');
            header('Location: receipts.php');
            exit;
        }

        if (!in_array($status, $allowedStatuses, true)) {
            flash('error', 'وضعیت نامعتبر است.');
            header('Location: receipts.php');
            exit;
        }

        db()->beginTransaction();

        try {
            $transactionColumns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasBalanceApplied = in_array('balance_applied', $transactionColumns, true);
            $selectFields = $hasBalanceApplied ? 'user_id, amount, status, balance_applied' : 'user_id, amount, status';
            $currentStmt = db()->prepare("SELECT {$selectFields} FROM transactions WHERE id = ? AND type = 'deposit' FOR UPDATE");
            $currentStmt->execute([$id]);
            $transaction = $currentStmt->fetch();

            if (!$transaction) {
                db()->rollBack();
                flash('error', 'فیش مورد نظر پیدا نشد.');
                header('Location: receipts.php');
                exit;
            }

            $previousStatus = (string)($transaction['status'] ?? '');
            $userId = (int)($transaction['user_id'] ?? 0);
            $amount = (string)($transaction['amount'] ?? '0');
            $alreadyApplied = $previousStatus === 'approved'
                || ($hasBalanceApplied && (int)($transaction['balance_applied'] ?? 0) === 1);

            $setParts = ['status = ?'];
            $updateValues = [$status];

            if ($status === 'approved' && !$alreadyApplied) {
                $result = debt_apply_user_credit(db(), $userId, $amount, $id, 'deposit_receipt');
                if ($hasBalanceApplied) {
                    $setParts[] = 'balance_applied = 1';
                }
                if (in_array('balance_after', $transactionColumns, true)) {
                    $setParts[] = 'balance_after = ?';
                    $updateValues[] = $result['balance_after'];
                }
            }

            $updateValues[] = $id;
            $updateStmt = db()->prepare('UPDATE transactions SET ' . implode(', ', $setParts) . ' WHERE id = ?');
            $updateStmt->execute($updateValues);

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $e->getMessage() === 'DEBT_SCHEMA_MISSING' ? 'ابتدا migration بدهی کاربران را اجرا کنید.' : 'ذخیره وضعیت فیش انجام نشد.');
            header('Location: receipts.php');
            exit;
        }

        log_activity('update', 'transaction', $id, 'تغییر وضعیت فیش به: ' . $status);

        flash('success', 'وضعیت فیش با موفقیت ذخیره شد.');
        header('Location: receipts.php');
        exit;
    }

    if ($action === 'add_receipt') {
        $username = trim((string)($_POST['username'] ?? ''));
        $amountInput = trim((string)($_POST['amount'] ?? ''));
        $amount = normalize_admin_money_amount($amountInput);
        $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $description = trim((string)($_POST['description'] ?? ''));

        $allowedStatuses = ['pending', 'approved', 'rejected', 'paid'];

        if ($username === '') {
            flash('error', 'لطفاً نام کاربری را وارد کنید.');
            header('Location: receipts.php');
            exit;
        }

        // جستجوی کاربر بر اساس شماره موبایل که حکم نام کاربری را در سیستم دارد
        $stmtUser = db()->prepare("SELECT id FROM users WHERE mobile = ?");
        $stmtUser->execute([$username]);
        $userId = (int)$stmtUser->fetchColumn();

        if ($userId <= 0) {
            flash('error', 'کاربری با این نام کاربری یافت نشد.');
            header('Location: receipts.php');
            exit;
        }

        if ($amount === null) {
            flash('error', 'مبلغ معتبر نیست.');
            header('Location: receipts.php');
            exit;
        }

        if ($trackingCode === '') {
            $trackingCode = (string)random_int(100000, 999999999);
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        if (
            !isset($_FILES['receipt_image']) ||
            !is_array($_FILES['receipt_image']) ||
            ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            flash('error', 'لطفاً عکس فیش را انتخاب کنید.');
            header('Location: receipts.php');
            exit;
        }

        $file = $_FILES['receipt_image'];
        $maxSize = 5 * 1024 * 1024;

        if ((int)$file['size'] > $maxSize) {
            flash('error', 'حجم عکس فیش نباید بیشتر از ۵ مگابایت باشد.');
            header('Location: receipts.php');
            exit;
        }

        $tmpName = (string)$file['tmp_name'];
        $originalName = (string)$file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($extension, $allowedExtensions, true)) {
            flash('error', 'فرمت فایل باید jpg، jpeg، png یا webp باشد.');
            header('Location: receipts.php');
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpName);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($mime, $allowedMimes, true)) {
            flash('error', 'فایل انتخابی تصویر معتبر نیست.');
            header('Location: receipts.php');
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/receipts';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            flash('error', 'امکان ساخت پوشه uploads/receipts وجود ندارد.');
            header('Location: receipts.php');
            exit;
        }

        $fileName = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmpName, $destination)) {
            flash('error', 'آپلود عکس فیش انجام نشد.');
            header('Location: receipts.php');
            exit;
        }

        $receiptImagePath = 'uploads/receipts/' . $fileName;

        db()->beginTransaction();
        try {
            $stmt = db()->prepare("
                INSERT INTO transactions
                    (user_id, amount, tracking_code, type, status, receipt_image, description, created_at)
                VALUES
                    (?, ?, ?, 'deposit', ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $amount,
                $trackingCode,
                $status,
                $receiptImagePath,
                $description,
            ]);

            $newId = (int)db()->lastInsertId();
            if ($status === 'approved') {
                $transactionColumns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
                $result = debt_apply_user_credit(db(), $userId, (string)$amount, $newId, 'admin_receipt');
                $setParts = [];
                $values = [];
                if (in_array('balance_applied', $transactionColumns, true)) {
                    $setParts[] = 'balance_applied = 1';
                }
                if (in_array('balance_after', $transactionColumns, true)) {
                    $setParts[] = 'balance_after = ?';
                    $values[] = $result['balance_after'];
                }
                if ($setParts !== []) {
                    $values[] = $newId;
                    db()->prepare('UPDATE transactions SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($values);
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_file($destination)) {
                @unlink($destination);
            }
            flash('error', $e->getMessage() === 'DEBT_SCHEMA_MISSING' ? 'ابتدا migration بدهی کاربران را اجرا کنید.' : 'ثبت فیش واریزی انجام نشد.');
            header('Location: receipts.php');
            exit;
        }

        log_activity('create', 'transaction', $newId, 'افزودن دستی فیش واریزی');

        flash('success', 'فیش واریزی با موفقیت اضافه شد.');
        header('Location: receipts.php');
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$typeFilter = 'deposit';
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$exportExcel = (string)($_GET['export'] ?? '') === 'excel';
$exportPrint = (string)($_GET['export'] ?? '') === 'print';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

$where[] = "t.type = ?";
$params[] = $typeFilter;

if ($statusFilter !== '') {
    $where[] = "t.status = ?";
    $params[] = $statusFilter;
}

if ($q !== '') {
    $where[] = "(t.tracking_code LIKE ? OR t.description LIKE ? OR u.mobile LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($startDate !== '') {
    $startDateSql = jalali_input_to_gregorian_datetime($startDate, false);
    if ($startDateSql) {
        $where[] = 't.created_at >= ?';
        $params[] = $startDateSql;
    }
}

if ($endDate !== '') {
    $endDateSql = jalali_input_to_gregorian_datetime($endDate, true);
    if ($endDateSql) {
        $where[] = 't.created_at <= ?';
        $params[] = $endDateSql;
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON t.user_id = u.id {$whereSql}");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalItems / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$limit = (int)$perPage;
$offset = (int)$offset;
$listSql = "
    SELECT t.id, t.user_id, t.amount, t.tracking_code, t.type, t.status, t.receipt_image, t.created_at, t.description, u.mobile as user_mobile, u.balance AS user_balance
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    {$whereSql}
    ORDER BY t.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
";
$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$receipts = $listStmt->fetchAll();

$sumStmt = db()->prepare("SELECT COALESCE(SUM(t.amount), 0) FROM transactions t LEFT JOIN users u ON t.user_id = u.id {$whereSql}");
$sumStmt->execute($params);
$totalAmount = (float)$sumStmt->fetchColumn();

if ($exportExcel) {
    $exportStmt = db()->prepare("
        SELECT t.id, t.user_id, t.amount, t.tracking_code, t.type, t.status, t.receipt_image, t.created_at, t.description, u.mobile as user_mobile, u.balance AS user_balance
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        {$whereSql}
        ORDER BY t.created_at DESC
    ");
    $exportStmt->execute($params);
    $exportRows = [];
    while ($row = $exportStmt->fetch()) {
        $exportRows[] = [
            '#' . (string)$row['id'],
            trim((string)($row['receipt_image'] ?? '')) !== '' ? (string)$row['receipt_image'] : 'بدون عکس',
            (string)($row['user_mobile'] ?? 'نامشخص'),
            admin_balance_with_unit($row['user_balance'] ?? null),
            (string)$row['amount'],
            (string)($row['tracking_code'] ?? ''),
            (string)($row['type'] === 'withdraw' ? 'برداشت' : 'واریز'),
            status_fa((string)$row['status']),
            (string)($row['description'] ?? ''),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    export_xls_table(
        'Transactions_Report_' . date('Ymd_His') . '.xls',
        ['شناسه', 'تصویر', 'کاربر (موبایل)', 'موجودی فعلی کاربر', 'مبلغ فیش', 'کد رهگیری', 'نوع', 'وضعیت', 'توضیحات', 'تاریخ ثبت'],
        $exportRows
    );
}

if ($exportPrint) {
    $printStmt = db()->prepare("
        SELECT t.id, t.user_id, t.amount, t.tracking_code, t.type, t.status, t.receipt_image, t.created_at, t.description, u.mobile as user_mobile, u.balance AS user_balance
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        {$whereSql}
        ORDER BY t.created_at DESC
    ");
    $printStmt->execute($params);
    $printRows = [];
    while ($row = $printStmt->fetch()) {
        $printRows[] = [
            '#' . (string)$row['id'],
            trim((string)($row['receipt_image'] ?? '')) !== '' ? 'تصویر موجود' : 'بدون عکس',
            (string)($row['user_mobile'] ?? 'نامشخص'),
            admin_balance_with_unit($row['user_balance'] ?? null),
            (string)$row['amount'],
            (string)($row['tracking_code'] ?? ''),
            (string)($row['type'] === 'withdraw' ? 'برداشت' : 'واریز'),
            status_fa((string)$row['status']),
            (string)($row['description'] ?? ''),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    render_print_table_view(
        'گزارش فیش‌های واریزی',
        ['شناسه', 'تصویر', 'کاربر (موبایل)', 'موجودی فعلی کاربر', 'مبلغ فیش', 'کد رهگیری', 'نوع', 'وضعیت', 'توضیحات', 'تاریخ ثبت'],
        $printRows,
        'فیلتر بازه تاریخ اعمال شده است.'
    );
}

render_page_start('فیش های واریزی', 'receipts');
?>

<style>
    :root {
        --receipt-primary: #2563eb;
        --receipt-primary-soft: #eff6ff;
        --receipt-primary-hover: #1d4ed8;
        --receipt-surface: #ffffff;
        --receipt-page-bg: #f8fafc;
        --receipt-border: #e5e7eb;
        --receipt-border-soft: #eef2f7;
        --receipt-text: #111827;
        --receipt-muted: #64748b;
        --receipt-muted-2: #94a3b8;
        --receipt-success: #16a34a;
        --receipt-danger: #dc2626;
        --receipt-warning: #ea580c;
        --receipt-radius: 14px;
        --receipt-radius-sm: 9px;
        --receipt-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
        --receipt-transition: 180ms ease;
    }

    .receipt-page {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .receipt-top-card {
        background: var(--receipt-surface);
        border: 1px solid var(--receipt-border-soft);
        border-radius: var(--receipt-radius);
        box-shadow: var(--receipt-shadow);
        padding: 18px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .receipt-top-title {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .receipt-top-title h1 {
        margin: 0;
        color: var(--receipt-text);
        font-size: 20px;
        font-weight: 900;
        line-height: 1.6;
    }

    .receipt-top-title p {
        margin: 0;
        color: var(--receipt-muted);
        font-size: 13px;
        font-weight: 600;
    }

    .receipt-add-button {
        border: none;
        outline: none;
        cursor: pointer;
        background: var(--receipt-primary);
        color: #fff;
        height: 42px;
        padding: 0 17px;
        border-radius: 10px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 7px 14px rgba(37, 99, 235, 0.18);
        transition: var(--receipt-transition);
        white-space: nowrap;
    }

    .receipt-add-button:hover {
        background: var(--receipt-primary-hover);
        transform: translateY(-1px);
    }

    .receipt-add-button span {
        font-size: 18px;
        line-height: 1;
        font-weight: 900;
    }

    .receipt-alert {
        border-radius: 12px;
        padding: 13px 15px;
        font-size: 13px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid transparent;
        background: #fff;
    }

    .receipt-alert.success {
        background: #f0fdf4;
        color: #15803d;
        border-color: #bbf7d0;
    }

    .receipt-alert.error {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .receipt-stats-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .receipt-stat-card {
        background: var(--receipt-surface);
        border: 1px solid var(--receipt-border-soft);
        border-radius: var(--receipt-radius);
        box-shadow: var(--receipt-shadow);
        padding: 16px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .receipt-stat-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .receipt-stat-label {
        color: var(--receipt-muted);
        font-size: 12px;
        font-weight: 800;
    }

    .receipt-stat-value {
        color: var(--receipt-text);
        font-size: 21px;
        font-weight: 950;
        direction: ltr;
        text-align: right;
    }

    .receipt-stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: var(--receipt-primary-soft);
        color: var(--receipt-primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 900;
        flex: 0 0 auto;
    }

    .receipt-list-card {
        background: var(--receipt-surface);
        border: 1px solid var(--receipt-border-soft);
        border-radius: var(--receipt-radius);
        box-shadow: var(--receipt-shadow);
        overflow: visible;
    }

    .receipt-list-header {
        padding: 17px 20px;
        border-bottom: 1px solid var(--receipt-border-soft);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
    }

    .receipt-list-header h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 900;
        color: var(--receipt-text);
    }

    .receipt-list-header .receipt-list-count {
        color: var(--receipt-muted);
        background: #f8fafc;
        border: 1px solid var(--receipt-border-soft);
        border-radius: 999px;
        padding: 6px 11px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .receipt-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .receipt-table {
        width: 100%;
        min-width: 1150px;
        border-collapse: collapse;
    }

    .receipt-table th {
        background: #f8fafc;
        color: #475569;
        padding: 13px 14px;
        border-bottom: 1px solid var(--receipt-border);
        text-align: center;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }

    .receipt-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #f1f5f9;
        color: var(--receipt-text);
        text-align: center;
        vertical-align: middle;
        font-size: 13px;
        font-weight: 600;
    }

    .receipt-table tbody tr:hover td {
        background: #fbfdff;
    }

    .receipt-filter-row td {
        background: #fff;
        padding: 10px 12px;
    }

    .receipt-filter-form {
        display: contents;
    }

    .receipt-filter-control {
        width: 100%;
        height: 38px;
        border: 1px solid var(--receipt-border);
        background: #fff;
        border-radius: 9px;
        padding: 0 10px;
        color: var(--receipt-text);
        font-family: inherit;
        font-size: 12px;
        font-weight: 700;
        outline: none;
        transition: var(--receipt-transition);
        box-sizing: border-box;
    }

    .receipt-filter-control:focus {
        border-color: var(--receipt-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
    }
    .datepicker-plot-area, .pwt-datepicker, .datepicker-container { z-index: 99999 !important; }
    .receipt-filter-shell { overflow: visible !important; position: relative; }

    .receipt-filter-actions {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
    }

    .receipt-filter-button,
    .receipt-reset-link {
        height: 38px;
        border-radius: 9px;
        padding: 0 12px;
        font-family: inherit;
        font-size: 12px;
        font-weight: 900;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        white-space: nowrap;
        transition: var(--receipt-transition);
    }

    .receipt-filter-button {
        border: 1px solid var(--receipt-primary);
        background: var(--receipt-primary);
        color: #fff;
    }

    .receipt-filter-button:hover {
        background: var(--receipt-primary-hover);
    }

    .receipt-reset-link {
        border: 1px solid var(--receipt-border);
        background: #f8fafc;
        color: #475569;
    }

    .receipt-reset-link:hover {
        background: #f1f5f9;
        color: #111827;
    }

    .receipt-id {
        font-weight: 900;
        color: #334155;
        direction: ltr;
    }

    .receipt-mobile {
        color: #2563eb;
        font-weight: 900;
        direction: ltr;
        font-size: 12px;
    }

    .receipt-amount {
        color: #0f172a;
        font-weight: 950;
        direction: ltr;
        white-space: nowrap;
    }
    .receipt-current-balance { display:inline-flex;padding:7px 10px;border-radius:10px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:800;white-space:nowrap;direction:rtl; }

    .receipt-code {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 0 9px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid var(--receipt-border-soft);
        color: #334155;
        font-size: 12px;
        font-weight: 800;
        direction: ltr;
        white-space: nowrap;
    }

    .receipt-type {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 0 9px;
        border-radius: 999px;
        background: #f8fafc;
        color: #475569;
        border: 1px solid var(--receipt-border-soft);
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .receipt-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 0 11px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
        border: 1px solid transparent;
    }

    .status-pending {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fed7aa;
    }

    .status-approved {
        background: #f0fdf4;
        color: #15803d;
        border-color: #bbf7d0;
    }

    .status-rejected {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .status-paid {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    .status-default {
        background: #f1f5f9;
        color: #475569;
        border-color: #cbd5e1;
    }

    .receipt-thumb-link {
        width: 52px;
        height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        border: 1px solid var(--receipt-border);
        background: #f8fafc;
        overflow: hidden;
        text-decoration: none;
        cursor: pointer;
    }

    .receipt-thumb-link img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: var(--receipt-transition);
    }

    .receipt-thumb-link:hover img {
        transform: scale(1.08);
    }

    .receipt-no-image {
        width: 96px;
        min-height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        border: 1px dashed #cbd5e1;
        background: #f8fafc;
        color: #94a3b8;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.6;
        padding: 5px;
    }

    .receipt-description {
        max-width: 200px;
        color: #475569;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.8;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin: 0 auto;
    }

    .receipt-date {
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
        direction: ltr;
        white-space: nowrap;
    }

    .receipt-row-actions {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: nowrap;
        white-space: nowrap;
    }
    .receipt-row-actions form {
        margin: 0;
        padding: 0;
        display: inline-block;
    }

    .receipt-view-link {
        height: 34px;
        padding: 0 12px;
        border-radius: 8px;
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #dbeafe;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 12px;
        font-weight: 900;
        transition: var(--receipt-transition);
        white-space: nowrap;
        cursor: pointer;
    }

    .receipt-view-link:hover {
        background: #dbeafe;
    }

    .receipt-view-link.disabled {
        background: #f8fafc;
        color: #94a3b8;
        border-color: #e2e8f0;
        cursor: not-allowed;
        pointer-events: none;
    }

    .receipt-status-form {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin: 0;
        padding: 3px;
        border: 1px solid var(--receipt-border);
        background: #f8fafc;
        border-radius: 9px;
        /* جلوگیری از شکسته شدن فرم */
        white-space: nowrap;
    }

    .receipt-status-select {
        width: 85px;
        height: 30px;
        border: none;
        outline: none;
        background: transparent;
        color: #334155;
        font-family: inherit;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
    }

    .receipt-save-button {
        height: 30px;
        border: 1px solid var(--receipt-border);
        background: #fff;
        color: #334155;
        border-radius: 7px;
        padding: 0 10px;
        font-family: inherit;
        font-size: 12px;
        font-weight: 900;
        cursor: pointer;
        transition: var(--receipt-transition);
        white-space: nowrap;
    }

    .receipt-save-button:hover {
        background: var(--receipt-primary);
        color: #fff;
        border-color: var(--receipt-primary);
    }

    .receipt-empty {
        padding: 38px 16px !important;
        color: var(--receipt-muted);
        font-size: 14px;
        font-weight: 800;
        text-align: center;
    }

    .receipt-pagination {
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border-top: 1px solid var(--receipt-border-soft);
        flex-wrap: wrap;
    }

    .receipt-page-link {
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border-radius: 9px;
        background: #f8fafc;
        color: #475569;
        border: 1px solid var(--receipt-border);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 13px;
        font-weight: 900;
        transition: var(--receipt-transition);
    }

    .receipt-page-link:hover {
        background: #f1f5f9;
        color: #111827;
    }

    .receipt-page-link.active {
        background: var(--receipt-primary);
        color: #fff;
        border-color: var(--receipt-primary);
        box-shadow: 0 5px 12px rgba(37, 99, 235, 0.20);
    }

    .receipt-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px;
        opacity: 0;
        visibility: hidden;
        transition: var(--receipt-transition);
    }

    .receipt-modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .receipt-modal-content {
        width: 100%;
        max-width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
        transform: translateY(14px) scale(0.98);
        transition: var(--receipt-transition);
    }

    .receipt-modal-overlay.active .receipt-modal-content {
        transform: translateY(0) scale(1);
    }

    .receipt-modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid var(--receipt-border-soft);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 2;
    }

    .receipt-modal-header h2 {
        margin: 0;
        color: var(--receipt-text);
        font-size: 17px;
        font-weight: 900;
    }

    .receipt-modal-close {
        width: 34px;
        height: 34px;
        border: none;
        background: #f8fafc;
        color: #64748b;
        border-radius: 9px;
        cursor: pointer;
        font-size: 22px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: var(--receipt-transition);
    }

    .receipt-modal-close:hover {
        background: #fef2f2;
        color: #dc2626;
    }

    /* Styles for the Preview Image Modal */
    #previewImageContainer {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 10px;
    }

    #previewImageContainer img {
        max-width: 100%;
        max-height: 70vh;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .receipt-modal-body {
        padding: 20px;
    }

    .receipt-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px;
    }

    .receipt-field {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .receipt-field-full {
        grid-column: span 2;
    }

    .receipt-field label {
        color: #475569;
        font-size: 12px;
        font-weight: 900;
    }

    .receipt-input,
    .receipt-select,
    .receipt-textarea {
        width: 100%;
        border: 1px solid var(--receipt-border);
        background: #f8fafc;
        border-radius: 10px;
        padding: 0 12px;
        font-family: inherit;
        color: var(--receipt-text);
        font-size: 13px;
        font-weight: 700;
        outline: none;
        transition: var(--receipt-transition);
        box-sizing: border-box;
    }

    .receipt-input,
    .receipt-select {
        height: 42px;
    }

    .receipt-textarea {
        min-height: 92px;
        resize: vertical;
        padding-top: 10px;
        line-height: 1.8;
    }

    .receipt-input:focus,
    .receipt-select:focus,
    .receipt-textarea:focus {
        background: #fff;
        border-color: var(--receipt-primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
    }

    .receipt-modal-actions {
        grid-column: span 2;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 6px;
    }

    .receipt-submit-button {
        border: none;
        background: var(--receipt-primary);
        color: #fff;
        height: 42px;
        border-radius: 10px;
        padding: 0 18px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 900;
        cursor: pointer;
        transition: var(--receipt-transition);
    }

    .receipt-submit-button:hover {
        background: var(--receipt-primary-hover);
    }

    .receipt-cancel-button {
        border: 1px solid var(--receipt-border);
        background: #f8fafc;
        color: #475569;
        height: 42px;
        border-radius: 10px;
        padding: 0 18px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 900;
        cursor: pointer;
        transition: var(--receipt-transition);
    }

    .receipt-cancel-button:hover {
        background: #f1f5f9;
        color: #111827;
    }

    @media (max-width: 900px) {
        .receipt-stats-row {
            grid-template-columns: 1fr;
        }

        .receipt-top-card {
            align-items: stretch;
            flex-direction: column;
        }

        .receipt-add-button {
            width: 100%;
        }
    }

    @media (max-width: 640px) {
        .receipt-form-grid {
            grid-template-columns: 1fr;
        }

        .receipt-field-full,
        .receipt-modal-actions {
            grid-column: span 1;
        }

        .receipt-modal-actions {
            flex-direction: column-reverse;
        }

        .receipt-submit-button,
        .receipt-cancel-button {
            width: 100%;
        }
    }
</style>
<style>
    .receipt-top-card,
    .receipt-stat-card,
    .receipt-list-card,
    .receipt-modal-content {
        border-radius: 18px !important;
        border: 1px solid #dbe4ef !important;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06) !important;
    }
    .receipt-list-card {
        overflow: visible !important;
    }
    .receipt-table {
        border-radius: 16px !important;
        overflow: hidden !important;
    }
    .receipt-table th,
    .receipt-table td {
        padding: 16px 18px !important;
        border-bottom: 1px solid #edf2f7 !important;
    }
    .receipt-table th {
        background: #f8fafc !important;
        color: #475569 !important;
    }
    .receipt-table tbody tr:hover td {
        background: #fcfdff !important;
    }
    .receipt-add-button,
    .receipt-filter-button,
    .receipt-save-button,
    .receipt-submit-button,
    .receipt-print,
    .receipt-view-link,
    .receipt-reset-link,
    .receipt-cancel-button {
        border-radius: 12px !important;
    }
    .receipt-add-button,
    .receipt-filter-button,
    .receipt-save-button,
    .receipt-submit-button,
    .receipt-print {
        background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%) !important;
        box-shadow: 0 12px 24px rgba(59, 130, 246, 0.18) !important;
    }
    .receipt-view-link,
    .receipt-reset-link {
        background: #ffffff !important;
        border: 1px solid #dbe4ef !important;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
    .receipt-input,
    .receipt-select,
    .receipt-textarea,
    .receipt-filter-control {
        border-radius: 12px !important;
        border-color: #dbe4ef !important;
        background: #fff !important;
    }
</style>

<div class="receipt-page">

    <?php if ($flash): ?>
        <div class="receipt-alert <?= ($flash['type'] ?? '') === 'error' ? 'error' : 'success' ?>">
            <?= ($flash['type'] ?? '') === 'error' ? '⚠️' : '✅' ?>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="receipt-top-card">
        <div class="receipt-top-title">
            <h1>مدیریت فیش‌ها</h1>
            <p>مشاهده، فیلتر و مدیریت وضعیت فیش‌های واریزی کاربران</p>
        </div>

        <?php if (can('edit')): ?>
            <button type="button" class="receipt-add-button" onclick="openModal('addModal')">
                <span>+</span>
                افزودن فیش جدید
            </button>
        <?php endif; ?>
    </div>

    <div class="receipt-stats-row">
        <div class="receipt-stat-card">
            <div class="receipt-stat-info">
                <div class="receipt-stat-label">تعداد نتایج</div>
                <div class="receipt-stat-value"><?= number_format($totalItems) ?></div>
            </div>
            <div class="receipt-stat-icon">#</div>
        </div>

        <div class="receipt-stat-card">
            <div class="receipt-stat-info">
                <div class="receipt-stat-label">جمع مبلغ</div>
                <div class="receipt-stat-value"><?= number_format($totalAmount) ?> <span style="font-size:14px;color:#64748b;">تومان</span></div>
            </div>
            <div class="receipt-stat-icon">تومان</div>
        </div>
    </div>

    <?php if (can('edit')): ?>
        <div id="addModal" class="receipt-modal-overlay">
            <div class="receipt-modal-content">
                <div class="receipt-modal-header">
                    <h2>افزودن دستی فیش واریزی</h2>
                    <button type="button" class="receipt-modal-close" onclick="closeModal('addModal')">&times;</button>
                </div>

                <div class="receipt-modal-body">
                    <form method="post" enctype="multipart/form-data" class="receipt-form-grid">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_receipt">

                        <div class="receipt-field">
                            <label for="add_username">شماره تماس ثبت شده در آفاریکس</label>
                            <input
                                type="text"
                                id="add_username"
                                name="username"
                                class="receipt-input"
                                required
                                placeholder="مثلاً 09123456789"
                                dir="ltr"
                            >
                        </div>

                        <div class="receipt-field">
                            <label for="add_amount">مبلغ</label>
                            <input
                                type="text"
                                inputmode="numeric"
                                id="add_amount"
                                name="amount"
                                class="receipt-input"
                                data-money-input
                                data-money-words-target="addAmountWords"
                                required
                                placeholder="مثلاً 1000000"
                                dir="ltr"
                            >
                            <div class="money-amount-words" id="addAmountWords"></div>
                        </div>

                        <div class="receipt-field">
                            <label for="add_tracking_code">کد رهگیری</label>
                            <input
                                type="text"
                                id="add_tracking_code"
                                name="tracking_code"
                                class="receipt-input"
                                placeholder="در صورت خالی بودن خودکار ساخته می‌شود"
                                dir="ltr"
                            >
                        </div>

                        <div class="receipt-field">
                            <label for="add_status">وضعیت</label>
                            <select id="add_status" name="status" class="receipt-select">
                                <option value="pending">در انتظار</option>
                                <option value="approved">تایید شده</option>
                                <option value="rejected">رد شده</option>
                                <option value="paid">پرداخت شده</option>
                            </select>
                        </div>

                        <div class="receipt-field receipt-field-full">
                            <label for="add_receipt_image">عکس فیش</label>
                            <input
                                type="file"
                                id="add_receipt_image"
                                name="receipt_image"
                                class="receipt-input"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                required
                            >
                        </div>

                        <div class="receipt-field receipt-field-full">
                            <label for="add_description">توضیحات</label>
                            <textarea
                                id="add_description"
                                name="description"
                                class="receipt-textarea"
                                placeholder="توضیحات اختیاری"
                            ></textarea>
                        </div>

                        <div class="receipt-modal-actions">
                            <button type="button" class="receipt-cancel-button" onclick="closeModal('addModal')">انصراف</button>
                            <button type="submit" class="receipt-submit-button">ثبت فیش</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal for Image Preview -->
    <div id="imagePreviewModal" class="receipt-modal-overlay">
        <div class="receipt-modal-content">
            <div class="receipt-modal-header">
                <h2>مشاهده فیش واریزی</h2>
                <button type="button" class="receipt-modal-close" onclick="closeModal('imagePreviewModal')">&times;</button>
            </div>
            <div class="receipt-modal-body">
                <div id="previewImageContainer">
                    <img id="previewImageElement" src="" alt="فیش واریزی">
                </div>
            </div>
            <div class="receipt-modal-header" style="border-top: 1px solid var(--receipt-border-soft); border-bottom: none; justify-content: flex-end; padding: 10px 20px;">
                 <button type="button" class="receipt-cancel-button" style="height: 34px;" onclick="closeModal('imagePreviewModal')">بستن</button>
            </div>
        </div>
    </div>

    <div class="receipt-list-card">
        <div class="receipt-list-header">
            <h2>لیست فیش‌ها</h2>
            <div class="receipt-list-count"><?= number_format($totalItems) ?> مورد</div>
        </div>

        <div class="actions receipt-filter-shell" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin:0 24px 1.5rem; overflow:visible !important;">
            <form method="get" class="actions" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin:0; overflow:visible !important;">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                <input type="hidden" name="page" value="1">
                <input type="text" name="start_date" class="receipt-filter-control js-persian-date" value="<?= e($startDate) ?>" placeholder="از تاریخ..." aria-label="از تاریخ" style="width:200px; max-width:200px;">
                <input type="text" name="end_date" class="receipt-filter-control js-persian-date" value="<?= e($endDate) ?>" placeholder="تا تاریخ..." aria-label="تا تاریخ" style="width:200px; max-width:200px;">
                <button type="submit" class="receipt-filter-button">فیلتر</button>
            </form>
            <a href="receipts.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="receipt-filter-button" style="text-decoration:none; display:inline-flex; align-items:center;">خروجی Excel</a>
            <a href="receipts.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'print']))) ?>" class="receipt-filter-button" style="text-decoration:none; display:inline-flex; align-items:center; background:#7c3aed; border-color:#7c3aed;">خروجی PDF</a>
        </div>

        <div class="receipt-table-wrap">
            <table class="receipt-table" id="receiptsTable">
                <thead>
                    <tr>
                        <th style="width:70px;">شناسه</th>
                        <th style="width:90px;">تصویر</th>
                        <th>کاربر (موبایل)</th>
                        <th>موجودی فعلی کاربر</th>
                        <th>مبلغ</th>
                        <th>کد رهگیری</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>توضیحات</th>
                        <th>تاریخ ثبت</th>
                        <th style="width:230px;">عملیات</th>
                    </tr>

                    <tr class="receipt-filter-row">
                        <form method="get" class="receipt-filter-form">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>

                            <td colspan="2">
                                <input
                                    type="text"
                                    name="q"
                                    class="receipt-filter-control"
                                    value="<?= e($q) ?>"
                                    placeholder="جستجو در کد، توضیحات یا موبایل..."
                                >
                            </td>

                            <td>
                                <select name="type" class="receipt-filter-control">
                                    <option value="deposit" <?= $typeFilter === 'deposit' ? 'selected' : '' ?>>deposit</option>
                                </select>
                            </td>

                            <td>
                                <select name="status" class="receipt-filter-control">
                                    <option value="">همه وضعیت‌ها</option>
                                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>تایید شده</option>
                                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>پرداخت شده</option>
                                </select>
                            </td>

                            <td></td>
                            <td></td>

                            <td>
                                <div class="receipt-filter-actions">
                                    <button type="submit" class="receipt-filter-button">فیلتر</button>
                                    <a href="receipts.php" class="receipt-reset-link">حذف</a>
                                </div>
                            </td>
                        </form>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$receipts): ?>
                        <tr>
                            <td colspan="11" class="receipt-empty">
                                هیچ فیشی برای نمایش وجود ندارد.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $row): ?>
                            <?php
                                $imageUrl = receipt_image_view_url($row);

                                $statusText = match ((string)$row['status']) {
                                    'pending' => 'در انتظار',
                                    'approved' => 'تایید شده',
                                    'rejected' => 'رد شده',
                                    'paid' => 'پرداخت شده',
                                    default => (string)$row['status'],
                                };

                                $typeText = match ((string)$row['type']) {
                                    'deposit' => 'واریز',
                                    'withdraw' => 'برداشت',
                                    default => (string)$row['type'],
                                };
                            ?>

                            <tr>
                                <td>
                                    <span class="receipt-id">#<?= (int)$row['id'] ?></span>
                                </td>

                                <td>
                                    <?php if ($imageUrl): ?>
                                        <div onclick="previewImage('<?= e($imageUrl) ?>')" class="receipt-thumb-link">
                                            <img src="<?= e($imageUrl) ?>" alt="receipt">
                                        </div>
                                    <?php else: ?>
                                        <span class="receipt-no-image">تصویر فیش موجود نیست</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="receipt-mobile"><?= e((string)($row['user_mobile'] ?? 'نامشخص')) ?></span>
                                </td>

                                <td><span class="receipt-current-balance"><?= e(admin_balance_with_unit($row['user_balance'] ?? null)) ?></span></td>

                                <td>
                                    <span class="receipt-amount">
                                        <?= number_format((float)$row['amount']) ?> تومان
                                    </span>
                                </td>

                                <td>
                                    <?php if (trim((string)$row['tracking_code']) !== ''): ?>
                                        <span class="receipt-code"><?= e((string)$row['tracking_code']) ?></span>
                                    <?php else: ?>
                                        <span class="receipt-code">---</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="receipt-type"><?= e($typeText) ?></span>
                                </td>

                                <td>
                                    <span class="receipt-badge <?= e(receipt_status_class((string)$row['status'])) ?>">
                                        <?= e($statusText) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (trim((string)$row['description']) !== ''): ?>
                                        <div class="receipt-description" title="<?= e((string)$row['description']) ?>">
                                            <?= e((string)$row['description']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-weight:800;">---</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="receipt-date">
                                        <?= e((string)$row['created_at']) ?>
                                    </span>
                                </td>

                                <td class="text-nowrap">
                                    <div class="d-flex flex-row align-items-center justify-content-center" style="gap: 8px; flex-wrap: nowrap;">
                                        <?php if ($imageUrl): ?>
                                            <button type="button" onclick="previewImage('<?= e($imageUrl) ?>')" class="receipt-view-link btn-sm">
                                                مشاهده
                                            </button>
                                        <?php else: ?>
                                            <span class="receipt-view-link disabled">تصویر فیش موجود نیست</span>
                                        <?php endif; ?>

                                        <a class="receipt-view-link btn-sm" href="view_transaction_receipt.php?id=<?= e((string)$row['id']) ?>" target="_blank" rel="noopener" style="text-decoration:none;">
                                            نمایش رسید
                                        </a>

                                        <?php if (can('edit')): ?>
                                            <form method="post" class="receipt-status-form m-0 p-0" style="display: inline-block;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

                                                <select name="status" class="receipt-status-select">
                                                    <option value="pending" <?= (string)$row['status'] === 'pending' ? 'selected' : '' ?>>انتظار</option>
                                                    <option value="approved" <?= (string)$row['status'] === 'approved' ? 'selected' : '' ?>>تایید</option>
                                                    <option value="rejected" <?= (string)$row['status'] === 'rejected' ? 'selected' : '' ?>>رد</option>
                                                </select>

                                                <button type="submit" class="receipt-save-button btn-sm">ذخیره</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="receipt-pagination">
                <?php
                    $queryBase = $_GET;
                ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php
                        $queryBase['page'] = $i;
                        $pageUrl = 'receipts.php?' . http_build_query($queryBase);
                    ?>
                    <a href="<?= e($pageUrl) ?>" class="receipt-page-link <?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
    function openModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
        }
    }

    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
        }
    }

    function previewImage(url) {
        var modal = document.getElementById('imagePreviewModal');
        var img = document.getElementById('previewImageElement');
        if (modal && img) {
            img.src = url;
            modal.classList.add('active');
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('addModal');
            closeModal('imagePreviewModal');
        }
    });

    document.addEventListener('click', function (event) {
        var addModal = document.getElementById('addModal');
        var previewModal = document.getElementById('imagePreviewModal');
        if (addModal && event.target === addModal) {
            closeModal('addModal');
        }
        if (previewModal && event.target === previewModal) {
            closeModal('imagePreviewModal');
        }
    });
</script>

<?php render_money_input_helpers(); ?>
<?php render_page_end(); ?>
