<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/includes/subcustomer.php';

header('Content-Type: application/json; charset=utf-8');

function referrals_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function masked_mobile(?string $mobile): ?string
{
    $value = trim((string)$mobile);
    if ($value === '') return null;
    $length = strlen($value);
    return $length <= 7 ? str_repeat('*', $length) : substr($value, 0, 4) . str_repeat('*', max(4, $length - 7)) . substr($value, -3);
}

try {
    $input = $_POST;
    if (!$input && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }
    $apiToken = trim((string)($input['api_token'] ?? $input['token'] ?? $input['user_token'] ?? ''));
    if ($apiToken === '') referrals_json(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED'], 401);

    $pdo = db();
    $columns = subcustomer_users_columns($pdo);
    $parentColumn = in_array('parent_id', $columns, true) ? 'parent_id' : (in_array('referred_by_user_id', $columns, true) ? 'referred_by_user_id' : null);
    if ($parentColumn === null) referrals_json(['success' => false, 'code' => 'SUBCUSTOMER_SCHEMA_MISSING'], 503);
    if (!in_array('username', $columns, true)) referrals_json(['success' => false, 'code' => 'SUBCUSTOMER_SCHEMA_MISSING'], 503);

    $userSelect = ['id'];
    foreach (['referral_code', 'created_at'] as $column) {
        if (in_array($column, $columns, true)) $userSelect[] = $column;
    }
    $userSelect[] = subcustomer_permission_expression($columns) . ' AS can_create_subuser';
    $userStmt = $pdo->prepare('SELECT ' . implode(', ', $userSelect) . ' FROM users WHERE api_token = ? LIMIT 1');
    $userStmt->execute([$apiToken]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) referrals_json(['success' => false, 'code' => 'AUTHENTICATION_FAILED'], 401);

    $customerColumns = ['id', 'mobile', 'username'];
    foreach (['full_name', 'first_name', 'last_name', 'created_at', 'role'] as $column) {
        if (in_array($column, $columns, true)) $customerColumns[] = $column;
    }
    $customerStmt = $pdo->prepare('SELECT ' . implode(', ', $customerColumns) . ' FROM users WHERE ' . $parentColumn . ' = ? ORDER BY id DESC');
    $customerStmt->execute([(int)$user['id']]);
    $customers = [];
    foreach ($customerStmt->fetchAll(PDO::FETCH_ASSOC) as $customer) {
        $verification = verification_state((int)$customer['id']);
        $firstName = trim((string)($customer['first_name'] ?? ''));
        $lastName = trim((string)($customer['last_name'] ?? ''));
        $name = trim((string)($customer['full_name'] ?? '')) ?: trim($firstName . ' ' . $lastName);
        $kycApproved = in_array($verification['level'], ['silver', 'gold'], true);
        $customers[] = [
            'id' => (int)$customer['id'],
            'name' => $name !== '' ? $name : null,
            'mobile' => masked_mobile($customer['mobile'] ?? null),
            'username' => $customer['username'] ?? null,
            'registered_at' => $customer['created_at'] ?? null,
            'level' => $verification['level'],
            'level_title' => $verification['level_title'],
            'phone_verified' => (bool)$verification['phone_verified'],
            'kyc_status' => $kycApproved ? 'approved' : 'pending',
            'kyc_approved' => $kycApproved,
            'verified' => (bool)$verification['bronze_eligible'],
        ];
    }

    $referralCode = trim((string)($user['referral_code'] ?? ''));
    if ($referralCode === '') $referralCode = 'AFX-' . str_pad((string)$user['id'], 5, '0', STR_PAD_LEFT);
    $activeCount = count(array_filter($customers, static fn (array $customer): bool => $customer['verified']));
    $canCreate = (bool)$user['can_create_subuser'];
    referrals_json([
        'success' => true,
        'code' => 'CUSTOMERS_LOADED',
        'data' => [
            'referral_code' => $referralCode,
            'can_create_subcustomers' => $canCreate,
            'can_create_subuser' => $canCreate,
            'total_invited' => count($customers),
            'active_invited' => $activeCount,
            'started_at' => $user['created_at'] ?? null,
            'customers' => $customers,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Customers API failure: ' . $error->getMessage());
    referrals_json(['success' => false, 'code' => 'CUSTOMERS_UNAVAILABLE'], 500);
}