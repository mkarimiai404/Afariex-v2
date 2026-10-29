<?php
declare(strict_types=1);

function subcustomer_users_columns(PDO $pdo): array
{
    static $columns;
    if ($columns !== null) return $columns;
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    return $columns;
}

function subcustomer_parent_expression(array $columns): string
{
    if (in_array('parent_id', $columns, true)) return 'COALESCE(parent_id, 0)';
    if (in_array('referred_by_user_id', $columns, true)) return 'COALESCE(referred_by_user_id, 0)';
    return '0';
}

function subcustomer_permission_expression(array $columns): string
{
    if (in_array('can_create_subuser', $columns, true) && in_array('can_create_subcustomers', $columns, true)) {
        return 'GREATEST(COALESCE(can_create_subuser, 0), COALESCE(can_create_subcustomers, 0))';
    }
    if (in_array('can_create_subuser', $columns, true)) return 'COALESCE(can_create_subuser, 0)';
    if (in_array('can_create_subcustomers', $columns, true)) return 'COALESCE(can_create_subcustomers, 0)';
    return '0';
}

function subcustomer_account_state(PDO $pdo, int $userId): array
{
    $columns = subcustomer_users_columns($pdo);
    $parentExpression = subcustomer_parent_expression($columns);
    $select = 'role, ' . $parentExpression . ' AS parent_link_id';
    $statement = $pdo->prepare('SELECT ' . $select . ' FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $parentId = (int)($row['parent_link_id'] ?? 0);
    $isSubcustomer = $parentId > 0 || (string)($row['role'] ?? '') === 'subcustomer';
    $verification = verification_state($userId);
    $kycStatus = in_array($verification['level'], ['silver', 'gold'], true) ? 'approved' : 'pending';

    return [
        'is_subuser' => $isSubcustomer,
        'parent_id' => $parentId > 0 ? $parentId : null,
        'phone_verified' => (bool)$verification['phone_verified'],
        'kyc_status' => $kycStatus,
        'verification_level' => (string)$verification['level'],
    ];
}

function subcustomer_require_verified(PDO $pdo, int $userId): void
{
    $state = subcustomer_account_state($pdo, $userId);
    if (!$state['is_subuser']) return;

    if (!$state['phone_verified'] || $state['kyc_status'] !== 'approved') {
        throw new RuntimeException('SUBCUSTOMER_VERIFICATION_REQUIRED');
    }
}