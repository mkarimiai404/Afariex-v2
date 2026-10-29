<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
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

function withdrawal_receipt_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function withdrawal_receipt_input(): array
{
    $input = $_POST;
    if (!$input && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }
    return $input;
}

function withdrawal_receipt_mask_number(?string $value): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    $length = strlen($digits);
    if ($length < 8) return null;
    return substr($digits, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($digits, -4);
}

function withdrawal_receipt_has_column(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function withdrawal_receipt_missing_reference_column(): never
{
    withdrawal_receipt_json([
        'success' => false,
        'code' => 'MISSING_REFERENCE_COLUMN',
        'message' => 'ستون شماره پیگیری برداشت نصب نشده است. لطفاً migration رسید برداشت را اجرا کنید.',
    ], 503);
}

function withdrawal_receipt_generate_reference(): string
{
    do {
        $reference = 'WDR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE withdrawal_reference = ?');
        $stmt->execute([$reference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $reference;
}

function withdrawal_receipt_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid', 'completed', 'success' => 'موفقیتآمیز',
        'rejected' => 'رد شده',
        'cancelled', 'canceled' => 'لغو شده',
        default => 'در انتظار انتقال',
    };
}

function withdrawal_receipt_state(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid', 'completed', 'success' => 'completed',
        'rejected' => 'rejected',
        'cancelled', 'canceled' => 'cancelled',
        default => 'pending',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    withdrawal_receipt_json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$input = withdrawal_receipt_input();
$apiToken = trim((string)($input['api_token'] ?? $input['token'] ?? $input['user_token'] ?? ''));
$withdrawalId = (int)($input['withdrawal_id'] ?? $input['id'] ?? 0);

if ($apiToken === '') {
    withdrawal_receipt_json(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت لازم است.'], 401);
}
if ($withdrawalId <= 0) {
    withdrawal_receipt_json(['success' => false, 'code' => 'INVALID_WITHDRAWAL_ID', 'message' => 'شناسه برداشت معتبر نیست.'], 422);
}

try {
    $authStmt = db()->prepare('SELECT id, mobile, first_name, last_name FROM users WHERE api_token = ? LIMIT 1');
    $authStmt->execute([$apiToken]);
    $authUser = $authStmt->fetch();
    if (!$authUser) {
        withdrawal_receipt_json(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }

    if (!withdrawal_receipt_has_column('transactions', 'withdrawal_reference')) {
        withdrawal_receipt_missing_reference_column();
    }

    $stmt = db()->prepare("SELECT
            t.id, t.user_id, t.amount, t.status, t.created_at, t.approved_at, t.paid_at, t.rejected_at,
            t.card_number, t.cardholder_name, t.tracking_code, t.withdrawal_reference,
            u.mobile, u.first_name, u.last_name
        FROM transactions t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.id = ? AND t.type IN ('withdraw', 'withdrawal')
        LIMIT 1");
    $stmt->execute([$withdrawalId]);
    $withdrawal = $stmt->fetch();
    if (!$withdrawal) {
        withdrawal_receipt_json(['success' => false, 'code' => 'RECEIPT_NOT_FOUND', 'message' => 'رسید برداشت پیدا نشد.'], 404);
    }
    if ((int)$withdrawal['user_id'] !== (int)$authUser['id']) {
        withdrawal_receipt_json(['success' => false, 'code' => 'UNAUTHORIZED_RECEIPT', 'message' => 'دسترسی به این رسید مجاز نیست.'], 403);
    }

    $reference = trim((string)($withdrawal['withdrawal_reference'] ?? ''));
    if ($reference === '') {
        $reference = withdrawal_receipt_generate_reference();
        $update = db()->prepare('UPDATE transactions SET withdrawal_reference = ? WHERE id = ? AND (withdrawal_reference IS NULL OR withdrawal_reference = \'\')');
        $update->execute([$reference, $withdrawalId]);
    }

    $status = strtolower(trim((string)$withdrawal['status']));
    $state = withdrawal_receipt_state($status);
    $time = match ($state) {
        'completed' => $withdrawal['paid_at'] ?: $withdrawal['approved_at'] ?: $withdrawal['created_at'],
        'rejected', 'cancelled' => $withdrawal['rejected_at'] ?: $withdrawal['created_at'],
        default => $withdrawal['created_at'],
    };
    $amountToman = (float)$withdrawal['amount'];
    $feeRow = service_fee_for_source('withdrawal', $withdrawalId);
    $feeAmount = $feeRow ? (int)$feeRow['fee_amount'] : 0;
    $totalCharged = (int)$amountToman + $feeAmount;
    $sourceAccount = trim((string)getenv('AFIREX_WITHDRAWAL_SOURCE_ACCOUNT'));
    $sourceAccountName = trim((string)getenv('AFIREX_WITHDRAWAL_SOURCE_ACCOUNT_NAME'));
    $supportPhone = trim((string)getenv('AFIREX_SUPPORT_PHONE'));

    withdrawal_receipt_json([
        'success' => true,
        'code' => 'WITHDRAWAL_RECEIPT_LOADED',
        'data' => [
            'id' => (int)$withdrawal['id'],
            'type' => 'withdrawal',
            'status' => $status,
            'receipt_state' => $state,
            'status_label' => withdrawal_receipt_status_label($status),
            'message' => $state === 'completed'
                ? 'عملیات انتقال وجه با موفقیت انجام شد.'
                : ($state === 'rejected' ? 'درخواست برداشت رد شده است.' : ($state === 'cancelled' ? 'درخواست برداشت لغو شده است.' : 'درخواست برداشت در انتظار انتقال است.')),
            'amount_toman' => $amountToman,
            'amount_rial' => $amountToman * 10,
            'currency' => 'ریال',
            'fee' => [
                'fee_amount_toman' => $feeAmount,
                'total_charged_toman' => $totalCharged,
                'fee_type' => $feeRow ? (string)$feeRow['configured_fee_type'] : 'fixed',
                'fee_value' => $feeRow ? (string)$feeRow['configured_fee_value'] : '0',
                'status' => $feeRow ? (string)$feeRow['status'] : null,
            ],
            'source' => [
                'name' => $sourceAccountName !== '' ? $sourceAccountName : 'حساب آفاریکس',
                'account_masked' => withdrawal_receipt_mask_number($sourceAccount),
            ],
            'destination' => [
                'card_masked' => withdrawal_receipt_mask_number((string)$withdrawal['card_number']),
                'cardholder_name' => trim((string)$withdrawal['cardholder_name']),
            ],
            'tracking_number' => $reference,
            'created_at' => $withdrawal['created_at'],
            'approved_at' => $withdrawal['approved_at'],
            'paid_at' => $withdrawal['paid_at'],
            'rejected_at' => $withdrawal['rejected_at'],
            'receipt_time' => $time,
            'support_phone' => $supportPhone !== '' ? $supportPhone : null,
            'website' => 'www.afirex.ir',
        ],
    ]);
} catch (PDOException $e) {
    error_log('Withdrawal receipt database failure: ' . $e->getMessage());
    withdrawal_receipt_json(['success' => false, 'code' => 'RECEIPT_DB_ERROR', 'message' => 'خطای پایگاه داده در دریافت رسید برداشت.'], 500);
} catch (Throwable $e) {
    error_log('Withdrawal receipt API failure: ' . $e->getMessage());
    withdrawal_receipt_json(['success' => false, 'code' => 'WITHDRAWAL_RECEIPT_UNAVAILABLE', 'message' => 'دریافت رسید برداشت انجام نشد.'], 500);
}
