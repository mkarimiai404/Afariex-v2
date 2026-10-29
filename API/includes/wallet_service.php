<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin_panel/config.php';

final class WalletInsufficientFundsException extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly float $balance, public readonly float $availableFunds, public readonly float $requiredAmount)
    {
        parent::__construct('INSUFFICIENT_BALANCE');
    }
}

final class WalletOperationException extends RuntimeException {}

function wallet_columns(PDO $pdo): array
{
    static $columns = null;
    return $columns ??= $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
}

function wallet_amount(mixed $amount): float
{
    if (is_string($amount)) $amount = trim($amount);
    if (!is_numeric($amount) || (float)$amount <= 0) throw new WalletOperationException('INVALID_WALLET_AMOUNT');
    return round((float)$amount, 2);
}

function wallet_debit(PDO $pdo, int $userId, mixed $amount, string $reason, string $ref): array
{
    $normalizedAmount = wallet_amount($amount);
    if ($userId <= 0) throw new WalletOperationException('INVALID_WALLET_USER');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $columns = wallet_columns($pdo);
        $hasOverdraft = in_array('overdraft_limit', $columns, true);
        $select = 'id, balance' . ($hasOverdraft ? ', overdraft_limit' : '');
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) throw new WalletOperationException('USER_NOT_FOUND');
        $balance = (float)($user['balance'] ?? 0);
        $overdraft = $hasOverdraft ? (float)($user['overdraft_limit'] ?? 0) : 0.0;
        $availableFunds = $balance + max(0.0, $overdraft);
        if ($availableFunds + 0.000001 < $normalizedAmount) throw new WalletInsufficientFundsException($userId, $balance, $availableFunds, $normalizedAmount);
        $update = $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
        $update->execute([number_format($normalizedAmount, 2, '.', ''), $userId]);
        if ($update->rowCount() !== 1) throw new WalletOperationException('WALLET_DEBIT_FAILED');
        $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
        $balanceStmt->execute([$userId]);
        $newBalance = (float)$balanceStmt->fetchColumn();
        if ($ownsTransaction) $pdo->commit();
        return ['user_id' => $userId, 'amount' => $normalizedAmount, 'balance_before' => $balance, 'balance_after' => $newBalance, 'available_funds' => $availableFunds, 'reason' => $reason, 'ref' => $ref];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function wallet_credit(PDO $pdo, int $userId, mixed $amount, string $reason, string $ref): array
{
    $normalizedAmount = wallet_amount($amount);
    if ($userId <= 0) throw new WalletOperationException('INVALID_WALLET_USER');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) throw new WalletOperationException('USER_NOT_FOUND');
        $balance = (float)($user['balance'] ?? 0);
        $update = $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
        $update->execute([number_format($normalizedAmount, 2, '.', ''), $userId]);
        if ($update->rowCount() !== 1) throw new WalletOperationException('WALLET_CREDIT_FAILED');
        $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
        $balanceStmt->execute([$userId]);
        $newBalance = (float)$balanceStmt->fetchColumn();
        if ($ownsTransaction) $pdo->commit();
        return ['user_id' => $userId, 'amount' => $normalizedAmount, 'balance_before' => $balance, 'balance_after' => $newBalance, 'reason' => $reason, 'ref' => $ref];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}