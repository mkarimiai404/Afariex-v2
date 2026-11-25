<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';
require_once __DIR__ . '/../API/includes/remittance_currencies.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/debt_service.php';
require_once __DIR__ . '/statement_export.php';
require_once __DIR__ . '/statement_balance_helpers.php';

require_login();
require_permission('view');

const AFARIEX_STATEMENT_WHATSAPP = '0793510735';

function statement_columns(string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    if (!table_exists($table)) {
        return $cache[$table] = [];
    }
    try {
        return $cache[$table] = db()->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function statement_has_columns(string $table, array $columns): bool
{
    $available = statement_columns($table);
    foreach ($columns as $column) {
        if (!in_array($column, $available, true)) {
            return false;
        }
    }
    return true;
}

function statement_date_sql(?string $value, bool $endOfDay = false): ?string
{
    return jalali_input_to_gregorian_datetime($value, $endOfDay);
}

function statement_currency_catalog(): array
{
    $catalog = remittance_source_currencies() + remittance_destination_currencies();
    $catalog['IRR'] = ['code' => 'IRR', 'name' => 'ریال ایران', 'symbol' => '﷼'];
    return $catalog;
}

function statement_currency_code(mixed $currency, string $fallback = 'IRT'): string
{
    $currency = strtoupper(trim((string)$currency));
    return preg_match('/^[A-Z][A-Z0-9]{2,7}$/D', $currency) === 1 ? $currency : $fallback;
}

function statement_currency_name(string $currency): string
{
    $currency = statement_currency_code($currency);
    $catalog = statement_currency_catalog();
    return (string)($catalog[$currency]['name'] ?? $currency);
}

function statement_money(float $amount, string $currency = 'IRT'): string
{
    $currency = statement_currency_code($currency);
    $precision = $currency === 'IRR' ? 0 : remittance_currency_precision($currency);
    return number_format($amount, $precision) . ' ' . statement_currency_name($currency);
}

function statement_detect_currency_column(string $table, array $columns, string $alias = ''): string
{
    foreach (['currency_code', 'currency', 'asset_currency', 'currency_type', 'source_currency'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return ($alias !== '' ? $alias . '.' : '') . '`' . $candidate . '`';
        }
    }
    return "'IRT'";
}

function statement_user_wallet_balances(array $user): array
{
    $columns = statement_columns('users');
    $balances = [];
    foreach ($columns as $column) {
        if (strcasecmp($column, 'balance') === 0) {
            $balances['IRT'] = (float)($user[$column] ?? 0);
            continue;
        }
        if (preg_match('/^(?:wallet_)?balance_([a-z]{3,8})$/i', $column, $matches) === 1
            || preg_match('/^([a-z]{3,8})_balance$/i', $column, $matches) === 1) {
            $currency = statement_currency_code($matches[1]);
            $balances[$currency] = (float)($user[$column] ?? 0);
        }
    }
    if (!array_key_exists('IRT', $balances)) {
        $balances['IRT'] = (float)($user['balance'] ?? 0);
    }
    if (table_exists('currency_wallets')) {
        $walletStmt = db()->prepare('SELECT currency_code, balance FROM currency_wallets WHERE user_id = ?');
        $walletStmt->execute([(int)($user['id'] ?? 0)]);
        foreach ($walletStmt->fetchAll(PDO::FETCH_ASSOC) as $wallet) {
            $currency = statement_currency_code($wallet['currency_code'] ?? 'IRT');
            $balances[$currency] = (float)($wallet['balance'] ?? 0);
        }
    }
    return $balances;
}

function statement_outstanding_debts_by_currency(int $userId): array
{
    if (!statement_has_columns('user_debts', ['id', 'user_id', 'remaining_amount'])) {
        return [];
    }
    $columns = statement_columns('user_debts');
    $currencySelect = statement_detect_currency_column('user_debts', $columns);
    $statusFilter = in_array('status', $columns, true) ? " AND status IN ('active', 'partially_paid')" : '';
    $stmt = db()->prepare("SELECT {$currencySelect} AS currency, COALESCE(SUM(remaining_amount), 0) AS amount FROM user_debts WHERE user_id = ?{$statusFilter} GROUP BY currency");
    $stmt->execute([$userId]);
    $debts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $currency = statement_currency_code($row['currency'] ?? 'IRT');
        $debts[$currency] = (float)($row['amount'] ?? 0);
    }
    return $debts;
}

function statement_status_fa(string $status): string
{
    return match ($status) {
        'approved' => 'تایید شده',
        'pending' => 'در انتظار',
        'paid' => 'پرداخت شده',
        'rejected' => 'رد شده',
        'active' => 'تسویه نشده',
        'partially_paid' => 'تسویه جزئی',
        'settled' => 'تسویه شده',
        'cancelled', 'canceled' => 'لغو شده',
        'collected' => 'دریافت شده',
        'refunded' => 'برگشت داده شده',
        'success', 'completed' => 'موفق',
        'failed' => 'ناموفق',
        default => $status,
    };
}

function statement_type_fa(string $type): string
{
    return match ($type) {
        'deposit' => 'افزایش موجودی',
        'manual_deposit' => 'واریز دستی',
        'admin_manual_increase' => 'افزایش دستی موجودی',
        'withdraw', 'withdrawal' => 'برداشت وجه',
        'manual_withdrawal' => 'برداشت دستی',
        'remittance' => 'حواله',
        'remittance_destination' => 'مبلغ ارزی پرداختی حواله',
        'refund' => 'برگشت وجه',
        'debt' => 'ثبت بدهی',
        'debt_payment' => 'تسویه بدهی',
        'service_fee' => 'کارمزد خدمات',
        'topup' => 'خرید کارت شارژ',
        'fee_share' => 'سهم کارمزد',
        default => $type,
    };
}

function statement_direction_fa(string $direction): string
{
    return match ($direction) {
        'credit' => 'بستانکار',
        'debit' => 'بدهکار',
        default => 'اطلاعی',
    };
}

function statement_default_description(string $type): string
{
    return match ($type) {
        'deposit' => 'شارژ کیف پول',
        'manual_deposit' => 'واریز دستی کیف پول',
        'withdraw', 'withdrawal' => 'برداشت از کیف پول',
        'manual_withdrawal' => 'برداشت دستی از کیف پول',
        'remittance' => 'ثبت حواله',
        'debt_payment' => 'تسویه بدهی',
        'service_fee' => 'کارمزد خدمات',
        'debt' => 'ثبت بدهی',
        'refund' => 'برگشت وجه',
        'topup' => 'خرید کارت شارژ',
        'fee_share' => 'سهم کارمزد',
        default => statement_type_fa($type),
    };
}

function statement_normalized_description(string $type, ?string $actualDescription = null, array $relatedParts = []): string
{
    $actualDescription = trim((string)$actualDescription);
    if ($actualDescription === 'باقی مانده طلب از رقم در این برنامه اضافه شد') {
        return 'تسویه بخشی از بدهی؛ مانده بدهی باقی است.';
    }
    if ($actualDescription !== '') {
        return $actualDescription;
    }

    $relatedParts = array_values(array_filter(array_map(
        static fn ($part): string => trim((string)$part),
        $relatedParts
    )));
    if ($relatedParts !== []) {
        return implode(' / ', $relatedParts);
    }

    return statement_default_description($type);
}

function statement_add_row(array &$rows, array $row): void
{
    $rows[] = array_merge([
        'date' => '',
        'sort_id' => 0,
        'type' => '',
        'currency' => 'IRT',
        'description' => '',
        'reference' => '',
        'direction' => 'neutral',
        'amount' => 0.0,
        'effect' => 0.0,
        'running_balance' => 0.0,
        'balance_before' => null,
        'balance_after' => null,
        'status' => '',
        'source_rank' => 0,
    ], $row);
}

function statement_build_rows(int $userId): array
{
    $rows = [];
    $debtCurrencies = [];

    try {
        $debtColumns = statement_columns('user_debts');
        if (statement_has_columns('user_debts', ['id', 'user_id', 'original_amount', 'created_at'])) {
            $descriptionSelect = in_array('description', $debtColumns, true) ? "COALESCE(description, '')" : "''";
            $statusSelect = in_array('status', $debtColumns, true) ? "COALESCE(status, '')" : "''";
            $currencySelect = statement_detect_currency_column('user_debts', $debtColumns);
            $stmt = db()->prepare("SELECT id, original_amount, created_at, {$descriptionSelect} AS description, {$statusSelect} AS status, {$currencySelect} AS currency FROM user_debts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $sourceRows = $stmt->fetchAll();
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt rows=' . count($sourceRows));
            foreach ($sourceRows as $row) {
                $currency = statement_currency_code($row['currency'] ?? 'IRT');
                $debtCurrencies[(int)$row['id']] = $currency;
                statement_add_row($rows, [
                    'date' => (string)($row['created_at'] ?? ''),
                    'sort_id' => (int)$row['id'],
                    'type' => 'debt',
                    'currency' => $currency,
                    'description' => statement_normalized_description('debt', (string)($row['description'] ?? ''), [statement_status_fa((string)($row['status'] ?? ''))]),
                    'reference' => 'DEBT-' . (int)$row['id'],
                    'direction' => 'debit',
                    'amount' => (string)($row['original_amount'] ?? '0'),
                    'effect' => 0.0,
                    'status' => (string)($row['status'] ?? ''),
                    'source_rank' => 10,
                ]);
            }
        } else {
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt rows=0 schema_incomplete=true');
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt rows=0 error=' . $e->getMessage());
    }

    try {
        if (statement_has_columns('transactions', ['id', 'user_id', 'amount', 'type', 'status', 'created_at'])) {
            $columns = statement_columns('transactions');
            $descriptionSelect = in_array('description', $columns, true) ? "COALESCE(t.description, '')" : "''";
            $balanceBeforeSelect = in_array('balance_before', $columns, true) ? 't.balance_before' : 'NULL AS balance_before';
            $balanceAfterSelect = in_array('balance_after', $columns, true) ? 't.balance_after' : 'NULL AS balance_after';
            $adminIdColumn = null;
            foreach (['created_by_admin_id', 'operator_id', 'admin_id', 'approved_by_admin_id'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $adminIdColumn = $candidate;
                    break;
                }
            }
            $adminIdSelect = $adminIdColumn !== null ? 't.`' . $adminIdColumn . '` AS admin_id' : 'NULL AS admin_id';
            $providerReferenceSelect = in_array('provider_ref_code', $columns, true) ? "COALESCE(t.provider_ref_code, '') AS provider_reference" : "'' AS provider_reference";
            $trackingSelect = in_array('withdrawal_reference', $columns, true) && in_array('tracking_code', $columns, true)
                ? "CASE WHEN t.type IN ('withdraw', 'withdrawal') THEN COALESCE(NULLIF(t.withdrawal_reference, ''), t.tracking_code) ELSE t.tracking_code END"
                : (in_array('tracking_code', $columns, true) ? 't.tracking_code' : "''");
            $currencySelect = statement_detect_currency_column('transactions', $columns, 't');
            $rechargeJoin = '';
            $rechargeSelect = ", '' AS recharge_trans_id, '' AS recharge_ref_code, '' AS recharge_error_code, '' AS recharge_error_msg";
            $rechargeColumns = statement_columns('mobile_recharges');
            if (in_array('idempotency_key', $columns, true) && in_array('order_id', $rechargeColumns, true)) {
                $rechargeJoin = ' LEFT JOIN mobile_recharges mr ON mr.order_id COLLATE utf8mb4_unicode_ci = t.idempotency_key COLLATE utf8mb4_unicode_ci AND t.type = \'topup\'';
                $rechargeSelect = ', '
                    . (in_array('trans_id', $rechargeColumns, true) ? "COALESCE(mr.trans_id, '')" : "''") . ' AS recharge_trans_id, '
                    . (in_array('ref_code', $rechargeColumns, true) ? "COALESCE(mr.ref_code, '')" : "''") . ' AS recharge_ref_code, '
                    . (in_array('error_code', $rechargeColumns, true) ? "COALESCE(mr.error_code, '')" : "''") . ' AS recharge_error_code, '
                    . (in_array('error_msg', $rechargeColumns, true) ? "COALESCE(mr.error_msg, '')" : "''") . ' AS recharge_error_msg';
            }
            $feeReady = statement_has_columns('service_fee_transactions', ['source_type', 'source_id', 'fee_amount', 'total_amount']);
            $feeSelect = $feeReady ? ', COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, t.amount) AS total_amount' : ', 0 AS fee_amount, t.amount AS total_amount';
            $feeJoin = $feeReady ? " LEFT JOIN service_fee_transactions sft ON sft.source_type COLLATE utf8mb4_unicode_ci = CASE WHEN t.type IN ('withdraw', 'withdrawal') THEN 'withdrawal' ELSE t.type END COLLATE utf8mb4_unicode_ci AND sft.source_id = t.id" : '';
            $stmt = db()->prepare("SELECT t.id, t.amount, t.type, t.status, t.created_at, {$currencySelect} AS currency, {$descriptionSelect} AS description, {$trackingSelect} AS reference, {$balanceBeforeSelect}, {$balanceAfterSelect}, {$adminIdSelect}, {$providerReferenceSelect}{$feeSelect}{$rechargeSelect} FROM transactions t{$feeJoin}{$rechargeJoin} WHERE t.user_id = ? ORDER BY t.created_at ASC, t.id ASC");
            $stmt->execute([$userId]);
            $sourceRows = $stmt->fetchAll();
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' transactions rows=' . count($sourceRows));
            foreach ($sourceRows as $row) {
                $type = strtolower(trim((string)($row['type'] ?? '')));
                if ($type === 'remittance') {
                    continue;
                }
                $status = strtolower(trim((string)($row['status'] ?? '')));
                $currency = statement_currency_code($row['currency'] ?? 'IRT');
                $amount = in_array($type, ['withdraw', 'withdrawal'], true) ? (float)($row['total_amount'] ?? $row['amount'] ?? 0) : (float)($row['amount'] ?? 0);
                $direction = 'neutral';
                if (in_array($type, ['deposit', 'manual_deposit'], true) && $status === 'approved') {
                    $direction = 'credit';
                } elseif ($type === 'refund' && in_array($status, ['approved', 'paid'], true)) {
                    $direction = 'credit';
                } elseif (in_array($type, ['withdraw', 'withdrawal', 'manual_withdrawal', 'topup'], true) && !in_array($status, ['rejected', 'failed', 'refunded'], true)) {
                    $direction = 'debit';
                }
                $effect = match ($direction) {
                    'credit' => $amount,
                    'debit' => -$amount,
                    default => 0.0,
                };
                if ((in_array($type, ['withdraw', 'withdrawal'], true) && $status === 'rejected')
                    || ($type === 'topup' && in_array($status, ['failed', 'refunded'], true))) {
                    $direction = 'neutral';
                    $effect = 0.0;
                }
                $feeAmount = (float)($row['fee_amount'] ?? 0);
                $description = statement_normalized_description($type, (string)($row['description'] ?? ''));
                if ($feeAmount > 0 && in_array($type, ['withdraw', 'withdrawal'], true)) {
                    $description .= ' / کارمزد: ' . statement_money($feeAmount, $currency);
                }
                if ($type === 'topup' && (string)($row['provider_reference'] ?? '') !== '') {
                    $description .= ' / مرجع سرویس: ' . trim((string)$row['provider_reference']);
                }
                if ($type === 'topup' && trim((string)($row['recharge_error_code'] ?? '')) !== '') {
                    $description .= ' / کد خطای شارژ: ' . trim((string)$row['recharge_error_code']);
                }
                if ($type === 'topup' && trim((string)($row['recharge_error_msg'] ?? '')) !== '') {
                    $description .= ' / شرح خطای شارژ: ' . trim((string)$row['recharge_error_msg']);
                }
                $adminId = (int)($row['admin_id'] ?? 0);
                if (in_array($type, ['manual_deposit', 'manual_withdrawal'], true) && $adminId > 0) {
                    $description .= ' / مدیر #' . $adminId;
                }
                $topupReference = trim((string)($row['recharge_ref_code'] ?? '')) ?: trim((string)($row['recharge_trans_id'] ?? ''));
                statement_add_row($rows, [
                    'date' => (string)($row['created_at'] ?? ''),
                    'sort_id' => (int)$row['id'],
                    'type' => $type,
                    'currency' => $currency,
                    'description' => $description,
                    'reference' => trim((string)($row['reference'] ?? '')) ?: ('TX-' . (int)$row['id']),
                    'direction' => $direction,
                    'amount' => (string)(in_array($type, ['withdraw', 'withdrawal'], true)
                        ? ($row['total_amount'] ?? $row['amount'] ?? '0')
                        : ($row['amount'] ?? '0')),
                    'effect' => $effect,
                    'balance_before' => $row['balance_before'] === null ? null : (string)$row['balance_before'],
                    'balance_after' => ($type === 'topup' && in_array($status, ['failed', 'refunded'], true)) || $row['balance_after'] === null
                        ? null
                        : (string)$row['balance_after'],
                    'status' => $status,
                    'reference' => trim((string)($row['reference'] ?? ''))
                        ?: (trim((string)($row['provider_reference'] ?? '')) ?: ($topupReference !== '' ? $topupReference : ('TX-' . (int)$row['id']))),
                    'source_rank' => 20,
                ]);
            }
        } else {
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' transactions rows=0 schema_incomplete=true');
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' transactions rows=0 error=' . $e->getMessage());
    }

    try {
        if (statement_has_columns('remittances', ['id', 'user_id', 'amount_toman', 'status', 'created_at'])) {
            $feeReady = statement_has_columns('service_fee_transactions', ['source_type', 'source_id', 'fee_amount', 'total_amount']);
            $feeSelect = $feeReady ? 'COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, r.amount_toman) AS total_amount' : '0 AS fee_amount, r.amount_toman AS total_amount';
            $feeJoin = $feeReady ? "LEFT JOIN service_fee_transactions sft ON sft.source_type = 'remittance' AND sft.source_id = r.id" : '';
            $remittanceColumns = statement_columns('remittances');
            $descriptionParts = [];
            foreach (['agency', 'destination_country', 'sender', 'receiver'] as $column) {
                if (in_array($column, $remittanceColumns, true)) {
                    $descriptionParts[] = $column;
                }
            }
            $remittanceDescriptionSelect = in_array('description', $remittanceColumns, true) ? "COALESCE(r.description, '') AS description" : "'' AS description";
            $sourceCurrencySelect = in_array('source_currency', $remittanceColumns, true) ? "COALESCE(NULLIF(r.source_currency, ''), 'IRT') AS source_currency" : "'IRT' AS source_currency";
            $destinationCurrencySelect = in_array('destination_currency', $remittanceColumns, true) ? "COALESCE(NULLIF(r.destination_currency, ''), 'AFN') AS destination_currency" : "'AFN' AS destination_currency";
            $destinationAmountSelect = in_array('amount_afghani', $remittanceColumns, true) ? 'r.amount_afghani' : 'NULL AS amount_afghani';
            $selectParts = ['r.id', 'r.amount_toman', 'r.status', 'r.created_at', $feeSelect, $sourceCurrencySelect, $destinationCurrencySelect, $destinationAmountSelect];
            $selectParts[] = $remittanceDescriptionSelect;
            foreach ($descriptionParts as $column) {
                $selectParts[] = 'r.' . $column;
            }
            $stmt = db()->prepare('SELECT ' . implode(', ', $selectParts) . " FROM remittances r {$feeJoin} WHERE r.user_id = ? ORDER BY r.created_at ASC, r.id ASC");
            $stmt->execute([$userId]);
            $sourceRows = $stmt->fetchAll();
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' remittances rows=' . count($sourceRows));
            foreach ($sourceRows as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                $principalAmount = (float)($row['amount_toman'] ?? 0);
                $totalAmount = (float)($row['total_amount'] ?? $principalAmount);
                $feeAmount = (float)($row['fee_amount'] ?? max(0, $totalAmount - $principalAmount));
                $sourceCurrency = statement_currency_code($row['source_currency'] ?? 'IRT');
                $destinationCurrency = statement_currency_code($row['destination_currency'] ?? 'AFN');
                $reference = 'REM-' . (int)$row['id'];
                $parts = array_filter([
                    isset($row['agency']) ? 'نمایندگی: ' . trim((string)$row['agency']) : '',
                    isset($row['destination_country']) ? 'کشور مقصد: ' . (remittance_destination_countries()[(string)$row['destination_country']] ?? '') : '',
                    isset($row['sender']) ? 'فرستنده: ' . trim((string)$row['sender']) : '',
                    isset($row['receiver']) ? 'گیرنده: ' . trim((string)$row['receiver']) : '',
                    'مبلغ اصلی حواله: ' . statement_money($principalAmount, $sourceCurrency),
                    'مبلغ پرداختی به مقصد: ' . statement_money((float)($row['amount_afghani'] ?? 0), $destinationCurrency),
                    'کارمزد خدمات: ' . statement_money($feeAmount, $sourceCurrency),
                    'جمع کسر از کیف پول: ' . statement_money($totalAmount, $sourceCurrency),
                    'وضعیت: ' . statement_status_fa($status),
                    'کد رهگیری: ' . $reference,
                ]);
                $description = statement_normalized_description('remittance', (string)($row['description'] ?? ''));
                if ($parts !== []) {
                    $description .= ($description !== '' ? ' / ' : '') . implode(' / ', $parts);
                }
                $direction = in_array($status, ['rejected', 'cancelled', 'canceled'], true) ? 'neutral' : 'debit';
                statement_add_row($rows, [
                    'date' => (string)($row['created_at'] ?? ''),
                    'sort_id' => (int)$row['id'],
                    'type' => 'remittance',
                    'currency' => $sourceCurrency,
                    'description' => $description,
                    'reference' => $reference,
                    'direction' => $direction,
                    'amount' => (string)($row['total_amount'] ?? $row['amount_toman'] ?? '0'),
                    'effect' => $direction === 'debit' ? -$totalAmount : 0.0,
                    'status' => $status,
                    'source_rank' => 30,
                ]);
                $destinationAmount = (float)($row['amount_afghani'] ?? 0);
                if ($destinationAmount > 0) {
                    statement_add_row($rows, [
                        'date' => (string)($row['created_at'] ?? ''),
                        'sort_id' => (int)$row['id'],
                        'type' => 'remittance_destination',
                        'currency' => $destinationCurrency,
                        'description' => 'مبلغ تحویلی به گیرنده؛ ردیف اطلاعاتی است و موجودی کیف پول کاربر را تغییر نمی‌دهد.',
                        'reference' => $reference . '-DEST',
                        'direction' => 'neutral',
                        'amount' => (string)($row['amount_afghani'] ?? '0'),
                        'effect' => 0.0,
                        'status' => $status,
                        'source_rank' => 31,
                    ]);
                }
            }
        } else {
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' remittances rows=0 schema_incomplete=true');
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' remittances rows=0 error=' . $e->getMessage());
    }

    try {
        if (statement_has_columns('user_debt_payments', ['id', 'user_id', 'amount', 'created_at'])) {
            $columns = statement_columns('user_debt_payments');
            $sourceSelect = in_array('source_transaction_id', $columns, true) ? 'source_transaction_id' : 'NULL AS source_transaction_id';
            $sourceTypeSelect = in_array('source_type', $columns, true) ? 'source_type' : "'' AS source_type";
            $debtSelect = in_array('debt_id', $columns, true) ? 'debt_id' : 'NULL AS debt_id';
            $currencySelect = statement_detect_currency_column('user_debt_payments', $columns);
            $paymentHasCurrency = count(array_intersect(['currency_code', 'currency', 'asset_currency', 'currency_type', 'source_currency'], $columns)) > 0;
            $stmt = db()->prepare("SELECT id, amount, created_at, {$sourceSelect}, {$sourceTypeSelect}, {$debtSelect}, {$currencySelect} AS currency FROM user_debt_payments WHERE user_id = ? ORDER BY created_at ASC, id ASC");
            $stmt->execute([$userId]);
            $sourceRows = $stmt->fetchAll();
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt payment rows=' . count($sourceRows));
            foreach ($sourceRows as $row) {
                $reference = $row['source_transaction_id'] ? (string)$row['source_transaction_id'] : ('DEBT-PAY-' . (int)$row['id']);
                $currency = statement_currency_code($paymentHasCurrency ? ($row['currency'] ?? 'IRT') : ($debtCurrencies[(int)($row['debt_id'] ?? 0)] ?? 'IRT'));
                statement_add_row($rows, [
                    'date' => (string)($row['created_at'] ?? ''),
                    'sort_id' => (int)$row['id'],
                    'type' => 'debt_payment',
                    'currency' => $currency,
                    'description' => statement_normalized_description('debt_payment', null, [
                        'تسویه بدهی' . ($row['debt_id'] ? ' #' . (int)$row['debt_id'] : ''),
                        trim((string)($row['source_type'] ?? '')),
                    ]),
                    'reference' => $reference,
                    'direction' => 'credit',
                    'amount' => (string)($row['amount'] ?? '0'),
                    'effect' => 0.0,
                    'status' => 'approved',
                    'source_rank' => 40,
                ]);
            }
        } else {
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt payment rows=0 schema_incomplete=true');
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' debt payment rows=0 error=' . $e->getMessage());
    }

    try {
        if (statement_has_columns('service_fee_transactions', ['id', 'user_id', 'source_type', 'source_id', 'fee_amount', 'status', 'created_at'])) {
            $feeColumns = statement_columns('service_fee_transactions');
            $feeCurrencySelect = statement_detect_currency_column('service_fee_transactions', $feeColumns);
            $stmt = db()->prepare("SELECT id, source_type, source_id, fee_amount, status, created_at, {$feeCurrencySelect} AS currency FROM service_fee_transactions WHERE user_id = ? AND fee_amount > 0 ORDER BY created_at ASC, id ASC");
            $stmt->execute([$userId]);
            $sourceRows = $stmt->fetchAll();
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' fee rows=' . count($sourceRows));
            foreach ($sourceRows as $row) {
                $currency = statement_currency_code($row['currency'] ?? 'IRT');
                statement_add_row($rows, [
                    'date' => (string)($row['created_at'] ?? ''),
                    'sort_id' => (int)$row['id'],
                    'type' => 'service_fee',
                    'currency' => $currency,
                    'description' => statement_normalized_description('service_fee', null, [
                        'کارمزد ' . statement_type_fa((string)($row['source_type'] ?? '')),
                        statement_status_fa((string)($row['status'] ?? '')),
                    ]),
                    'reference' => strtoupper((string)($row['source_type'] ?? 'FEE')) . '-' . (int)($row['source_id'] ?? 0),
                    'direction' => 'neutral',
                    'amount' => (string)($row['fee_amount'] ?? '0'),
                    'effect' => 0.0,
                    'status' => (string)($row['status'] ?? ''),
                    'source_rank' => 50,
                ]);
            }
        } else {
            error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' fee rows=0 schema_incomplete=true');
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' fee rows=0 error=' . $e->getMessage());
    }

    try {
        if (statement_has_columns('user_fee_share_transactions', ['id', 'parent_user_id', 'share_amount', 'status', 'created_at'])) {
            $shareColumns = statement_columns('user_fee_share_transactions');
            $shareCurrencySelect = statement_detect_currency_column('user_fee_share_transactions', $shareColumns);
            $stmt = db()->prepare("SELECT id, share_amount, status, created_at, {$shareCurrencySelect} AS currency FROM user_fee_share_transactions WHERE parent_user_id = ? AND status = 'paid' ORDER BY created_at ASC, id ASC");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $row) {
                $currency = statement_currency_code($row['currency'] ?? 'IRT');
                statement_add_row($rows, [
                    'date' => (string)$row['created_at'],
                    'sort_id' => (int)$row['id'],
                    'type' => 'fee_share',
                    'currency' => $currency,
                    'description' => statement_default_description('fee_share'),
                    'reference' => 'FEE-SHARE-' . (int)$row['id'],
                    'direction' => 'credit',
                    'amount' => (string)$row['share_amount'],
                    'effect' => (float)$row['share_amount'],
                    'status' => (string)$row['status'],
                    'source_rank' => 60,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' fee share rows unavailable');
    }

    usort($rows, static function (array $left, array $right): int {
        $dateCompare = strcmp((string)$left['date'], (string)$right['date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        $rankCompare = ((int)$left['source_rank']) <=> ((int)$right['source_rank']);
        return $rankCompare !== 0 ? $rankCompare : (((int)$left['sort_id']) <=> ((int)$right['sort_id']));
    });

    error_log('[USER_STATEMENT_DEBUG] user_id=' . $userId . ' final normalized rows=' . count($rows));
    return $rows;
}

function statement_filtered_report(array $user, ?string $from, ?string $to, ?string $currencyFilter = null): array
{
    $userId = (int)($user['id'] ?? 0);

    try {
        $allRows = statement_build_rows($userId);
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user found=true report_rows_loaded=false user_id=' . $userId . ' message=' . $e->getMessage());
        $allRows = [];
    }
    $walletBalances = statement_user_wallet_balances($user);
    $currentBalance = (float)($walletBalances['IRT'] ?? 0);
    try {
        $debtsByCurrency = statement_outstanding_debts_by_currency($userId);
    } catch (Throwable $e) {
        error_log('[USER_STATEMENT_DEBUG] user found=true debt_lookup_loaded=false user_id=' . $userId . ' message=' . $e->getMessage());
        $debtsByCurrency = [];
    }
    $outstandingDebt = (float)($debtsByCurrency['IRT'] ?? 0);
    $netBalance = $currentBalance - $outstandingDebt;

    $currencies = ['IRT' => true];
    foreach (array_keys($walletBalances) as $code) $currencies[statement_currency_code($code)] = true;
    foreach (array_keys($debtsByCurrency) as $code) $currencies[statement_currency_code($code)] = true;
    foreach ($allRows as $row) $currencies[statement_currency_code($row['currency'] ?? 'IRT')] = true;
    $activityByCurrency = [];
    foreach ($allRows as $row) {
        $code = statement_currency_code($row['currency'] ?? 'IRT');
        $activityByCurrency[$code] ??= ['debit' => 0.0, 'credit' => 0.0, 'informational' => 0.0, 'count' => 0];
        $activityByCurrency[$code]['count']++;
        if (in_array((string)$row['direction'], ['debit', 'credit'], true)) {
            $activityByCurrency[$code][(string)$row['direction']] += (float)$row['amount'];
        } else {
            $activityByCurrency[$code]['informational'] += (float)$row['amount'];
        }
    }
    $currencySummaries = [];
    foreach (array_keys($currencies) as $code) {
        $code = statement_currency_code($code);
        $walletTracked = array_key_exists($code, $walletBalances);
        $wallet = $walletTracked ? (float)$walletBalances[$code] : null;
        $debt = (float)($debtsByCurrency[$code] ?? 0);
        $currencySummaries[$code] = [
            'code' => $code,
            'name' => statement_currency_name($code),
            'wallet_tracked' => $walletTracked,
            'wallet_balance' => $wallet,
            'outstanding_debt' => $debt,
            'net_balance' => ($walletTracked || $debt > 0) ? (float)($wallet ?? 0) - $debt : null,
            'activity' => $activityByCurrency[$code] ?? ['debit' => 0.0, 'credit' => 0.0, 'informational' => 0.0, 'count' => 0],
        ];
    }

    $allRows = statement_assign_running_balances($allRows, $currencySummaries);

    $fromSql = statement_date_sql($from, false);
    $toSql = statement_date_sql($to, true);
    $filteredRows = array_values(array_filter($allRows, static function (array $row) use ($fromSql, $toSql): bool {
        $date = (string)($row['date'] ?? '');
        if ($fromSql !== null && $date < $fromSql) {
            return false;
        }
        if ($toSql !== null && $date > $toSql) {
            return false;
        }
        return true;
    }));
    if ($currencyFilter !== null && $currencyFilter !== '') {
        $currencyFilter = statement_currency_code($currencyFilter);
        $filteredRows = array_values(array_filter($filteredRows, static fn(array $row): bool => statement_currency_code($row['currency'] ?? 'IRT') === $currencyFilter));
    }

    return [
        'user' => $user,
        'rows' => $filteredRows,
        'summary' => [
            'final_balance' => $netBalance,
            'current_net_balance' => $netBalance,
            'wallet_balance' => $currentBalance,
            'outstanding_debt' => $outstandingDebt,
            'currencies' => $currencySummaries,
            'wallet_balances' => $walletBalances,
            'debts_by_currency' => $debtsByCurrency,
            'row_count' => count($filteredRows),
        ],
    ];
}

function statement_export_rows(array $rows, int $offset = 0): array
{
    $out = [];
    $i = $offset + 1;
    foreach ($rows as $row) {
        $out[] = [
            (string)$i++,
            to_jalali_datetime((string)$row['date']),
            statement_type_fa((string)$row['type']),
            (string)$row['description'],
            statement_money((float)$row['amount'], statement_currency_code($row['currency'] ?? 'IRT')),
            statement_currency_code($row['currency'] ?? 'IRT'),
            statement_direction_fa((string)$row['direction']),
            statement_status_fa((string)$row['status']),
            empty($row['balance_tracked']) ? '—' : statement_money((float)($row['running_balance_before'] ?? 0), statement_currency_code($row['currency'] ?? 'IRT')),
            empty($row['balance_tracked']) ? '—' : statement_money((float)($row['running_balance'] ?? 0), statement_currency_code($row['currency'] ?? 'IRT')),
            (string)$row['reference'],
        ];
    }
    return $out;
}

function statement_render_print(array $report, ?string $from, ?string $to): void
{
    $user = $report['user'];
    $summary = $report['summary'];
    $final = (float)$summary['final_balance'];
    $finalClass = $final > 0 ? 'positive' : ($final < 0 ? 'negative' : 'zero');
    $finalLabel = $final > 0 ? 'بستانکار' : ($final < 0 ? 'بدهکار' : 'تسویه');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>صورتحساب کاربر</title>
        <style>
            @font-face{font-family:'StatementVazirmatn';src:url('fonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;font-style:normal;font-display:swap}
            @font-face{font-family:'StatementVazirmatn';src:url('fonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;font-style:normal;font-display:swap}
            @page { size: A4 landscape; margin: 10mm; @bottom-center { content: "صفحه " counter(page) " از " counter(pages); } }
            body { margin:0; color:#111827; background:#f8fafc; font-family:'StatementVazirmatn', Tahoma, Arial, sans-serif; direction:rtl; }
            .sheet { max-width:210mm; margin:0 auto; background:#fff; border:1px solid #dbe4ef; border-radius:10px; padding:12mm; box-sizing:border-box; }
            .sheet * { font-family:'StatementVazirmatn', Tahoma, Arial, sans-serif; box-sizing:border-box; }
            .brand { display:flex; align-items:center; justify-content:space-between; gap:12px; border-bottom:2px solid #0f766e; padding-bottom:12px; margin-bottom:14px; }
            .brand-main { display:flex; align-items:center; gap:12px; }
            .logo { width:54px; height:54px; object-fit:contain; }
            .brand h1 { margin:0; color:#0f766e; font-size:24px; }
            .brand p { margin:3px 0 0; color:#475569; font-size:12px; }
            .title { text-align:center; font-size:18px; font-weight:900; margin:10px 0 14px; }
            .info, .summary { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:12px; }
            .box { border:1px solid #dbe4ef; padding:8px; border-radius:6px; min-height:42px; }
            .label { display:block; color:#64748b; font-size:11px; margin-bottom:4px; }
            .value { color:#111827; font-weight:800; font-size:12px; }
            .positive { color:#047857; }
            .negative { color:#b91c1c; }
            .zero { color:#475569; }
            table { width:100%; border-collapse:collapse; font-size:10.5px; }
            thead { display:table-header-group; }
            tfoot { display:table-footer-group; }
            th, td { border:1px solid #d1d5db; padding:6px; text-align:right; vertical-align:top; }
            th { background:#f1f5f9; color:#334155; font-weight:900; }
            tr { page-break-inside:avoid; }
            .final { margin-top:12px; border:2px solid #dbe4ef; padding:10px; border-radius:6px; font-size:14px; font-weight:900; text-align:left; }
            .actions { margin:12px 0; text-align:left; }
            .actions button { border:0; background:#0f766e; color:#fff; padding:9px 14px; border-radius:8px; font-family:'StatementVazirmatn', Tahoma, Arial, sans-serif; cursor:pointer; }
            @media print {
                body { background:#fff; }
                .sheet { border:none; border-radius:0; padding:0; }
                .actions { display:none; }
                .page-number:after { content: counter(page); }
            }
        </style>
    </head>
    <body>
        <div class="sheet">
            <div class="actions"><button type="button" onclick="window.print()">چاپ یا ذخیره PDF</button></div>
            <div class="brand">
                <div class="brand-main">
                    <img class="logo" src="../assets/images/logo.png" alt="AFARIEX">
                    <div>
                        <h1>افارایکس</h1>
                        <p>خدمات آنلاین افارایکس</p>
                    </div>
                </div>
                <div><strong>واتساپ:</strong> <?= e(AFARIEX_STATEMENT_WHATSAPP) ?></div>
            </div>
            <div class="title">صورتحساب کاربر</div>
            <div class="info">
                <div class="box"><span class="label">کاربر</span><span class="value"><?= e(trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')) ?: (string)$user['mobile']) ?></span></div>
                <div class="box"><span class="label">موبایل</span><span class="value" dir="ltr"><?= e((string)$user['mobile']) ?></span></div>
                <div class="box"><span class="label">تاریخ صدور</span><span class="value"><?= e(to_jalali_datetime(date('Y-m-d H:i:s'))) ?></span></div>
                <div class="box"><span class="label">بازه گزارش</span><span class="value"><?= e(($from ?: 'ابتدا') . ' تا ' . ($to ?: 'امروز')) ?></span></div>
            </div>
            <div class="summary">
                <div class="box"><span class="label">ردیف‌های فعالیت در گزارش</span><span class="value"><?= e(number_format((int)$summary['row_count'])) ?></span></div>
                <?php foreach ($summary['currencies'] as $currencySummary): ?>
                    <div class="box"><span class="label"><?= e((string)$currencySummary['name']) ?> (<?= e((string)$currencySummary['code']) ?>) — موجودی کیف پول</span><span class="value"><?= $currencySummary['wallet_tracked'] ? e(statement_money((float)$currencySummary['wallet_balance'], (string)$currencySummary['code'])) : 'در کیف پول ذخیره نمی‌شود' ?></span></div>
                    <div class="box"><span class="label">بدهی باز</span><span class="value negative"><?= e(statement_money((float)$currencySummary['outstanding_debt'], (string)$currencySummary['code'])) ?></span></div>
                    <div class="box"><span class="label">موقعیت خالص</span><span class="value <?= (float)($currencySummary['net_balance'] ?? 0) > 0 ? 'positive' : ((float)($currencySummary['net_balance'] ?? 0) < 0 ? 'negative' : 'zero') ?>"><?= $currencySummary['net_balance'] === null ? 'اطلاعات مانده ثبت نشده' : e(statement_money((float)$currencySummary['net_balance'], (string)$currencySummary['code'])) ?></span></div>
                <?php endforeach; ?>
            </div>
            <table>
                <thead>
                    <tr><th>ردیف</th><th>تاریخ</th><th>نوع فعالیت</th><th>شرح فعالیت</th><th>مبلغ</th><th>ارز</th><th>جهت</th><th>وضعیت</th><th>مانده قبل از تراکنش</th><th>مانده پس از تراکنش</th><th>کد رهگیری</th></tr>
                </thead>
                <tbody>
                    <?php foreach (statement_export_rows($report['rows']) as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?= e((string)$cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$report['rows']): ?>
                        <tr><td colspan="11" style="text-align:center;color:#64748b;padding:18px;">فعالیت مالی برای این کاربر ثبت نشده است.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="final <?= e($finalClass) ?>">موقعیت خالص فعلی IRT (تومان): <?= $final == 0.0 ? 'تسویه' : e(statement_money($final, 'IRT') . ' - ' . $finalLabel) ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$currencyFilter = strtoupper(trim((string)($_GET['currency'] ?? '')));
$export = trim((string)($_GET['export'] ?? ''));

error_log('[USER_STATEMENT_DEBUG] received user_id=' . $userId);

if ($userId <= 0) {
    flash('error', 'کاربر معتبر نیست.');
    header('Location: users.php');
    exit;
}

try {
    error_log('[USER_STATEMENT_DEBUG] lookup SQL started user_id=' . $userId);
    $userColumns = statement_columns('users');
    $userSelectColumns = array_values(array_intersect(['id', 'mobile', 'first_name', 'last_name', 'balance'], $userColumns));
    foreach ($userColumns as $column) {
        if (preg_match('/^(?:wallet_)?balance_[a-z]{3,8}$/i', $column) === 1 || preg_match('/^[a-z]{3,8}_balance$/i', $column) === 1) {
            $userSelectColumns[] = $column;
        }
    }
    $userSelectColumns = array_values(array_unique($userSelectColumns));
    $userSelectSql = implode(', ', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $userSelectColumns));
    $userStmt = db()->prepare('SELECT ' . $userSelectSql . ' FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $statementUser = $userStmt->fetch();
    error_log('[USER_STATEMENT_DEBUG] user found=' . ($statementUser ? 'true' : 'false') . ' user_id=' . $userId);
    if (!$statementUser) {
        flash('error', 'کاربر مورد نظر یافت نشد.');
        header('Location: users.php');
        exit;
    }

    $report = statement_filtered_report($statementUser, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null, $currencyFilter !== '' ? $currencyFilter : null);
} catch (Throwable $e) {
    error_log('[USER_STATEMENT_DEBUG] statement load failed user_id=' . $userId . ' message=' . $e->getMessage());
    flash('error', 'بارگذاری صورتحساب انجام نشد.');
    header('Location: users.php');
    exit;
}

if ($export === 'excel') {
    statement_render_xlsx($report, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null);
}
if ($export === 'print') {
    statement_render_print($report, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null);
}

$pageData = statement_page_rows($report['rows'], (int)($_GET['page'] ?? 1), 25);
$pageRows = $pageData['rows'];
$user = $report['user'];
$summary = $report['summary'];
$final = (float)$summary['final_balance'];
$finalClass = $final > 0 ? 'positive' : ($final < 0 ? 'negative' : 'zero');
$finalLabel = $final > 0 ? 'بستانکار' : ($final < 0 ? 'بدهکار' : 'تسویه');

render_page_start('صورتحساب کاربر', 'users');
?>
<style>
  .statement-report { max-width:1180px;margin:0 auto;background:#fff;border:1px solid #dbe4ef;border-radius:14px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.06);direction:rtl;font-family:'Vazirmatn',Tahoma,sans-serif;box-sizing:border-box; }
  .statement-report * { font-family:'Vazirmatn',Tahoma,sans-serif;box-sizing:border-box; }
  .statement-brand { display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:16px; }
  .statement-brand-main { display:flex;align-items:center;gap:12px; }
  .statement-logo { width:52px;height:52px;object-fit:contain; }
  .statement-brand-title { margin:0;color:#0f766e;font-size:22px;font-weight:900; }
  .statement-brand-subtitle { margin:2px 0 0;color:#64748b;font-size:12px;font-weight:700; }
  .statement-brand-contact { color:#334155;font-weight:800;white-space:nowrap; }
  .statement-title { font-size:18px;font-weight:900;text-align:center;color:#0f172a;margin:0 0 16px; }
  .statement-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px; }
  .statement-currency-table { width:100%;min-width:760px;border-collapse:collapse;margin:10px 0 18px; }
  .statement-currency-table th,.statement-currency-table td { padding:11px 13px;border-bottom:1px solid #e2e8f0;text-align:right; }
  .statement-currency-table th { color:#475569;background:#f8fafc; }
  .statement-currency-note { color:#64748b;font-size:12px;margin:8px 0; }
  .statement-card { background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px; }
  .statement-label { color:#64748b;font-size:12px;margin-bottom:6px; }
  .statement-value { color:#0f172a;font-weight:900;font-size:16px; }
  .statement-value.positive { color:#047857; }
  .statement-value.negative { color:#b91c1c; }
  .statement-value.zero { color:#475569; }
  .statement-filter { display:flex;gap:12px;flex-wrap:wrap;align-items:center;overflow:visible!important; }
  .statement-final { display:inline-flex;padding:9px 12px;border-radius:10px;border:1px solid #e2e8f0;font-weight:900; }
  .statement-final.positive { color:#047857;background:#ecfdf5;border-color:#a7f3d0; }
  .statement-final.negative { color:#b91c1c;background:#fef2f2;border-color:#fecaca; }
  .statement-final.zero { color:#475569;background:#f8fafc; }
  .statement-report .table-wrap { overflow-x:auto; }
  .statement-report table { min-width:1120px; }
  .statement-pagination { display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:14px; }
  .statement-pagination a,.statement-pagination span { min-width:36px;padding:7px 10px;text-align:center;border:1px solid #dbe4ef;border-radius:8px;text-decoration:none; }
  .statement-pagination .active { background:#0f766e;color:#fff;border-color:#0f766e; }
  @media(max-width:760px){.statement-report{padding:12px;border-radius:10px}.statement-brand{align-items:flex-start;flex-direction:column}.statement-brand-contact{white-space:normal}.statement-filter .filter-input{width:100%!important;max-width:none!important}.statement-filter .btn{width:100%;justify-content:center}}
</style>

<div class="card">
  <div class="actions" style="justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h3 style="margin:0;">صورتحساب کاربر</h3>
    <a class="btn btn-light" href="users.php">بازگشت به کاربران</a>
  </div>
</div>

<div class="statement-report">
  <div class="statement-brand">
    <div class="statement-brand-main">
      <img class="statement-logo" src="../assets/images/logo.png" alt="AFARIEX">
      <div>
        <h2 class="statement-brand-title">افارایکس</h2>
        <p class="statement-brand-subtitle">خدمات آنلاین افارایکس</p>
      </div>
    </div>
    <div class="statement-brand-contact">واتساپ: <?= e(AFARIEX_STATEMENT_WHATSAPP) ?></div>
  </div>

  <h3 class="statement-title">صورتحساب کاربر</h3>

  <div class="statement-summary">
    <div class="statement-card"><div class="statement-label">کاربر</div><div class="statement-value"><?= e(trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')) ?: (string)$user['mobile']) ?></div></div>
    <div class="statement-card"><div class="statement-label">موبایل</div><div class="statement-value" dir="ltr"><?= e((string)$user['mobile']) ?></div></div>
  </div>

  <h3 class="statement-title" style="text-align:right;margin-top:8px;">خلاصه مانده به تفکیک ارز</h3>
  <div class="table-wrap"><table class="statement-currency-table"><thead><tr><th>ارز</th><th>موجودی کیف پول</th><th>بدهی باز</th><th>موقعیت خالص</th><th>فعالیت ثبت‌شده</th></tr></thead><tbody>
    <?php foreach ($summary['currencies'] as $currencySummary): $currencyCode = (string)$currencySummary['code']; ?>
    <tr><td><strong><?= e((string)$currencySummary['name']) ?></strong><br><small dir="ltr"><?= e($currencyCode) ?></small></td><td><?= $currencySummary['wallet_tracked'] ? e(statement_money((float)$currencySummary['wallet_balance'], $currencyCode)) : '<span class="statement-currency-note">کیف پول مستقل ثبت نشده</span>' ?></td><td><?= e(statement_money((float)$currencySummary['outstanding_debt'], $currencyCode)) ?></td><td><?= $currencySummary['net_balance'] === null ? 'مانده قابل محاسبه نیست' : e(statement_money((float)$currencySummary['net_balance'], $currencyCode)) ?></td><td><?= e(number_format((int)$currencySummary['activity']['count'])) ?> ردیف</td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <p class="statement-currency-note">واحد مقصد حواله‌ها به‌عنوان اطلاعات پرداختی گیرنده نمایش داده می‌شود و مانده کیف پول محسوب نمی‌شود. برای ارزهایی که کیف پول مستقل ندارند، مانده ساختگی نمایش داده نمی‌شود.</p>

  <form method="get" class="statement-filter">
    <input type="hidden" name="user_id" value="<?= e((string)$userId) ?>">
    <input type="text" class="filter-input js-persian-date" name="start_date" value="<?= e($startDate) ?>" placeholder="از تاریخ..." aria-label="از تاریخ" style="width:200px;max-width:200px;">
    <input type="text" class="filter-input js-persian-date" name="end_date" value="<?= e($endDate) ?>" placeholder="تا تاریخ..." aria-label="تا تاریخ" style="width:200px;max-width:200px;">
    <select class="filter-input" name="currency" aria-label="فیلتر ارز"><option value="">همه ارزها</option><?php foreach ($summary['currencies'] as $currencySummary): ?><option value="<?= e((string)$currencySummary['code']) ?>" <?= $currencyFilter === (string)$currencySummary['code'] ? 'selected' : '' ?>><?= e((string)$currencySummary['name']) ?> (<?= e((string)$currencySummary['code']) ?>)</option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary" style="height:36px;">فیلتر</button>
    <a class="btn btn-light" href="user_statement.php?<?= e(http_build_query(array_filter(['user_id' => $userId, 'start_date' => $startDate, 'end_date' => $endDate, 'currency' => $currencyFilter, 'export' => 'excel'], static fn($value): bool => $value !== '' && $value !== null))) ?>" style="color:#2563eb;border-color:#bfdbfe;text-decoration:none;height:36px;display:inline-flex;align-items:center;">خروجی Excel (.xlsx)</a>
    <a class="btn btn-light" href="user_statement.php?<?= e(http_build_query(array_filter(['user_id' => $userId, 'start_date' => $startDate, 'end_date' => $endDate, 'currency' => $currencyFilter, 'export' => 'print'], static fn($value): bool => $value !== '' && $value !== null))) ?>" style="color:#7c3aed;border-color:#ddd6fe;text-decoration:none;height:36px;display:inline-flex;align-items:center;">چاپ / PDF</a>
  </form>

  <div class="actions" style="justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h3 style="margin:0;">جزئیات فعالیت مالی</h3>
    <span class="statement-final <?= e($finalClass) ?>">موقعیت خالص فعلی IRT: <?= $final == 0.0 ? 'تسویه' : e(statement_money($final, 'IRT') . ' - ' . $finalLabel) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ردیف</th><th>تاریخ</th><th>نوع فعالیت</th><th>شرح فعالیت</th><th>مبلغ</th><th>ارز</th><th>جهت</th><th>وضعیت</th><th>مانده قبل از تراکنش</th><th>مانده پس از تراکنش</th><th>کد رهگیری</th></tr></thead>
      <tbody>
      <?php $displayRows = statement_export_rows($pageRows, ($pageData['page'] - 1) * $pageData['page_size']); ?>
      <?php if (!$displayRows): ?>
        <tr><td colspan="11" style="text-align:center;color:#64748b;padding:20px;">فعالیت مالی برای این کاربر ثبت نشده است.</td></tr>
      <?php endif; ?>
      <?php foreach ($displayRows as $row): ?>
        <tr>
          <?php foreach ($row as $cell): ?>
            <td><?= e((string)$cell) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pageData['page_count'] > 1): ?>
    <nav class="statement-pagination" aria-label="صفحه‌بندی صورتحساب">
      <?php for ($pageNumber = 1; $pageNumber <= $pageData['page_count']; $pageNumber++): ?>
        <?php if ($pageNumber === $pageData['page']): ?>
          <span class="active" aria-current="page"><?= e((string)$pageNumber) ?></span>
        <?php else: ?>
          <a href="user_statement.php?<?= e(http_build_query(array_merge($_GET, ['page' => $pageNumber]))) ?>"><?= e((string)$pageNumber) ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </nav>
    <p style="text-align:center;color:#64748b;font-size:12px;">ردیف <?= e((string)(($pageData['page'] - 1) * $pageData['page_size'] + 1)) ?> تا <?= e((string)min($pageData['total_rows'], $pageData['page'] * $pageData['page_size'])) ?> از <?= e((string)$pageData['total_rows']) ?></p>
  <?php endif; ?>
</div>

<?php render_page_end(); ?>
