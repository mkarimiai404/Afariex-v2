<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// The shared config starts an admin session; discard any environment warning output
// so this API remains a JSON-only response without changing that shared config.
ob_start();
set_error_handler(static function (): bool { return true; });
require_once __DIR__ . '/../admin_panel/config.php';
restore_error_handler();
ob_end_clean();
ini_set('display_errors', '0');

function profile_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = $_POST;
    if (!$input && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }

    // The token is the only user selector; user_id is deliberately ignored.
    $apiToken = trim((string)($input['api_token'] ?? $input['token'] ?? $input['user_token'] ?? ''));
    if ($apiToken === '') {
        profile_json(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت لازم است.'], 401);
    }

    $columns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    $requiredColumns = ['id', 'mobile', 'first_name', 'last_name'];
    $optionalColumns = ['username', 'full_name', 'email', 'created_at', 'status', 'account_status', 'is_active'];
    $selectColumns = array_merge($requiredColumns, array_values(array_intersect($optionalColumns, $columns)));
    $userStmt = db()->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM users WHERE api_token = ? LIMIT 1');
    $userStmt->execute([$apiToken]);
    $user = $userStmt->fetch();
    if (!$user) {
        profile_json(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }

    $userId = (int)$user['id'];
    ensure_verification_schema();
    $verification = verification_state($userId);
    $limits = daily_transaction_summary($userId, $verification);

    $requestStmt = db()->prepare('SELECT request_type, status, rejection_reason, admin_note FROM verification_upgrade_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $requestStmt->execute([$userId]);
    $latestRequest = $requestStmt->fetch() ?: [];
    $requestStatus = $latestRequest['status'] ?? null;
    $requestType = $latestRequest['request_type'] ?? null;
    $requestReason = $latestRequest['rejection_reason'] ?? $latestRequest['admin_note'] ?? null;
    $verification['upgrade_request_status'] = $requestStatus;
    $verification['upgrade_request_type'] = $requestType;
    $verification['rejection_reason'] = $requestReason;

    $level = $verification['level'];
    $accessLevel = [
        'current_level' => $level,
        'level' => $level,
        'level_title' => $verification['level_title'],
        'verification_status' => $requestStatus ?: ($level === 'gold' ? 'gold_approved' : ($level === 'silver' ? 'silver_approved' : ($verification['bronze_eligible'] ? 'bronze_verified' : 'unverified'))),
        'phone_verified' => $verification['phone_verified'],
        'email_verified' => $verification['email_verified'],
        'upgrade_request_status' => $requestStatus,
        'upgrade_request_type' => $requestType,
        'next_level' => $verification['next_level'],
        'next_level_documents' => $verification['next_level_documents'],
    ];

    $firstName = trim((string)($user['first_name'] ?? ''));
    $lastName = trim((string)($user['last_name'] ?? ''));
    $fullName = trim((string)($user['full_name'] ?? '')) ?: trim($firstName . ' ' . $lastName);
    $accountStatus = $user['account_status'] ?? ($user['status'] ?? null);
    if ($accountStatus === null && array_key_exists('is_active', $user)) {
        $accountStatus = ((int)$user['is_active'] === 1) ? 'active' : 'inactive';
    }

    profile_json([
        'success' => true,
        'code' => 'PROFILE_LOADED',
        'user' => [
            'id' => $userId,
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'created_at' => $user['created_at'] ?? null,
            'account_status' => $accountStatus,
        ],
        'access_level' => $accessLevel,
        'verification' => $verification,
        'limits' => $limits,
        'customers' => ['total' => 0, 'items' => []],
        'orders' => ['total' => 0, 'items' => []],
        'cooperation' => ['referral_code' => null, 'referral_count' => 0, 'earnings' => 0],
        'support' => ['open_tickets' => 0, 'recent_tickets' => []],
        'settings' => [],
    ]);
} catch (Throwable $error) {
    error_log('Profile API failure: ' . $error->getMessage());
    profile_json(['success' => false, 'code' => 'PROFILE_UNAVAILABLE', 'message' => 'دریافت اطلاعات پروفایل انجام نشد.'], 500);
}
