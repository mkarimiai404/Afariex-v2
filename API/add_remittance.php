<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/../admin_panel/service_fee_service.php';
require_once __DIR__ . '/includes/subcustomer.php';
require_once __DIR__ . '/includes/remittance_destination_countries.php';
require_once __DIR__ . '/includes/remittance_currencies.php';

const REMITTANCE_AFN_TOLERANCE = 0.05;

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
$agency = trim((string)($data['agency'] ?? $data['agency_name'] ?? $data['agency_id'] ?? ''));
$destinationCountryInput = strtoupper(trim((string)($data['destination_country'] ?? '')));
$sourceCurrency = strtoupper(trim((string)($data['source_currency'] ?? 'IRT')));
$destinationCurrency = strtoupper(trim((string)($data['destination_currency'] ?? 'AFN')));
$requestedRateId = null;
if (array_key_exists('rate_id', $data)) {
    $rateIdValue = trim((string)$data['rate_id']);
    if ($rateIdValue === '' || !ctype_digit($rateIdValue) || (int)$rateIdValue <= 0) {
        json_response(['success' => false, 'code' => 'INVALID_EXCHANGE_RATE', 'message' => 'Invalid exchange rate. Please reload the remittance form.'], 422);
    }
    $requestedRateId = (int)$rateIdValue;
}
$requestedRateValue = null;
if (array_key_exists('rate_value', $data)) {
    $rateValue = trim((string)$data['rate_value']);
    if ($rateValue === '' || !is_numeric($rateValue) || !is_finite((float)$rateValue) || (float)$rateValue <= 0) {
        json_response(['success' => false, 'code' => 'INVALID_EXCHANGE_RATE', 'message' => 'Invalid exchange rate. Please reload the remittance form.'], 422);
    }
    $requestedRateValue = (float)$rateValue;
}
$senderName = trim((string)($data['sender_name'] ?? ''));
$receiverName = trim((string)($data['receiver_name'] ?? ''));
$receiverPhone = trim((string)($data['receiver_phone'] ?? ''));
$amountTomanRaw = trim((string)($data['amount_toman'] ?? '0'));
$amountAfghaniRaw = trim((string)($data['amount_afn'] ?? '0'));
$amountToman = is_numeric($amountTomanRaw) ? (float)$amountTomanRaw : 0.0;
$amountAfghani = is_numeric($amountAfghaniRaw) ? (float)$amountAfghaniRaw : 0.0;

if ($userId <= 0) {
    json_response(['success' => false, 'message' => 'شناسه کاربر نامعتبر است.'], 400);
}

if (!is_finite($amountToman) || !is_finite($amountAfghani) || $amountToman <= 0 || $amountAfghani <= 0) {
    json_response(['success' => false, 'message' => 'مبلغ حواله معتبر نیست.'], 400);
}

if ($agency === '' || $senderName === '' || $receiverName === '' || $receiverPhone === '') {
    json_response(['success' => false, 'message' => 'اطلاعات حواله ناقص است.'], 400);
}

if ($destinationCountryInput !== '' && !remittance_destination_country_is_supported($destinationCountryInput)) {
    json_response(['success' => false, 'code' => 'INVALID_DESTINATION_COUNTRY', 'message' => 'کشور مقصد معتبر را انتخاب کنید.'], 422);
}
$destinationCountry = $destinationCountryInput !== '' ? $destinationCountryInput : null;

if ($destinationCountry === null || !remittance_destination_currency_is_supported($destinationCountry, $destinationCurrency)) {
    json_response(['success' => false, 'code' => 'INVALID_DESTINATION_CURRENCY', 'message' => 'ارز مقصد برای این کشور معتبر نیست.'], 422);
}

if (!remittance_is_fixed_rate_pair($sourceCurrency, $destinationCurrency)
    || !remittance_pair_uses_country($sourceCurrency, $destinationCurrency, $destinationCountry)) {
    json_response([
        'success' => false,
        'code' => 'VARIABLE_RATE_REQUIRES_WHATSAPP',
        'message' => 'نرخ این حواله متغیر است. برای دریافت نرخ روز و اطلاعات پرداخت با پشتیبانی واتساپ در ارتباط باشید.',
    ], 422);
}

$rateColumns = db()->query('SHOW COLUMNS FROM exchange_rates')->fetchAll(PDO::FETCH_COLUMN, 0);
$rateColumn = in_array('afn_to_toman', $rateColumns, true)
    ? 'afn_to_toman'
    : (in_array('rate', $rateColumns, true) ? 'rate' : null);
