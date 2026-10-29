<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

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
require_once __DIR__ . '/includes/sms_provider.php';

function phone_verification_response(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function valid_mobile(string $mobile): bool
{
    return preg_match('/^(?:09\d{9}|\+989\d{9}|989\d{9})$/', $mobile) === 1;
}

function normalize_otp_digits(string $value): string
{
    return strtr($value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

function send_verification_sms(string $mobile, string $code): array
{
    return sms_provider_send_otp($mobile, $code, 'phone_verification');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    phone_verification_response(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$userId = (int)($_POST['user_id'] ?? $_POST['id'] ?? 0);
$token = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? $_POST['user_token'] ?? ''));
$action = trim((string)($_POST['action'] ?? 'request'));

if ($userId <= 0 || $token === '') {
    phone_verification_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت کاربر انجام نشد.'], 401);
}

try {
    ensure_verification_schema();
    $userStmt = db()->prepare('SELECT id, mobile FROM users WHERE id = ? AND api_token = ? LIMIT 1');
    $userStmt->execute([$userId, $token]);
    $user = $userStmt->fetch();
    if (!$user) phone_verification_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'اطلاعات احراز هویت معتبر نیست.'], 401);

    $mobile = trim((string)$user['mobile']);
    if (!valid_mobile($mobile)) {
        phone_verification_response(['success' => false, 'code' => 'INVALID_MOBILE', 'message' => 'شماره موبایل معتبر نیست.'], 422);
    }
    $state = verification_state($userId);

    if ($action === 'request') {
        if ($state['phone_verified']) {
            phone_verification_response(['success' => true, 'message' => 'شماره موبایل قبلاً تأیید شده است.', 'data' => ['phone_verified' => true]]);
        }
        db()->beginTransaction();
        $lockStmt = db()->prepare('SELECT id FROM users WHERE id = ? AND api_token = ? LIMIT 1 FOR UPDATE');
        $lockStmt->execute([$userId, $token]);
        if (!$lockStmt->fetch()) {
            db()->rollBack();
            phone_verification_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED'], 401);
        }
        $cooldownStmt = db()->prepare("SELECT id, created_at FROM phone_verification_codes WHERE user_id = ? AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1");
        $cooldownStmt->execute([$userId, 'phone_verification']);
        if ($activeOtp = $cooldownStmt->fetch()) {
            db()->rollBack();
            $createdAt = strtotime((string)$activeOtp['created_at']);
            $resendAfter = $createdAt === false ? 60 : max(0, 60 - (time() - $createdAt));
            phone_verification_response(['success' => false, 'code' => 'OTP_COOLDOWN', 'message' => 'کد قبلاً ارسال شده است؛ کد دریافتی را وارد کنید.', 'data' => ['resend_after' => $resendAfter]], 429);
        }
        $rateStmt = db()->prepare("SELECT COUNT(*) FROM phone_verification_codes WHERE user_id = ? AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $rateStmt->execute([$userId, 'phone_verification']);
        if ((int)$rateStmt->fetchColumn() >= 5) {
            db()->rollBack();
            phone_verification_response(['success' => false, 'code' => 'OTP_RATE_LIMITED', 'message' => 'تعداد درخواست‌های کد تأیید بیش از حد مجاز است. بعداً دوباره تلاش کنید.'], 429);
        }

        $code = (string)random_int(100000, 999999);
        $insert = db()->prepare("INSERT INTO phone_verification_codes (user_id, mobile, purpose, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())");
        $insert->execute([$userId, $mobile, 'phone_verification', password_hash($code, PASSWORD_DEFAULT)]);
        $codeId = (int)db()->lastInsertId();
        db()->commit();
        $smsResult = send_verification_sms($mobile, $code);
        if (($smsResult['success'] ?? false) !== true) {
            db()->prepare('DELETE FROM phone_verification_codes WHERE id = ? AND used_at IS NULL')->execute([$codeId]);
            $providerCode = (string)($smsResult['code'] ?? 'SMS_PROVIDER_UNAVAILABLE');
            error_log('Phone verification SMS rejected code=' . $providerCode . ' phone=' . mask_iran_mobile($mobile));
            phone_verification_response(['success' => false, 'code' => $providerCode, 'message' => sms_public_error_message($providerCode)], 503);
        }

        $response = ['success' => true, 'message' => 'کد تأیید برای شماره شما ارسال شد.', 'data' => ['expires_in' => 300, 'phone_verified' => false, 'provider_receipt' => sms_mask_request_id($smsResult['request_id'] ?? null)]];
        if (sms_local_mode() && sms_bool_env('OTP_DEV_RETURN_CODE')) $response['data']['development_code'] = $code;
        phone_verification_response($response);
    }

    if ($action !== 'verify') {
        phone_verification_response(['success' => false, 'code' => 'INVALID_ACTION', 'message' => 'عملیات نامعتبر است.'], 422);
    }

    $code = trim(normalize_otp_digits((string)($_POST['code'] ?? '')));
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        phone_verification_response(['success' => false, 'code' => 'INVALID_OTP', 'message' => 'کد تأیید صحیح نیست.'], 422);
    }

    db()->beginTransaction();
    $otpStmt = db()->prepare('SELECT id, code_hash, attempts, expires_at, used_at, mobile FROM phone_verification_codes WHERE user_id = ? AND mobile = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $otpStmt->execute([$userId, $mobile, 'phone_verification']);
    $otp = $otpStmt->fetch();
    if (!$otp || strtotime((string)$otp['expires_at']) < time()) {
        if (db()->inTransaction()) db()->rollBack();
        phone_verification_response(['success' => false, 'code' => 'OTP_EXPIRED', 'message' => 'کد تأیید منقضی شده است. کد جدید درخواست کنید.'], 422);
    }
    if ((int)$otp['attempts'] >= 5) {
        db()->rollBack();
        phone_verification_response(['success' => false, 'code' => 'OTP_ATTEMPTS_EXCEEDED', 'message' => 'تعداد تلاش‌های وارد کردن کد بیش از حد مجاز است.'], 429);
    }
    if (!password_verify($code, (string)$otp['code_hash'])) {
        db()->prepare('UPDATE phone_verification_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$otp['id']]);
        db()->commit();
        phone_verification_response(['success' => false, 'code' => 'INVALID_OTP', 'message' => 'کد واردشده صحیح نیست.'], 422);
    }

    db()->prepare('UPDATE phone_verification_codes SET used_at = NOW() WHERE id = ?')->execute([(int)$otp['id']]);
    db()->prepare('UPDATE phone_verification_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$userId, 'phone_verification']);
    db()->prepare("INSERT INTO user_verification_levels (user_id, level, phone_verified, phone_verified_at, withdrawal_limit, updated_at) VALUES (?, 'initial', 1, NOW(), ?, NOW()) ON DUPLICATE KEY UPDATE phone_verified = 1, phone_verified_at = NOW(), updated_at = NOW()")->execute([$userId, INITIAL_WITHDRAWAL_LIMIT_TOMAN]);
    db()->commit();
    phone_verification_response(['success' => true, 'message' => 'شماره موبایل با موفقیت تأیید شد.', 'data' => ['phone_verified' => true, 'phone_verified_at' => date('Y-m-d H:i:s')] ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Phone verification failed: ' . $e->getMessage());
    phone_verification_response(['success' => false, 'code' => 'PHONE_VERIFICATION_FAILED', 'message' => 'عملیات تأیید شماره موبایل انجام نشد.'], 500);
}
