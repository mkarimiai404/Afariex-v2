<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';
require_once __DIR__ . '/../../admin_panel/service_fee_service.php';
require_once __DIR__ . '/../includes/wallet_service.php';
require_once __DIR__ . '/../includes/subcustomer.php';
require_once __DIR__ . '/InaxClient.php';
require_once __DIR__ . '/../services/InaxService.php';

function topup_headers(): void
{
    $allowedOrigins = [
        'http://localhost:8081',
        'http://127.0.0.1:8081',
        'https://afariex.ir',
    ];

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');

    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');
}

function topup_json(array $payload, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function topup_request_data(): array
{
    $raw = file_get_contents('php://input');

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function topup_authenticate_user(array $data): array
{
    $token = trim((string)(
        $data['api_token']
        ?? $data['token']
        ?? $data['user_token']
        ?? ''
    ));

    if (
        $token === ''
        && preg_match(
            '/^Bearer\s+(.+)$/i',
            (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
            $matches
        )
    ) {
        $token = trim($matches[1]);
    }

    if ($token === '') {
        topup_json([
            'success' => false,
            'code' => 'AUTHENTICATION_REQUIRED',
            'message' => 'نشست کاربری معتبر نیست.',
        ], 401);
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, mobile, balance
         FROM users
         WHERE api_token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        topup_json([
            'success' => false,
            'code' => 'AUTHENTICATION_FAILED',
            'message' => 'نشست کاربری معتبر نیست.',
        ], 401);
    }

    try {
        subcustomer_require_verified($pdo, (int)$user['id']);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'SUBCUSTOMER_VERIFICATION_REQUIRED') {
            topup_json([
                'success' => false,
                'code' => 'SUBCUSTOMER_VERIFICATION_REQUIRED',
                'message' => 'ابتدا تایید تلفن و احراز هویت را تکمیل کنید.',
            ], 403);
        }

        throw $error;
    }

    return $user;
}

function topup_require_columns(PDO $pdo): void
{
    $columns = $pdo
        ->query('SHOW COLUMNS FROM transactions')
        ->fetchAll(PDO::FETCH_COLUMN, 0);

    $requiredColumns = [
        'id',
        'user_id',
        'amount',
        'type',
        'status',
        'created_at',
        'idempotency_key',
        'topup_mobile',
        'topup_operator',
        'provider_ref_code',
        'client_request_id',
    ];

    foreach ($requiredColumns as $required) {
        if (!in_array($required, $columns, true)) {
            topup_json([
                'success' => false,
                'code' => 'TOPUP_SCHEMA_MISSING',
                'message' => 'ساختار دیتابیس برای خرید شارژ آماده نیست.',
            ], 503);
        }
    }
}

function topup_require_recharge_columns(PDO $pdo): void
{
    try {
        $columns = $pdo
            ->query('SHOW COLUMNS FROM mobile_recharges')
            ->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $error) {
        topup_json([
            'success' => false,
            'code' => 'TOPUP_SCHEMA_MISSING',
            'message' => 'جدول ثبت خرید شارژ آماده نیست.',
        ], 503);
    }

    $requiredColumns = [
        'id',
        'user_id',
        'mobile',
        'operator',
        'charge_type',
        'amount',
        'total_cost',
        'order_id',
        'trans_id',
        'ref_code',
        'status',
        'error_code',
        'error_msg',
        'raw_response',
        'client_request_id',
    ];

    foreach ($requiredColumns as $required) {
        if (!in_array($required, $columns, true)) {
            topup_json([
                'success' => false,
                'code' => 'TOPUP_SCHEMA_MISSING',
                'message' => 'جدول ثبت خرید شارژ آماده نیست.',
            ], 503);
        }
    }
}

function topup_update_recharge(PDO $pdo, string $orderId, array $updates): void
{
    $allowed = [
        'trans_id',
        'ref_code',
        'status',
        'error_code',
        'error_msg',
        'raw_response',
    ];

    $parts = [];
    $values = [];

    foreach ($updates as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }

        if ($column === 'raw_response' && is_array($value)) {
            $value = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if ($column === 'error_msg' && is_string($value)) {
            $value = substr($value, 0, 255);
        }

        if (
            in_array($column, ['trans_id', 'ref_code'], true)
            && is_string($value)
        ) {
            $maxLength = $column === 'trans_id' ? 50 : 100;
            $value = substr($value, 0, $maxLength);
        }

        $parts[] = '`' . $column . '` = ?';
        $values[] = $value;
    }

    if ($parts === []) {
        return;
    }

    $values[] = $orderId;

    $sql = 'UPDATE mobile_recharges
            SET ' . implode(', ', $parts) . '
            WHERE order_id = ?';

    $pdo->prepare($sql)->execute($values);
}

function topup_update_records(
    PDO $pdo,
    int $transactionId,
    string $orderId,
    array $transactionUpdates,
    array $rechargeUpdates
): string {
    // Global recharge FSM: pending -> completed (success) or refunded (failed); terminal states are immutable.
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $transactionLock = $pdo->prepare(
            "SELECT id
             FROM transactions
             WHERE id = ? AND type = 'topup'
             LIMIT 1
             FOR UPDATE"
        );
        $transactionLock->execute([$transactionId]);

        if (!$transactionLock->fetchColumn()) {
            throw new RuntimeException('TOPUP_TRANSACTION_NOT_FOUND');
        }

        $rechargeLock = $pdo->prepare(
            'SELECT status
             FROM mobile_recharges
             WHERE order_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $rechargeLock->execute([$orderId]);

        $currentRechargeStatus = $rechargeLock->fetchColumn();
        $targetRechargeStatus = $rechargeUpdates['status'] ?? null;

        if (in_array($currentRechargeStatus, ['completed', 'refunded', 'failed'], true)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $currentRechargeStatus === 'completed' ? 'success' : 'failed';
        }
        if ($currentRechargeStatus !== 'pending' || !in_array($targetRechargeStatus, ['pending', 'completed'], true)) {
            throw new RuntimeException('INVALID_TOPUP_STATUS_TRANSITION');
        }

        topup_update_transaction(
            $pdo,
            $transactionId,
            $transactionUpdates
        );

        topup_update_recharge(
            $pdo,
            $orderId,
            $rechargeUpdates
        );

        if (($rechargeUpdates['status'] ?? null) === 'completed') {
            service_fee_update_status($pdo, 'topup', $transactionId, 'collected');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $targetRechargeStatus === 'completed' ? 'success' : 'pending';
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function topup_operator_rules(): array
{
    return [
        'MCI' => [
            'provider_code' => 'MCI',
            'title' => 'همراه اول',
            'min_amount' => 20000,
            'max_amount' => 500000,
        ],
        'MTN' => [
            'provider_code' => 'MTN',
            'title' => 'ایرانسل',
            'min_amount' => 22000,
            'max_amount' => 1000000,
        ],
        'RTL' => [
            'provider_code' => 'RTL',
            'title' => 'رایتل',
            'min_amount' => 22000,
            'max_amount' => 500000,
        ],
    ];
}

function topup_normalize_mobile(mixed $value): ?string
{
    $mobile = strtr(trim((string)$value), [
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
    ]);
    $mobile = preg_replace('/[\s().-]+/u', '', $mobile) ?? '';
    if (str_starts_with($mobile, '+98')) {
        $mobile = '0' . substr($mobile, 3);
    } elseif (str_starts_with($mobile, '0098')) {
        $mobile = '0' . substr($mobile, 4);
    } elseif (str_starts_with($mobile, '98')) {
        $mobile = '0' . substr($mobile, 2);
    }

    return preg_match('/^09\d{9}$/', $mobile) === 1
        ? $mobile
        : null;
}

function topup_provider_operator_code(string $operator): string
{
    $rules = topup_operator_rules();
    if (!isset($rules[$operator]['provider_code'])) {
        throw new InvalidArgumentException('INVALID_TOPUP_PROVIDER_OPERATOR');
    }
    return (string)$rules[$operator]['provider_code'];
}

function topup_log_lifecycle(string $event, string $orderId, int $transactionId, ?int $providerCode, string $finalStatus): void
{
    error_log('[topup] ' . json_encode([
        'event' => $event,
        'order_id' => $orderId,
        'transaction_id' => $transactionId,
        'provider_code' => $providerCode,
        'final_status' => $finalStatus,
    ], JSON_UNESCAPED_SLASHES));
}

function topup_provider_code(array $response): int
{
    return is_numeric($response['code'] ?? null)
        ? (int)$response['code']
        : -91;
}

function topup_provider_message(
    int $code,
    string $providerMessage = ''
): string {
    return match ($code) {
        -11 => 'پارامترهای سرویس کامل نیست.',
        -12 => 'شماره موبایل یا اپراتور صحیح نیست.',
        -18 => 'این شماره همراه اول دائمی نیست.',
        -22 => 'احراز هویت سرویس خرید شارژ ناموفق بود.',
        -33 => 'اعتبار پنل خرید شارژ کافی نیست.',
        -44 => 'مبلغ شارژ برای این اپراتور مجاز نیست.',
        -55 => 'تراکنش خرید شارژ پیدا نشد.',
        -66 => 'اپراتور ایرانسل موقتاً در دسترس نیست.',
        -67 => 'اپراتور همراه اول موقتاً در دسترس نیست.',
        -77 => 'این درخواست قبلاً ارسال شده است.',
        -91 => 'خطای موقت سرویس خرید شارژ رخ داد.',
        -200 => 'تراکنش در انتظار پاسخ نهایی سرویس است.',
        default => $providerMessage !== ''
            ? $providerMessage
            : 'خرید شارژ انجام نشد.',
    };
}

function topup_order_id(): string
{
    $microtime = (int)(microtime(true) * 1000);
    $random = random_int(100000, 999999);

    return (string)$microtime . $random;
}

function topup_validate_client_request_id(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = strtolower(trim($value));
    return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
        ? $value
        : null;
}

function topup_find_client_request(PDO $pdo, int $userId, string $clientRequestId): ?array
{
    $statement = $pdo->prepare(
        "SELECT t.id, t.user_id, t.amount, t.status, t.idempotency_key, t.topup_mobile, t.topup_operator, t.provider_ref_code, r.charge_type
         FROM transactions t
         LEFT JOIN mobile_recharges r ON r.order_id = t.idempotency_key
         WHERE t.user_id = ? AND t.type = 'topup' AND t.client_request_id = ?
         LIMIT 1"
    );
    $statement->execute([$userId, $clientRequestId]);
    $transaction = $statement->fetch();
    return $transaction ?: null;
}

function topup_current_balance(PDO $pdo, int $userId): ?float
{
    $statement = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $balance = $statement->fetchColumn();
    return $balance === false ? null : (float)$balance;
}

function topup_recharge_status(PDO $pdo, string $orderId): ?string
{
    $statement = $pdo->prepare('SELECT status FROM mobile_recharges WHERE order_id = ? LIMIT 1');
    $statement->execute([$orderId]);
    $status = $statement->fetchColumn();
    return $status === false ? null : (string)$status;
}

function topup_commission_data(PDO $pdo, int $transactionId, int $baseAmount): array
{
    $fee = service_fee_for_source('topup', $transactionId, $pdo);
    $commissionAmount = max(0, (int)($fee['fee_amount'] ?? 0));
    return [
        'commission_amount' => $commissionAmount,
        'total_amount' => $baseAmount + $commissionAmount,
    ];
}

function topup_respond_existing_request(PDO $pdo, array $transaction, string $mobile, string $operator, int $amount, string $chargeType): never
{
    if (
        (string)$transaction['topup_mobile'] !== $mobile
        || (string)$transaction['topup_operator'] !== $operator
        || (int)$transaction['amount'] !== $amount
        || (string)($transaction['charge_type'] ?? 'normal') !== $chargeType
    ) {
        topup_json([
            'success' => false,
            'status' => 'failed',
            'code' => 'IDEMPOTENCY_KEY_REUSED',
            'message' => 'شناسه درخواست قبلاً برای اطلاعات خرید دیگری استفاده شده است.',
            'data' => [
                'transaction_id' => (int)$transaction['id'],
                'mobile' => (string)$transaction['topup_mobile'],
                'operator' => (string)$transaction['topup_operator'],
                'amount' => (int)$transaction['amount'],
                'charge_type' => (string)($transaction['charge_type'] ?? 'normal'),
                'order_id' => (string)$transaction['idempotency_key'],
            ],
        ], 409);
    }

    $balance = topup_current_balance($pdo, (int)$transaction['user_id']);
    $commissionData = topup_commission_data($pdo, (int)$transaction['id'], (int)$transaction['amount']);
    $status = (string)$transaction['status'];
    $status = in_array($status, ['success', 'failed', 'pending'], true) ? $status : 'pending';
    $trackingCode = trim((string)($transaction['provider_ref_code'] ?? ''));

    topup_json([
        'success' => $status !== 'failed',
        'status' => $status,
        'code' => 'IDEMPOTENT_REPLAY',
        'message' => match ($status) {
            'success' => 'این درخواست قبلاً با موفقیت انجام شده است.',
            'failed' => 'نتیجه این درخواست قبلاً ناموفق ثبت شده است.',
            default => 'این درخواست قبلاً ثبت شده و نتیجه آن در حال پیگیری است.',
        },
        'data' => [
            'transaction_id' => (int)$transaction['id'],
            'order_id' => (string)$transaction['idempotency_key'],
            'tracking_code' => $trackingCode !== '' ? $trackingCode : (string)$transaction['idempotency_key'],
            'ref_code' => $trackingCode !== '' ? $trackingCode : null,
            'mobile' => $mobile,
            'operator' => $operator,
            'amount' => $amount,
            ...$commissionData,
            'charge_type' => $chargeType,
            'balance' => $balance,
            'remaining_balance' => $balance,
        ],
    ]);
}

function topup_update_transaction(
    PDO $pdo,
    int $transactionId,
    array $updates
): void {
    $allowed = [
        'status',
        'description',
        'provider_ref_code',
        'refund_applied',
        'balance_after',
    ];

    $parts = [];
    $values = [];

    foreach ($updates as $column => $value) {
        if (in_array($column, $allowed, true)) {
            $parts[] = $column . ' = ?';
            $values[] = $value;
        }
    }

    if ($parts === []) {
        return;
    }

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $transactionStmt = $pdo->prepare(
            "SELECT idempotency_key
             FROM transactions
             WHERE id = ? AND type = 'topup'
             LIMIT 1
             FOR UPDATE"
        );
        $transactionStmt->execute([$transactionId]);
        $transaction = $transactionStmt->fetch();

        if (!$transaction) {
            throw new RuntimeException('TOPUP_TRANSACTION_NOT_FOUND');
        }

        $rechargeStmt = $pdo->prepare(
            'SELECT status
             FROM mobile_recharges
             WHERE order_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $rechargeStmt->execute([
            (string)$transaction['idempotency_key'],
        ]);

        $rechargeStatus = $rechargeStmt->fetchColumn();
        $requestedStatus = $updates['status'] ?? null;

        $terminalStatus = match ((string)$rechargeStatus) {
            'completed' => 'success',
            'refunded' => 'failed',
            default => null,
        };

        if (
            $terminalStatus !== null
            && $requestedStatus !== $terminalStatus
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return;
        }

        $values[] = $transactionId;

        $sql = "UPDATE transactions
                SET " . implode(', ', $parts) . "
                WHERE id = ? AND type = 'topup'";

        $pdo->prepare($sql)->execute($values);

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function topup_refund_transaction(
    PDO $pdo,
    int $transactionId,
    string $reason,
    array $rechargeDetails = []
): array {
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $columns = $pdo
            ->query('SHOW COLUMNS FROM transactions')
            ->fetchAll(PDO::FETCH_COLUMN, 0);

        $hasRefundApplied = in_array(
            'refund_applied',
            $columns,
            true
        );

        $select = 'id, user_id, amount, status, idempotency_key'
            . ($hasRefundApplied ? ', refund_applied' : '');

        $stmt = $pdo->prepare(
            "SELECT $select
             FROM transactions
             WHERE id = ? AND type = 'topup'
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            throw new RuntimeException('TOPUP_TRANSACTION_NOT_FOUND');
        }

        $rechargeStmt = $pdo->prepare(
            'SELECT status, total_cost
             FROM mobile_recharges
             WHERE order_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $rechargeStmt->execute([
            (string)$transaction['idempotency_key'],
        ]);
        $recharge = $rechargeStmt->fetch();

        if (
            (
                $hasRefundApplied
                && (int)$transaction['refund_applied'] === 1
            )
            || (
                $recharge
                && in_array(
                    (string)$recharge['status'],
                    ['refunded', 'completed', 'failed'],
                    true
                )
            )
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'refunded' => false,
                'already_refunded' => true,
            ];
        }

        if ((string)$transaction['status'] === 'success') {
            throw new RuntimeException('TOPUP_ALREADY_SUCCESS');
        }

        $debitedAmount = (int)($recharge['total_cost'] ?? 0);
        if ($debitedAmount <= 0) {
            throw new RuntimeException('TOPUP_DEBIT_AMOUNT_MISSING');
        }

        $credit = wallet_credit(
            $pdo,
            (int)$transaction['user_id'],
            $debitedAmount,
            'Top-up reversal',
            (string)$transactionId
        );

        $updates = [
            'status' => 'failed',
            'description' => 'خرید شارژ ناموفق؛ مبلغ به کیف پول بازگردانده شد. '
                . $reason,
        ];

        if ($hasRefundApplied) {
            $updates['refund_applied'] = 1;
        }

        if (in_array('balance_after', $columns, true)) {
            $updates['balance_after'] = $credit['balance_after'];
        }

        topup_update_transaction(
            $pdo,
            $transactionId,
            $updates
        );

        topup_update_recharge(
            $pdo,
            (string)$transaction['idempotency_key'],
            array_merge(
                $rechargeDetails,
                [
                    'status' => 'refunded',
                    'error_msg' => $reason,
                ]
            )
        );

        service_fee_update_status($pdo, 'topup', $transactionId, 'refunded');

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'refunded' => true,
            'already_refunded' => false,
            'balance_after' => $credit['balance_after'],
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}