$activeRateRow = false;
if ($rateColumn !== null) {
    // Match submissions against the same newest active rate returned by
    // get_exchange_rates.php; older rows can remain marked active temporarily.
    $activeRateStmt = db()->query("SELECT id, `{$rateColumn}` AS toman_per_afn FROM exchange_rates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $activeRateRow = $activeRateStmt ? $activeRateStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($requestedRateId !== null && (!$activeRateRow || (int)$activeRateRow['id'] !== $requestedRateId)) {
        json_response([
            'success' => false,
            'code' => 'EXCHANGE_RATE_CHANGED',
            'message' => 'The exchange rate changed. The latest rate is included for automatic retry.',
            'data' => ['active_rate' => $activeRateRow ?: null],
        ], 409);
    }
}
$activeRate = $activeRateRow ? (float)($activeRateRow['toman_per_afn'] ?? 0) : 0.0;
if (!is_finite($activeRate) || $activeRate <= 0) {
    json_response([
        'success' => false,
        'code' => 'FIXED_RATE_UNAVAILABLE',
        'message' => 'نرخ ثابت این حواله در دسترس نیست. برای دریافت نرخ روز با پشتیبانی واتساپ در ارتباط باشید.',
    ], 422);
}

if ($requestedRateValue !== null) {
    $rateValueTolerance = max(0.00000001, abs($activeRate) * 0.0000000001);
    if (abs($requestedRateValue - $activeRate) > $rateValueTolerance) {
        json_response([
            'success' => false,
            'code' => 'EXCHANGE_RATE_CHANGED',
            'message' => 'The exchange rate changed. Please reload the remittance form.',
            'data' => [
                'active_rate' => [
                    'id' => (int)($activeRateRow['id'] ?? 0),
                    'toman_per_afn' => $activeRate,
                ],
            ],
        ], 409);
    }
}

$expectedAfghani = $amountToman / $activeRate;
$expectedAfghaniCents = (int)round($expectedAfghani * 100, 0, PHP_ROUND_HALF_UP);
$clientAfghaniCents = (int)round($amountAfghani * 100, 0, PHP_ROUND_HALF_UP);
$expectedAfghaniRounded = $expectedAfghaniCents / 100;

// Both values are rounded to cents before comparison. This mirrors the
// frontend's two-decimal AFN amount and avoids binary float noise while
// retaining the established five-paisa tolerance.
$toleranceCents = (int)round(REMITTANCE_AFN_TOLERANCE * 100, 0, PHP_ROUND_HALF_UP);
if (abs($clientAfghaniCents - $expectedAfghaniCents) > $toleranceCents) {
    json_response([
        'success' => false,
        'code' => 'INVALID_CONVERTED_AMOUNT',
        'message' => 'مبلغ معادل افغانی با نرخ فعال مطابقت ندارد.',
    ], 422);
}

