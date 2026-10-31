<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/topup_common.php';

topup_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    topup_json([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'روش درخواست مجاز نیست.'
    ], 405);
}

$data = topup_request_data();
$user = topup_authenticate_user($data);
$pdo = db();

topup_require_columns($pdo);
topup_require_recharge_columns($pdo);

$mobile = topup_normalize_mobile($data['phone'] ?? $data['mobile'] ?? '');
$clientRequestId = topup_validate_client_request_id($data['client_request_id'] ?? null);
$operator = strtoupper(trim((string)($data['operator'] ?? '')));
$chargeType = strtolower(trim((string)($data['charge_type'] ?? 'normal')));
$amount = normalize_persian_digits(trim((string)($data['amount'] ?? '')));
$amount = str_replace([',', '٬', ' '], '', $amount);

$rules = topup_operator_rules();

if ($mobile === null) {
    topup_json([
        'success' => false,
        'code' => 'INVALID_MOBILE',
        'message' => 'شماره موبایل باید به شکل ۰۹xxxxxxxxx باشد.'
    ], 422);
}

if ($clientRequestId === null) {
    topup_json([
        'success' => false,
        'code' => 'INVALID_CLIENT_REQUEST_ID',
        'message' => 'شناسه درخواست باید یک UUID نسخه ۴ معتبر باشد.',
    ], 422);
}

if (!isset($rules[$operator])) {
    topup_json([
        'success' => false,
        'code' => 'INVALID_OPERATOR',
        'message' => 'اپراتور انتخاب‌شده معتبر نیست.'
    ], 422);
}

if (!in_array($chargeType, ['normal', 'amazing', 'permanent'], true)) {
    topup_json([
        'success' => false,
        'code' => 'INVALID_CHARGE_TYPE',
        'message' => 'نوع شارژ انتخاب‌شده معتبر نیست.',
    ], 422);
}

if (!preg_match('/^\d+$/', $amount)) {
    topup_json([
        'success' => false,
        'code' => 'INVALID_AMOUNT',
        'message' => 'مبلغ شارژ معتبر نیست.'
    ], 422);
}

$amountInt = (int)$amount;

if (
    $amountInt < $rules[$operator]['min_amount']
    || $amountInt > $rules[$operator]['max_amount']
) {
    topup_json([
        'success' => false,
        'code' => 'AMOUNT_OUT_OF_RANGE',
        'message' => 'مبلغ برای اپراتور انتخاب‌شده مجاز نیست.',
        'data' => [
            'min_amount' => $rules[$operator]['min_amount'],
            'max_amount' => $rules[$operator]['max_amount']
        ]
    ], 422);
}

$existingRequest = topup_find_client_request($pdo, (int)$user['id'], $clientRequestId);
if ($existingRequest !== null) {
    topup_respond_existing_request($pdo, $existingRequest, $mobile, $operator, $amountInt, $chargeType);
}

try {
    service_fee_require_schema();
} catch (Throwable $error) {
    if ($error->getMessage() !== 'SERVICE_FEE_SCHEMA_MISSING') {
        error_log('Top-up commission configuration check failed: ' . $error->getMessage());
    }
    topup_json([
        'success' => false,
        'code' => 'TOPUP_COMMISSION_UNAVAILABLE',
        'message' => 'تنظیمات کارمزد شارژ آماده نیست. لطفاً با پشتیبانی تماس بگیرید.',
    ], 503);
}

$orderId = topup_order_id();

if (preg_match('/\A[0-9]+\z/', $orderId) !== 1) {
    error_log('Top-up order ID is not numeric.');
    topup_json([
        'success' => false,
        'code' => 'INVALID_ORDER_ID_FORMAT',
        'message' => 'ساخت شناسه سفارش با خطا روبه‌رو شد.'
    ], 500);
}

$transactionId = 0;
$wallet = null;
$commissionAmount = 0;
$totalCost = $amountInt;

