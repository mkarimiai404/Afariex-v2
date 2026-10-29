<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/topup_common.php';

topup_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    topup_json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$data = topup_request_data();
$user = topup_authenticate_user($data);
$pdo = db();
topup_require_columns($pdo);
topup_require_recharge_columns($pdo);

$transactionId = (int)($data['transaction_id'] ?? 0);
$clientRequestId = topup_validate_client_request_id($data['client_request_id'] ?? null);
if ($transactionId <= 0 && $clientRequestId === null) {
    topup_json(['success' => false, 'code' => 'INVALID_TRANSACTION', 'message' => 'شناسه تراکنش یا شناسه درخواست معتبر نیست.'], 422);
}

$select = "SELECT t.id, t.user_id, t.amount, t.status, t.idempotency_key, t.topup_mobile,
                  t.topup_operator, t.provider_ref_code, t.client_request_id,
                  r.charge_type, r.trans_id, r.ref_code, r.status AS recharge_status
           FROM transactions t
           INNER JOIN mobile_recharges r ON r.order_id = t.idempotency_key
           WHERE %s AND t.user_id = ? AND t.type = 'topup'
           LIMIT 1";
if ($transactionId > 0) {
    $stmt = $pdo->prepare(sprintf($select, 't.id = ?'));
    $stmt->execute([$transactionId, (int)$user['id']]);
} else {
    $stmt = $pdo->prepare(sprintf($select, 't.client_request_id = ?'));
    $stmt->execute([$clientRequestId, (int)$user['id']]);
}
$transaction = $stmt->fetch();
if (!$transaction) {
    topup_json([
        'success' => true,
        'status' => 'pending',
        'code' => 'TOPUP_TRANSACTION_NOT_VISIBLE_YET',
        'message' => 'درخواست هنوز ثبت‌شده نیست؛ با همان شناسه درخواست بعداً دوباره بررسی کنید.',
        'data' => ['transaction_id' => null, 'client_request_id' => $clientRequestId],
    ], 404);
}

$transactionId = (int)$transaction['id'];
$orderId = (string)$transaction['idempotency_key'];
$commissionData = topup_commission_data($pdo, $transactionId, (int)$transaction['amount']);
$baseData = [
    'transaction_id' => $transactionId,
    'client_request_id' => (string)$transaction['client_request_id'],
    'order_id' => $orderId,
    'mobile' => (string)$transaction['topup_mobile'],
    'operator' => (string)$transaction['topup_operator'],
    'charge_type' => (string)($transaction['charge_type'] ?? 'normal'),
    'amount' => (int)$transaction['amount'],
    ...$commissionData,
];
$refCode = trim((string)($transaction['provider_ref_code'] ?? ''));
if ($refCode === '') $refCode = trim((string)($transaction['ref_code'] ?? ''));

$storedStatus = match (true) {
    $transaction['recharge_status'] === 'completed' => 'success',
    in_array($transaction['recharge_status'], ['refunded', 'failed'], true) => 'failed',
    $transaction['status'] === 'success' => 'success',
    $transaction['status'] === 'failed' => 'failed',
    default => 'pending',
};

