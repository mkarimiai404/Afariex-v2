<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function service_fee_schema_ready(): bool
{
    return table_exists('service_fees') && table_exists('service_fee_transactions');
}

function service_fee_share_schema_ready(): bool
{
    return service_fee_schema_ready() && table_exists('user_fee_share_rules') && table_exists('user_fee_share_transactions');
}

function service_fee_require_schema(): void
{
    if (!service_fee_schema_ready()) {
        throw new RuntimeException('SERVICE_FEE_SCHEMA_MISSING');
    }
}

function service_fee_normalize_money(string $value): ?int
{
    $normalized = normalize_persian_digits(trim($value));
    $normalized = str_replace([',', '٬', ' '], '', $normalized);
    if (preg_match('/^(?:0|[1-9]\d*)$/', $normalized) !== 1) {
        return null;
    }
    return (int)$normalized;
}

function service_fee_normalize_percent(string $value): ?string
{
    $normalized = normalize_persian_digits(trim($value));
    $normalized = str_replace([',', '٬', ' '], '', $normalized);
    if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', $normalized) !== 1) {
        return null;
    }
    return number_format((float)$normalized, 4, '.', '');
}

function service_fee_status_fa(string $status): string
{
    return match ($status) {
        'pending' => 'در انتظار',
        'collected' => 'دریافت شده',
        'refunded' => 'برگشت داده شده',
        'cancelled' => 'لغو شده',
        default => $status,
    };
}

function service_fee_type_fa(string $type): string
{
    return match ($type) {
        'fixed' => 'مبلغ ثابت',
        'percentage' => 'درصدی',
        default => $type,
    };
}

function service_fee_name(string $serviceCode): string
{
    return match ($serviceCode) {
        'remittance' => 'حواله',
        'withdrawal' => 'انتقال وجه / برداشت',
        'mobile_recharge' => 'خرید شارژ موبایل',
        'internet_package' => 'خرید بسته اینترنت',
        'money_transfer' => 'انتقال وجه',
        default => 'سایر خدمات',
    };
}

function service_fee_share_type_fa(string $type): string
{
    return match ($type) {
        'fixed' => 'مبلغ ثابت',
        'percentage' => 'درصدی',
        default => $type,
    };
}

function service_fee_calculate(string $serviceCode, int $amountToman, ?PDO $pdo = null): array
{
    service_fee_require_schema();
    if ($amountToman < 0) {
        throw new InvalidArgumentException('INVALID_FEE_AMOUNT');
    }
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT service_code, service_name, fee_type, fee_value, min_fee, max_fee, is_active FROM service_fees WHERE service_code = ? LIMIT 1');
    $stmt->execute([$serviceCode]);
    $rule = $stmt->fetch();

    if (!$rule || (int)($rule['is_active'] ?? 0) !== 1) {
        return [
            'service_code' => $serviceCode,
            'service_name' => service_fee_name($serviceCode),
            'original_amount' => $amountToman,
            'fee_type' => 'fixed',
            'fee_value' => '0.0000',
            'fee_amount' => 0,
            'total_amount' => $amountToman,
            'is_active' => false,
        ];
    }

    $feeType = (string)$rule['fee_type'];
    $feeValue = (float)$rule['fee_value'];
    $feeAmount = 0;
    if ($feeType === 'fixed') {
        $feeAmount = max(0, (int)round($feeValue, 0, PHP_ROUND_HALF_UP));
    } elseif ($feeType === 'percentage') {
        $feeAmount = max(0, (int)round(($amountToman * $feeValue) / 100, 0, PHP_ROUND_HALF_UP));
    } else {
        throw new RuntimeException('INVALID_FEE_TYPE');
    }

    if ($rule['min_fee'] !== null) {
        $feeAmount = max($feeAmount, (int)$rule['min_fee']);
    }
    if ($rule['max_fee'] !== null) {
        $feeAmount = min($feeAmount, (int)$rule['max_fee']);
    }

    return [
        'service_code' => $serviceCode,
        'service_name' => (string)$rule['service_name'],
        'original_amount' => $amountToman,
        'fee_type' => $feeType,
        'fee_value' => number_format($feeValue, 4, '.', ''),
        'fee_amount' => $feeAmount,
        'total_amount' => $amountToman + $feeAmount,
        'is_active' => true,
    ];
}

