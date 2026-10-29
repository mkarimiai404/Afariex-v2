<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/debt_service.php';
require_once __DIR__ . '/service_fee_service.php';
require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';
$moneyInputHelpersPath = __DIR__ . '/money_input_helpers.php';
if (is_file($moneyInputHelpersPath)) {
    require_once $moneyInputHelpersPath;
}
require_login();
require_permission('view');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$exportExcel = (string)($_GET['export'] ?? '') === 'excel';
$exportPrint = (string)($_GET['export'] ?? '') === 'print';
ensure_verification_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_operation') {
        require_permission('edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        $manualAction = trim((string)($_POST['manual_action'] ?? ''));
        $amountInput = trim((string)($_POST['amount'] ?? ''));
        $agency = trim((string)($_POST['agency'] ?? ''));
        $destinationCountry = strtoupper(trim((string)($_POST['destination_country'] ?? '')));
        $sender = trim((string)($_POST['sender'] ?? ''));
        $receiver = trim((string)($_POST['receiver'] ?? ''));
        $amountAfghaniInput = trim((string)($_POST['amount_afghani'] ?? ''));

        $allowedActions = ['increase', 'decrease', 'remittance'];

        $isValidAmount = static function (string $value): bool {
            return preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1 && (float)$value > 0;
        };

        if ($userId <= 0 || !in_array($manualAction, $allowedActions, true) || !$isValidAmount($amountInput)) {
            flash('error', 'عملیات یا مبلغ وارد شده نامعتبر است.');
            header('Location: users.php?page=' . $page);
            exit;
        }

        if ($manualAction === 'remittance' && ($agency === '' || !remittance_destination_country_is_supported($destinationCountry) || $sender === '' || $receiver === '' || !$isValidAmount($amountAfghaniInput))) {
            flash('error', 'برای ثبت حواله، فیلدهای نمایندگی، فرستنده و گیرنده اجباری هستند.');
            header('Location: users.php?page=' . $page);
            exit;
        }

        $operationNonce = trim((string)($_POST['operation_nonce'] ?? ''));
        if ($operationNonce === '' || !hash_equals((string)($_SESSION['manual_operation_nonce'] ?? ''), $operationNonce)) {
            flash('error', 'Invalid or already processed operation.');
            header('Location: users.php?page=' . $page);
            exit;
        }

        db()->beginTransaction();
        try {
            $userStmt = db()->prepare('SELECT id, mobile, balance FROM users WHERE id = ? FOR UPDATE');
            $userStmt->execute([$userId]);
            $userRow = $userStmt->fetch();

            if (!$userRow) {
                db()->rollBack();
                flash('error', 'کاربر مورد نظر یافت نشد.');
                header('Location: users.php?page=' . $page);
                exit;
            }

            $userBalance = (string)($userRow['balance'] ?? '0');
            $mobile = (string)($userRow['mobile'] ?? '');
            $verification = verification_state($userId, true);

            if ($manualAction === 'remittance') {
                $usedToday = daily_transaction_usage($userId, true);
                if ((float)$amountInput > max(0, (float)$verification['daily_limit'] - $usedToday)) {
                    db()->rollBack();
                    flash('error', 'سقف روزانه حواله این کاربر تکمیل شده است.');
                    header('Location: users.php?page=' . $page);
                    exit;
                }
            }
            if ($manualAction === 'remittance' && $verification['custom_remittance_limit'] !== null && (float)$amountInput > max(0, (float)$verification['custom_remittance_limit'] - daily_remittance_usage($userId))) {
                db()->rollBack();
                flash('error', 'سقف اختصاصی انتقال وجه این کاربر تکمیل شده است.');
                header('Location: users.php?page=' . $page);
                exit;
            }

            if ($manualAction === 'decrease' && (float)$amountInput > (float)$verification['withdrawal_limit']) {
                db()->rollBack();
                flash('error', 'سقف برداشت سطح فعلی کاربر ' . number_format((float)$verification['withdrawal_limit']) . ' تومان است.');
                header('Location: users.php?page=' . $page);
                exit;
            }

            $manualFee = null;
            $manualTotalDeduction = $amountInput;
            if ($manualAction === 'remittance') {
                $manualFee = service_fee_calculate('remittance', (int)$amountInput, db());
                $manualTotalDeduction = (string)$manualFee['total_amount'];
            }

            $sufficientBalance = true;
            if (in_array($manualAction, ['decrease', 'remittance'], true)) {
                $sufficientStmt = db()->prepare('SELECT id FROM users WHERE id = ? AND balance >= ?');
                $sufficientStmt->execute([$userId, $manualAction === 'remittance' ? $manualTotalDeduction : $amountInput]);
                $sufficientBalance = (bool)$sufficientStmt->fetchColumn();
            }
            if (!$sufficientBalance) {
                db()->rollBack();
                flash('error', 'موجودی کاربر برای این برداشت کافی نیست.');
                header('Location: users.php?page=' . $page);
                exit;
            }

            $creditResult = null;
            if ($manualAction === 'increase') {
                $creditResult = debt_apply_user_credit(db(), $userId, $amountInput, null, 'admin_manual_increase');
                $transactionType = 'deposit';
            } elseif ($manualAction === 'decrease') {
                $updateStmt = db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
                $updateStmt->execute([$amountInput, $userId, $amountInput]);
                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Insufficient wallet balance.');
                }
                $transactionType = 'withdrawal';
            } else {
                $updateStmt = db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
                $updateStmt->execute([$manualTotalDeduction, $userId, $manualTotalDeduction]);
                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Insufficient wallet balance.');
                }
                $transactionType = 'remittance';
            }

            $balanceStmt = db()->prepare('SELECT balance FROM users WHERE id = ?');
            $balanceStmt->execute([$userId]);
            $balanceAfter = (string)$balanceStmt->fetchColumn();

            $manualRemittanceId = null;
            if ($manualAction === 'remittance') {
                $remittanceColumns = db()->query('SHOW COLUMNS FROM remittances')
                    ->fetchAll(PDO::FETCH_COLUMN, 0);

                $remittanceData = [
                    'user_id' => $userId,
                    'agency' => $agency,
                    'destination_country' => $destinationCountry,
                    'sender' => $sender,
                    'receiver' => $receiver,
                    'amount_toman' => $amountInput,
                    'status' => 'approved',
                ];

                if (in_array('amount_afghani', $remittanceColumns, true)) {
                    $remittanceData['amount_afghani'] = $amountAfghaniInput;
                }
                if (in_array('created_at', $remittanceColumns, true)) {
                    $remittanceData['created_at'] = date('Y-m-d H:i:s');
                }

                if (!in_array('user_id', $remittanceColumns, true) || !in_array('amount_toman', $remittanceColumns, true) || !in_array('destination_country', $remittanceColumns, true)) {
                    db()->rollBack();
                    flash('error', 'ساختار جدول حواله‌ها با این عملیات سازگار نیست.');
                    header('Location: users.php?page=' . $page);
                    exit;
                }

                $remittanceFields = array_keys($remittanceData);
                $remittancePlaceholders = array_fill(0, count($remittanceFields), '?');
                $remittanceSql = 'INSERT INTO remittances (' . implode(', ', $remittanceFields) . ') VALUES (' . implode(', ', $remittancePlaceholders) . ')';
                $remittanceStmt = db()->prepare($remittanceSql);
                $remittanceStmt->execute(array_values($remittanceData));
                $manualRemittanceId = (int)db()->lastInsertId();
                if (is_array($manualFee)) {
                    service_fee_record(db(), $userId, $manualFee, 'remittance', $manualRemittanceId, 'collected');
                }
            }

            $transactionColumns = db()->query('SHOW COLUMNS FROM transactions')
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $transactionData = [
                'user_id' => $userId,
                'amount' => $manualAction === 'remittance' ? $manualTotalDeduction : $amountInput,
                'type' => $transactionType,
                'status' => 'approved',
            ];
            if (in_array('description', $transactionColumns, true)) {
                $transactionData['description'] = $manualAction === 'remittance'
                    ? "Remittance {$agency} / {$sender} / {$receiver}"
                    : ($manualAction === 'increase' && is_array($creditResult) && (float)$creditResult['debt_settled_amount'] > 0
                        ? 'Manual operation increase; debt settled ' . number_format((float)$creditResult['debt_settled_amount'], 2, '.', '')
                        : "Manual operation {$manualAction}");
            }

            if (in_array('balance_applied', $transactionColumns, true)) {
                $transactionData['balance_applied'] = 1;
            }
            if (in_array('created_at', $transactionColumns, true)) {
                $transactionData['created_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('balance_before', $transactionColumns, true)) {
                $transactionData['balance_before'] = $userBalance;
            }
            if (in_array('balance_after', $transactionColumns, true)) {
                $transactionData['balance_after'] = $balanceAfter;
            }
            if (in_array('operator_id', $transactionColumns, true)) {
                $transactionData['operator_id'] = (int)($_SESSION['admin_id'] ?? 0);
            } elseif (in_array('admin_id', $transactionColumns, true)) {
                $transactionData['admin_id'] = (int)($_SESSION['admin_id'] ?? 0);
            }

            if (!in_array('user_id', $transactionColumns, true) || !in_array('amount', $transactionColumns, true) || !in_array('type', $transactionColumns, true) || !in_array('status', $transactionColumns, true)) {
                db()->rollBack();
                flash('error', 'ساختار جدول تراکنش‌ها با این عملیات سازگار نیست.');
                header('Location: users.php?page=' . $page);
                exit;
            }

            $transactionFields = array_keys($transactionData);
            $transactionPlaceholders = array_fill(0, count($transactionFields), '?');
            $transactionSql = 'INSERT INTO transactions (' . implode(', ', $transactionFields) . ') VALUES (' . implode(', ', $transactionPlaceholders) . ')';
            $transactionStmt = db()->prepare($transactionSql);
            $transactionStmt->execute(array_values($transactionData));
            $manualTransactionId = (int)db()->lastInsertId();
            if ($manualAction === 'increase' && is_array($creditResult) && !empty($creditResult['settlements'])) {
                $paymentIds = array_values(array_filter(array_map(
                    static fn (array $settlement): int => (int)($settlement['payment_id'] ?? 0),
                    $creditResult['settlements']
                )));
                if ($paymentIds !== []) {
                    $placeholders = implode(', ', array_fill(0, count($paymentIds), '?'));
                    $paymentUpdate = db()->prepare("UPDATE user_debt_payments SET source_transaction_id = ? WHERE id IN ({$placeholders}) AND user_id = ? AND source_transaction_id IS NULL");
                    $paymentUpdate->execute(array_merge([$manualTransactionId], $paymentIds, [$userId]));
                }
            }

            log_activity(
                'manual_operation',
                'users',
                $userId,
                "عملیات دستی {$manualAction} برای کاربر {$mobile} به مبلغ {$amountInput}"
            );
            db()->commit();
            unset($_SESSION['manual_operation_nonce']);
            flash('success', 'عملیات دستی با موفقیت انجام شد.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $e->getMessage() === 'Insufficient wallet balance.'
                ? 'Insufficient wallet balance.'
                : ($e->getMessage() === 'DEBT_SCHEMA_MISSING'
                    ? 'ابتدا migration بدهی کاربران را اجرا کنید.'
                    : ($e->getMessage() === 'SERVICE_FEE_SCHEMA_MISSING'
                        ? 'ابتدا migration کارمزد خدمات را اجرا کنید.'
                    : 'Operation failed; all changes were rolled back.')));
        }

        header('Location: users.php?page=' . $page);
        exit;
    }

    if ($action === 'create') {
        require_permission('create');
        $mobile = trim($_POST['mobile'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $pinCode = trim($_POST['pin_code'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'viewer');
        $customLimitInput = trim((string)($_POST['custom_remittance_limit'] ?? ''));
        $customLimit = null;
        if ($customLimitInput !== '') {
            if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $customLimitInput) || (float)$customLimitInput <= 0) {
                flash('error', 'سقف حواله باید یک مبلغ مثبت باشد یا خالی بماند.');
                header('Location: users.php?page=' . $page);
                exit;
            }
            $customLimit = $customLimitInput;
        }
        if (!preg_match('/^\d{4}$/', $pinCode)) {
            flash('error', 'پین کد باید دقیقاً ۴ رقم عددی باشد.');
        } elseif ($mobile === '' || $password === '' || !in_array($role, ['admin', 'editor', 'viewer'], true)) {
            flash('error', 'اطلاعات کاربر جدید نامعتبر است.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare('INSERT INTO users (mobile, first_name, last_name, pin_code, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$mobile, $firstName, $lastName, $pinCode, $hash, $role]);
            $newId = (int)db()->lastInsertId();
            $limitStmt = db()->prepare('INSERT INTO user_verification_levels (user_id, level, custom_remittance_limit, updated_at) VALUES (?, \'bronze\', ?, NOW()) ON DUPLICATE KEY UPDATE custom_remittance_limit = VALUES(custom_remittance_limit), updated_at = NOW()');
            $limitStmt->execute([$newId, $customLimit]);
            log_activity('create', 'users', $newId, "ایجاد کاربر: {$mobile}");
            flash('success', 'کاربر جدید ثبت شد.');
        }
        header('Location: users.php?page=' . $page);
        exit;
    }

    if ($action === 'update') {
        require_permission('edit');
        $id = (int)($_POST['id'] ?? 0);
        $mobile = trim($_POST['mobile'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $pinCode = trim($_POST['pin_code'] ?? '');
        $role = trim($_POST['role'] ?? 'viewer');
        $newPassword = trim($_POST['new_password'] ?? '');
        $customLimitInput = trim((string)($_POST['custom_remittance_limit'] ?? ''));
        $customLimit = null;
        if ($customLimitInput !== '') {
            if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $customLimitInput) || (float)$customLimitInput <= 0) {
                flash('error', 'سقف حواله باید یک مبلغ مثبت باشد یا خالی بماند.');
                header('Location: users.php?page=' . $page);
                exit;
            }
            $customLimit = $customLimitInput;
        }
        $userColumnsForUpdate = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
        $parentLinkColumnForUpdate = in_array('parent_id', $userColumnsForUpdate, true) ? 'parent_id' : (in_array('referred_by_user_id', $userColumnsForUpdate, true) ? 'referred_by_user_id' : null);
        $parentId = (int)($_POST['parent_id'] ?? $_POST['referred_by_user_id'] ?? 0);
        $parentError = null;
        if ($parentLinkColumnForUpdate !== null && $parentId > 0) {
            if ($parentId === $id) {
                $parentError = 'کاربر نمی‌تواند خودش را به‌عنوان کاربر بالادستی انتخاب کند.';
            } else {
                $parentExists = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $parentExists->execute([$parentId]);
                if (!$parentExists->fetchColumn()) {
                    $parentError = 'کاربر بالادستی انتخاب‌شده معتبر نیست.';
                } else {
                    $ancestorId = $parentId;
                    $visited = [];
                    while ($ancestorId > 0 && !isset($visited[$ancestorId])) {
                        if ($ancestorId === $id) {
                            $parentError = 'ایجاد رابطه حلقوی بین کاربران مجاز نیست.';
                            break;
                        }
                        $visited[$ancestorId] = true;
                        $ancestorStmt = db()->prepare('SELECT ' . $parentLinkColumnForUpdate . ' FROM users WHERE id = ? LIMIT 1');
                        $ancestorStmt->execute([$ancestorId]);
                        $ancestorId = (int)$ancestorStmt->fetchColumn();
                    }
                }
            }
        }
        if (!preg_match('/^\d{4}$/', $pinCode)) {
            flash('error', 'پین کد باید دقیقاً ۴ رقم عددی باشد.');
        } elseif ($parentError !== null) {
            flash('error', $parentError);
        } elseif ($id <= 0 || $mobile === '' || !in_array($role, ['admin', 'editor', 'viewer'], true)) {
            flash('error', 'اطلاعات ویرایش کاربر نامعتبر است.');
        } else {
            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = db()->prepare('UPDATE users SET mobile = ?, first_name = ?, last_name = ?, pin_code = ?, role = ?, password = ? WHERE id = ?');
                $stmt->execute([$mobile, $firstName, $lastName, $pinCode, $role, $hash, $id]);
            } else {
                $stmt = db()->prepare('UPDATE users SET mobile = ?, first_name = ?, last_name = ?, pin_code = ?, role = ? WHERE id = ?');
                $stmt->execute([$mobile, $firstName, $lastName, $pinCode, $role, $id]);
            }
            $limitStmt = db()->prepare('INSERT INTO user_verification_levels (user_id, level, custom_remittance_limit, updated_at) VALUES (?, \'bronze\', ?, NOW()) ON DUPLICATE KEY UPDATE custom_remittance_limit = VALUES(custom_remittance_limit), updated_at = NOW()');
            $limitStmt->execute([$id, $customLimit]);
            $allowSubcustomers = isset($_POST['can_create_subuser']) || isset($_POST['can_create_subcustomers']) ? 1 : 0;
            if (in_array('can_create_subuser', $userColumnsForUpdate, true)) {
                db()->prepare('UPDATE users SET can_create_subuser = ? WHERE id = ?')->execute([$allowSubcustomers, $id]);
            }
            if (in_array('can_create_subcustomers', $userColumnsForUpdate, true)) {
                db()->prepare('UPDATE users SET can_create_subcustomers = ? WHERE id = ?')->execute([$allowSubcustomers, $id]);
            }
            $parentValue = $parentId > 0 ? $parentId : null;
            if (in_array('parent_id', $userColumnsForUpdate, true)) {
                db()->prepare('UPDATE users SET parent_id = ? WHERE id = ?')->execute([$parentValue, $id]);
            }
            if (in_array('referred_by_user_id', $userColumnsForUpdate, true)) {
                db()->prepare('UPDATE users SET referred_by_user_id = ? WHERE id = ?')->execute([$parentValue, $id]);
            }
            log_activity('update', 'users', $id, "ویرایش کاربر: {$mobile}");
            flash('success', 'کاربر ویرایش شد.');
        }
        header('Location: users.php?page=' . $page);
        exit;
    }

    if ($action === 'delete') {
        require_permission('delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($id === (int)($_SESSION['admin_id'] ?? 0)) {
                flash('error', 'امکان حذف حساب کاربری فعلی وجود ندارد.');
            } else {
                $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
                log_activity('delete', 'users', $id, "حذف کاربر #{$id}");
                flash('success', 'کاربر حذف شد.');
            }
        } else {
            flash('error', 'شناسه کاربر نامعتبر است.');
        }
        header('Location: users.php?page=' . $page);
        exit;
    }
}