if ($storedStatus === 'pending') {
    try {
        $provider = (new InaxClient())->transStatus(
            $orderId,
            trim((string)($transaction['trans_id'] ?? '')) ?: null
        );
        $providerCode = topup_provider_code($provider);
        $finalStatus = strtolower(trim((string)($provider['final_status'] ?? '')));
        $paymentStatus = strtolower(trim((string)($provider['payment_status'] ?? '')));
        $providerRefCode = trim((string)($provider['ref_code'] ?? ''));
        if ($providerRefCode !== '') $refCode = $providerRefCode;
        $transId = trim((string)($provider['trans_id'] ?? ''));
        $isSuccess = $finalStatus === 'succeeded' || $paymentStatus === 'paid';
        $isFailure = in_array($finalStatus, ['failed', 'refund_provider'], true) || $paymentStatus === 'refund';

        if ($isSuccess) {
            $storedStatus = topup_update_records(
                $pdo,
                $transactionId,
                $orderId,
                ['status' => 'success', 'provider_ref_code' => $refCode],
                [
                    'status' => 'completed',
                    'trans_id' => $transId,
                    'ref_code' => $refCode,
                    'raw_response' => $provider,
                    'error_code' => null,
                    'error_msg' => null,
                ]
            );
        } elseif ($isFailure) {
            try {
                topup_refund_transaction(
                    $pdo,
                    $transactionId,
                    'کد سرویس: ' . $providerCode . '؛ ' . topup_provider_message($providerCode, trim((string)($provider['msg'] ?? ''))),
                    [
                        'error_code' => $providerCode,
                        'raw_response' => $provider,
                        'trans_id' => $transId,
                        'ref_code' => $refCode,
                    ]
                );
                $storedStatus = topup_recharge_status($pdo, $orderId) === 'completed' ? 'success' : 'failed';
            } catch (Throwable $refundError) {
                topup_log_lifecycle('refund_failed', $orderId, $transactionId, $providerCode, 'pending');
                error_log('Top-up status refund failed transaction=' . $transactionId . ' error=' . $refundError->getMessage());
                topup_json([
                    'success' => false,
                    'status' => 'pending',
                    'code' => 'REFUND_MANUAL_REVIEW',
                    'message' => 'نتیجه سرویس ناموفق است، اما بازپرداخت هنوز نهایی نشده و در حال پیگیری است.',
                    'data' => $baseData,
                ], 503);
            }
        } else {
            $storedStatus = topup_update_records(
                $pdo,
                $transactionId,
                $orderId,
                ['status' => 'pending', 'description' => 'خرید شارژ در انتظار پاسخ نهایی سرویس است.'],
                [
                    'status' => 'pending',
                    'trans_id' => $transId,
                    'ref_code' => $refCode,
                    'error_code' => $providerCode,
                    'error_msg' => topup_provider_message($providerCode, trim((string)($provider['msg'] ?? ''))),
                    'raw_response' => $provider,
                ]
            );
        }

        topup_log_lifecycle('status_reconciled', $orderId, $transactionId, $providerCode, $storedStatus);
    } catch (InaxConfigurationException $error) {
        topup_log_lifecycle('status_configuration_error', $orderId, $transactionId, null, 'pending');
        topup_json(['success' => false, 'status' => 'pending', 'code' => 'INAX_CONFIGURATION_ERROR', 'message' => 'تنظیمات سرویس خرید شارژ روی سرور کامل نیست.', 'data' => $baseData], 503);
    } catch (InaxNetworkException $error) {
        topup_log_lifecycle('status_network_error', $orderId, $transactionId, null, 'pending');
        topup_json(['success' => false, 'status' => 'pending', 'code' => 'INAX_NETWORK_ERROR', 'message' => 'ارتباط با سرویس برقرار نشد؛ وضعیت خرید همچنان قابل پیگیری است.', 'data' => $baseData], 502);
    } catch (Throwable $error) {
        topup_log_lifecycle('status_internal_error', $orderId, $transactionId, null, 'pending');
        error_log('Top-up status failure transaction=' . $transactionId . ' error=' . $error->getMessage());
        topup_json(['success' => false, 'status' => 'pending', 'code' => 'TOPUP_STATUS_ERROR', 'message' => 'دریافت وضعیت خرید شارژ انجام نشد؛ بعداً دوباره بررسی کنید.', 'data' => $baseData], 500);
    }
    } else {
        topup_log_lifecycle('status_terminal_replay', $orderId, $transactionId, null, $storedStatus);
    }

$balance = topup_current_balance($pdo, (int)$user['id']);
$message = match ($storedStatus) {
    'success' => 'خرید شارژ با موفقیت تأیید شد.',
    'failed' => 'خرید شارژ ناموفق بود و بازپرداخت ثبت شد.',
    default => 'وضعیت خرید شارژ هنوز نهایی نشده است.',
};
topup_json([
    'success' => $storedStatus !== 'failed',
    'status' => $storedStatus,
    'code' => $storedStatus === 'pending' ? 'PROVIDER_PENDING' : null,
    'message' => $message,
    'data' => [
        ...$baseData,
        'tracking_code' => $refCode !== '' ? $refCode : $orderId,
        'ref_code' => $refCode !== '' ? $refCode : null,
        'balance' => $balance,
        'remaining_balance' => $balance,
    ],
]);
