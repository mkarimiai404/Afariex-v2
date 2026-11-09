<?php
declare(strict_types=1);

ob_start();
set_exception_handler(static function (Throwable $error): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Unhandled top-up status exception: ' . get_class($error));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'status' => 'pending',
        'code' => 'INTERNAL_SERVER_ERROR',
        'retryable' => true,
        'message' => 'دریافت وضعیت خرید شارژ موقتاً انجام نشد؛ بعداً دوباره بررسی کنید.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/lib/topup_common.php';

topup_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    topup_json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$data = topup_request_data();
$user = topup_authenticate_user($data);
$pdo = db();

topup_require_columns($pdo);
topup_require_recharge_columns($pdo);

try {
    service_fee_require_schema();
} catch (Throwable $error) {
    topup_json([
        'success' => false,
        'status' => 'pending',
        'code' => 'TOPUP_FINANCIAL_EVIDENCE_MISSING',
        'retryable' => false,
        'message' => 'سوابق کارمزد برای تأیید وضعیت مالی موجود نیست؛ بررسی پشتیبانی لازم است.',
    ], 503);
}

$transactionColumns = $pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
$balanceAppliedSelect = 't.balance_applied';
$refundAppliedSelect = in_array('refund_applied', $transactionColumns, true) ? 't.refund_applied' : '0 AS refund_applied';

$transactionId = (int)($data['transaction_id'] ?? 0);
$providerReference = trim((string)($data['trans_id'] ?? $data['ref_id'] ?? ''));
$providerReference = strlen($providerReference) <= 50 ? $providerReference : '';
$requestedOrderId = trim((string)($data['order_id'] ?? ''));
$requestedOrderId = strlen($requestedOrderId) <= 50 ? $requestedOrderId : '';
$clientRequestId = topup_validate_client_request_id($data['client_request_id'] ?? null);

if ($transactionId <= 0 && $providerReference === '' && $requestedOrderId === '' && $clientRequestId === null) {
    topup_json(['success' => false, 'code' => 'INVALID_TRANSACTION', 'message' => 'شناسه تراکنش یا شناسه درخواست معتبر نیست.'], 422);
}

$select = "SELECT t.id, t.user_id, t.amount, t.status, t.idempotency_key, t.topup_mobile,
                  t.topup_operator, t.topup_charge_type, t.provider_ref_code, t.client_request_id,
                  t.balance_before, t.balance_after, {$balanceAppliedSelect}, {$refundAppliedSelect},
                  t.provider_operation_token, t.provider_operation_type,
                  (t.provider_operation_lease_until > UTC_TIMESTAMP()) AS provider_claim_active,
                  r.user_id AS recharge_user_id, r.mobile, r.operator, r.charge_type,
                  r.amount AS recharge_amount, r.total_cost, r.order_id,
                  r.client_request_id AS recharge_client_request_id,
                  r.trans_id, r.ref_code, r.error_code AS provider_error_code,
                  r.error_msg AS provider_error_message, r.status AS recharge_status,
                  CASE WHEN r.error_msg LIKE 'TOPUP_RECONCILIATION_REQUIRED:%' THEN 1 ELSE 0 END AS reconciliation_marker,
                  sf.user_id AS fee_user_id, sf.source_type AS fee_source_type, sf.service_code AS fee_service_code,
                  sf.source_id AS fee_source_id, sf.original_amount AS fee_original_amount,
                  sf.fee_amount, sf.total_amount AS fee_total_amount, sf.status AS fee_status
           FROM transactions t
           LEFT JOIN mobile_recharges r ON r.order_id = t.idempotency_key
           LEFT JOIN service_fee_transactions sf ON sf.source_type = 'topup' AND sf.source_id = t.id
           WHERE %s AND t.user_id = ? AND t.type = 'topup'
           LIMIT 1 FOR UPDATE";

$findTransaction = static function (PDO $pdo, string $condition, array $identifiers, int $userId, string $select): array|false {
    $stmt = $pdo->prepare(sprintf($select, $condition));
    $stmt->execute([...$identifiers, $userId]);
    return $stmt->fetch();
};

$responseForSnapshot = static function (array $row, string $status, int $userId, PDO $pdo): array {
    $transactionId = (int)$row['id'];
    $refCode = trim((string)($row['provider_ref_code'] ?? $row['ref_code'] ?? ''));
    $commission = topup_commission_data($pdo, $transactionId, (int)$row['amount']);
    $balance = topup_current_balance($pdo, $userId);

    return [
        'success' => $status === 'success',
        'status' => $status === 'success' ? 'succeeded' : $status,
        'code' => $status === 'pending'
            ? 'PROVIDER_PENDING'
            : ($status === 'failed' && is_numeric($row['provider_error_code'] ?? null) ? (int)$row['provider_error_code'] : null),
        'message' => match ($status) {
            'success' => 'خرید شارژ با موفقیت تأیید شد.',
            'failed' => trim((string)($row['provider_error_message'] ?? '')) ?: 'خرید شارژ ناموفق بود و بازپرداخت ثبت شده است.',
            default => 'وضعیت خرید شارژ هنوز نهایی نشده است؛ بعداً با همین شناسه دوباره بررسی کنید.',
        },
        'data' => [
            'transaction_id' => $transactionId,
            'client_request_id' => (string)$row['client_request_id'],
            'order_id' => (string)$row['idempotency_key'],
            'trans_id' => trim((string)($row['trans_id'] ?? '')) ?: null,
            'tracking_code' => $refCode !== '' ? $refCode : (string)$row['idempotency_key'],
            'ref_code' => $refCode !== '' ? $refCode : null,
            'mobile' => (string)$row['topup_mobile'],
            'operator' => (string)$row['topup_operator'],
            'charge_type' => (string)$row['topup_charge_type'],
            'amount' => (int)$row['amount'],
            ...$commission,
            'balance' => $balance,
            'remaining_balance' => $balance,
        ],
    ];
};

$manualReview = static function (string $code, ?int $transactionId = null, ?string $requestId = null, ?string $orderId = null): never {
    topup_json([
        'success' => false,
        'status' => 'pending',
        'code' => $code,
        'retryable' => false,
        'reconciliation_required' => true,
        'message' => 'اطلاعات مالی درخواست با سوابق کیف پول سازگار نیست؛ خرید یا refund تکرار نمی‌شود و بررسی پشتیبانی لازم است.',
        'data' => array_filter([
            'transaction_id' => $transactionId,
            'client_request_id' => $requestId,
            'order_id' => $orderId,
            'reconciliation_required' => 1,
        ], static fn(mixed $value): bool => $value !== null),
    ], 503);
};

// Phase 1: Lookup, validate, and set lease claim token
$pdo->beginTransaction();
try {
    $transaction = false;
    if ($transactionId > 0) {
        $transaction = $findTransaction($pdo, 't.id = ?', [$transactionId], (int)$user['id'], $select);
    }
    if (!$transaction && $providerReference !== '') {
        $transaction = $findTransaction($pdo, '(r.trans_id = ? OR r.ref_code = ?)', [$providerReference, $providerReference], (int)$user['id'], $select);
    }
    if (!$transaction && $requestedOrderId !== '') {
        $transaction = $findTransaction($pdo, 't.idempotency_key = ?', [$requestedOrderId], (int)$user['id'], $select);
    }
    if (!$transaction && $clientRequestId !== null) {
        $transaction = $findTransaction($pdo, 't.client_request_id = ?', [$clientRequestId], (int)$user['id'], $select);
    }

    if (!$transaction) {
        $pdo->commit();
        topup_json([
            'success' => false,
            'status' => 'pending',
            'code' => 'TOPUP_TRANSACTION_NOT_FOUND',
            'retryable' => true,
            'message' => 'درخواست هنوز در دسترس نیست؛ وضعیت را بعداً با همین شناسه دوباره بررسی کنید.',
            'data' => ['transaction_id' => null, 'client_request_id' => $clientRequestId],
        ], 404);
    }

    $transactionId = (int)$transaction['id'];
    $orderId = (string)$transaction['idempotency_key'];
    
    $snapshot = topup_status_snapshot($transaction);
    if ($snapshot['reconciliation_required']) {
        $pdo->commit();
        $manualReview(empty($transaction['recharge_status']) ? 'TOPUP_RECHARGE_RECORD_MISSING' : 'TOPUP_STATUS_RECONCILIATION_REQUIRED', $transactionId, (string)$transaction['client_request_id'], $orderId);
    }

    $evidenceState = match ($snapshot['status']) {
        'success' => 'success',
        'failed' => 'refunded',
        default => 'pending',
    };
    $evidence = topup_refund_evidence($transaction, $evidenceState);
    if (!$evidence['valid']) {
        $pdo->commit();
        $manualReview((string)$evidence['code'], $transactionId, (string)$transaction['client_request_id'], $orderId);
    }

    if ($snapshot['status'] !== 'pending') {
        $response = $responseForSnapshot($transaction, $snapshot['status'], (int)$user['id'], $pdo);
        $pdo->commit();
        topup_json($response);
    }

    if ((int)$transaction['provider_claim_active'] === 1) {
        $pdo->commit();
        topup_json([
            'success' => true,
            'status' => 'pending',
            'code' => 'TOPUP_PROVIDER_OPERATION_IN_PROGRESS',
            'retryable' => true,
            'message' => 'درخواست خرید یا بررسی وضعیت هنوز در حال اجراست؛ کمی بعد دوباره بررسی کنید.',
            'data' => [
                'transaction_id' => $transactionId,
                'client_request_id' => (string)$transaction['client_request_id'],
                'order_id' => $orderId,
                'mobile' => (string)$transaction['topup_mobile'],
                'operator' => (string)$transaction['topup_operator'],
                'amount' => (int)$transaction['amount'],
                'commission_amount' => (int)$transaction['fee_amount'],
                'total_amount' => (int)$evidence['amount_toman'],
            ],
        ], 409);
    }

    $storedTransId = topup_provider_trans_id($transaction['trans_id'] ?? null);
    if ($storedTransId === null) {
        $marker = 'TOPUP_RECONCILIATION_REQUIRED: provider trans_id is missing or invalid.';
        $pdo->prepare('UPDATE mobile_recharges SET error_msg = ? WHERE order_id = ? AND status = \'pending\'')
            ->execute([$marker, (string)$transaction['idempotency_key']]);
        if (in_array('description', $transactionColumns, true)) {
            topup_update_transaction($pdo, (int)$transaction['id'], ['description' => $marker]);
        }
        $pdo->commit();
        $manualReview('TOPUP_PROVIDER_TRANS_ID_MISSING', (int)$transaction['id'], (string)$transaction['client_request_id'], (string)$transaction['idempotency_key']);
    }

    $claimToken = topup_provider_claim_token();
    $claimLeaseSeconds = topup_provider_claim_lease_seconds();
    $claim = $pdo->prepare(
        "UPDATE transactions
         SET provider_operation_token = ?,
             provider_operation_lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$claimLeaseSeconds} SECOND),
             provider_operation_type = 'status'
         WHERE id = ? AND status = 'pending'
           AND (provider_operation_token IS NULL OR provider_operation_lease_until <= UTC_TIMESTAMP())"
    );
    $claim->execute([$claimToken, $transactionId]);

    if ($claim->rowCount() !== 1) {
        $pdo->commit();
        topup_json([
            'success' => true,
            'status' => 'pending',
            'code' => 'TOPUP_PROVIDER_OPERATION_IN_PROGRESS',
            'retryable' => true,
            'message' => 'درخواست دیگری وضعیت خرید را بررسی می‌کند؛ کمی بعد دوباره تلاش کنید.',
            'data' => ['transaction_id' => $transactionId, 'client_request_id' => (string)$transaction['client_request_id'], 'order_id' => $orderId],
        ], 409);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

// External gateway inquiry (outside DB transaction lock)
try {
    $provider = (new InaxClient())->transStatus($orderId, $storedTransId);
} catch (InaxConfigurationException $error) {
    $pdo = db();
    topup_clear_provider_claim($pdo, $transactionId, $claimToken);
    topup_json([
        'success' => false,
        'status' => 'pending',
        'code' => 'INAX_CONFIGURATION_ERROR',
        'retryable' => false,
        'message' => 'تنظیمات درگاه اینکس ناقص یا نامعتبر است.',
    ], 503);
} catch (InaxNetworkException $error) {
    $pdo = db();
    topup_clear_provider_claim($pdo, $transactionId, $claimToken);
    topup_json([
        'success' => false,
        'status' => 'pending',
        'code' => 'INAX_NETWORK_ERROR',
        'retryable' => true,
        'message' => 'خطای ارتباطی با درگاه اینکس رخ داد؛ بعداً دوباره تلاش کنید.',
    ], 502);
} catch (Throwable $error) {
    $pdo = db();
    topup_clear_provider_claim($pdo, $transactionId, $claimToken);
    topup_json([
        'success' => false,
        'status' => 'pending',
        'code' => 'TOPUP_PROVIDER_ERROR',
        'retryable' => true,
        'message' => 'خطای پیش‌بینی‌نشده در استعلام وضعیت رخ داد.',
    ], 500);
}

// Phase 2: Process response and update database atomically
$pdo = db();
$providerCode = topup_provider_code($provider);
$providerStatus = topup_provider_status_class($provider);
$providerTransId = topup_provider_trans_id($provider['trans_id'] ?? null);
$transId = $providerTransId ?? $storedTransId;
$refCode = trim((string)($provider['ref_code'] ?? '')) ?: trim((string)($transaction['provider_ref_code'] ?? $transaction['ref_code'] ?? ''));
if (strlen($refCode) > 100) {
    $refCode = substr($refCode, 0, 100);
}

$pdo->beginTransaction();
try {
    $freshStatement = $pdo->prepare(str_replace('%s', 't.id = ?', $select));
    $freshStatement->execute([$transactionId, (int)$user['id']]);
    $fresh = $freshStatement->fetch();

    if (!$fresh) {
        throw new RuntimeException('TOPUP_TRANSACTION_DISAPPEARED');
    }

    $freshSnapshot = topup_status_snapshot($fresh);
    if ($freshSnapshot['reconciliation_required']) {
        topup_clear_provider_claim($pdo, $transactionId, $claimToken);
        $pdo->commit();
        $manualReview('TOPUP_STATUS_RECONCILIATION_REQUIRED', $transactionId, (string)$fresh['client_request_id'], (string)$fresh['idempotency_key']);
    }

    if ($freshSnapshot['status'] !== 'pending') {
        $freshEvidenceState = $freshSnapshot['status'] === 'success' ? 'success' : 'refunded';
        $freshEvidence = topup_refund_evidence($fresh, $freshEvidenceState);
        if (!$freshEvidence['valid']) {
            $pdo->commit();
            $manualReview((string)$freshEvidence['code'], $transactionId, (string)$fresh['client_request_id'], (string)$fresh['idempotency_key']);
        }
        $response = $responseForSnapshot($fresh, $freshSnapshot['status'], (int)$user['id'], $pdo);
        $pdo->commit();
        topup_json($response);
    }

    if (!hash_equals((string)($fresh['provider_operation_token'] ?? ''), $claimToken) || (int)($fresh['provider_claim_active'] ?? 0) !== 1) {
        $pdo->commit();
        topup_json([
            'success' => true,
            'status' => 'pending',
            'code' => 'TOPUP_PROVIDER_CLAIM_EXPIRED',
            'retryable' => true,
            'message' => 'مهلت این بررسی پایان یافته است؛ وضعیت را دوباره بررسی کنید.',
            'data' => ['transaction_id' => $transactionId],
        ], 409);
    }

    if (topup_provider_status_conflicts($provider)) {
        $marker = 'TOPUP_RECONCILIATION_REQUIRED: conflicting provider success and failure indicators.';
        $pdo->prepare('UPDATE mobile_recharges SET error_msg = ? WHERE order_id = ? AND status = \'pending\'')
            ->execute([$marker, $orderId]);
        if (in_array('description', $transactionColumns, true)) {
            topup_update_transaction($pdo, $transactionId, ['description' => $marker]);
        }
        topup_clear_provider_claim($pdo, $transactionId, $claimToken);
        $pdo->commit();
        $manualReview('TOPUP_PROVIDER_STATUS_CONFLICT', $transactionId, (string)$fresh['client_request_id'], $orderId);
    }

    if ($providerStatus === 'success') {
        topup_update_records($pdo, $transactionId, 'success', $transId, $refCode, $claimToken);
        $freshStatement->execute([$transactionId, (int)$user['id']]);
        $updated = $freshStatement->fetch();
        $response = $responseForSnapshot($updated ?: $fresh, 'success', (int)$user['id'], $pdo);
        $pdo->commit();
        topup_json($response);
    }

    if ($providerStatus === 'failed') {
        $refundResult = topup_refund_transaction(
            $pdo,
            $transactionId,
            (string)($provider['msg'] ?? 'خرید شارژ توسط درگاه لغو یا ناموفق اعلام شد.'),
            (string)$providerCode,
            $transId,
            $refCode,
            $claimToken
        );
        if (!$refundResult['success']) {
            $marker = 'TOPUP_RECONCILIATION_REQUIRED: ' . ($refundResult['code'] ?? 'REFUND_FAILED');
            $pdo->prepare('UPDATE mobile_recharges SET error_msg = ? WHERE order_id = ? AND status = \'pending\'')
                ->execute([$marker, $orderId]);
            $pdo->commit();
            $manualReview((string)($refundResult['code'] ?? 'REFUND_MANUAL_REVIEW'), $transactionId, (string)$fresh['client_request_id'], $orderId);
        }
        $freshStatement->execute([$transactionId, (int)$user['id']]);
        $updated = $freshStatement->fetch();
        $response = $responseForSnapshot($updated ?: $fresh, 'failed', (int)$user['id'], $pdo);
        $pdo->commit();
        topup_json($response);
    }

    // Still pending at provider side
    if ($refCode !== '' || $transId !== null) {
        $updateData = [];
        if ($refCode !== '' && empty($fresh['provider_ref_code']) && empty($fresh['ref_code'])) {
            $updateData['provider_ref_code'] = $refCode;
        }
        if (!empty($updateData)) {
            topup_update_transaction($pdo, $transactionId, $updateData);
        }
        if ($transId !== null) {
            $pdo->prepare('UPDATE mobile_recharges SET trans_id = COALESCE(NULLIF(trans_id, \'\'), ?) WHERE order_id = ? AND status = \'pending\'')
                ->execute([$transId, $orderId]);
        }
    }

    topup_clear_provider_claim($pdo, $transactionId, $claimToken);
    $pdo->commit();

    topup_json([
        'success' => true,
        'status' => 'pending',
        'code' => 'PROVIDER_PENDING',
        'retryable' => true,
        'message' => trim((string)($provider['msg'] ?? '')) ?: 'تراکنش همچنان در شبکه اپراتور در انتظار است.',
        'data' => [
            'transaction_id' => $transactionId,
            'client_request_id' => (string)$fresh['client_request_id'],
            'order_id' => $orderId,
            'trans_id' => $transId,
            'tracking_code' => $refCode !== '' ? $refCode : $orderId,
            'mobile' => (string)$fresh['topup_mobile'],
            'operator' => (string)$fresh['topup_operator'],
            'amount' => (int)$fresh['amount'],
        ],
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