$where = [];
$params = [];
$parentSearch = trim((string)($_GET['parent_search'] ?? ''));
  $userColumns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasReferralParentColumn = in_array('referred_by_user_id', $userColumns, true);
  $userSearch = trim((string)($_GET['user_search'] ?? ''));
if (!$hasReferralParentColumn && $parentSearch !== '') {
    error_log('[USERS_PARENT_DEBUG] users.referred_by_user_id missing; parent filter ignored');
    $parentSearch = '';
}

if ($startDate !== '') {
    $startDateSql = jalali_input_to_gregorian_datetime($startDate, false);
    if ($startDateSql) {
        $where[] = 'u.created_at >= ?';
        $params[] = $startDateSql;
    }
}

if ($endDate !== '') {
    $endDateSql = jalali_input_to_gregorian_datetime($endDate, true);
    if ($endDateSql) {
        $where[] = 'u.created_at <= ?';
        $params[] = $endDateSql;
    }
}

if ($hasReferralParentColumn && $parentSearch !== '') {
    $where[] = "(u.referred_by_user_id = ? OR parent.id = ? OR parent.mobile LIKE ? OR CONCAT(COALESCE(parent.first_name, ''), ' ', COALESCE(parent.last_name, '')) LIKE ?)";
    $parentSearchId = ctype_digit($parentSearch) ? (int)$parentSearch : 0;
    $params[] = $parentSearchId;
    $params[] = $parentSearchId;
    $params[] = '%' . $parentSearch . '%';
    $params[] = '%' . $parentSearch . '%';
}

