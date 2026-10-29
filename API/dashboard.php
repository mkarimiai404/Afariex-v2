<?php
declare(strict_types=1);

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/../admin_panel/debt_service.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_request_data(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$data = get_request_data();
$userId = (int)($data['user_id'] ?? $data['id'] ?? $data['uid'] ?? 0);
$apiToken = trim((string)($data['api_token'] ?? $data['token'] ?? $data['user_token'] ?? ''));

if ($userId <= 0 || $apiToken === '') {
    json_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت کاربر انجام نشد.'], 401);
    json_response(['success' => false, 'message' => 'شناسه کاربر نامعتبر است.'], 400);
}

try {
    $userColumns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    $safeOptionalColumns = array_values(array_intersect(['email', 'created_at', 'status', 'account_status', 'is_active'], $userColumns));
    $selectColumns = array_merge(['id', 'mobile', 'first_name', 'last_name', 'balance'], $safeOptionalColumns);
    $stmt = db()->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM users WHERE id = ? AND api_token = ? LIMIT 1');
    $stmt->execute([$userId, $apiToken]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'اطلاعات احراز هویت معتبر نیست.'], 401);
        json_response(['success' => false, 'message' => 'کاربر یافت نشد.'], 404);
    }

    $balance = (float)($user['balance'] ?? 0);
    $outstandingDebt = debt_outstanding_amount($userId);
    $verification = verification_state($userId);
    $verification = array_merge($verification, daily_transaction_summary($userId, $verification));
    $requestStmt = db()->prepare('SELECT request_type, status, rejection_reason, admin_note FROM verification_upgrade_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $requestStmt->execute([$userId]);
    $latestRequest = $requestStmt->fetch() ?: null;
    $verification['upgrade_request_status'] = $latestRequest['status'] ?? null;
    $verification['upgrade_request_type'] = $latestRequest['request_type'] ?? null;
    $verification['rejection_reason'] = $latestRequest['rejection_reason'] ?? ($latestRequest['admin_note'] ?? null);

    $pendingDepositStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'pending'");
    $pendingDepositStmt->execute([$userId]);
    $pendingDeposits = (float)$pendingDepositStmt->fetchColumn();

    $approvedDepositStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'approved'");
    $approvedDepositStmt->execute([$userId]);
    $approvedDeposits = (float)$approvedDepositStmt->fetchColumn();

    $remittanceStmt = db()->prepare("SELECT COALESCE(SUM(amount_toman), 0) FROM remittances WHERE user_id = ?");
    $remittanceStmt->execute([$userId]);
    $totalRemittances = (float)$remittanceStmt->fetchColumn();

    json_response([
        'success' => true,
        'message' => 'اطلاعات کیف پول دریافت شد.',
        'user' => array_merge([
            'id' => (int)$user['id'],
            'mobile' => (string)($user['mobile'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'name' => trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')),
            'balance' => $balance,
            'debt' => [
                'outstanding_toman' => $outstandingDebt,
                'has_debt' => $outstandingDebt > 0,
            ],
            'verification' => $verification,
        ], array_intersect_key($user, array_flip($safeOptionalColumns))),
        'balance' => $balance,
        'debt' => [
            'outstanding_toman' => $outstandingDebt,
            'has_debt' => $outstandingDebt > 0,
        ],
        'verification' => $verification,
        'data' => [
            'balance' => $balance,
            'debt' => [
                'outstanding_toman' => $outstandingDebt,
                'has_debt' => $outstandingDebt > 0,
            ],
            'pending_deposits' => $pendingDeposits,
            'approved_deposits' => $approvedDeposits,
            'total_remittances' => $totalRemittances,
        ],
        'meta' => [
            'api_token_received' => $apiToken !== '',
        ],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'خطا در دریافت اطلاعات کیف پول.'], 500);
}
