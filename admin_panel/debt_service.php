<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function debt_schema_ready(): bool
{
    return table_exists('user_debts') && table_exists('user_debt_payments');
}

function debt_require_schema(): void
{
    if (!debt_schema_ready()) {
        throw new RuntimeException('DEBT_SCHEMA_MISSING');
    }
}

function debt_normalize_amount(string $value): ?string
{
    $normalized = normalize_persian_digits(trim($value));
    $normalized = str_replace([',', ' '], '', $normalized);
    if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $normalized) !== 1) {
        return null;
    }
    if ((float)$normalized <= 0) {
        return null;
    }
    return number_format((float)$normalized, 2, '.', '');
}

function debt_status_fa(string $status): string
{
    return match ($status) {
        'active' => 'تسویه نشده',
        'partially_paid' => 'تسویه جزئی',
        'settled' => 'تسویه شده',
        'cancelled' => 'لغو شده',
        default => $status,
    };
}

function debt_outstanding_amount(int $userId, ?PDO $pdo = null, bool $forUpdate = false): float
{
    if (!debt_schema_ready()) {
        return 0.0;
    }
    $pdo ??= db();
    $sql = "SELECT COALESCE(SUM(remaining_amount), 0) FROM user_debts WHERE user_id = ? AND status IN ('active', 'partially_paid')";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

function debt_create(int $userId, string $amountInput, string $description, ?int $adminId): int
{
    debt_require_schema();
    $amount = debt_normalize_amount($amountInput);
    if ($userId <= 0 || $amount === null) {
        throw new InvalidArgumentException('INVALID_DEBT_INPUT');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        if (!$userStmt->fetchColumn()) {
            throw new DomainException('USER_NOT_FOUND');
        }

        $stmt = $pdo->prepare("INSERT INTO user_debts
            (user_id, original_amount, remaining_amount, status, description, created_by_admin_id, created_at, updated_at)
            VALUES (?, ?, ?, 'active', ?, ?, NOW(), NOW())");
        $stmt->execute([$userId, $amount, $amount, $description !== '' ? $description : null, $adminId]);
        $debtId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $debtId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function debt_cancel_unpaid(int $debtId, ?int $adminId): void
{
    debt_require_schema();
    if ($debtId <= 0) {
        throw new InvalidArgumentException('INVALID_DEBT_ID');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, original_amount, remaining_amount, status FROM user_debts WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$debtId]);
        $debt = $stmt->fetch();
        if (!$debt) {
            throw new DomainException('DEBT_NOT_FOUND');
        }
        if (!in_array((string)$debt['status'], ['active', 'partially_paid'], true)) {
            throw new DomainException('DEBT_NOT_CANCELLABLE');
        }
        if ((float)$debt['remaining_amount'] < (float)$debt['original_amount']) {
            throw new DomainException('DEBT_ALREADY_PARTIALLY_SETTLED');
        }

        $update = $pdo->prepare("UPDATE user_debts SET status = 'cancelled', cancelled_by_admin_id = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
        $update->execute([$adminId, $debtId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function debt_apply_user_credit(PDO $pdo, int $userId, string $incomingAmount, ?int $sourceTransactionId, string $sourceType): array
{
    debt_require_schema();
    $credit = (float)(debt_normalize_amount($incomingAmount) ?? '0');
    if ($userId <= 0 || $credit <= 0) {
        throw new InvalidArgumentException('INVALID_CREDIT_INPUT');
    }

    $userStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        throw new DomainException('USER_NOT_FOUND');
    }

    $existingBalance = (float)($user['balance'] ?? 0);
    $totalFunds = $existingBalance + $credit;
    $remainingFunds = $totalFunds;
    $settledTotal = 0.0;
    $settlements = [];

    $debtStmt = $pdo->prepare("SELECT id, remaining_amount FROM user_debts
        WHERE user_id = ? AND status IN ('active', 'partially_paid') AND remaining_amount > 0
        ORDER BY created_at ASC, id ASC
        FOR UPDATE");
    $debtStmt->execute([$userId]);
    $debts = $debtStmt->fetchAll();

    foreach ($debts as $debt) {
        if ($remainingFunds <= 0) {
            break;
        }

        $debtId = (int)$debt['id'];
        $debtRemaining = (float)$debt['remaining_amount'];
        if ($debtRemaining <= 0) {
            continue;
        }

        $payment = min($remainingFunds, $debtRemaining);
        $newRemaining = max(0.0, $debtRemaining - $payment);
        $newStatus = $newRemaining <= 0.00001 ? 'settled' : 'partially_paid';

        $paymentStmt = $pdo->prepare('INSERT INTO user_debt_payments (debt_id, user_id, amount, source_transaction_id, source_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $paymentStmt->execute([$debtId, $userId, number_format($payment, 2, '.', ''), $sourceTransactionId, $sourceType]);
        $paymentId = (int)$pdo->lastInsertId();

        $updateStmt = $pdo->prepare("UPDATE user_debts SET remaining_amount = ?, status = ?, settled_at = CASE WHEN ? = 'settled' THEN NOW() ELSE settled_at END, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([number_format($newRemaining, 2, '.', ''), $newStatus, $newStatus, $debtId]);

        $remainingFunds -= $payment;
        $settledTotal += $payment;
        $settlements[] = [
            'payment_id' => $paymentId,
            'debt_id' => $debtId,
            'amount' => $payment,
            'remaining_amount' => $newRemaining,
            'status' => $newStatus,
        ];
    }

    $finalBalance = max(0.0, $remainingFunds);
    $walletStmt = $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?');
    if (!$walletStmt->execute([number_format($finalBalance, 2, '.', ''), $userId])) {
        throw new RuntimeException('WALLET_CREDIT_FAILED');
    }

    $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
    $balanceStmt->execute([$userId]);

    return [
        'incoming_amount' => $credit,
        'balance_before' => $existingBalance,
        'total_available_funds' => $totalFunds,
        'debt_settled_amount' => $settledTotal,
        'wallet_credit_amount' => max(0.0, $finalBalance - $existingBalance),
        'balance_after' => (float)$balanceStmt->fetchColumn(),
        'settlements' => $settlements,
    ];
}