if ($userSearch !== '') {
    $searchLike = '%' . $userSearch . '%';
    $searchConditions = [];
    $searchParams = [];

    if (in_array('mobile', $userColumns, true)) {
        $searchConditions[] = 'u.mobile LIKE ?';
        $searchParams[] = $searchLike;
    }
    if (in_array('full_name', $userColumns, true)) {
        $searchConditions[] = 'u.full_name LIKE ?';
        $searchParams[] = $searchLike;
    }

    $nameParts = [];
    if (in_array('first_name', $userColumns, true)) {
        $nameParts[] = "COALESCE(u.first_name, '')";
    }
    if (in_array('last_name', $userColumns, true)) {
        $nameParts[] = "COALESCE(u.last_name, '')";
    }
    if ($nameParts !== []) {
        $searchConditions[] = 'CONCAT(' . implode(", ' ', ", $nameParts) . ') LIKE ?';
        $searchParams[] = $searchLike;
    }

    if ($searchConditions !== []) {
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
        foreach ($searchParams as $searchParam) {
            $params[] = $searchParam;
        }
    }
}

$usersFromSql = $hasReferralParentColumn
    ? 'FROM users u LEFT JOIN users parent ON parent.id = u.referred_by_user_id'
    : 'FROM users u';
$parentSelectSql = $hasReferralParentColumn
    ? ', u.referred_by_user_id, parent.mobile AS parent_mobile, parent.first_name AS parent_first_name, parent.last_name AS parent_last_name'
    : ', NULL AS referred_by_user_id, NULL AS parent_mobile, NULL AS parent_first_name, NULL AS parent_last_name';
