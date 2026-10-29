<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../admin_panel/config.php';

function notifications_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    notifications_response(['success' => false, 'message' => 'روش درخواست مجاز نیست.'], 405);
}
$token = trim((string)($_POST['api_token'] ?? ''));
if ($token === '') notifications_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'نشست کاربری معتبر نیست.'], 401);

try {
    $userStmt = db()->prepare('SELECT id, mobile FROM users WHERE api_token = ? LIMIT 1');
    $userStmt->execute([$token]);
    $user = $userStmt->fetch();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) notifications_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    if (!table_exists('notifications')) notifications_response(['success' => true, 'rows' => []]);
    $mobile = trim((string)($user['mobile'] ?? ''));
    $mobileCountStmt = db()->prepare('SELECT COUNT(*) FROM users WHERE BINARY mobile = BINARY ?');
    $mobileCountStmt->execute([$mobile]);
    $allowLegacyMobile = $mobile !== '' && (int)$mobileCountStmt->fetchColumn() === 1 ? 1 : 0;
    $stmt = db()->prepare('SELECT id, title, message, is_read, created_at FROM notifications
        WHERE user_id = ? OR (user_id IS NULL AND ? = 1 AND BINARY mobile = BINARY ?)
        ORDER BY id DESC LIMIT 100');
    $stmt->execute([$userId, $allowLegacyMobile, $mobile]);
    notifications_response(['success' => true, 'rows' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('Notification list failed.');
    notifications_response(['success' => false, 'message' => 'دریافت اعلان‌ها انجام نشد.'], 500);
}
