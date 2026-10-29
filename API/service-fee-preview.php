<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/../admin_panel/service_fee_service.php';

function fee_preview_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fee_preview_response(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$apiToken = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? $_POST['user_token'] ?? ''));
$serviceCode = trim((string)($_POST['service_code'] ?? ''));
$amount = service_fee_normalize_money((string)($_POST['amount'] ?? ''));

if ($apiToken === '') {
    fee_preview_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت لازم است.'], 401);
}
if (!in_array($serviceCode, ['remittance', 'withdrawal', 'mobile_recharge', 'internet_package', 'money_transfer', 'other'], true) || $amount === null || $amount <= 0) {
    fee_preview_response(['success' => false, 'code' => 'INVALID_REQUEST', 'message' => 'اطلاعات کارمزد معتبر نیست.'], 422);
}

try {
    $authStmt = db()->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $authStmt->execute([$apiToken]);
    if (!(int)($authStmt->fetchColumn() ?: 0)) {
        fee_preview_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }
    $fee = service_fee_calculate($serviceCode, $amount);
    fee_preview_response(['success' => true, 'data' => ['fee' => $fee]]);
} catch (Throwable $e) {
    if ($e->getMessage() === 'SERVICE_FEE_SCHEMA_MISSING') {
        fee_preview_response(['success' => false, 'code' => 'SERVICE_FEE_SCHEMA_MISSING', 'message' => 'تنظیمات کارمزد خدمات نصب نشده است.'], 503);
    }
    fee_preview_response(['success' => false, 'code' => 'FEE_PREVIEW_FAILED', 'message' => 'محاسبه کارمزد انجام نشد.'], 500);
}