$subcustomerPermissionSelectSql = in_array('can_create_subcustomers', $userColumns, true)
    ? ', u.can_create_subcustomers'
    : ', 0 AS can_create_subcustomers';
$parentCandidates = [];
if ($hasReferralParentColumn) {
    $parentCandidates = db()->query('SELECT id, mobile, first_name, last_name FROM users ORDER BY id DESC')->fetchAll();
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) {$usersFromSql} {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$limit = (int)$perPage;
$offset = (int)$offset;

$stmt = db()->prepare("SELECT u.id, u.mobile, u.first_name, u.last_name, u.balance, u.pin_code, u.role, u.created_at{$parentSelectSql}{$subcustomerPermissionSelectSql}
    {$usersFromSql} {$whereSql}
    ORDER BY u.id DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$debtSchemaReady = debt_schema_ready();
foreach ($rows as &$userRow) {
    $userRow['verification'] = verification_state((int)$userRow['id']);
    $userRow['outstanding_debt'] = $debtSchemaReady ? debt_outstanding_amount((int)$userRow['id']) : null;
    $pendingRequestStmt = db()->prepare("SELECT id, status FROM verification_upgrade_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $pendingRequestStmt->execute([(int)$userRow['id']]);
    $userRow['upgrade_request'] = $pendingRequestStmt->fetch() ?: null;
}
unset($userRow);
$pendingUpgradeStmt = db()->query("SELECT r.id, r.user_id, r.created_at, u.mobile, u.first_name, u.last_name, v.level FROM verification_upgrade_requests r INNER JOIN users u ON u.id = r.user_id LEFT JOIN user_verification_levels v ON v.user_id = r.user_id WHERE r.status = 'pending' ORDER BY r.id ASC");
$pendingUpgrades = $pendingUpgradeStmt->fetchAll();

function admin_user_parent_label(array $row): string
{
    $parentId = (int)($row['referred_by_user_id'] ?? 0);
    if ($parentId <= 0) {
        return '—';
    }
    $name = trim((string)($row['parent_first_name'] ?? '') . ' ' . (string)($row['parent_last_name'] ?? ''));
    $mobile = trim((string)($row['parent_mobile'] ?? ''));
    $label = $name !== '' ? $name : ($mobile !== '' ? $mobile : 'کاربر #' . $parentId);
    return $label . ' (#' . $parentId . ')' . ($mobile !== '' && $name !== '' ? ' - ' . $mobile : '');
}

if ($exportExcel) {
    $exportStmt = db()->prepare("SELECT u.id, u.mobile, u.first_name, u.last_name, u.balance, u.pin_code, u.role, u.created_at{$parentSelectSql}
        {$usersFromSql} {$whereSql}
        ORDER BY u.id DESC");
    $exportStmt->execute($params);
    $exportRows = [];
    while ($row = $exportStmt->fetch()) {
        $exportRows[] = [
            '#' . (string)$row['id'],
            (string)$row['mobile'],
            trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
            admin_user_parent_label($row),
            admin_balance_with_unit($row['balance'] ?? null),
            (string)($row['pin_code'] ?? '-'),
            (string)($row['role'] ?? ''),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    export_xls_table(
        'Users_Report_' . date('Ymd_His') . '.xls',
        ['شناسه', 'شماره موبایل', 'نام کامل', 'کاربر بالادستی', 'موجودی', 'پین کد', 'نقش', 'تاریخ ایجاد'],
        $exportRows
    );
}

if ($exportPrint) {
    $printStmt = db()->prepare("SELECT u.id, u.mobile, u.first_name, u.last_name, u.balance, u.pin_code, u.role, u.created_at{$parentSelectSql}
        {$usersFromSql} {$whereSql}
        ORDER BY u.id DESC");
    $printStmt->execute($params);
    $printRows = [];
    while ($row = $printStmt->fetch()) {
        $printRows[] = [
            '#' . (string)$row['id'],
            (string)$row['mobile'],
            trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
            admin_user_parent_label($row),
            admin_balance_with_unit($row['balance'] ?? null),
            (string)($row['pin_code'] ?? '-'),
            (string)($row['role'] ?? ''),
            to_jalali_datetime((string)$row['created_at']),
        ];
    }

    render_print_table_view(
        'گزارش کاربران',
        ['شناسه', 'شماره موبایل', 'نام کامل', 'کاربر بالادستی', 'موجودی', 'پین کد', 'نقش', 'تاریخ ایجاد'],
        $printRows,
        'فیلتر بازه تاریخ اعمال شده است.'
    );
}

$csrf = csrf_token();
if (empty($_SESSION['manual_operation_nonce'])) {
    $_SESSION['manual_operation_nonce'] = bin2hex(random_bytes(16));
}
$manualOperationNonce = (string)$_SESSION['manual_operation_nonce'];

render_page_start('مدیریت کاربران', 'users');
?>
<style>
  .datepicker-plot-area, .pwt-datepicker, .datepicker-container { z-index: 99999 !important; }
  .users-filter-shell { overflow: visible !important; position: relative; }
  .users-table-filter {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #dbe4ef;
    border-radius: 12px;
    font-family: inherit;
    font-size: 13px;
    color: #0f172a;
    background: #fff;
    outline: none;
    box-sizing: border-box;
  }
  .users-table-filter:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.10);
  }
  .users-row-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
  }
  .users-actions-toggle {
    min-width: 38px;
    width: 38px;
    height: 34px;
    padding: 0;
    font-size: 23px;
    line-height: 1;
    font-weight: 800;
  }
  .users-actions-menu {
    display: none;
    position: fixed;
    min-width: 180px;
    max-width: calc(100vw - 16px);
    padding: 6px;
    background: #fff;
    border: 1px solid #dbe4ef;
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .18);
    z-index: 1100;
    direction: rtl;
  }
  .users-actions-menu.open {
    display: flex;
    flex-direction: column;
    gap: 3px;
  }
  .users-actions-menu a,
  .users-actions-menu button {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #334155;
    font: inherit;
    font-size: 13px;
    text-align: right;
    text-decoration: none;
    cursor: pointer;
    box-sizing: border-box;
  }
  .users-actions-menu a:hover,
  .users-actions-menu button:hover {
    background: #f1f5f9;
  }
  .users-actions-menu .users-action-delete {
    margin-top: 3px;
    border-top: 1px solid #e2e8f0;
    border-radius: 0 0 7px 7px;
    color: #b91c1c;
  }
  .users-actions-menu form {
    margin: 0;
    padding: 0;
  }
  .users-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    width: 100%;
    margin: 20px 0 0;
    padding: 12px 0 2px;
    box-sizing: border-box;
    direction: ltr;
  }
  .users-pagination .page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    min-width: 40px;
    height: 40px;
    padding: 0;
    box-sizing: border-box;
    border-radius: 10px;
    text-align: center;
  }
  .admin-balance-badge { display:inline-flex;align-items:center;justify-content:center;padding:7px 11px;border-radius:10px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-weight:800;white-space:nowrap;direction:rtl; }
  .admin-debt-badge { display:inline-flex;align-items:center;justify-content:center;padding:7px 11px;border-radius:10px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;font-weight:800;white-space:nowrap;direction:rtl; }
  .admin-debt-badge.has-debt { background:#fef2f2;color:#b91c1c;border-color:#fecaca; }
  .admin-balance-panel { padding:14px 16px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:800;text-align:right; }
  @media (max-width: 840px) { .admin-balance-badge { width:100%;box-sizing:border-box; } }
</style>
<?php if ($pendingUpgrades !== []): ?>
<div class="card">
  <h3 style="margin-top:0;">درخواست‌های ارتقاء سطح کاربری</h3>
  <p><a class="btn btn-primary btn-sm" href="verifications.php">مشاهده مدارک و بررسی امن درخواست‌ها</a></p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>کاربر</th><th>موبایل</th><th>سطح فعلی</th><th>سقف برداشت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($pendingUpgrades as $upgrade): $upgradeState = verification_state((int)$upgrade['user_id']); ?>
        <tr>
          <td><?= e(trim(($upgrade['first_name'] ?? '') . ' ' . ($upgrade['last_name'] ?? ''))) ?></td>
          <td dir="ltr"><?= e((string)$upgrade['mobile']) ?></td>
          <td><?= e((string)$upgradeState['level']) ?> / تلفن <?= $upgradeState['phone_verified'] ? 'تأییدشده' : 'تأییدنشده' ?></td>
          <td><?= e(number_format((float)$upgradeState['withdrawal_limit'])) ?> تومان</td>
          <td><a class="btn btn-sm" href="verifications.php?status=pending">بررسی در صف احراز هویت</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<div class="card">
  <div class="actions" style="justify-content: space-between;">
    <h3 style="margin:0;">لیست کاربران</h3>
    <?php if (can('create')): ?><button class="btn btn-primary" data-modal-open="createUserModal">افزودن کاربر</button><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="actions users-filter-shell" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin-bottom:1.5rem; overflow:visible !important;">
    <form method="get" class="actions" style="display:flex; align-items:center; gap:15px; flex-wrap:wrap; margin:0; overflow:visible !important;">
      <input type="hidden" name="page" value="1">
      <input type="text" class="filter-input js-persian-date" name="start_date" value="<?= e($startDate) ?>" placeholder="از تاریخ..." aria-label="از تاریخ" style="width:200px; max-width:200px;">
      <input type="text" class="filter-input js-persian-date" name="end_date" value="<?= e($endDate) ?>" placeholder="تا تاریخ..." aria-label="تا تاریخ" style="width:200px; max-width:200px;">
      <input type="text" class="filter-input" name="user_search" value="<?= e($userSearch) ?>" placeholder="جستجوی موبایل یا نام..." aria-label="جستجوی موبایل یا نام" autocomplete="off" style="width:240px; max-width:240px;">
      <?php if ($hasReferralParentColumn): ?><input type="text" class="filter-input" name="parent_search" value="<?= e($parentSearch) ?>" placeholder="کاربر بالادستی..." aria-label="کاربر بالادستی" style="width:220px; max-width:220px;"><?php endif; ?>
      <button type="submit" class="btn btn-primary" style="height:36px;">فیلتر</button>
    </form>
    <a class="btn btn-light" style="color:#2563eb;border-color:#bfdbfe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="users.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>">خروجی Excel</a>
    <a class="btn btn-light" style="color:#7c3aed;border-color:#ddd6fe; text-decoration:none; display:inline-flex; align-items:center; height:36px;" href="users.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'print']))) ?>">خروجی PDF</a>
  </div>
  <div class="table-wrap">
    <table id="usersTable">
      <thead>
        <tr>
          <th>#</th><th>شماره موبایل</th><th>نام کامل</th><?php if ($hasReferralParentColumn): ?><th>کاربر بالادستی</th><?php endif; ?><th>موجودی</th><th>بدهی کاربر</th><th>پین کد</th><th>نقش</th><th>تاریخ ایجاد</th><th>عملیات</th>
        </tr>
        <tr class="filter-row">
          <th><input class="users-table-filter" data-col="0" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="1" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="2" placeholder="جستجو"></th>
          <?php if ($hasReferralParentColumn): ?><th><input class="users-table-filter" data-col="3" placeholder="جستجو"></th><?php endif; ?>
          <th><input class="users-table-filter" data-col="<?= $hasReferralParentColumn ? 4 : 3 ?>" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="<?= $hasReferralParentColumn ? 5 : 4 ?>" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="<?= $hasReferralParentColumn ? 6 : 5 ?>" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="<?= $hasReferralParentColumn ? 7 : 6 ?>" placeholder="جستجو"></th>
          <th><input class="users-table-filter" data-col="<?= $hasReferralParentColumn ? 8 : 7 ?>" placeholder="جستجو"></th>
          <th style="text-align:center;">
            <button type="button" class="btn btn-primary btn-sm" onclick="(function(){const evt=new Event('input',{bubbles:true});document.querySelectorAll('#usersTable .filter-row input[data-col]').forEach(i=>i.dispatchEvent(evt));})();">فیلتر</button>
          </th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e((string)$row['id']) ?></td>
          <?php $rowVerification = verification_state((int)$row['id']); ?>
          <td><?= e($row['mobile']) ?><br><small>تأیید تلفن: <?= $rowVerification['phone_verified'] ? 'بله' : 'خیر' ?><?= $rowVerification['phone_verified_at'] ? ' / ' . e((string)$rowVerification['phone_verified_at']) : '' ?></small></td>
          <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></td>
          <?php if ($hasReferralParentColumn): ?><td><?= e(admin_user_parent_label($row)) ?></td><?php endif; ?>
          <td><span class="admin-balance-badge"><?= e(admin_balance_with_unit($row['balance'] ?? null)) ?></span></td>
          <td>
            <?php if ($debtSchemaReady): ?>
              <span class="admin-debt-badge <?= (float)($row['outstanding_debt'] ?? 0) > 0 ? 'has-debt' : '' ?>">
                <?= e(number_format((float)($row['outstanding_debt'] ?? 0))) ?> تومان
              </span>
            <?php else: ?>
              <span class="admin-debt-badge">migration لازم است</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['pin_code'] ?? '-') ?></td>
          <td>
            <?= e($row['role']) ?><br>
            <small><?= e((string)$rowVerification['level']) ?> · <?= $rowVerification['withdrawal_limit'] === null ? 'بدون سقف اولیه' : e(number_format((float)$rowVerification['withdrawal_limit'])) . ' تومان' ?></small>
          </td>
          <td><?= e(to_jalali_datetime((string)$row['created_at'])) ?></td>
          <td class="text-nowrap">
            <div class="users-row-actions">
              <button type="button" class="btn btn-light btn-sm users-actions-toggle" aria-label="عملیات کاربر" aria-expanded="false">⋯</button>
              <div class="users-actions-menu">
              <?php if (can('edit')): ?>
                <a href="user_debts.php?user_id=<?= e((string)$row['id']) ?>">بدهی کاربر</a>
                <a href="user_statement.php?user_id=<?= e((string)$row['id']) ?>">صورتحساب</a>
                <?php if ($hasReferralParentColumn): ?><a href="user_fee_shares.php?parent_user_id=<?= e((string)$row['id']) ?>">سهم مفاد</a><?php endif; ?>
                <button type="button" data-id="<?= e((string)$row['id']) ?>"
                        data-mobile="<?= e($row['mobile']) ?>"
                        data-balance="<?= e(admin_balance_with_unit($row['balance'] ?? null)) ?>"
                        onclick="openManualOperations(this)">عملیات دستی</button>
              <?php endif; ?>
              <?php if (can('edit')): ?>
                <button type="button" data-modal-open="editUserModal"
                        data-id="<?= e((string)$row['id']) ?>"
                        data-mobile="<?= e($row['mobile']) ?>"
                        data-first_name="<?= e($row['first_name'] ?? '') ?>"
                        data-last_name="<?= e($row['last_name'] ?? '') ?>"
                        data-balance="<?= e(admin_balance_with_unit($row['balance'] ?? null)) ?>"
                        data-pin_code="<?= e($row['pin_code'] ?? '') ?>"
                        data-role="<?= e($row['role']) ?>"
                        data-can_create_subcustomers="<?= e((string)($row['can_create_subcustomers'] ?? 0)) ?>"
                        <?php if ($hasReferralParentColumn): ?>data-parent_label="<?= e(admin_user_parent_label($row)) ?>" data-parent_id="<?= e((string)($row['referred_by_user_id'] ?? '')) ?>"<?php endif; ?>
                        data-custom_remittance_limit="<?= $rowVerification['custom_remittance_limit'] === null ? '' : e((string)$rowVerification['custom_remittance_limit']) ?>"
                        data-level_remittance_limit="<?= e((string)$rowVerification['daily_limit']) ?>"
                        data-remittance_used_today="<?= e((string)daily_remittance_usage((int)$row['id'])) ?>"
                        onclick="openEditUser(this)">ویرایش</button>
              <?php endif; ?>
              <?php if (can('delete')): ?>
                <form method="post" onsubmit="return confirm('کاربر حذف شود؟');">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                  <button type="submit" class="users-action-delete">حذف</button>
                </form>
              <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination users-pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="users.php?<?= e(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>

<?php if (can('create')): ?>
<div class="modal-backdrop" id="createUserModal">
  <div class="modal">
    <div class="modal-head"><h4 class="modal-title">افزودن کاربر</h4><button class="icon-btn" data-modal-close="createUserModal">×</button></div>
    <div class="modal-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-3"><label class="field-label">شماره موبایل</label><input class="input" name="mobile"></div>
        <div class="col-3"><label class="field-label">نام</label><input class="input" name="first_name"></div>
        <div class="col-3"><label class="field-label">نام خانوادگی</label><input class="input" name="last_name"></div>
        <div class="col-3"><label class="field-label">پین کد</label><input class="input" name="pin_code"></div>
        <div class="col-4"><label class="field-label">رمز عبور</label><input class="input" type="password" name="password"></div>
        <div class="col-4"><label class="field-label">نقش</label>
          <select class="select" name="role"><option>viewer</option><option>editor</option><option>admin</option></select>
        </div>
        <div class="col-4"></div>
        <?php $defaultVerification = verification_level_definitions()['bronze']; ?>
        <div class="col-12" style="padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
          <h5 style="margin:0 0 12px;">تنظیمات انتقال وجه</h5>
          <div class="form-grid">
            <div class="col-6"><label class="field-label">سقف پیش‌فرض سطح کاربر</label><div><?= e(number_format((float)$defaultVerification['daily_limit'])) ?> تومان</div></div>
            <div class="col-6"><label class="field-label">سقف اختصاصی انتقال وجه</label><input class="input" name="custom_remittance_limit" type="number" min="0.01" step="0.01" dir="ltr"></div>
            <div class="col-12"><small>در صورت خالی بودن، سقف پیش‌فرض سطح کاربر اعمال می‌شود.</small></div>
          </div>
        </div>
        <div class="col-12 actions"><button class="btn btn-primary">ثبت</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can('edit')): ?>
