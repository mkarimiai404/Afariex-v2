<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/service_fee_service.php';

function withdrawal_normalize_amount(string $value): ?string
{
    $value = normalize_persian_digits(trim($value));
    $value = str_replace([',', '٬', ' '], '', $value);
    if (preg_match('/^[1-9]\d{0,15}$/', $value) !== 1) return null;
    return $value;
}

function withdrawal_normalize_card(string $value): ?string
{
    $value = normalize_persian_digits(trim($value));
    $value = str_replace([' ', '-', '‐', '‑', '–'], '', $value);
    return preg_match('/^\d{16}$/', $value) === 1 ? $value : null;
}

function withdrawal_is_valid_iranian_card(string $cardNumber): bool
{
    if (preg_match('/^\d{16}$/', $cardNumber) !== 1 || preg_match('/^(\d)\1{15}$/', $cardNumber) === 1) return false;
    $sum = 0;
    for ($index = 0; $index < 16; $index++) {
        $product = (int)$cardNumber[$index] * ($index % 2 === 0 ? 2 : 1);
        $sum += $product > 9 ? $product - 9 : $product;
    }
    return $sum % 10 === 0;
}

function withdrawal_normalize_cardholder(string $value): ?string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length < 3 || $length > 150) return null;
    if (preg_match("/^[\p{L}\p{M}\s‌'’-]+$/u", $value) !== 1) return null;
    return $value;
}

function withdrawal_validate_idempotency_key(string $value): ?string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value) === 1 ? $value : null;
}