try {
    ensure_verification_schema();
    db()->beginTransaction();

    $userStmt = db()->prepare('SELECT id, balance, overdraft_limit FROM users WHERE api_token = ? LIMIT 1 FOR UPDATE');
    $userStmt->execute([$apiToken]);
    $user = $userStmt->fetch();
    if ($user) {
        $userId = (int)$user['id'];
    }

    if (!$user) {
        db()->rollBack();
        json_response(['success' => false, 'message' => 'کاربر یافت نشد.'], 404);
    }

    subcustomer_require_verified(db(), $userId);
    $balance = (float)($user['balance'] ?? 0);
    $overdraftLimit = (float)($user['overdraft_limit'] ?? 0);
    $availableFunds = $balance + $overdraftLimit;

    $verification = verification_state($userId, true);
    $dailyUsage = daily_transaction_usage($userId, true);
    $dailyLimit = $verification['daily_limit'];
    $dailyRemaining = max(0, (float)$dailyLimit - $dailyUsage);
    if ($amountToman > $dailyRemaining) {
        db()->rollBack();
        json_response(['success' => false, 'code' => 'DAILY_TRANSACTION_LIMIT_EXCEEDED', 'message' => 'سقف انتقال وجه مجاز برای حساب شما تکمیل شده است.', 'data' => ['level' => $verification['level'], 'daily_limit' => $dailyLimit, 'combined_daily_limit' => $dailyLimit, 'used_today' => $dailyUsage, 'remaining_today' => $dailyRemaining, 'requested_amount' => $amountToman, 'upgrade_required' => false]], 422);
    }
    $remittanceUsage = daily_remittance_usage($userId);
    $customRemittanceLimit = $verification['custom_remittance_limit'];
    if ($customRemittanceLimit !== null && $amountToman > max(0, (float)$customRemittanceLimit - $remittanceUsage)) {
        db()->rollBack();
        json_response(['success' => false, 'code' => 'CUSTOM_REMITTANCE_LIMIT_EXCEEDED', 'message' => 'سقف اختصاصی انتقال وجه مجاز برای حساب شما تکمیل شده است.', 'data' => ['custom_remittance_limit' => $customRemittanceLimit, 'remittance_used_today' => $remittanceUsage, 'remaining_remittance_today' => max(0, (float)$customRemittanceLimit - $remittanceUsage), 'requested_amount' => $amountToman]], 422);
    }

    $fee = service_fee_calculate('remittance', (int)round($amountToman), db());
    $totalDeduction = (float)$fee['total_amount'];

    if ($totalDeduction > $availableFunds) {
        db()->rollBack();
        json_response(['success' => false, 'code' => 'INSUFFICIENT_BALANCE', 'message' => 'موجودی و اعتبار کاربر برای مبلغ حواله و کارمزد کافی نیست.', 'data' => ['fee' => $fee]], 422);
    }

    $insertStmt = db()->prepare('
        INSERT INTO remittances
            (user_id, agency, destination_country, sender, receiver, amount_toman, amount_afghani, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');

    $status = 'pending';
    $insertStmt->execute([
        $userId,
        $agency,
        $destinationCountry,
        $senderName,
        $receiverName,
        $amountToman,
        $expectedAfghaniRounded,
        $status,
    ]);

    $remittanceId = (int)db()->lastInsertId();

    $createdStmt = db()->prepare('SELECT r.created_at, r.status, r.sender, r.receiver,
            r.amount_afghani, r.agency, r.destination_country,
            (SELECT a.address FROM agencies a WHERE BINARY a.name = BINARY r.agency ORDER BY a.id DESC LIMIT 1) AS agency_address
        FROM remittances r WHERE r.id = ? AND r.user_id = ? LIMIT 1');
    $createdStmt->execute([$remittanceId, $userId]);
    $savedRemittance = $createdStmt->fetch();
    if (!$savedRemittance) {
        throw new RuntimeException('Committed remittance could not be reloaded.');
    }

    $balanceStmt = db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance + overdraft_limit >= ?');
    $balanceStmt->execute([$totalDeduction, $userId, $totalDeduction]);
    if ($balanceStmt->rowCount() !== 1) {
        throw new RuntimeException('Wallet balance changed before remittance deduction.');
    }
    service_fee_record(db(), $userId, $fee, 'remittance', $remittanceId, 'pending');
    $balanceAfterStmt = db()->prepare('SELECT balance FROM users WHERE id = ?');
    $balanceAfterStmt->execute([$userId]);
    $balanceAfter = (float)$balanceAfterStmt->fetchColumn();

    db()->commit();

    $agencyAddress = trim((string)($savedRemittance['agency_address'] ?? ''));
    $savedAgency = trim((string)($savedRemittance['agency'] ?? $agency));

    json_response([
        'success' => true,
        'message' => 'حواله با موفقیت ثبت شد.',
        'data' => [
            'remittance_id' => $remittanceId,
            'tracking_number' => $remittanceId,
            'code' => $remittanceId,
            'agency_name' => $savedAgency,
            'destination_country' => $savedRemittance['destination_country'] ?? null,
            'destination_country_name' => remittance_destination_countries()[(string)($savedRemittance['destination_country'] ?? '')] ?? null,
            'agency_address' => $agencyAddress,
            'status' => strtolower(trim((string)$savedRemittance['status'])),
            'created_at' => $savedRemittance['created_at'] ?: null,
            'sender' => trim((string)$savedRemittance['sender']),
            'receiver' => trim((string)$savedRemittance['receiver']),
            'amount_afghani' => (float)$savedRemittance['amount_afghani'],
            'amount_toman' => (float)$amountToman,
            'fee' => $fee,
            'new_balance' => $balanceAfter,
            'destination' => $agencyAddress !== '' ? $agencyAddress : $savedAgency,
        ],
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log('Add remittance failed: ' . $e->getMessage());

    if ($e->getMessage() === 'SUBCUSTOMER_VERIFICATION_REQUIRED') {
        json_response(['success' => false, 'code' => 'SUBCUSTOMER_VERIFICATION_REQUIRED', 'message' => 'ابتدا تایید تلفن و احراز هویت را تکمیل کنید.'], 403);
    }
    if ($e->getMessage() === 'SERVICE_FEE_SCHEMA_MISSING') {
        json_response(['success' => false, 'code' => 'SERVICE_FEE_SCHEMA_MISSING', 'message' => 'تنظیمات کارمزد خدمات نصب نشده است.'], 503);
    }

    json_response(['success' => false, 'message' => 'خطا در ثبت حواله.'], 500);
}