<div class="modal-backdrop" id="manualOperationsModal">
  <div class="modal">
    <div class="modal-head"><h4 class="modal-title">عملیات دستی</h4><button class="icon-btn" data-modal-close="manualOperationsModal">×</button></div>
    <div class="modal-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="manual_operation">
        <input type="hidden" name="operation_nonce" value="<?= e($manualOperationNonce) ?>">
        <input type="hidden" name="user_id" id="manualOperationUserId">
        <div class="col-12">
          <p id="manualOperationUserHint" class="field-label">کاربر: -</p>
        </div>
        <div class="col-12"><div id="manualOperationBalance" class="admin-balance-panel">موجودی فعلی حساب: —</div></div>
        <div class="col-6">
          <label class="field-label">نوع عملیات</label>
          <select class="select" id="manualOperationType" name="manual_action" onchange="toggleRemittanceFields(this.value)">
            <option value="increase">افزایش موجودی</option>
            <option value="decrease">کاهش موجودی</option>
            <option value="remittance">ثبت حواله</option>
          </select>
        </div>
        <div class="col-6">
          <label class="field-label">مبلغ (تومان)</label>
          <input class="input" name="amount" type="text" inputmode="numeric" dir="ltr" data-money-input data-money-words-target="manualOperationAmountWords" required>
          <div class="money-amount-words" id="manualOperationAmountWords"></div>
        </div>
        <div class="col-4 manual-remittance-field">
          <label class="field-label">نمایندگی</label>
          <input class="input" name="agency" id="manualOperationAgency">
        </div>
        <div class="col-4 manual-remittance-field">
          <label class="field-label">کشور مقصد</label>
          <select class="input" name="destination_country" id="manualOperationDestinationCountry">
            <option value="">انتخاب کشور...</option>
            <?php foreach (remittance_destination_countries() as $countryCode => $countryName): ?>
              <option value="<?= e($countryCode) ?>"><?= e($countryName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-4 manual-remittance-field">
          <label class="field-label">فرستنده</label>
          <input class="input" name="sender" id="manualOperationSender">
        </div>
        <div class="col-4 manual-remittance-field">
          <label class="field-label">گیرنده</label>
          <input class="input" name="receiver" id="manualOperationReceiver">
        </div>
        <div class="col-6 manual-remittance-field">
          <label class="field-label">مبلغ افغانی</label>
          <input class="input" name="amount_afghani" id="manualOperationAmountAfghani" type="text" inputmode="decimal" dir="ltr" data-money-decimal data-money-unit="افغانی" data-money-words-target="manualOperationAmountAfghaniWords">
          <div class="money-amount-words" id="manualOperationAmountAfghaniWords"></div>
        </div>
        <div class="col-12 actions">
          <button class="btn btn-primary" id="manualOperationSubmitButton">ثبت</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can('edit')): ?>
