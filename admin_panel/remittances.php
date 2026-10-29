<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/service_fee_service.php';
require_once __DIR__ . '/money_input_helpers.php';
require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';

require_login();
require_permission('view');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$exportExcel = (string)($_GET['export'] ?? '') === 'excel';
$exportPrint = (string)($_GET['export'] ?? '') === 'print';
$destinationCountries = remittance_destination_countries();

// دریافت نرخ فعال تبدیل افغانی به تومان
$activeRateRow = db()->query('SELECT rate FROM exchange_rates WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
$activeRate = $activeRateRow ? (float)$activeRateRow['rate'] : 0;

// دریافت لیست نمایندگی‌ها
$agencies = db()->query('SELECT name FROM agencies ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $mobile = trim($_POST['mobile'] ?? '');
        $agency = trim($_POST['agency'] ?? '');
        $destinationCountry = strtoupper(trim((string)($_POST['destination_country'] ?? '')));
        $sender = trim($_POST['sender'] ?? '');
        $receiver = trim($_POST['receiver'] ?? '');
        $amountToman = trim((string)($_POST['amount_toman'] ?? ''));
        $amountAfghani = trim((string)($_POST['amount_afghani'] ?? ''));
        $isValidRemittanceAmount = static function (string $value): bool {
            return preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1 && (float)$value > 0;
        };
        $status = trim($_POST['status'] ?? 'pending');
        $allowedStatuses = ['pending', 'approved', 'rejected', 'paid'];

        // پیدا کردن user_id از روی شماره موبایل
        $stmtUser = db()->prepare('SELECT id FROM users WHERE mobile = ?');
        $stmtUser->execute([$mobile]);
        $userId = (int)$stmtUser->fetchColumn();

        if ($userId <= 0) {
            flash('error', 'کاربری با این شماره موبایل یافت نشد.');
            header('Location: remittances.php?page=' . $page);
            exit;
        }

        if ($action === 'create') {
            require_permission('create');
            $createNonce = trim((string)($_POST['create_nonce'] ?? ''));
            if ($createNonce === '' || !hash_equals((string)($_SESSION['remittance_create_nonce'] ?? ''), $createNonce)) {
                flash('error', 'Invalid or already processed remittance request.');
                header('Location: remittances.php?page=' . $page);
                exit;
            }
            if ($agency === '' || !remittance_destination_country_is_supported($destinationCountry) || $sender === '' || $receiver === '' || !$isValidRemittanceAmount($amountToman) || !$isValidRemittanceAmount($amountAfghani) || $status === '') {
                flash('error', 'لطفاً تمام فیلدهای ضروری را وارد کنید.');
            } else {
                if (!in_array($status, $allowedStatuses, true)) {
                    flash('error', 'وضعیت نامعتبر است.');
                } else {
                    db()->beginTransaction();

                    try {
                        $userStmt = db()->prepare('SELECT id, balance FROM users WHERE mobile = ? FOR UPDATE');
                        $userStmt->execute([$mobile]);
                        $userRow = $userStmt->fetch();

                        if (!$userRow) {
                            db()->rollBack();
                            flash('error', 'کاربری با این شماره موبایل یافت نشد.');
                            header('Location: remittances.php?page=' . $page);
                            exit;
                        }

                        $currentBalance = (float)($userRow['balance'] ?? 0);

                        $fee = service_fee_calculate('remittance', (int)$amountToman, db());
                        $totalDeduction = (string)$fee['total_amount'];
                        $shouldChargeWallet = $status !== 'rejected';
                        if ($shouldChargeWallet) {
                            $sufficientStmt = db()->prepare('SELECT id FROM users WHERE id = ? AND balance >= ?');
                            $sufficientStmt->execute([$userId, $totalDeduction]);
                            if (!$sufficientStmt->fetchColumn()) {
                                db()->rollBack();
                                flash('error', 'موجودی کاربر برای مبلغ حواله و کارمزد کافی نیست.');
                                header('Location: remittances.php?page=' . $page);
                                exit;
                            }

                            $deductStmt = db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
                            $deductStmt->execute([$totalDeduction, $userId]);
                        }

                        $stmt = db()->prepare('INSERT INTO remittances (user_id, agency, destination_country, sender, receiver, amount_toman, amount_afghani, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                        $stmt->execute([$userId, $agency, $destinationCountry, $sender, $receiver, $amountToman, $amountAfghani, $status]);
                        $remittanceId = (int)db()->lastInsertId();
                        if ($shouldChargeWallet) {
                            $feeStatus = in_array($status, ['approved', 'paid'], true) ? 'collected' : 'pending';
                            service_fee_record(db(), $userId, $fee, 'remittance', $remittanceId, $feeStatus);
                        }

                        if ($shouldChargeWallet) {
                            $transactionStmt = db()->prepare("
                                INSERT INTO transactions
                                    (user_id, amount, type, status, balance_applied, description)
                                VALUES (?, ?, 'remittance', 'approved', 1, ?)
                            ");
                            $transactionStmt->execute([$userId, $totalDeduction, "رسید حواله #{$remittanceId}"]);
                        }

                        log_activity('create', 'remittances', $remittanceId, "ایجاد حواله برای کاربر {$mobile}");
                        db()->commit();
                        unset($_SESSION['remittance_create_nonce']);
                        flash('success', 'حواله با موفقیت ثبت شد.');
                    } catch (Throwable $e) {
                        if (db()->inTransaction()) {
                            db()->rollBack();
                        }
                        throw $e;
                    }
                }
            }
        } elseif ($action === 'update') {
            require_permission('edit');
            $id = (int)($_POST['id'] ?? 0);
            $destinationCountry = strtoupper(trim((string)($_POST['destination_country'] ?? '')));
            if ($id <= 0 || $agency === '' || ($destinationCountry !== '' && !remittance_destination_country_is_supported($destinationCountry)) || $sender === '' || $receiver === '' || !$isValidRemittanceAmount($amountToman) || !$isValidRemittanceAmount($amountAfghani) || $status === '') {
                flash('error', 'اطلاعات ویرایش نامعتبر است.');
            } else {
                if (!in_array($status, $allowedStatuses, true)) {
                    flash('error', 'وضعیت نامعتبر است.');
                } else {
                    db()->beginTransaction();

                    try {
                        $remittanceStmt = db()->prepare('SELECT user_id, amount_toman, status, destination_country FROM remittances WHERE id = ? FOR UPDATE');
                        $remittanceStmt->execute([$id]);
                        $existingRemittance = $remittanceStmt->fetch();

                        if (!$existingRemittance) {
                            db()->rollBack();
                            flash('error', 'اطلاعات ویرایش نامعتبر است.');
                            header('Location: remittances.php?page=' . $page);
                            exit;
                        }

                        $currentStatus = (string)($existingRemittance['status'] ?? '');
                        $remittanceAmount = (float)($existingRemittance['amount_toman'] ?? 0);
                        $ownerUserId = (int)($existingRemittance['user_id'] ?? 0);

                        $destinationCountry = $destinationCountry !== '' ? $destinationCountry : (string)($existingRemittance['destination_country'] ?? '');
                        $stmt = db()->prepare('UPDATE remittances SET user_id = ?, agency = ?, destination_country = ?, sender = ?, receiver = ?, amount_toman = ?, amount_afghani = ?, status = ? WHERE id = ?');
                        $stmt->execute([$userId, $agency, $destinationCountry !== '' ? $destinationCountry : null, $sender, $receiver, $amountToman, $amountAfghani, $status, $id]);

                        if ($status === 'rejected' && $currentStatus !== 'rejected' && $ownerUserId > 0) {
                            $userStmt = db()->prepare('SELECT id, balance FROM users WHERE id = ? FOR UPDATE');
                            $userStmt->execute([$ownerUserId]);
                            $userRow = $userStmt->fetch();

                            if (!$userRow) {
                                db()->rollBack();
                                flash('error', 'کاربر مربوط به این حواله یافت نشد.');
                                header('Location: remittances.php?page=' . $page);
                                exit;
                            }

                            $feeRow = service_fee_for_source('remittance', $id, db());
                            $feeAmount = $feeRow ? (int)$feeRow['fee_amount'] : 0;
                            $refundAmount = $remittanceAmount + $feeAmount;
                            $refundStmt = db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
                            $refundStmt->execute([$refundAmount, $ownerUserId]);
                            service_fee_update_status(db(), 'remittance', $id, 'refunded');

                            $transactionStmt = db()->prepare("
                                INSERT INTO transactions
                                    (user_id, amount, type, status, balance_applied, description)
                                VALUES (?, ?, 'refund', 'approved', 1, 'برگشت وجه بابت رد حواله')
                            ");
                            $transactionStmt->execute([$ownerUserId, $remittanceAmount]);
                        } elseif ($status === 'approved' && $currentStatus === 'rejected' && $ownerUserId > 0) {
                            $userStmt = db()->prepare('SELECT id, balance FROM users WHERE id = ? FOR UPDATE');
                            $userStmt->execute([$ownerUserId]);
                            $userRow = $userStmt->fetch();

                            if (!$userRow) {
                                db()->rollBack();
                                flash('error', 'کاربر مربوط به این حواله یافت نشد.');
                                header('Location: remittances.php?page=' . $page);
                                exit;
                            }

                            $feeRow = service_fee_for_source('remittance', $id, db());
                            $calculatedFee = $feeRow ? null : service_fee_calculate('remittance', (int)$remittanceAmount, db());
                            $feeAmount = $feeRow ? (int)$feeRow['fee_amount'] : (int)$calculatedFee['fee_amount'];
                            $totalDeduction = $remittanceAmount + $feeAmount;
                            $userBalance = (float)($userRow['balance'] ?? 0);

                            if ($userBalance < $totalDeduction) {
                                db()->rollBack();
                                flash('error', 'موجودی کاربر برای تایید مجدد حواله و کارمزد کافی نیست');
                                header('Location: remittances.php?page=' . $page);
                                exit;
                            }

                            $deductStmt = db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
                            $deductStmt->execute([$totalDeduction, $ownerUserId]);
                            if ($feeRow) {
                                service_fee_update_status(db(), 'remittance', $id, 'collected');
                            } elseif (is_array($calculatedFee)) {
                                service_fee_record(db(), $ownerUserId, $calculatedFee, 'remittance', $id, 'collected');
                            }

                            $transactionStmt = db()->prepare("
                                INSERT INTO transactions
                                    (user_id, amount, type, status, balance_applied, description)
                                VALUES (?, ?, 'remittance', 'approved', 1, 'کسر مجدد وجه بابت تایید حواله')
                            ");
                            $transactionStmt->execute([$ownerUserId, $remittanceAmount]);
                        }

                        if (in_array($status, ['approved', 'paid'], true)) {
                            service_fee_update_status(db(), 'remittance', $id, 'collected');
                        } elseif (in_array($status, ['cancelled', 'canceled'], true)) {
                            service_fee_update_status(db(), 'remittance', $id, 'cancelled');
                        }

                        log_activity('update', 'remittances', $id, "ویرایش حواله #{$id}");
                        db()->commit();
                        flash('success', 'حواله با موفقیت ویرایش شد.');
                    } catch (Throwable $e) {
                        if (db()->inTransaction()) {
                            db()->rollBack();
                        }
                        throw $e;
                    }
                }
            }
        }
        header('Location: remittances.php?page=' . $page);
        exit;
    }

    if ($action === 'delete') {
        require_permission('delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM remittances WHERE id = ?');
            $stmt->execute([$id]);
            log_activity('delete', 'remittances', $id, "حذف حواله #{$id}");
            flash('success', 'حواله حذف شد.');
        } else {
            flash('error', 'شناسه نامعتبر است.');
        }
        header('Location: remittances.php?page=' . $page);
        exit;
    }
}

$where = [];
$params = [];

if ($startDate !== '') {
    $startDateSql = jalali_input_to_gregorian_datetime($startDate, false);
    if ($startDateSql) {
        $where[] = 'r.created_at >= ?';
        $params[] = $startDateSql;
    }
}

if ($endDate !== '') {
    $endDateSql = jalali_input_to_gregorian_datetime($endDate, true);
    if ($endDateSql) {
        $where[] = 'r.created_at <= ?';
        $params[] = $endDateSql;
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM remittances r LEFT JOIN users u ON r.user_id = u.id {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$limit = (int)$perPage;
$offset = (int)$offset;

$stmtSql = "
    SELECT r.id, r.user_id, u.mobile, u.balance AS user_balance, r.agency, r.destination_country, r.sender, r.receiver, r.amount_toman, r.amount_afghani, r.status, r.created_at
    FROM remittances r
    LEFT JOIN users u ON r.user_id = u.id
    {$whereSql}
    ORDER BY r.id DESC 
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = db()->prepare($stmtSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($exportExcel) {
    $exportStmt = db()->prepare("
        SELECT r.id, r.user_id, u.mobile, u.balance AS user_balance, r.agency, r.destination_country, r.sender, r.receiver, r.amount_toman, r.amount_afghani, r.status, r.created_at
        FROM remittances r
        LEFT JOIN users u ON r.user_id = u.id
        {$whereSql}
        ORDER BY r.id DESC
    ");
    $exportStmt->execute($params);
    $exportRows = [];
    while ($row = $exportStmt->fetch()) {
        $exportRows[] = [
            '#' . (string)$row['id'],
            (string)($row['mobile'] ?? 'نامشخص'),
            admin_balance_with_unit($row['user_balance'] ?? null),
            (string)$row['agency'],
            $destinationCountries[(string)($row['destination_country'] ?? '')] ?? '',
            (string)$row['sender'],
            (string)$row['receiver'],
            number_format((float)$row['amount_toman']),
            number_format((float)$row['amount_afghani']),
            status_fa((string)$row['status']),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    export_xls_table(
        'Remittances_Report_' . date('Ymd_His') . '.xls',
        ['شناسه', 'موبایل کاربر', 'موجودی فعلی کاربر', 'نمایندگی', 'کشور مقصد', 'فرستنده', 'گیرنده', 'مبلغ حواله (تومان)', 'مبلغ حواله (افغانی)', 'وضعیت', 'تاریخ'],
        $exportRows
    );
}

if ($exportPrint) {
    $printStmt = db()->prepare("
        SELECT r.id, r.user_id, u.mobile, u.balance AS user_balance, r.agency, r.destination_country, r.sender, r.receiver, r.amount_toman, r.amount_afghani, r.status, r.created_at
        FROM remittances r
        LEFT JOIN users u ON r.user_id = u.id
        {$whereSql}
        ORDER BY r.id DESC
    ");
    $printStmt->execute($params);
    $printRows = [];
    while ($row = $printStmt->fetch()) {
        $printRows[] = [
            '#' . (string)$row['id'],
            (string)($row['mobile'] ?? 'نامشخص'),
            admin_balance_with_unit($row['user_balance'] ?? null),
            (string)$row['agency'],
            $destinationCountries[(string)($row['destination_country'] ?? '')] ?? '',
            (string)$row['sender'],
            (string)$row['receiver'],
            number_format((float)$row['amount_toman']),
            number_format((float)$row['amount_afghani']),
            status_fa((string)$row['status']),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    render_print_table_view(
        'گزارش حواله‌ها',
        ['شناسه', 'موبایل کاربر', 'موجودی فعلی کاربر', 'نمایندگی', 'کشور مقصد', 'فرستنده', 'گیرنده', 'مبلغ حواله (تومان)', 'مبلغ حواله (افغانی)', 'وضعیت', 'تاریخ'],
        $printRows,
        'فیلتر بازه تاریخ اعمال شده است.'
    );
}

$csrf = csrf_token();
if (empty($_SESSION['remittance_create_nonce'])) {
    $_SESSION['remittance_create_nonce'] = bin2hex(random_bytes(16));
}
$remittanceCreateNonce = (string)$_SESSION['remittance_create_nonce'];

function rm_status_class(string $status): string {
    return match ($status) {
        'pending' => 'status-pending',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'paid' => 'status-paid',
        default => 'status-default',
    };
}

render_page_start('مدیریت حواله‌ها', 'remittances');
?>

<style>
    /* استایل‌های قبلی شما با کمی بهبود */
    :root {
        --rm-primary: #3b82f6; --rm-primary-hover: #2563eb; --rm-danger: #ef4444; --rm-danger-hover: #dc2626;
        --rm-surface: #ffffff; --rm-bg: #f8fafc; --rm-border: #e2e8f0; --rm-text: #0f172a; --rm-muted: #64748b;
        --rm-radius: 16px; --rm-shadow: 0 4px 20px rgba(15, 23, 42, 0.04); --rm-transition: all 0.3s ease;
    }
    .rm-container { display: flex; flex-direction: column; gap: 24px; font-family: inherit; }
    .rm-actions-header { display: flex; justify-content: space-between; align-items: center; background: var(--rm-surface); padding: 20px 24px; border-radius: var(--rm-radius); box-shadow: var(--rm-shadow); border: 1px solid var(--rm-border); }
    .rm-actions-header h3 { margin: 0; font-size: 20px; font-weight: 800; color: var(--rm-text); }
    .btn-toggle-add { background: var(--rm-primary); color: #fff; border: none; padding: 12px 20px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 700; transition: var(--rm-transition); display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }
    .btn-toggle-add:hover { background: var(--rm-primary-hover); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3); }
    .rm-panel { background: var(--rm-surface); border: 1px solid var(--rm-border); border-radius: var(--rm-radius); padding: 24px; box-shadow: var(--rm-shadow); }
    .rm-table-container { overflow-x: auto; margin: 0 -24px; padding: 0 24px; }
    .rm-table { width: 100%; min-width: 1000px; border-collapse: separate; border-spacing: 0; }
    .rm-table th { background: var(--rm-bg); color: #475569; font-size: 13px; font-weight: 800; padding: 16px; text-align: right; border-bottom: 2px solid var(--rm-border); white-space: nowrap; }
    .rm-table td { padding: 16px; border-bottom: 1px solid var(--rm-border); font-size: 14px; color: var(--rm-text); vertical-align: middle; transition: background 0.2s; }
    .rm-table tbody tr:hover td { background: #f8fafc; }
    .filter-row th { padding: 8px 16px; background: #f1f5f9; border-bottom: 2px solid var(--rm-border); }
    .rm-filter-input { width: 100%; height: 36px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 12px; outline: none; transition: var(--rm-transition); background: #fff; }
    .rm-filter-input:focus { border-color: var(--rm-primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
    .datepicker-plot-area, .pwt-datepicker, .datepicker-container { z-index: 99999 !important; }
    .rm-filter-shell { overflow: visible !important; position: relative; }
    .rm-badge { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 99px; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .status-pending { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .status-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .status-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .status-paid { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .status-default { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .rm-status-form { display: inline-flex; align-items: center; justify-content: center; gap: 6px; margin: 0; padding: 3px; border: 1px solid #dbe4ef; background: #f8fafc; border-radius: 9px; white-space: nowrap; }
    .rm-status-select { width: 92px; height: 30px; border: none; outline: none; background: transparent; color: #334155; font-family: inherit; font-size: 12px; font-weight: 800; cursor: pointer; padding: 0 4px; }
    .rm-status-save { height: 30px; padding: 0 10px; border: none; border-radius: 8px; background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%); color: #fff; font-size: 12px; font-weight: 800; cursor: pointer; white-space: nowrap; box-shadow: 0 8px 18px rgba(59, 130, 246, 0.18); }
    .rm-status-save:hover { filter: brightness(1.02); }
    .rm-actions { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: nowrap; white-space: nowrap; }
    .rm-actions form { margin: 0; display: inline-flex; }
    .btn-edit { background: #eff6ff; color: #2563eb; border: none; height: 32px; padding: 0 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: var(--rm-transition); display: inline-flex; align-items: center; white-space: nowrap; }
    .btn-edit:hover { background: #dbeafe; }
    .btn-delete { background: #fef2f2; color: var(--rm-danger); border: none; height: 32px; padding: 0 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: var(--rm-transition); display: inline-flex; align-items: center; white-space: nowrap; }
    .btn-delete:hover { background: #fee2e2; color: var(--rm-danger-hover); }
    .btn-receipt { background: #ecfdf5; color: #047857; border: none; height: 32px; padding: 0 10px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; transition: var(--rm-transition); white-space: nowrap; }
    .btn-receipt:hover { background: #d1fae5; }
    .rm-pagination { display: flex; justify-content: center; gap: 6px; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--rm-border); }
    .page-link { min-width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; background: #f1f5f9; color: #475569; text-decoration: none; font-size: 14px; font-weight: 700; transition: var(--rm-transition); }
    .page-link:hover { background: #e2e8f0; color: #0f172a; }
    .page-link.active { background: var(--rm-primary); color: #fff; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }
    .rm-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: var(--rm-transition); padding: 20px; }
    .rm-modal-overlay.active { opacity: 1; visibility: visible; }
    .rm-modal-content { background: var(--rm-surface); border-radius: var(--rm-radius); width: 100%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: translateY(20px) scale(0.95); transition: var(--rm-transition); }
    .rm-modal-overlay.active .rm-modal-content { transform: translateY(0) scale(1); }
    .rm-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--rm-border); position: sticky; top: 0; background: var(--rm-surface); z-index: 10; }
    .rm-modal-header h4 { margin: 0; font-size: 18px; font-weight: 800; color: var(--rm-text); }
    .btn-close-modal { background: transparent; border: none; font-size: 24px; color: var(--rm-muted); cursor: pointer; transition: color 0.2s; line-height: 1; }
    .btn-close-modal:hover { color: var(--rm-danger); }
    .rm-modal-body { padding: 24px; }
    .rm-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
    .rm-field { display: flex; flex-direction: column; gap: 8px; }
    .rm-field label { font-size: 13px; font-weight: 700; color: #475569; }
    .rm-input, .rm-select { width: 100%; height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 14px; font-size: 14px; font-family: inherit; background: var(--rm-bg); transition: var(--rm-transition); outline: none; box-sizing: border-box; }
    .rm-input:focus, .rm-select:focus { background: #fff; border-color: var(--rm-primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
    .rm-field-full { grid-column: 1 / -1; }
    .btn-submit { background: var(--rm-primary); color: #fff; border: none; border-radius: 10px; height: 44px; padding: 0 24px; font-size: 14px; font-weight: 700; cursor: pointer; transition: var(--rm-transition); }
    .btn-submit:hover { background: var(--rm-primary-hover); }
    .persian-amount { font-size: 12px; color: var(--rm-primary); font-weight: 700; min-height: 18px; }
    .rm-current-balance { display:inline-flex;padding:7px 10px;border-radius:10px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:800;white-space:nowrap;direction:rtl; }
    .rm-balance-review { margin-bottom:16px;padding:15px 17px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:800;text-align:right; }
    @media (max-width: 840px) { .rm-current-balance { width:100%;box-sizing:border-box;justify-content:center; } }
    .active-rate-info { font-size: 13px; color: var(--rm-muted); background: #f1f5f9; padding: 10px; border-radius: 8px; text-align: center; font-weight: bold; margin-bottom: 10px;}
</style>
<style>
    .rm-actions-header,
    .rm-panel,
    .rm-modal-content {
        border-radius: 18px !important;
        border: 1px solid #dbe4ef !important;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06) !important;
    }
    .rm-table-container {
        border-radius: 16px !important;
        border: 1px solid #dbe4ef !important;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05) !important;
        overflow: hidden !important;
    }
    .rm-table th,
    .rm-table td {
        padding: 16px 18px !important;
        border-bottom: 1px solid #edf2f7 !important;
    }
    .rm-table th {
        background: #f8fafc !important;
        color: #475569 !important;
    }
    .rm-table tbody tr:hover td {
        background: #fcfdff !important;
    }
    .btn-toggle-add,
    .btn-edit,
    .btn-delete,
    .btn-receipt,
    .btn-submit,
    .btn-close-modal,
    .btn-close-modal:hover {
        border-radius: 12px !important;
    }
    .btn-toggle-add,
    .btn-submit {
        background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%) !important;
        box-shadow: 0 12px 24px rgba(59, 130, 246, 0.18) !important;
    }
    .btn-edit,
    .btn-receipt {
        background: #ffffff !important;
        border: 1px solid #dbe4ef !important;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
    .btn-delete {
        background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%) !important;
        box-shadow: 0 12px 24px rgba(239, 68, 68, 0.18) !important;
    }
    .btn-toggle-add:hover,
    .btn-edit:hover,
    .btn-delete:hover,
    .btn-receipt:hover,
    .btn-submit:hover {
        transform: translateY(-1px) !important;
    }
    .rm-filter-input,
    .rm-input,
    .rm-select {
        border-radius: 12px !important;
        border-color: #dbe4ef !important;
        background: #fff !important;
    }
    .page-link {
        border-radius: 10px !important;
        border: 1px solid #dbe4ef !important;
        background: #fff !important;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
</style>

<div class="rm-container">
    
    <div class="rm-actions-header">
        <h3>لیست حواله‌ها</h3>
        <?php if (can('create')): ?>
            <button class="btn-toggle-add" onclick="openModal('createRemittanceModal')">
                <span>+</span> افزودن حواله
            </button>
        <?php endif; ?>
    </div>

    <div class="rm-panel">
        <div class="actions rm-filter-shell" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin-bottom:1.5rem; overflow:visible !important;">
            <form method="get" class="actions" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin:0; overflow:visible !important;">
                <input type="hidden" name="page" value="1">
                <input type="text" class="rm-filter-input js-persian-date" name="start_date" value="<?= e($startDate) ?>" placeholder="از تاریخ..." aria-label="از تاریخ" style="width:200px; max-width:200px;">
                <input type="text" class="rm-filter-input js-persian-date" name="end_date" value="<?= e($endDate) ?>" placeholder="تا تاریخ..." aria-label="تا تاریخ" style="width:200px; max-width:200px;">
                <button type="submit" class="btn-submit" style="height: 36px;">فیلتر تاریخ</button>
            </form>
            <a class="btn btn-light" style="color:#2563eb;border-color:#bfdbfe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="remittances.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>">خروجی Excel</a>
            <a class="btn btn-light" style="color:#7c3aed;border-color:#ddd6fe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="remittances.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'print']))) ?>">خروجی PDF</a>
        </div>
        <div class="rm-table-container">
            <table class="rm-table" id="remittancesTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>موبایل کاربر</th>
                        <th>موجودی فعلی کاربر</th>
                        <th>نمایندگی</th>
                        <th>کشور مقصد</th>
                        <th>فرستنده</th>
                        <th>گیرنده</th>
                        <th>مبلغ (تومان)</th>
                        <th>مبلغ (افغانی)</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                    <tr class="filter-row">
                        <th><input class="rm-filter-input" data-col="0" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="1" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="2" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="3" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="4" placeholder="جستجوی کشور"></th>
                        <th><input class="rm-filter-input" data-col="5" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="6" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="7" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="8" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="9" placeholder="جستجو"></th>
                        <th><input class="rm-filter-input" data-col="10" placeholder="جستجو"></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="12" style="text-align: center; color: var(--rm-muted); padding: 32px;">هیچ حواله‌ای یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong><?= e((string)$row['id']) ?></strong></td>
                                <td dir="ltr" style="text-align: right;"><?= e((string)($row['mobile'] ?? 'نامشخص')) ?></td>
                                <td><span class="rm-current-balance"><?= e(admin_balance_with_unit($row['user_balance'] ?? null)) ?></span></td>
                                <td><?= e($row['agency']) ?></td>
                                <td><?= e($destinationCountries[(string)($row['destination_country'] ?? '')] ?? '—') ?></td>
                                <td><?= e($row['sender']) ?></td>
                                <td><?= e($row['receiver']) ?></td>
                                <td><strong><?= number_format((float)$row['amount_toman']) ?></strong></td>
                                <td><strong><?= number_format((float)$row['amount_afghani']) ?></strong></td>
                                <td>
                                    <form method="post" class="rm-status-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                        <input type="hidden" name="mobile" value="<?= e((string)($row['mobile'] ?? '')) ?>">
                                        <input type="hidden" name="agency" value="<?= e((string)$row['agency']) ?>">
                                        <input type="hidden" name="destination_country" value="<?= e((string)($row['destination_country'] ?? '')) ?>">
                                        <input type="hidden" name="sender" value="<?= e((string)$row['sender']) ?>">
                                        <input type="hidden" name="receiver" value="<?= e((string)$row['receiver']) ?>">
                                        <input type="hidden" name="amount_toman" value="<?= e((string)$row['amount_toman']) ?>">
                                        <input type="hidden" name="amount_afghani" value="<?= e((string)$row['amount_afghani']) ?>">
                                        <select name="status" class="rm-status-select">
                                            <option value="pending" <?= (string)$row['status'] === 'pending' ? 'selected' : '' ?>>انتظار</option>
                                            <option value="approved" <?= (string)$row['status'] === 'approved' ? 'selected' : '' ?>>تایید</option>
                                            <option value="rejected" <?= (string)$row['status'] === 'rejected' ? 'selected' : '' ?>>رد</option>
                                        </select>
                                        <button type="submit" class="rm-status-save">ذخیره</button>
                                    </form>
                                </td>
                                <td dir="ltr" style="text-align: right;"><?= e(to_jalali_datetime((string)$row['created_at'])) ?></td>
                                <td class="text-nowrap" style="white-space: nowrap;">
                                    <div class="d-flex flex-row justify-content-center align-items-center" style="gap: 8px; flex-wrap: nowrap;">
                                        <?php if (can('edit')): ?>
                                            <button class="btn-edit btn-sm" 
                                                    data-id="<?= e((string)$row['id']) ?>" 
                                                    data-mobile="<?= e((string)$row['mobile']) ?>" 
                                                    data-user_balance="<?= e(admin_balance_with_unit($row['user_balance'] ?? null)) ?>"
                                                    data-agency="<?= e($row['agency']) ?>"
                                                    data-destination_country="<?= e((string)($row['destination_country'] ?? '')) ?>"
                                                    data-sender="<?= e($row['sender']) ?>" 
                                                    data-receiver="<?= e($row['receiver']) ?>"
                                                    data-amount_toman="<?= e((string)$row['amount_toman']) ?>" 
                                                    data-amount_afghani="<?= e((string)$row['amount_afghani']) ?>"
                                                    data-status="<?= e($row['status']) ?>" 
                                                    onclick="prepareEditModal(this)">ویرایش</button>
                                        <?php endif; ?>
                                        <a class="btn-receipt btn-sm" href="view_receipt.php?id=<?= e((string)$row['id']) ?>" target="_blank" rel="noopener" title="نمایش رسید">نمایش رسید</a>
                                        
                                        <?php if (can('delete')): ?>
                                            <form method="post" class="m-0 p-0" style="display: inline-block; margin:0;" onsubmit="return confirm('آیا از حذف این حواله اطمینان دارید؟');">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                                <button class="btn-delete btn-sm" type="submit">حذف</button>
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
            <div class="rm-pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="remittances.php?<?= e(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- مدال افزودن حواله -->
<?php if (can('create')): ?>
<div class="rm-modal-overlay" id="createRemittanceModal">
    <div class="rm-modal-content">
        <div class="rm-modal-header">
            <h4>افزودن حواله جدید</h4>
            <button class="btn-close-modal" onclick="closeModal('createRemittanceModal')">&times;</button>
        </div>
        <div class="rm-modal-body">
            <div class="active-rate-info">
                نرخ ارز فعال (افغانی به تومان): <?= number_format($activeRate, 2) ?>
            </div>
            <form method="post" class="rm-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="create_nonce" value="<?= e($remittanceCreateNonce) ?>">
                
                <div class="rm-field">
                    <label>شماره موبایل کاربر</label>
                    <input class="rm-input" type="text" dir="ltr" name="mobile" required>
                </div>
                <div class="rm-field">
                    <label>نمایندگی</label>
                    <select class="rm-select" name="agency" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach($agencies as $agc): ?>
                            <option value="<?= e($agc) ?>"><?= e($agc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rm-field">
                    <label>کشور مقصد</label>
                    <select class="rm-select" name="destination_country" required>
                        <option value="">انتخاب کشور...</option>
                        <?php foreach ($destinationCountries as $countryCode => $countryName): ?>
                            <option value="<?= e($countryCode) ?>"><?= e($countryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rm-field">
                    <label>وضعیت</label>
                    <select class="rm-select" name="status">
                        <option value="pending">در انتظار</option>
                        <option value="approved">تایید شده</option>
                        <option value="rejected">رد شده</option>
                        <option value="paid">پرداخت شده</option>
                    </select>
                </div>
                <div class="rm-field"></div> <!-- برای تعادل در گرید -->

                <div class="rm-field">
                    <label>فرستنده</label>
                    <input class="rm-input" name="sender" required>
                </div>
                <div class="rm-field">
                    <label>گیرنده</label>
                    <input class="rm-input" name="receiver" required>
                </div>
                
                <div class="rm-field">
                    <label>مبلغ (تومان)</label>
                    <input class="rm-input amount-toman" type="text" inputmode="numeric" dir="ltr" name="amount_toman" data-money-input required>
                    <div class="persian-amount toman-word"></div>
                </div>
                <div class="rm-field">
                    <label>مبلغ (افغانی)</label>
                    <input class="rm-input amount-afghani" type="text" inputmode="decimal" dir="ltr" name="amount_afghani" data-money-decimal data-money-unit="افغانی" data-money-words-target="createRemittanceAfghaniWords" required>
                    <div class="money-amount-words persian-amount" id="createRemittanceAfghaniWords"></div>
                </div>
                
                <div class="rm-field rm-field-full" style="margin-top: 16px; align-items: flex-end;">
                    <button type="submit" class="btn-submit">ثبت حواله</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- مدال ویرایش حواله -->
<?php if (can('edit')): ?>
<div class="rm-modal-overlay" id="editRemittanceModal">
    <div class="rm-modal-content">
        <div class="rm-modal-header">
            <h4>ویرایش حواله</h4>
            <button class="btn-close-modal" onclick="closeModal('editRemittanceModal')">&times;</button>
        </div>
        <div class="rm-modal-body">
            <div id="editRemittanceBalance" class="rm-balance-review">موجودی فعلی کاربر: —</div>
             <div class="active-rate-info">
                نرخ ارز فعال (افغانی به تومان): <?= number_format($activeRate, 2) ?>
            </div>
            <form method="post" class="rm-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editRemittanceId">
                
                <div class="rm-field">
                    <label>شماره موبایل کاربر</label>
                    <input class="rm-input" type="text" dir="ltr" id="editRemittanceMobile" name="mobile" required>
                </div>
                <div class="rm-field">
                    <label>نمایندگی</label>
                    <select class="rm-select" id="editRemittanceAgency" name="agency" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach($agencies as $agc): ?>
                            <option value="<?= e($agc) ?>"><?= e($agc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rm-field">
                    <label>کشور مقصد</label>
                    <select class="rm-select" id="editRemittanceCountry" name="destination_country">
                        <option value="">بدون تغییر / حواله قدیمی</option>
                        <?php foreach ($destinationCountries as $countryCode => $countryName): ?>
                            <option value="<?= e($countryCode) ?>"><?= e($countryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rm-field">
                    <label>وضعیت</label>
                    <select class="rm-select" id="editRemittanceStatus" name="status">
                        <option value="pending">در انتظار</option>
                        <option value="approved">تایید شده</option>
                        <option value="rejected">رد شده</option>
                        <option value="paid">پرداخت شده</option>
                    </select>
                </div>
                <div class="rm-field"></div> <!-- برای تعادل در گرید -->

                <div class="rm-field">
                    <label>فرستنده</label>
                    <input class="rm-input" id="editRemittanceSender" name="sender" required>
                </div>
                <div class="rm-field">
                    <label>گیرنده</label>
                    <input class="rm-input" id="editRemittanceReceiver" name="receiver" required>
                </div>
                <div class="rm-field">
                    <label>مبلغ (تومان)</label>
                    <input class="rm-input amount-toman" type="text" inputmode="numeric" dir="ltr" id="editRemittanceToman" name="amount_toman" data-money-input required>
                    <div class="persian-amount toman-word"></div>
                </div>
                <div class="rm-field">
                    <label>مبلغ (افغانی)</label>
                    <input class="rm-input amount-afghani" type="text" inputmode="decimal" dir="ltr" id="editRemittanceAfghani" name="amount_afghani" data-money-decimal data-money-unit="افغانی" data-money-words-target="editRemittanceAfghaniWords" required>
                    <div class="money-amount-words persian-amount" id="editRemittanceAfghaniWords"></div>
                </div>
                
                <div class="rm-field rm-field-full" style="margin-top: 16px; align-items: flex-end;">
                    <button type="submit" class="btn-submit">ذخیره تغییرات</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- اسکریپت تبدیل عدد به حروف -->
<script src="https://cdn.jsdelivr.net/gh/saeedalipoor/numero@1.0.1/dist/numero.min.js"></script>

<script>
    const ACTIVE_RATE = <?= $activeRate ?>;

    // توابع تبدیل ارز
    document.querySelectorAll('.amount-toman').forEach(input => {
        input.addEventListener('input', function() {
            const cleanToman = window.AfariexMoneyInputs ? window.AfariexMoneyInputs.clean(this.value) : String(this.value).replace(/[,\s٬]/g, '');
            const valToman = parseFloat(cleanToman) || 0;
            // پیدا کردن فیلد افغانی در همان فرم
            const afgInput = this.closest('form').querySelector('.amount-afghani');
            if(ACTIVE_RATE > 0 && afgInput) {
                afgInput.value = (valToman / ACTIVE_RATE).toFixed(2);
                if (window.AfariexMoneyInputs) window.AfariexMoneyInputs.refresh(afgInput);
            }
            
            // نمایش به حروف
            const wordDiv = this.parentElement.querySelector('.toman-word');
            if(wordDiv) {
                wordDiv.innerText = window.AfariexMoneyInputs ? window.AfariexMoneyInputs.words(this.value) : (typeof Numero !== 'undefined' && valToman > 0 ? Numero.numberToWords(valToman) + ' تومان' : '');
            }
        });
    });

    document.querySelectorAll('.amount-afghani').forEach(input => {
        input.addEventListener('input', function() {
            const cleanAfg = window.AfariexMoneyInputs ? window.AfariexMoneyInputs.cleanDecimal(this.value) : String(this.value).replace(/,/g, '');
            if (!/^\d+(?:\.\d+)?$/.test(cleanAfg)) return;
            const valAfg = parseFloat(cleanAfg);
            // پیدا کردن فیلد تومان در همان فرم
            const tomanInput = this.closest('form').querySelector('.amount-toman');
            if(ACTIVE_RATE > 0 && tomanInput) {
                const computedToman = Math.round(valAfg * ACTIVE_RATE);
                tomanInput.value = window.AfariexMoneyInputs ? window.AfariexMoneyInputs.format(computedToman) : computedToman;
                
                // بروزرسانی حروف
                const wordDiv = tomanInput.parentElement.querySelector('.toman-word');
                if(wordDiv) {
                    wordDiv.innerText = window.AfariexMoneyInputs ? window.AfariexMoneyInputs.words(computedToman) : (typeof Numero !== 'undefined' && computedToman > 0 ? Numero.numberToWords(computedToman) + ' تومان' : '');
                }
            }
        });
    });

    // مدیریت مدال‌ها
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('rm-modal-overlay')) {
            event.target.classList.remove('active');
        }
    }

    function prepareEditModal(btn) {
        document.getElementById('editRemittanceId').value = btn.dataset.id || '';
        document.getElementById('editRemittanceMobile').value = btn.dataset.mobile || '';
        document.getElementById('editRemittanceBalance').textContent = 'موجودی فعلی کاربر: ' + (btn.dataset.user_balance || '—');
        document.getElementById('editRemittanceAgency').value = btn.dataset.agency || '';
        document.getElementById('editRemittanceCountry').value = btn.dataset.destination_country || '';
        document.getElementById('editRemittanceSender').value = btn.dataset.sender || '';
        document.getElementById('editRemittanceReceiver').value = btn.dataset.receiver || '';
        document.getElementById('editRemittanceToman').value = btn.dataset.amount_toman || '';
        document.getElementById('editRemittanceAfghani').value = btn.dataset.amount_afghani || '';
        document.getElementById('editRemittanceStatus').value = btn.dataset.status || 'pending';
        
        // تریگر کردن ایونت برای نمایش عدد به حروف هنگام لود ویرایش
        const event = new Event('input');
        document.getElementById('editRemittanceToman').dispatchEvent(event);
        if (window.AfariexMoneyInputs) window.AfariexMoneyInputs.refresh(document.getElementById('editRemittanceAfghani'));

        openModal('editRemittanceModal');
    }

    // فیلتر جدول
    (function () {
        const table = document.getElementById('remittancesTable');
        if (!table) return;
        const filters = table.querySelectorAll('.filter-row input[data-col]');
        const rows = Array.from(table.querySelectorAll('tbody tr:not(.filter-row)'));
        
        function applyFilters() {
            rows.forEach((row) => {
                if(row.cells.length === 1) return; 
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

<?php render_money_input_helpers(); ?>
<?php render_page_end(); ?>
