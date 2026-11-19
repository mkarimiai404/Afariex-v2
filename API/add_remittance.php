<?php
/**
 * Afariex - API: add_remittance.php
 * نسخه یکپارچه، امن و سازگار با دیتابیس و توابع سراسری
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// بارگذاری تنظیمات و فایل‌های وابسته
require_once __DIR__ . '/../admin_panel/config.php';

if (file_exists(__DIR__ . '/../admin_panel/service_fee_service.php')) {
    require_once __DIR__ . '/../admin_panel/service_fee_service.php';
}
if (file_exists(__DIR__ . '/../admin_panel/exchange_rate_service.php')) {
    require_once __DIR__ . '/../admin_panel/exchange_rate_service.php';
}
if (file_exists(__DIR__ . '/includes/subcustomer_validation.php')) {
    require_once __DIR__ . '/includes/subcustomer_validation.php';
}
if (file_exists(__DIR__ . '/includes/remittance_destinations.php')) {
    require_once __DIR__ . '/includes/remittance_destinations.php';
}
if (file_exists(__DIR__ . '/includes/remittance_currencies.php')) {
    require_once __DIR__ . '/includes/remittance_currencies.php';
}

// -------------------------------------------------------------
// توابع کمکی درون‌برنامه‌ای (Fallback در صورت نبود در سرویس‌ها)
// -------------------------------------------------------------
if (!function_exists('exchange_rate_parse_minor_units')) {
    function exchange_rate_parse_minor_units($val): float {
        if ($val === null || $val === '') return 0.0;
        return round((float)$val, 4);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $payload, int $statusCode = 200): void {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function get_request_data(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return !empty($_POST) ? $_POST : [];
}

// بررسی روش ارسال
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'روش ارسال درخواست نامعتبر است.'], 405);
}

$data = get_request_data();

// فیلدهای ضروری
$userIdRaw = $data['user_id'] ?? null;
$apiToken = trim((string)($data['api_token'] ?? ''));
$agency = trim((string)($data['agency'] ?? ''));
$destinationCountry = trim((string)($data['destination_country'] ?? 'افغانستان'));
$sourceCurrency = trim((string)($data['source_currency'] ?? 'IRT'));
$destinationCurrency = trim((string)($data['destination_currency'] ?? 'AFN'));
$sender = trim((string)($data['sender_name'] ?? ($data['sender'] ?? '')));
$receiver = trim((string)($data['receiver_name'] ?? ($data['receiver'] ?? '')));
$receiverPhone = trim((string)($data['receiver_phone'] ?? ''));
$amountTomanRaw = $data['amount_toman'] ?? null;
$amountAfnRaw = $data['amount_afn'] ?? ($data['amount_afghani'] ?? null);
$rateId = isset($data['rate_id']) ? (int)$data['rate_id'] : null;

if (!$userIdRaw || $apiToken === '' || $agency === '' || $sender === '' || $receiver === '' || $amountTomanRaw === null) {
    json_response(['success' => false, 'message' => 'تمام فیلدهای الزامی باید تکمیل شوند.'], 422);
}

$userId = (int)$userIdRaw;
$amountToman = (float)$amountTomanRaw;
if ($amountToman <= 0) {
    json_response(['success' => false, 'message' => 'مبلغ ارسال باید بزرگتر از صفر باشد.'], 422);
}

try {
    $pdo = db();

    // 1. دریافت و اعتبارسنجی نرخ ارز
    $rateRow = null;
    $rateColumns = [];
    $stmtCols = $pdo->query("SHOW COLUMNS FROM exchange_rates");
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $rateColumns[] = $col['Field'];
    }

    $rateField = in_array('afn_to_toman', $rateColumns, true) ? 'afn_to_toman' : 'rate';

    if ($rateId) {
        $stmtRate = $pdo->prepare("SELECT * FROM exchange_rates WHERE id = :id LIMIT 1");
        $stmtRate->execute(['id' => $rateId]);
        $rateRow = $stmtRate->fetch(PDO::FETCH_ASSOC);
    }

    if (!$rateRow) {
        $stmtRate = $pdo->prepare("SELECT * FROM exchange_rates ORDER BY id DESC LIMIT 1");
        $stmtRate->execute();
        $rateRow = $stmtRate->fetch(PDO::FETCH_ASSOC);
    }

    if (!$rateRow || empty($rateRow[$rateField])) {
        json_response(['success' => false, 'message' => 'نرخ ارز فعالی در سیستم یافت نشد.'], 400);
    }

    $tomanPerAfn = (float)$rateRow[$rateField];
    if ($tomanPerAfn <= 0) {
        json_response(['success' => false, 'message' => 'نرخ ارز معتبر نیست.'], 400);
    }

    // محاسبه مبلغ دریافتی نهایی
    $amountAfn = round($amountToman / $tomanPerAfn, 2);

    // شروع تراکنش
    $pdo->beginTransaction();

    // 2. احراز هویت و بررسی موجودی کاربر با Lock
    $userStmt = $pdo->prepare("SELECT id, balance, status FROM users WHERE id = :id AND (api_token = :token OR token = :token) FOR UPDATE");
    $userStmt->execute(['id' => $userId, 'token' => $apiToken]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'توکن نامعتبر است یا کاربر یافت نشد.'], 401);
    }

    if (isset($user['status']) && $user['status'] !== 'active') {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'حساب کاربری شما فعال نیست.'], 403);
    }

    // 3. محاسبه کارمزد (در صورت وجود سیستم کارمزد)
    $feeAmount = 0;
    if (function_exists('service_fee_calculate')) {
        try {
            $feeRes = service_fee_calculate('remittance', (int)round($amountToman), $pdo);
            $feeAmount = isset($feeRes['total_amount']) ? (float)$feeRes['total_amount'] : 0.0;
        } catch (\Throwable $e) {
            $feeAmount = 0.0;
        }
    }

    $totalRequired = $amountToman + $feeAmount;
    $currentBalance = (float)($user['balance'] ?? 0);

    if ($currentBalance < $totalRequired) {
        $pdo->rollBack();
        json_response([
            'success' => false, 
            'message' => 'موجودی حساب کافی نیست.',
            'required' => $totalRequired,
            'balance' => $currentBalance
        ], 400);
    }

    // 4. کسر موجودی
    $newBalance = $currentBalance - $totalRequired;
    $upUser = $pdo->prepare("UPDATE users SET balance = :b WHERE id = :id");
    $upUser->execute(['b' => $newBalance, 'id' => $userId]);

    // 5. بررسی ساختار جدول remittances برای تطابق پویا
    $remitCols = [];
    $stmtRCols = $pdo->query("SHOW COLUMNS FROM remittances");
    while ($rcol = $stmtRCols->fetch(PDO::FETCH_ASSOC)) {
        $remitCols[] = $rcol['Field'];
    }

    // مقادیر پایه
    // مقادیر پایه
    $insertData = [
        'user_id'             => $userId,
        'agency'              => $agency,
        'destination_country' => $destinationCountry,
        'sender'              => $sender,
        'receiver'            => $receiver,
        'amount_toman'        => $amountToman,
        'amount_afghani'      => $amountAfn,
        'created_at'          => date('Y-m-d H:i:s')
    ];

    // تطبیق فیلدهای اختیاری در صورت وجود در دیتابیس
    if (in_array('status', $remitCols, true)) {
        $insertData['status'] = 'pending';
    }
    if (in_array('receiver_phone', $remitCols, true)) {
        $insertData['receiver_phone'] = $receiverPhone;
    }
    if (in_array('source_currency', $remitCols, true)) {
        $insertData['source_currency'] = $sourceCurrency;
    }
    if (in_array('destination_currency', $remitCols, true)) {
        $insertData['destination_currency'] = $destinationCurrency;
    }
    if (in_array('rate_id', $remitCols, true)) {
        $insertData['rate_id'] = (int)$rateRow['id'];
    }
    if (in_array('exchange_rate_id', $remitCols, true)) {
        $insertData['exchange_rate_id'] = (int)$rateRow['id'];
    }
    if (in_array('exchange_rate_value', $remitCols, true)) {
        $insertData['exchange_rate_value'] = $tomanPerAfn;
    }
    if (in_array('fee', $remitCols, true)) {
        $insertData['fee'] = $feeAmount;
    }


    $colNames = implode(', ', array_keys($insertData));
    $placeholders = ':' . implode(', :', array_keys($insertData));

    $sqlInsert = "INSERT INTO remittances ({$colNames}) VALUES ({$placeholders})";
    $insStmt = $pdo->prepare($sqlInsert);
    $insStmt->execute($insertData);

    $remittanceId = (int)$pdo->lastInsertId();

    // ثبت گزارش کارمزد در صورت وجود متد
    if ($feeAmount > 0 && function_exists('service_fee_record')) {
        try {
            service_fee_record($pdo, $userId, ['total_amount' => $feeAmount], 'remittance', $remittanceId, 'pending');
        } catch (\Throwable $e) {
            // نادیده گرفتن خطای لاگ کارمزد برای عدم اختلال در تراکنش اصلی
        }
    }

    $pdo->commit();

    // 6. خروجی استاندارد
    json_response([
        'success' => true,
        'message' => 'حواله با موفقیت ثبت شد.',
        'data' => [
            'remittance_id' => $remittanceId,
            'amount_toman'  => $amountToman,
            'amount_afn'    => $amountAfn,
            'status'        => 'pending'
        ]
    ], 200);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Remittance Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    json_response([
        'success' => false,
        'message' => 'خطا در ثبت حواله: ' . $e->getMessage()
    ], 500);
}