<div class="modal-backdrop" id="editUserModal">
  <div class="modal">
    <div class="modal-head"><h4 class="modal-title">ویرایش کاربر</h4><button class="icon-btn" data-modal-close="editUserModal">×</button></div>
    <div class="modal-body">
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editUserId">
        <div class="col-12"><div id="editUserBalance" class="admin-balance-panel">موجودی فعلی حساب: —</div></div>
        <?php if ($hasReferralParentColumn): ?><div class="col-12"><div id="editUserParent" class="admin-balance-panel" style="background:#f8fafc;border-color:#e2e8f0;color:#475569;">شناسه کاربر بالادستی: —</div></div><?php endif; ?>
        <?php if ($hasReferralParentColumn): ?><div class="col-12"><label class="field-label">کاربر بالادستی</label><select class="input" name="referred_by_user_id" id="editUserParentId"><option value="">بدون کاربر بالادستی</option><?php foreach ($parentCandidates as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= e(trim((string)($candidate['first_name'] ?? '') . ' ' . (string)($candidate['last_name'] ?? '')) ?: (string)$candidate['mobile']) ?> - <?= e((string)$candidate['mobile']) ?> - #<?= (int)$candidate['id'] ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-12"><label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="can_create_subuser" id="editUserCanCreateSubcustomers"> اجازه ایجاد مشتری زیرمجموعه</label></div>
        <div class="col-3"><label class="field-label">شماره موبایل</label><input class="input" id="editUserMobile" name="mobile"></div>
        <div class="col-3"><label class="field-label">نام</label><input class="input" id="editUserFirstName" name="first_name"></div>
        <div class="col-3"><label class="field-label">نام خانوادگی</label><input class="input" id="editUserLastName" name="last_name"></div>
        <div class="col-3"><label class="field-label">پین کد</label><input class="input" id="editUserPinCode" name="pin_code"></div>
        <div class="col-4"><label class="field-label">رمز عبور جدید (اختیاری)</label><input class="input" type="password" name="new_password"></div>
        <div class="col-4"><label class="field-label">نقش</label>
          <select class="select" id="editUserRole" name="role"><option>viewer</option><option>editor</option><option>admin</option></select>
        </div>
        <div class="col-4"></div>
        <div class="col-12" id="editUserRemittanceSettings" style="padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
          <h5 style="margin:0 0 12px;">تنظیمات انتقال وجه</h5>
          <div class="form-grid">
            <div class="col-6"><label class="field-label">سقف روزانه سطح کاربر</label><div id="editUserNormalRemittanceLimit">—</div></div>
            <div class="col-6"><label class="field-label">سقف اختصاصی انتقال وجه</label><input class="input" id="editUserCustomRemittanceLimit" name="custom_remittance_limit" type="number" min="0.01" step="0.01" dir="ltr"></div>
            <div class="col-6"><label class="field-label">مصرف انتقال وجه امروز</label><div id="editUserRemittanceUsedToday">—</div></div>
            <div class="col-6"><label class="field-label">باقیمانده سقف اختصاصی</label><div id="editUserRemittanceRemaining">—</div></div>
            <div class="col-12"><label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="editUserUseDefaultRemittanceLimit"> استفاده از سقف پیش‌فرض سطح کاربر</label><small id="editUserCustomRemittanceState">تنظیم نشده - استفاده از سقف پیش‌فرض سطح کاربر</small></div>
          </div>
        </div>
        <div class="col-12 actions"><button class="btn btn-primary">ذخیره</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  function openEditUser(btn) {
    document.getElementById('editUserId').value = btn.dataset.id || '';
    document.getElementById('editUserMobile').value = btn.dataset.mobile || '';
    document.getElementById('editUserFirstName').value = btn.dataset.first_name || '';
    document.getElementById('editUserLastName').value = btn.dataset.last_name || '';
    document.getElementById('editUserBalance').textContent = 'موجودی فعلی حساب: ' + (btn.dataset.balance || '—');
    const parentBox = document.getElementById('editUserParent');
    if (parentBox) parentBox.textContent = 'شناسه کاربر بالادستی: ' + (btn.dataset.parent_label || '—');
    const parentSelect = document.getElementById('editUserParentId');
    if (parentSelect) {
      Array.from(parentSelect.options).forEach((option) => {
        option.disabled = option.value !== '' && option.value === (btn.dataset.id || '');
      });
      parentSelect.value = btn.dataset.parent_id || '';
    }
    const createSubcustomers = document.getElementById('editUserCanCreateSubcustomers');
    if (createSubcustomers) createSubcustomers.checked = btn.dataset.can_create_subcustomers === '1';
    document.getElementById('editUserPinCode').value = btn.dataset.pin_code || '';
    document.getElementById('editUserRole').value = btn.dataset.role || 'viewer';
    document.getElementById('editUserCustomRemittanceLimit').value = btn.dataset.custom_remittance_limit || '';
    const normal = Number(btn.dataset.level_remittance_limit || 0);
    const custom = btn.dataset.custom_remittance_limit || '';
    document.getElementById('editUserNormalRemittanceLimit').textContent = normal > 0 ? normal.toLocaleString('en-US') + ' تومان' : 'تنظیم نشده';
    const used = Number(btn.dataset.remittance_used_today || 0);
    document.getElementById('editUserRemittanceUsedToday').textContent = used.toLocaleString('en-US') + ' تومان';
    document.getElementById('editUserRemittanceRemaining').textContent = custom ? Math.max(0, Number(custom) - used).toLocaleString('en-US') + ' تومان' : '—';
    document.getElementById('editUserUseDefaultRemittanceLimit').checked = !custom;
    document.getElementById('editUserCustomRemittanceState').textContent = custom ? '' : 'تنظیم نشده - استفاده از سقف پیش‌فرض سطح کاربر';
    document.getElementById('editUserCustomRemittanceLimit').disabled = !custom;
  }

  document.getElementById('editUserUseDefaultRemittanceLimit')?.addEventListener('change', function () {
    const input = document.getElementById('editUserCustomRemittanceLimit');
    if (this.checked) input.value = '';
    input.disabled = this.checked;
  });

  function openManualOperations(btn) {
    const modal = document.getElementById('manualOperationsModal');
    if (!modal) return;

    document.querySelector('#manualOperationsModal form').reset();
    window.AfariexMoneyInputs?.apply(modal);
    document.getElementById('manualOperationUserId').value = btn.dataset.id || '';
    document.getElementById('manualOperationUserHint').textContent = 'کاربر: ' + (btn.dataset.mobile || '-');
    document.getElementById('manualOperationBalance').textContent = 'موجودی فعلی حساب: ' + (btn.dataset.balance || '—');
    document.getElementById('manualOperationAgency').value = '';
    document.getElementById('manualOperationSender').value = '';
    document.getElementById('manualOperationReceiver').value = '';
    document.getElementById('manualOperationAmountAfghani').value = '';
    const typeSelect = document.getElementById('manualOperationType');
    typeSelect.value = 'increase';
    toggleRemittanceFields(typeSelect.value);
    modal.classList.add('open');
  }

  function toggleRemittanceFields(type) {
    const isRemittance = type === 'remittance';
    const submitButton = document.getElementById('manualOperationSubmitButton');
    const fields = document.querySelectorAll('.manual-remittance-field');
    const agency = document.getElementById('manualOperationAgency');
    const destinationCountry = document.getElementById('manualOperationDestinationCountry');
    const sender = document.getElementById('manualOperationSender');
    const receiver = document.getElementById('manualOperationReceiver');
    const amountAfghani = document.getElementById('manualOperationAmountAfghani');

    fields.forEach((field) => {
      field.style.display = isRemittance ? '' : 'none';
    });

    if (agency) agency.required = isRemittance;
    if (destinationCountry) {
      destinationCountry.required = isRemittance;
      if (!isRemittance) destinationCountry.value = '';
    }
    if (sender) sender.required = isRemittance;
    if (receiver) receiver.required = isRemittance;

    if (amountAfghani) {
      amountAfghani.required = isRemittance;
      if (!isRemittance) {
        amountAfghani.value = '';
      }
    }

    if (submitButton) {
      submitButton.textContent = isRemittance ? 'ثبت حواله' : 'ثبت عملیات';
    }
  }

  (function () {
    const table = document.getElementById('usersTable');
    if (!table) return;
    const filters = table.querySelectorAll('.filter-row input[data-col]');
    const rows = Array.from(table.querySelectorAll('tbody tr'));
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

  (function () {
    const input = document.querySelector('form input[name="user_search"]');
    if (!input || !input.form) return;

    let timer = null;
    input.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => input.form.submit(), 400);
    });
  })();

  (function () {
    const actionGroups = document.querySelectorAll('.users-row-actions');
    if (!actionGroups.length) return;

    function closeMenus() {
      document.querySelectorAll('.users-actions-menu.open').forEach((menu) => {
        menu.classList.remove('open');
        menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
      });
    }

    actionGroups.forEach((group) => {
      const toggle = group.querySelector('.users-actions-toggle');
      const menu = group.querySelector('.users-actions-menu');
      if (!toggle || !menu) return;

      toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = !menu.classList.contains('open');
        closeMenus();
        if (!willOpen) return;

        const rect = toggle.getBoundingClientRect();
        const menuWidth = Math.min(180, window.innerWidth - 16);
        const left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8));
        menu.classList.add('open');
        const menuHeight = menu.offsetHeight;
        const top = rect.bottom + 4 + menuHeight <= window.innerHeight
          ? rect.bottom + 4
          : Math.max(8, rect.top - menuHeight - 4);
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        toggle.setAttribute('aria-expanded', 'true');
      });

      menu.addEventListener('click', () => {
        closeMenus();
      });
    });

    document.addEventListener('click', closeMenus);
    window.addEventListener('resize', closeMenus);
    window.addEventListener('scroll', closeMenus, true);
  })();
</script>
<?php if (function_exists('render_money_input_helpers')) { render_money_input_helpers(); } ?>
<?php render_page_end(); ?>