function service_fee_record(PDO $pdo, int $userId, array $calculation, string $sourceType, int $sourceId, string $status = 'pending'): int
{
    service_fee_require_schema();
    if ($userId <= 0 || $sourceType === '' || $sourceId <= 0) {
        throw new InvalidArgumentException('INVALID_FEE_SOURCE');
    }
    $stmt = $pdo->prepare("INSERT INTO service_fee_transactions
        (user_id, service_code, source_type, source_id, original_amount, configured_fee_type, configured_fee_value, fee_amount, total_amount, status, created_at, status_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    try {
        $stmt->execute([
            $userId,
            $calculation['service_code'],
            $sourceType,
            $sourceId,
            (int)$calculation['original_amount'],
            $calculation['fee_type'],
            $calculation['fee_value'],
            (int)$calculation['fee_amount'],
            (int)$calculation['total_amount'],
            $status,
        ]);
        $feeTransactionId = (int)$pdo->lastInsertId();
        if ($status === 'collected') {
            service_fee_share_apply_for_fee($pdo, $feeTransactionId);
        }
        return $feeTransactionId;
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $existing = $pdo->prepare('SELECT id FROM service_fee_transactions WHERE source_type = ? AND source_id = ? LIMIT 1');
        $existing->execute([$sourceType, $sourceId]);
        $feeTransactionId = (int)($existing->fetchColumn() ?: 0);
        if ($status === 'collected' && $feeTransactionId > 0) {
            service_fee_share_apply_for_fee($pdo, $feeTransactionId);
        }
        return $feeTransactionId;
    }
}

function service_fee_for_source(string $sourceType, int $sourceId, ?PDO $pdo = null): ?array
{
    if (!service_fee_schema_ready() || $sourceType === '' || $sourceId <= 0) {
        return null;
    }
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT * FROM service_fee_transactions WHERE source_type = ? AND source_id = ? LIMIT 1');
    $stmt->execute([$sourceType, $sourceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function service_fee_update_status(PDO $pdo, string $sourceType, int $sourceId, string $status): void
{
    if (!service_fee_schema_ready()) {
        return;
    }
    if (!in_array($status, ['pending', 'collected', 'refunded', 'cancelled'], true)) {
        throw new InvalidArgumentException('INVALID_FEE_STATUS');
    }
    $stmt = $pdo->prepare('UPDATE service_fee_transactions SET status = ?, status_updated_at = NOW() WHERE source_type = ? AND source_id = ? AND status <> ?');
    $stmt->execute([$status, $sourceType, $sourceId, $status]);
    if ($status === 'collected') {
        $feeStmt = $pdo->prepare('SELECT id FROM service_fee_transactions WHERE source_type = ? AND source_id = ? LIMIT 1');
        $feeStmt->execute([$sourceType, $sourceId]);
        $feeTransactionId = (int)($feeStmt->fetchColumn() ?: 0);
        if ($feeTransactionId > 0) {
            service_fee_share_apply_for_fee($pdo, $feeTransactionId);
        }
    }
}

function service_fee_share_apply_for_fee(PDO $pdo, int $feeTransactionId): ?int
{
    if (!service_fee_share_schema_ready() || $feeTransactionId <= 0) {
        return null;
    }

    $feeStmt = $pdo->prepare("SELECT * FROM service_fee_transactions WHERE id = ? AND status = 'collected' LIMIT 1 FOR UPDATE");
    $feeStmt->execute([$feeTransactionId]);
    $fee = $feeStmt->fetch();
    if (!$fee) {
        return null;
    }

    $feeAmount = (int)($fee['fee_amount'] ?? 0);
    if ($feeAmount <= 0) {
        return null;
    }

    $childUserId = (int)($fee['user_id'] ?? 0);
    $childStmt = $pdo->prepare('SELECT id, referred_by_user_id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    $childStmt->execute([$childUserId]);
    $child = $childStmt->fetch();
    $parentUserId = (int)($child['referred_by_user_id'] ?? 0);
    if (!$child || $parentUserId <= 0 || $parentUserId === $childUserId) {
        return null;
    }

    $parentStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    $parentStmt->execute([$parentUserId]);
    if (!$parentStmt->fetchColumn()) {
        return null;
    }

    $ruleStmt = $pdo->prepare("SELECT * FROM user_fee_share_rules WHERE parent_user_id = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
    $ruleStmt->execute([$parentUserId]);
    $rule = $ruleStmt->fetch();
    if (!$rule) {
        return null;
    }

    $shareType = (string)$rule['share_type'];
    $shareValue = (float)$rule['share_value'];
    if ($shareType === 'percentage') {
        $shareAmount = (int)round(($feeAmount * $shareValue) / 100, 0, PHP_ROUND_HALF_UP);
    } elseif ($shareType === 'fixed') {
        $shareAmount = (int)round($shareValue, 0, PHP_ROUND_HALF_UP);
    } else {
        return null;
    }

    $shareAmount = max(0, min($shareAmount, $feeAmount));
    if ($shareAmount <= 0) {
        return null;
    }

    try {
        $insert = $pdo->prepare("INSERT INTO user_fee_share_transactions
            (parent_user_id, child_user_id, service_fee_transaction_id, source_type, source_id, original_fee_amount, share_type, configured_share_value, share_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', NOW())");
        $insert->execute([
            $parentUserId,
            $childUserId,
            $feeTransactionId,
            (string)$fee['source_type'],
            (int)$fee['source_id'],
            $feeAmount,
            $shareType,
            number_format($shareValue, 4, '.', ''),
            $shareAmount,
        ]);
        $shareTransactionId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $existing = $pdo->prepare('SELECT id FROM user_fee_share_transactions WHERE parent_user_id = ? AND source_type = ? AND source_id = ? LIMIT 1');
        $existing->execute([$parentUserId, (string)$fee['source_type'], (int)$fee['source_id']]);
        return (int)($existing->fetchColumn() ?: 0) ?: null;
    }

    $credit = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
    $credit->execute([$shareAmount, $parentUserId]);
    if ($credit->rowCount() !== 1) {
        throw new RuntimeException('PARENT_SHARE_CREDIT_FAILED');
    }

    return $shareTransactionId;
}