try {
    $pdo->beginTransaction();

    $balanceStmt = $pdo->prepare(
        'SELECT balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $balanceStmt->execute([(int)$user['id']]);
    $lockedBalance = $balanceStmt->fetchColumn();

    if ($lockedBalance === false) {
        throw new WalletOperationException('USER_NOT_FOUND');
    }

    $existingRequest = topup_find_client_request($pdo, (int)$user['id'], $clientRequestId);
    if ($existingRequest !== null) {
        $pdo->rollBack();
        topup_respond_existing_request($pdo, $existingRequest, $mobile, $operator, $amountInt, $chargeType);
    }

    $commission = service_fee_calculate('mobile_recharge', $amountInt, $pdo);
    $commissionAmount = (int)$commission['fee_amount'];
    $totalCost = (int)$commission['total_amount'];

    if ((float)$lockedBalance < $totalCost) {
        throw new WalletInsufficientFundsException(
            (int)$user['id'],
            (float)$lockedBalance,
            (float)$lockedBalance,
            (float)$totalCost
        );
    }

    $wallet = wallet_debit(
        $pdo,
        (int)$user['id'],
        $totalCost,
        'Mobile Top-up',
        $orderId
    );

    $columns = $pdo
        ->query('SHOW COLUMNS FROM transactions')
        ->fetchAll(PDO::FETCH_COLUMN, 0);

    $insertColumns = [
        'user_id',
        'amount',
        'type',
        'status',
        'idempotency_key',
        'topup_mobile',
        'topup_operator',
        'client_request_id'
    ];

    $insertValues = [
        (int)$user['id'],
        $amountInt,
        'topup',
        'pending',
        $orderId,
        $mobile,
        $operator,
        $clientRequestId
    ];

    $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?'];

    if (in_array('description', $columns, true)) {
        $insertColumns[] = 'description';
        $placeholders[] = '?';
        $insertValues[] = 'درخواست خرید شارژ موبایل';
    }

    if (in_array('balance_before', $columns, true)) {
        $insertColumns[] = 'balance_before';
        $placeholders[] = '?';
        $insertValues[] = $wallet['balance_before'];
    }

    if (in_array('balance_after', $columns, true)) {
        $insertColumns[] = 'balance_after';
        $placeholders[] = '?';
        $insertValues[] = $wallet['balance_after'];
    }

    if (in_array('balance_applied', $columns, true)) {
        $insertColumns[] = 'balance_applied';
        $placeholders[] = '1';
    }

    $insertColumns[] = 'created_at';
    $placeholders[] = 'NOW()';

    $insert = $pdo->prepare(
        'INSERT INTO transactions ('
        . implode(', ', $insertColumns)
        . ') VALUES ('
        . implode(', ', $placeholders)
        . ')'
    );
    $insert->execute($insertValues);

    $transactionId = (int)$pdo->lastInsertId();

    service_fee_record(
        $pdo,
        (int)$user['id'],
        $commission,
        'topup',
        $transactionId,
        'pending'
    );

    /*
     * order_id و idempotency_key از ابتدا یکسان‌اند.
     * دیگر مقدار AFX-... ساخته یا در دیتابیس بازنویسی نمی‌شود.
     */
    $rechargeInsert = $pdo->prepare(
        "INSERT INTO mobile_recharges
        (user_id, mobile, operator, charge_type, amount, total_cost, order_id, status, client_request_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    $rechargeInsert->execute([
        (int)$user['id'],
        $mobile,
        $operator,
        $chargeType,
        $amountInt,
        $totalCost,
        $orderId,
        $clientRequestId
    ]);

    $pdo->commit();
    topup_log_lifecycle('reserved', $orderId, $transactionId, null, 'pending');
} catch (WalletInsufficientFundsException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    topup_json([
        'success' => false,
        'code' => 'INSUFFICIENT_BALANCE',
        'message' => 'موجودی کیف پول برای مبلغ شارژ و کارمزد کافی نیست.',
        'data' => [
            'amount' => $amountInt,
            'commission_amount' => $commissionAmount,
            'total_amount' => $totalCost,
            'balance' => (float)$lockedBalance,
        ],
    ], 422);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Top-up reservation failed: ' . $error->getMessage());

    topup_json([
        'success' => false,
        'code' => 'TOPUP_RESERVATION_FAILED',
        'message' => 'ثبت درخواست خرید شارژ انجام نشد.'
    ], 500);
}




try {
    // Inax expects amount in Toman (IRT), same as wallet
    $amountProvider = $amountInt;

    // Keep processing after a client disconnect; the provider outcome may still need local finalization.
    ignore_user_abort(true);
    $providerResponse = (new InaxService())->buyTopup(
        $mobile,
        $amountProvider,
        topup_provider_operator_code($operator),
        $chargeType,
        $orderId,
        null
    );

    $providerCode = topup_provider_code($providerResponse);
    $providerMessage = trim((string)($providerResponse['msg'] ?? ''));

    if ($providerCode === 1) {
        $finalStatus = topup_update_records(
            $pdo,
            $transactionId,
            $orderId,
            [
                'status' => 'success',
                'provider_ref_code' => trim(
                    (string)($providerResponse['ref_code'] ?? '')
                )
            ],
            [
                'status' => 'completed',
                'trans_id' => (string)($providerResponse['trans_id'] ?? ''),
                'ref_code' => (string)($providerResponse['ref_code'] ?? ''),
                'raw_response' => $providerResponse,
                'error_code' => null,
                'error_msg' => null
            ]
        );
        topup_log_lifecycle('provider_result', $orderId, $transactionId, $providerCode, $finalStatus);
        $responseBalance = topup_current_balance($pdo, (int)$user['id']) ?? $wallet['balance_after'];

        topup_json([
            'success' => $finalStatus !== 'failed',
            'status' => $finalStatus,
            'message' => match ($finalStatus) {
                'success' => 'خرید شارژ با موفقیت انجام شد.',
                'failed' => 'خرید قبلاً ناموفق و بازپرداخت شده است.',
                default => 'نتیجه خرید هنوز در حال نهایی‌شدن است.',
            },
            'code' => $finalStatus === 'pending' ? 'PROVIDER_PENDING' : null,
            'data' => [
                'transaction_id' => $transactionId,
                'ref_code' => $providerResponse['ref_code'] ?? null,
                'order_id' => $orderId,
                'client_request_id' => $clientRequestId,
                'tracking_code' => trim((string)($providerResponse['ref_code'] ?? '')) ?: $orderId,
                'mobile' => $mobile,
                'operator' => $operator,
                'amount' => $amountInt,
                'commission_amount' => $commissionAmount,
                'total_amount' => $totalCost,
                'balance' => $responseBalance,
                'remaining_balance' => $responseBalance
            ]
        ]);
    }

    // Ambiguous provider replies must remain recoverable; only known explicit failures are refunded below.
    if (topup_provider_result_class($providerCode) === 'pending') {
        $finalStatus = topup_update_records(
            $pdo,
            $transactionId,
            $orderId,
            ['status' => 'pending', 'provider_ref_code' => trim((string)($providerResponse['ref_code'] ?? ''))],
            [
                'status' => 'pending',
                'trans_id' => (string)($providerResponse['trans_id'] ?? ''),
                'ref_code' => (string)($providerResponse['ref_code'] ?? ''),
                'error_code' => $providerCode,
                'error_msg' => topup_provider_message(
                    $providerCode,
                    $providerMessage
                ),
                'raw_response' => $providerResponse
            ]
        );
        topup_log_lifecycle('provider_result', $orderId, $transactionId, $providerCode, $finalStatus);
        $responseBalance = topup_current_balance($pdo, (int)$user['id']) ?? $wallet['balance_after'];

        topup_json([
            'success' => $finalStatus !== 'failed',
            'status' => $finalStatus,
            'code' => $finalStatus === 'pending' ? 'PROVIDER_PENDING' : null,
            'message' => $finalStatus === 'success'
                ? 'خرید شارژ با موفقیت تأیید شد.'
                : ($finalStatus === 'failed' ? 'خرید قبلاً ناموفق و بازپرداخت شده است.' : 'درخواست خرید شارژ در انتظار پاسخ نهایی سرویس است.'),
            'data' => [
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'client_request_id' => $clientRequestId,
                'tracking_code' => $orderId,
                'mobile' => $mobile,
                'operator' => $operator,
                'amount' => $amountInt,
                'commission_amount' => $commissionAmount,
                'total_amount' => $totalCost,
                'balance' => $responseBalance,
                'remaining_balance' => $responseBalance
            ]
        ]);
    }

    $reason = 'کد سرویس: '
        . $providerCode
        . '؛ '
        . topup_provider_message($providerCode, $providerMessage);

    try {
        $refund = topup_refund_transaction(
            $pdo,
            $transactionId,
            $reason,
            [
                'error_code' => $providerCode,
                'raw_response' => $providerResponse,
                'trans_id' => (string)($providerResponse['trans_id'] ?? ''),
                'ref_code' => (string)($providerResponse['ref_code'] ?? ''),
            ]
        );
    } catch (Throwable $refundError) {
        topup_log_lifecycle('refund_failed', $orderId, $transactionId, $providerCode, 'pending');
        error_log(
            'Top-up refund failed transaction='
            . $transactionId
            . ' error='
            . $refundError->getMessage()
        );

        topup_json([
            'success' => false,
            'status' => 'pending',
            'code' => 'REFUND_MANUAL_REVIEW',
            'message' => 'خرید شارژ ناموفق بود و بازپرداخت نیازمند بررسی پشتیبانی است.',
            'data' => [
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'client_request_id' => $clientRequestId,
                'tracking_code' => $orderId,
                'amount' => $amountInt,
                'commission_amount' => $commissionAmount,
                'total_amount' => $totalCost,
                'balance' => topup_current_balance($pdo, (int)$user['id']),
                'remaining_balance' => topup_current_balance($pdo, (int)$user['id']),
            ]
        ], 503);
    }

    topup_log_lifecycle('provider_result', $orderId, $transactionId, $providerCode, 'failed');
    topup_json([
        'success' => false,
        'status' => 'failed',
        'code' => 'PROVIDER_FAILED',
        'message' => topup_provider_message(
            $providerCode,
            $providerMessage
        ),
        'data' => [
            'transaction_id' => $transactionId,
            'provider_code' => $providerCode,
            'order_id' => $orderId,
            'client_request_id' => $clientRequestId,
            'tracking_code' => trim((string)($providerResponse['ref_code'] ?? '')) ?: $orderId,
            'ref_code' => trim((string)($providerResponse['ref_code'] ?? '')) ?: null,
            'amount' => $amountInt,
            'commission_amount' => $commissionAmount,
            'total_amount' => $totalCost,
            'refunded' => $refund['refunded'] ?? false,
            'balance' => $refund['balance_after'] ?? topup_current_balance($pdo, (int)$user['id']),
            'remaining_balance' => $refund['balance_after'] ?? topup_current_balance($pdo, (int)$user['id']),
        ]
    ], 422);
} catch (InaxConfigurationException $error) {
    $configRefund = null;
    try {
        $configRefund = topup_refund_transaction(
            $pdo,
            $transactionId,
            'تنظیمات سرویس خرید شارژ روی سرور کامل نیست.'
        );
    } catch (Throwable $refundError) {
        error_log(
            'Top-up config refund failed transaction='
            . $transactionId
            . ' error='
            . $refundError->getMessage()
        );
    }

    $configurationFinalStatus = $configRefund !== null ? 'failed' : 'pending';
    topup_log_lifecycle('configuration_error', $orderId, $transactionId, null, $configurationFinalStatus);
    topup_json([
        'success' => false,
        'status' => $configurationFinalStatus,
        'code' => $configRefund !== null ? 'INAX_CONFIGURATION_ERROR' : 'REFUND_MANUAL_REVIEW',
        'message' => $configRefund !== null
            ? 'تنظیمات سرویس خرید شارژ روی سرور کامل نیست و مبلغ بازگردانده شد.'
            : 'تنظیمات سرویس خرید شارژ کامل نیست و بازپرداخت نیازمند بررسی پشتیبانی است.',
        'data' => [
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'client_request_id' => $clientRequestId,
            'tracking_code' => $orderId,
            'amount' => $amountInt,
            'commission_amount' => $commissionAmount,
            'total_amount' => $totalCost,
            'balance' => $configRefund['balance_after'] ?? topup_current_balance($pdo, (int)$user['id']),
            'remaining_balance' => $configRefund['balance_after'] ?? topup_current_balance($pdo, (int)$user['id']),
        ],
    ], 503);
} catch (InaxNetworkException $error) {
    $finalStatus = 'pending';
    try {
        $finalStatus = topup_update_records(
            $pdo,
            $transactionId,
            $orderId,
            ['status' => 'pending', 'description' => 'پاسخ سرویس نامشخص است و وضعیت خرید در حال پیگیری است.'],
            ['status' => 'pending', 'error_msg' => 'پاسخ سرویس دریافت نشد؛ وضعیت با همان شناسه سفارش پیگیری شود.']
        );
    } catch (Throwable $updateError) {
        error_log('Top-up pending-state update failed transaction=' . $transactionId . ' error=' . $updateError->getMessage());
    }
    topup_log_lifecycle('provider_network_error', $orderId, $transactionId, null, $finalStatus);
    $responseBalance = topup_current_balance($pdo, (int)$user['id']) ?? $wallet['balance_after'];

    topup_json([
        'success' => $finalStatus !== 'failed',
        'status' => $finalStatus,
        'code' => 'INAX_NETWORK_ERROR',
        'message' => $finalStatus === 'success'
            ? 'خرید شارژ با موفقیت تأیید شد.'
            : ($finalStatus === 'failed' ? 'خرید ناموفق بود و بازپرداخت ثبت شد.' : 'پاسخ سرویس دریافت نشد؛ وضعیت خرید در حال پیگیری است و مبلغ فعلاً رزرو می‌ماند.'),
        'data' => [
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'client_request_id' => $clientRequestId,
            'tracking_code' => $orderId,
            'mobile' => $mobile,
            'operator' => $operator,
            'amount' => $amountInt,
            'commission_amount' => $commissionAmount,
            'total_amount' => $totalCost,
            'balance' => $responseBalance,
            'remaining_balance' => $responseBalance,
        ],
    ], 503);
} catch (Throwable $error) {
    if (
        isset($providerResponse)
        && in_array(topup_provider_code($providerResponse), [1, -200], true)
    ) {
        topup_log_lifecycle('local_finalization_error', $orderId, $transactionId, topup_provider_code($providerResponse), 'pending');
        error_log(
            'Top-up provider result requires local finalization retry transaction='
            . $transactionId
            . ' order='
            . $orderId
            . ' error='
            . $error->getMessage()
        );

        topup_json([
            'success' => true,
            'status' => 'pending',
            'code' => 'TOPUP_FINALIZATION_RETRY',
            'message' => 'نتیجه خرید از سرویس دریافت شد و ثبت نهایی در حال پیگیری است.',
            'data' => [
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'client_request_id' => $clientRequestId,
                'tracking_code' => $orderId,
                'mobile' => $mobile,
                'operator' => $operator,
                'amount' => $amountInt,
                'commission_amount' => $commissionAmount,
                'total_amount' => $totalCost,
                'balance' => $wallet['balance_after'],
                'remaining_balance' => $wallet['balance_after']
            ]
        ], 503);
    }
    topup_log_lifecycle('internal_error', $orderId, $transactionId, isset($providerResponse) ? topup_provider_code($providerResponse) : null, 'pending');

    error_log(
        'Top-up provider failure transaction='
        . $transactionId
        . ' error='
        . $error->getMessage()
    );

    try {
        topup_update_records(
            $pdo,
            $transactionId,
            $orderId,
            ['status' => 'pending', 'description' => 'خطای داخلی پس از ثبت سفارش؛ نتیجه سرویس باید پیگیری شود.'],
            ['status' => 'pending', 'error_msg' => 'خطای داخلی پس از ارسال درخواست؛ نتیجه سرویس نامشخص است.']
        );
    } catch (Throwable $updateError) {
        error_log('Top-up error-state update failed transaction=' . $transactionId . ' error=' . $updateError->getMessage());
    }
    topup_json([
        'success' => true,
        'status' => 'pending',
        'code' => 'TOPUP_PROVIDER_ERROR',
        'message' => 'نتیجه خرید هنوز قطعی نیست؛ وضعیت با همان شناسه سفارش پیگیری می‌شود.',
        'data' => [
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'client_request_id' => $clientRequestId,
            'tracking_code' => $orderId,
            'mobile' => $mobile,
            'operator' => $operator,
            'amount' => $amountInt,
            'commission_amount' => $commissionAmount,
            'total_amount' => $totalCost,
            'balance' => $wallet['balance_after'],
            'remaining_balance' => $wallet['balance_after'],
        ],
    ], 503);
}
