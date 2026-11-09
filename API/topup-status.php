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
        'message' => 'اطلاعات مالی درخواست با سوابق کیف پول سازگار نیست؛ خرید یا بازپرداخت تکرار نمی‌شود و بررسی پشتیبانی لازم است.',
        'data' => array_filter([
            'transaction_id' => $transactionId,
            'client_request_id' => $requestId,
            'order_id' => $orderId,
            'reconciliation_required' => 1,
        ], static fn(mixed $value): bool => $value !== null),
    ], 503);
};

// Phase 1: look up, validate, and claim in a short transaction. The lease also
// coordinates status checks with the original purchase provider call.
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
    if ((int)($transaction['provider_claim_active'] ?? 0) === 1) {
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
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

// No transaction or row lock is held across this external request. A crashed worker leaves a finite lease.
db_disconnect();
$pdo = null;
try {
    $provider = (new InaxClient())->transStatus(
        $orderId,
        $storedTransId
    );
} catch (InaxConfigurationException $error) {
    $pdo = db();
    try { topup_clear_provider_claim($pdo, $transactionId, $claimToken); } catch (Throwable $releaseError) {
        error_log('Top-up status claim release failed transaction=' . $transactionId);
    }
    topup_json(['success' => false, 'status' => 'pending', 'code' => 'INAX_CONFIGURATION_ERROR', 'retryable' => true, 'message' => 'تنظیمات سرویس کامل نیست؛ وضعیت مالی نامشخص باقی ماند.'], 503);
} catch (InaxNetworkException $error) {
    $pdo = db();
    try { topup_clear_provider_claim($pdo, $transactionId, $claimToken); } catch (Throwable $releaseError) {
        error_log('Top-up status claim release failed transaction=' . $transactionId);
    }
    topup_json(['success' => false, 'status' => 'pending', 'code' => 'INAX_NETWORK_ERROR', 'retryable' => true, 'message' => 'ارتباط با سرویس برقرار نشد؛ وضعیت مالی نامشخص است و بعداً دوباره بررسی می‌شود.'], 502);
} catch (Throwable $error) {
    $pdo = db();
    try { topup_clear_provider_claim($pdo, $transactionId, $claimToken); } catch (Throwable $releaseError) {
        error_log('Top-up status claim release failed transaction=' . $transactionId);
    }
    error_log('Top-up provider status call failed transaction=' . $transactionId . ' exception=' . get_class($error));
    topup_json(['success' => false, 'status' => 'pending', 'code' => 'TOPUP_STATUS_ERROR', 'retryable' => true, 'message' => 'دریافت وضعیت خرید شارژ انجام نشد؛ بعداً دوباره بررسی کنید.'], 500);
}

$pdo = db();
$providerCode = topup_provider_code($provider);
$providerStatus = topup_provider_status_class($provider);
$providerTransId = topup_provider_trans_id($provider['trans_id'] ?? null);
$transId = $providerTransId ?? $storedTransId;
$refCode = trim((string)($provider['ref_code'] ?? '')) ?: trim((string)($transaction['provider_ref_code'] ?? $transaction['ref_code'] ?? ''));
if (strlen($refCode) > 100) $refCode = substr($refCode, 0, 100);

// Phase 2: re-read and lock current state in a new short transaction. A lost or
// expired claim cannot write; a concurrently finalized transaction is immutable.
$pdo->beginTransaction();
try {
    $freshStatement = $pdo->prepare(str_replace('%s', 't.id = ?', $select));
    $freshStatement->execute([$transactionId, (int)$user['id']]);
    $fresh = $freshStatement->fetch();
    if (!$fresh) throw new RuntimeException('TOPUP_TRANSACTION_DISAPPEARED');

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
    if (!hash_equals((string)($fresh['provider_operation_token'] ?? ''), $claimToken)
        || (int)($fresh['provider_claim_active'] ?? 0) !== 1) {
        $response = [
            'success' => true,
            'status' => 'pending',
            'code' => 'TOPUP_PROVIDER_CLAIM_EXPIRED',
            'retryable' => true,
            'message' => 'مهلت این بررسی پایان یافته است؛ وضعیت را دوباره بررسی کنید.',
            'data' => ['transaction_id' => $transactionId, 'client_request_id' => (string)$fresh['client_request_id'], 'order_id' => (string)$fresh['idempotency_key']],
        ];
        $pdo->commit();
        topup_json($response);
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

    $evidence = topup_refund_evidence($fresh);
    if (!$evidence['valid']) {
        topup_clear_provider_claim($pdo, $transactionId, $claimToken);
        $pdo->commit();
        $manualReview((string)$evidence['code'], $transactionId, (string)$fresh['client_request_id'], (string)$fresh['idempotency_key']);
    }

    if ($providerStatus === 'success') {
        topup_update_records($pdo, $transactionId, $orderId,
            ['status' => 'success', 'provider_ref_code' => $refCode],
            ['status' => 'completed', 'trans_id' => $transId, 'ref_code' => $refCode, 'raw_response' => $provider, 'error_code' => null, 'error_msg' => null],
            $claimToken);
    } elseif ($providerStatus === 'failed') {
        topup_refund_transaction($pdo, $transactionId,
            'کد سرویس: ' . $providerCode . '؛ ' . topup_provider_message($providerCode, trim((string)($provider['msg'] ?? ''))),
            $claimToken,
            ['error_code' => $providerCode, 'raw_response' => $provider, 'trans_id' => $transId, 'ref_code' => $refCode]);
    } else {
        topup_update_records($pdo, $transactionId, $orderId,
            ['status' => 'pending', 'description' => 'خرید شارژ در انتظار پاسخ نهایی سرویس است.'],
            ['status' => 'pending', 'trans_id' => $transId, 'ref_code' => $refCode, 'error_code' => $providerCode,
                'error_msg' => topup_provider_message($providerCode, trim((string)($provider['msg'] ?? ''))), 'raw_response' => $provider],
            $claimToken);
    }

    $freshStatement->execute([$transactionId, (int)$user['id']]);
    $final = $freshStatement->fetch();
    if (!$final) throw new RuntimeException('TOPUP_TRANSACTION_DISAPPEARED');
    $finalSnapshot = topup_status_snapshot($final);
    if ($finalSnapshot['reconciliation_required']) {
        $pdo->commit();
        $manualReview('TOPUP_STATUS_RECONCILIATION_REQUIRED', $transactionId, (string)$final['client_request_id'], (string)$final['idempotency_key']);
    }
    $finalEvidenceState = match ($finalSnapshot['status']) {
        'success' => 'success',
        'failed' => 'refunded',
        default => 'pending',
    };
    $finalEvidence = topup_refund_evidence($final, $finalEvidenceState);
    if (!$finalEvidence['valid']) {
        $pdo->commit();
        $manualReview((string)$finalEvidence['code'], $transactionId, (string)$final['client_request_id'], (string)$final['idempotency_key']);
    }
    $response = $responseForSnapshot($final, $finalSnapshot['status'], (int)$user['id'], $pdo);
    $pdo->commit();
    topup_json($response);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try { topup_clear_provider_claim($pdo, $transactionId, $claimToken); } catch (Throwable $releaseError) {
        error_log('Top-up status claim release failed transaction=' . $transactionId);
    }
    topup_log_lifecycle('status_finalization_error', $orderId, $transactionId, $providerCode, 'pending');
    error_log('Top-up status finalization failed transaction=' . $transactionId . ' exception=' . get_class($error));
    topup_json(['success' => false, 'status' => 'pending', 'code' => 'TOPUP_STATUS_ERROR', 'retryable' => true, 'message' => 'وضعیت مالی درخواست قابل تأیید نیست؛ بعداً دوباره بررسی کنید.'], 500);
}
