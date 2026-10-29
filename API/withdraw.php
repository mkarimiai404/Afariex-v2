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

require_once __DIR__ . '/../admin_panel/withdrawal_service.php';
require_once __DIR__ . '/includes/subcustomer.php';

function withdraw_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    withdraw_response(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$token = trim((string)($_POST['api_token'] ?? ''));
if ($token === '') {
    withdraw_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت کاربر انجام نشد.'], 401);
}

try {
    $authStmt = db()->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $authStmt->execute([$token]);
    $userId = (int)($authStmt->fetchColumn() ?: 0);
    if ($userId <= 0) {
        withdraw_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'اطلاعات احراز هویت معتبر نیست.'], 401);
    }

    subcustomer_require_verified(db(), $userId);
    $result = withdrawal_create_request(
        $userId,
        (string)($_POST['amount'] ?? ''),
        (string)($_POST['card_number'] ?? ''),
        (string)($_POST['cardholder_name'] ?? ''),
        (string)($_POST['idempotency_key'] ?? ''),
        'customer'
    );

    withdraw_response([
        'success' => true,
        'message' => 'درخواست برداشت شما با موفقیت ثبت شد و در انتظار بررسی است.',
        'data' => [
            'request_id' => $result['id'],
            'status' => $result['status'],
            'balance' => $result['balance'] === '' ? null : (float)$result['balance'],
            'fee' => $result['fee'] ?? null,
        ],
    ]);
} catch (InvalidArgumentException $e) {
    $responses = [
        'INVALID_AMOUNT' => ['INVALID_AMOUNT', 'مبلغ برداشت معتبر نیست.', 422],
        'INVALID_CARD_NUMBER' => ['INVALID_CARD_NUMBER', 'شماره کارت بانکی معتبر نیست.', 422],
        'INVALID_CARDHOLDER_NAME' => ['INVALID_CARDHOLDER_NAME', 'نام کامل صاحب کارت معتبر نیست.', 422],
        'INVALID_IDEMPOTENCY_KEY' => ['INVALID_REQUEST', 'شناسه امن درخواست معتبر نیست.', 422],
    ];
    [$code, $message, $status] = $responses[$e->getMessage()] ?? ['INVALID_REQUEST', 'اطلاعات درخواست معتبر نیست.', 422];
    withdraw_response(['success' => false, 'code' => $code, 'message' => $message], $status);
} catch (DomainException $e) {
    $responses = [
        'INSUFFICIENT_BALANCE' => ['موجودی کیف پول برای این برداشت کافی نیست.', 422],
        'DAILY_TRANSACTION_LIMIT_EXCEEDED' => ['سقف تراکنش روزانه سطح فعلی شما تکمیل شده است.', 422],
    ];
    [$message, $status] = $responses[$e->getMessage()] ?? ['ثبت درخواست برداشت انجام نشد.', 422];
    withdraw_response(['success' => false, 'code' => $e->getMessage(), 'message' => $message], $status);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'SUBCUSTOMER_VERIFICATION_REQUIRED') {
        withdraw_response(['success' => false, 'code' => 'SUBCUSTOMER_VERIFICATION_REQUIRED', 'message' => 'ابتدا تایید تلفن و احراز هویت را تکمیل کنید.'], 403);
    }
    withdraw_response(['success' => false, 'code' => 'WITHDRAWAL_FAILED', 'message' => 'ثبت درخواست برداشت انجام نشد.'], 500);
} catch (Throwable $e) {
    error_log('Withdrawal request failed.');
    if ($e->getMessage() === 'SERVICE_FEE_SCHEMA_MISSING') {
        withdraw_response(['success' => false, 'code' => 'SERVICE_FEE_SCHEMA_MISSING', 'message' => 'تنظیمات کارمزد خدمات نصب نشده است.'], 503);
    }
    withdraw_response(['success' => false, 'code' => 'WITHDRAWAL_FAILED', 'message' => 'ثبت درخواست برداشت انجام نشد.'], 500);
}