function withdrawal_transaction_has_column(string $column): bool
{
    static $columns = null;
    if ($columns === null) {
        $columns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    return in_array($column, $columns, true);
}

function withdrawal_generate_reference(PDO $pdo): string
{
    do {
        $reference = 'WDR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE withdrawal_reference = ?");
        $stmt->execute([$reference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $reference;
}

function withdrawal_target_status(string $currentStatus, string $action): string
{
    $transitions = [
        'approve' => ['from' => 'pending', 'to' => 'approved'],
        'reject' => ['from' => 'pending', 'to' => 'rejected'],
        'paid' => ['from' => 'approved', 'to' => 'paid'],
    ];
    if (!isset($transitions[$action]) || $transitions[$action]['from'] !== $currentStatus) {
        throw new DomainException('INVALID_STATE_TRANSITION');
    }
    return $transitions[$action]['to'];
}

function withdrawal_insert_notification(PDO $pdo, int $userId, string $title, string $message): void
{
    if (!table_exists('notifications')) {
        throw new RuntimeException('Notifications table is not installed. Run migration 005.');
    }
    $mobileStmt = $pdo->prepare('SELECT mobile FROM users WHERE id = ? LIMIT 1');
    $mobileStmt->execute([$userId]);
    $mobile = trim((string)($mobileStmt->fetchColumn() ?: ''));
    if ($mobile === '') throw new RuntimeException('Notification recipient mobile is missing.');
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, mobile, title, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $stmt->execute([$userId, $mobile, $title, $message]);
}

/** @return array{id:int,balance:string,status:string} */
function withdrawal_create_request(
    int $userId,
    string $amountInput,
    string $cardInput,
    string $cardholderInput,
    string $idempotencyInput,
    string $source,
    ?int $adminId = null
): array {
    $amount = withdrawal_normalize_amount($amountInput);
    $cardNumber = withdrawal_normalize_card($cardInput);
    $cardholderName = withdrawal_normalize_cardholder($cardholderInput);
    $idempotencyKey = withdrawal_validate_idempotency_key($idempotencyInput);
    if ($userId <= 0) throw new InvalidArgumentException('INVALID_USER');
    if ($amount === null) throw new InvalidArgumentException('INVALID_AMOUNT');
    if ($cardNumber === null || !withdrawal_is_valid_iranian_card($cardNumber)) throw new InvalidArgumentException('INVALID_CARD_NUMBER');
    if ($cardholderName === null) throw new InvalidArgumentException('INVALID_CARDHOLDER_NAME');
    if ($idempotencyKey === null) throw new InvalidArgumentException('INVALID_IDEMPOTENCY_KEY');
    if (!in_array($source, ['customer', 'admin'], true)) throw new InvalidArgumentException('INVALID_SOURCE');

    // Schema checks can execute DDL in legacy installations; keep them outside
    // the money transaction because MySQL DDL performs an implicit commit.
    ensure_verification_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) throw new InvalidArgumentException('INVALID_USER');

        $duplicateStmt = $pdo->prepare("SELECT id, status FROM transactions WHERE user_id = ? AND type IN ('withdraw', 'withdrawal') AND idempotency_key = ? LIMIT 1 FOR UPDATE");
        $duplicateStmt->execute([$userId, $idempotencyKey]);
        $duplicate = $duplicateStmt->fetch();
        if ($duplicate) {
            $pdo->commit();
            return ['id' => (int)$duplicate['id'], 'balance' => (string)$user['balance'], 'status' => (string)$duplicate['status']];
        }

        $verification = verification_state($userId, true);
        $dailyLimit = $verification['daily_limit'];
        $dailyUsage = daily_transaction_usage($userId, true);
        if ((float)$amount > max(0, (float)$dailyLimit - $dailyUsage)) throw new DomainException('DAILY_TRANSACTION_LIMIT_EXCEEDED');
        $fee = service_fee_calculate('withdrawal', (int)$amount, $pdo);
        $totalDeduction = (string)$fee['total_amount'];

        $deductStmt = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
        $deductStmt->execute([$totalDeduction, $userId, $totalDeduction]);
        if ($deductStmt->rowCount() !== 1) throw new DomainException('INSUFFICIENT_BALANCE');
        $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
        $balanceStmt->execute([$userId]);
        $balanceAfter = (string)$balanceStmt->fetchColumn();

        $hasWithdrawalReference = withdrawal_transaction_has_column('withdrawal_reference');
        $columns = ['user_id', 'amount', 'type', 'status', 'balance_applied', 'balance_applied_at', 'balance_before', 'balance_after', 'description', 'card_number', 'cardholder_name', 'request_source', 'idempotency_key', 'operator_admin_id'];
        $placeholders = ['?', '?', "'withdraw'", "'pending'", '1', 'NOW()', '?', '?', '?', '?', '?', '?', '?', '?'];
        $values = [
            $userId, $amount, (string)$user['balance'], $balanceAfter,
            'درخواست برداشت وجه از کیف پول', $cardNumber, $cardholderName,
            $source, $idempotencyKey, $adminId,
        ];
        if ($hasWithdrawalReference) {
            $columns[] = 'withdrawal_reference';
            $placeholders[] = '?';
            $values[] = withdrawal_generate_reference($pdo);
        }
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';
        $insert = $pdo->prepare('INSERT INTO transactions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $insert->execute($values);
        $id = (int)$pdo->lastInsertId();
        service_fee_record($pdo, $userId, $fee, 'withdrawal', $id, 'pending');
        $pdo->commit();
        return ['id' => $id, 'balance' => $balanceAfter, 'status' => 'pending', 'fee' => $fee];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $existing = $pdo->prepare("SELECT id, status FROM transactions WHERE user_id = ? AND type IN ('withdraw', 'withdrawal') AND idempotency_key = ? LIMIT 1");
            $existing->execute([$userId, $idempotencyKey]);
            $row = $existing->fetch();
            if ($row) return ['id' => (int)$row['id'], 'balance' => '', 'status' => (string)$row['status']];
        }
        throw $e;
    }
}

function withdrawal_transition(int $requestId, string $action, int $adminId): string
{
    $fromByAction = ['approve' => 'pending', 'paid' => 'approved', 'reject' => 'pending'];
    if ($requestId <= 0 || !isset($fromByAction[$action]) || $adminId <= 0) throw new InvalidArgumentException('INVALID_ACTION');
    $requiredFrom = $fromByAction[$action];
    $pdo = db();
    $ownerStmt = $pdo->prepare("SELECT user_id FROM transactions WHERE id = ? AND type IN ('withdraw', 'withdrawal') AND request_source IN ('customer', 'admin') LIMIT 1");
    $ownerStmt->execute([$requestId]);
    $ownerUserId = (int)($ownerStmt->fetchColumn() ?: 0);
    if ($ownerUserId <= 0) throw new DomainException('REQUEST_NOT_FOUND');
    $pdo->beginTransaction();
    try {
        // Use the same user-then-request lock order as request creation.
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userLock->execute([$ownerUserId]);
        if (!$userLock->fetchColumn()) throw new DomainException('INVALID_USER');
        $stmt = $pdo->prepare("SELECT id, user_id, amount, status, refund_applied FROM transactions WHERE id = ? AND type IN ('withdraw', 'withdrawal') AND request_source IN ('customer', 'admin') LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) throw new DomainException('REQUEST_NOT_FOUND');
        if ((int)$request['user_id'] !== $ownerUserId) throw new DomainException('INVALID_USER');
        $target = withdrawal_target_status((string)$request['status'], $action);

        $timestampColumn = ['approved' => 'approved_at', 'paid' => 'paid_at', 'rejected' => 'rejected_at'][$target];
        $setParts = ['status = ?', "{$timestampColumn} = NOW()", 'operator_admin_id = ?'];
        $updateValues = [$target, $adminId];
        if ($target === 'paid' && withdrawal_transaction_has_column('withdrawal_reference')) {
            $setParts[] = 'withdrawal_reference = COALESCE(NULLIF(withdrawal_reference, \'\'), ?)';
            $updateValues[] = withdrawal_generate_reference($pdo);
        }
        $updateValues[] = $requestId;
        $updateValues[] = $requiredFrom;
        $update = $pdo->prepare('UPDATE transactions SET ' . implode(', ', $setParts) . ' WHERE id = ? AND status = ?');
        $update->execute($updateValues);
        if ($update->rowCount() !== 1) throw new DomainException('INVALID_STATE_TRANSITION');

        $userId = (int)$request['user_id'];
        $amount = (string)$request['amount'];
        $feeRow = service_fee_for_source('withdrawal', $requestId, $pdo);
        $feeAmount = $feeRow ? (int)$feeRow['fee_amount'] : 0;
        $refundAmount = (string)((int)$amount + $feeAmount);
        if ($target === 'rejected') {
            if ((int)$request['refund_applied'] !== 0) throw new DomainException('REFUND_ALREADY_APPLIED');
            $refundMark = $pdo->prepare('UPDATE transactions SET refund_applied = 1 WHERE id = ? AND refund_applied = 0');
            $refundMark->execute([$requestId]);
            if ($refundMark->rowCount() !== 1) throw new DomainException('REFUND_ALREADY_APPLIED');
            $refund = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
            $refund->execute([$refundAmount, $userId]);
            if ($refund->rowCount() !== 1) throw new RuntimeException('Withdrawal refund failed.');
            service_fee_update_status($pdo, 'withdrawal', $requestId, 'refunded');
            withdrawal_insert_notification($pdo, $userId, 'رد درخواست برداشت', 'درخواست برداشت وجه شما به مبلغ ' . number_format((float)$amount) . ' تومان رد شد و مبلغ به همراه کارمزد به موجودی کیف پول شما بازگشت.');
        } elseif ($target === 'paid') {
            service_fee_update_status($pdo, 'withdrawal', $requestId, 'collected');
            withdrawal_insert_notification($pdo, $userId, 'پرداخت برداشت وجه', 'برداشت وجه شما به مبلغ ' . number_format((float)$amount) . ' تومان با موفقیت انجام شد.');
        }
        $pdo->commit();
        return $target;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
