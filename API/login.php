<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin === '') {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function login_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    login_json(['success' => false, 'status' => 'error', 'code' => 'METHOD_NOT_ALLOWED'], 405);
}

try {
    require_once __DIR__ . '/../admin_panel/config.php';
    require_once __DIR__ . '/includes/phone.php';
    require_once __DIR__ . '/includes/subcustomer.php';

    $identifier = trim((string)($_POST['username'] ?? $_POST['mobile'] ?? ''));
    $mobile = normalize_iran_mobile($identifier);
    $password = (string)($_POST['password'] ?? '');
    $columns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasUsername = in_array('username', $columns, true);
    if (($mobile === null && (!$hasUsername || !preg_match('/^[A-Za-z0-9_.-]{4,64}$/', $identifier))) || $password === '') {
        login_json(['success' => false, 'status' => 'error', 'code' => 'CREDENTIALS_REQUIRED', 'message' => 'نام کاربری یا شماره موبایل و رمز عبور را وارد کنید.'], 422);
    }

    $select = ['id', 'mobile', 'password', 'api_token'];
    foreach (['username', 'full_name', 'first_name', 'last_name', 'role'] as $optional) {
        if (in_array($optional, $columns, true)) $select[] = $optional;
    }
    $parentExpression = subcustomer_parent_expression($columns);
    $select[] = $parentExpression . ' AS parent_link_id';
    $statement = db()->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE ' . ($hasUsername ? 'mobile = ? OR username = ?' : 'mobile = ?') . ' LIMIT 1');
    $statement->execute($hasUsername ? [$mobile ?? $identifier, $identifier] : [$mobile]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, (string)$user['password'])) {
        login_json(['success' => false, 'status' => 'error', 'code' => 'LOGIN_FAILED', 'message' => 'نام کاربری یا شماره موبایل و رمز عبور نادرست است.'], 401);
    }

    $apiToken = trim((string)($user['api_token'] ?? ''));
    if ($apiToken === '') {
        $apiToken = bin2hex(random_bytes(32));
        $tokenStatement = db()->prepare('UPDATE users SET api_token = ? WHERE id = ?');
        $tokenStatement->execute([$apiToken, (int)$user['id']]);
    }

    $fullName = trim((string)($user['full_name'] ?? ''));
    if ($fullName === '') $fullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    $verification = subcustomer_account_state(db(), (int)$user['id']);
    login_json([
        'success' => true,
        'status' => 'success',
        'code' => 'LOGIN_SUCCESS',
        'data' => [
            'id' => (int)$user['id'],
            'user_id' => (int)$user['id'],
            'mobile' => (string)$user['mobile'],
            'username' => $user['username'] ?? null,
            'full_name' => $fullName,
            'api_token' => $apiToken,
            'is_subuser' => (bool)$verification['is_subuser'],
            'parent_id' => $verification['parent_id'],
            'phone_verified' => (bool)$verification['phone_verified'],
            'kyc_status' => $verification['kyc_status'],
            'verification_level' => $verification['verification_level'],
        ],
    ]);
} catch (Throwable $error) {
    error_log('Login API failure: ' . $error->getMessage());
    login_json(['success' => false, 'status' => 'error', 'code' => 'LOGIN_UNAVAILABLE'], 500);
}
