<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/otp.php';
otp_cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') otp_json(['success'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);
$input = otp_input();
$purpose = trim((string)($input['purpose'] ?? ''));
$mobile = normalize_iran_mobile((string)($input['mobile'] ?? ''));
if (!otp_purpose($purpose)) otp_json(['success'=>false,'code'=>'INVALID_OTP_PURPOSE','message'=>'نوع کد تأیید نامعتبر است.'],422);
if ($mobile === null) otp_json(['success'=>false,'code'=>'INVALID_MOBILE','message'=>'شماره موبایل معتبر نیست.'],422);

try {
    $pdo = db();
    $user = null;
    $apiToken = trim((string)($input['api_token'] ?? $input['token'] ?? ''));
    if ($purpose === 'registration') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
        $stmt->execute([$mobile]);
        if ($stmt->fetch()) otp_json(['success'=>false,'code'=>'MOBILE_ALREADY_REGISTERED','message'=>'این شماره موبایل قبلاً ثبت شده است.'],409);
    } else {
        if ($apiToken === '') otp_json(['success'=>false,'code'=>'AUTHENTICATION_REQUIRED','message'=>'احراز هویت لازم است.'],401);
        $stmt = $pdo->prepare('SELECT id, mobile, password, pin, pin_hash FROM users WHERE api_token = ? LIMIT 1');
        $stmt->execute([$apiToken]); $user = $stmt->fetch();
        if (!$user) otp_json(['success'=>false,'code'=>'AUTHENTICATION_FAILED','message'=>'نشست کاربر معتبر نیست.'],401);
        $mobile = normalize_iran_mobile((string)$user['mobile']);
        if ($mobile === null) otp_json(['success'=>false,'code'=>'INVALID_MOBILE'],422);
        if ($purpose === 'change_password' && !password_verify((string)($input['current_password'] ?? ''), (string)$user['password'])) otp_json(['success'=>false,'code'=>'INVALID_CURRENT_PASSWORD','message'=>'رمز عبور فعلی صحیح نیست.'],422);
        if ($purpose === 'change_pin') {
            $currentPin = (string)($input['current_pin'] ?? '');
            $valid = trim((string)($user['pin_hash'] ?? '')) !== '' ? password_verify($currentPin, (string)$user['pin_hash']) : hash_equals((string)($user['pin'] ?? ''), $currentPin);
            if (!$valid) otp_json(['success'=>false,'code'=>'INVALID_CURRENT_PIN','message'=>'پین فعلی صحیح نیست.'],422);
        }
    }
    $ipHash = otp_requester_hash();
    $cooldown = $pdo->prepare('SELECT id, created_at, expires_at FROM phone_verification_codes WHERE mobile = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $cooldown->execute([$mobile, $purpose]);
    if ($activeOtp = $cooldown->fetch()) {
        $createdAt = strtotime((string)$activeOtp['created_at']);
        $expiresAt = strtotime((string)$activeOtp['expires_at']);
        $resendAfter = $createdAt === false ? 60 : max(0, 60 - (time() - $createdAt));
        otp_json(['success'=>false,'code'=>'OTP_COOLDOWN','message'=>'کد قبلاً ارسال شده است؛ کد دریافتی را وارد کنید.','data'=>[
            'resend_after'=>$resendAfter,
            'expires_in'=>$expiresAt === false ? 0 : max(0, $expiresAt - time()),
        ]],429);
    }
    $rate = $pdo->prepare('SELECT COUNT(*) FROM phone_verification_codes WHERE (mobile = ? OR requester_ip_hash = ?) AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $rate->execute([$mobile, $ipHash, $purpose]);
    if ((int)$rate->fetchColumn() >= 5) otp_json(['success'=>false,'code'=>'OTP_RATE_LIMITED','message'=>'تعداد درخواست‌ها بیش از حد مجاز است.'],429);
    $daily = $pdo->prepare('SELECT COUNT(*) FROM phone_verification_codes WHERE (mobile = ? OR requester_ip_hash = ?) AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $daily->execute([$mobile, $ipHash, $purpose]);
    if ((int)$daily->fetchColumn() >= 20) otp_json(['success'=>false,'code'=>'OTP_DAILY_LIMITED','message'=>'تعداد درخواست‌های روزانه بیش از حد مجاز است.'],429);

    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO phone_verification_codes (user_id, mobile, purpose, code_hash, expires_at, created_at, last_sent_at, resend_count, requester_ip_hash) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW(), NOW(), 0, ?)");
    $insert->execute([$user['id'] ?? null, $mobile, $purpose, $hash, $ipHash]);
    $id = (int)$pdo->lastInsertId();
    $sms = sms_provider_send_otp($mobile, $otp, $purpose);
    if (!$sms['success']) {
        $pdo->prepare('DELETE FROM phone_verification_codes WHERE id = ?')->execute([$id]);
        $publicCode = (string)($sms['code'] ?? 'OTP_SEND_FAILED');
        error_log('OTP delivery rejected code=' . $publicCode . ' purpose=' . $purpose . ' phone=' . mask_iran_mobile($mobile));
        otp_json(['success'=>false,'code'=>$publicCode,'message'=>sms_public_error_message($publicCode)],503);
    }
    $data = ['purpose'=>$purpose,'expires_in'=>300,'resend_after'=>60,'provider_receipt'=>sms_mask_request_id($sms['request_id'] ?? null)];
    if (otp_dev_code_allowed()) $data['development_code'] = $otp;
    otp_json(['success'=>true,'code'=>'OTP_SENT','data'=>$data]);
} catch (Throwable $error) {
    error_log('OTP request failure code=OTP_SEND_FAILED purpose=' . $purpose . ' phone=' . mask_iran_mobile((string)($mobile ?? '')));
    otp_json(['success'=>false,'code'=>'OTP_SEND_FAILED','message'=>'ارسال کد تأیید انجام نشد.'],503);
}
