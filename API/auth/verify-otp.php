<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/otp.php';

function otp_diagnostic_id(): string
{
    return 'OTP-' . strtoupper(bin2hex(random_bytes(4)));
}

function otp_diagnostic_mask_mobile(?string $mobile): string
{
    $value = (string)$mobile;
    return strlen($value) >= 4 ? substr($value, 0, 2) . '******' . substr($value, -2) : '**********';
}

function otp_diagnostic_log(string $id, string $stage, Throwable $error, ?string $mobile, string $purpose, bool $found, bool $expired, bool $consumed, bool $attemptsExceeded, bool $hashMatch): void
{
    $sqlState = $error instanceof PDOException ? (string)($error->errorInfo[0] ?? '') : '';
    $driverCode = $error instanceof PDOException ? (string)($error->errorInfo[1] ?? $error->getCode()) : (string)$error->getCode();
    $message = preg_replace('/\s+/', ' ', $error->getMessage()) ?? 'otp verification failure';
    $message = preg_replace('/(password|passwd|secret|token|otp|pin|api[_ -]?key)\s*[=:]\s*[^,; ]+/i', '$1=[redacted]', $message) ?? $message;
    $message = substr($message, 0, 240);
    error_log(sprintf(
        'AFARIEX_OTP_DIAG timestamp=%s id=%s stage=%s class=%s sqlstate=%s driver_code=%s mobile=%s purpose=%s candidate_row=%s expired=%s consumed=%s attempts_exceeded=%s hash_match=%s message=%s',
        date('c'), $id, $stage, get_class($error), $sqlState !== '' ? $sqlState : '-', $driverCode !== '' ? $driverCode : '-',
        otp_diagnostic_mask_mobile($mobile), $purpose !== '' ? $purpose : '-', $found ? 'yes' : 'no', $expired ? 'yes' : 'no',
        $consumed ? 'yes' : 'no', $attemptsExceeded ? 'yes' : 'no', $hashMatch ? 'yes' : 'no', $message
    ));
}

otp_cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') otp_json(['success'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);
$input = otp_input();
$purpose = trim((string)($input['purpose'] ?? ''));
$mobile = normalize_iran_mobile((string)($input['mobile'] ?? ''));
$code = otp_normalized_code((string)($input['otp'] ?? $input['code'] ?? ''));
$diagnosticId = otp_diagnostic_id();
$diagnosticStage = 'request_validation';
$candidateFound = false;
$candidateExpired = false;
$candidateConsumed = false;
$candidateAttemptsExceeded = false;
$candidateHashMatch = false;
if (!otp_purpose($purpose) || preg_match('/^\d{6}$/', $code) !== 1 || ($purpose === 'registration' && $mobile === null)) otp_json(['success'=>false,'code'=>'INVALID_OTP','message'=>'کد تأیید صحیح نیست.'],422);
try {
    $diagnosticStage = 'mobile_normalization';
    $pdo = db();
    $userId = null;
    if ($purpose !== 'registration') {
        $token = trim((string)($input['api_token'] ?? $input['token'] ?? ''));
        $stmt = $pdo->prepare('SELECT id, mobile FROM users WHERE api_token = ? LIMIT 1'); $stmt->execute([$token]); $user = $stmt->fetch();
        $userMobile = $user ? normalize_iran_mobile((string)$user['mobile']) : null;
        if (!$user || $userMobile === null || ($mobile !== null && $userMobile !== $mobile)) otp_json(['success'=>false,'code'=>'AUTHENTICATION_FAILED'],401);
        $mobile = $userMobile;
        $userId = (int)$user['id'];
    }
    $query = $userId === null
        ? 'SELECT * FROM phone_verification_codes WHERE user_id IS NULL AND mobile = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE'
        : 'SELECT * FROM phone_verification_codes WHERE user_id = ? AND mobile = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE';
    $diagnosticStage = 'otp_row_lookup';
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($query); $userId === null ? $stmt->execute([$mobile,$purpose]) : $stmt->execute([$userId,$mobile,$purpose]); $row = $stmt->fetch();
    $candidateFound = is_array($row);
    $candidateExpired = $candidateFound && strtotime((string)$row['expires_at']) < time();
    $candidateConsumed = $candidateFound && !empty($row['used_at']);
    $candidateAttemptsExceeded = $candidateFound && (int)$row['attempts'] >= 5;
    $diagnosticStage = 'otp_row_state';
    if (!$row || $candidateExpired) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'OTP_EXPIRED','message'=>'کد تأیید منقضی شده است.'],422); }
    if ($candidateAttemptsExceeded) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'OTP_ATTEMPTS_EXCEEDED','message'=>'تعداد تلاش‌ها بیش از حد مجاز است.'],429); }
    $diagnosticStage = 'hash_verification';
    $candidateHashMatch = password_verify($code, (string)$row['code_hash']);
    if (!$candidateHashMatch) { $diagnosticStage = 'attempt_update'; $pdo->prepare('UPDATE phone_verification_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$row['id']]); $pdo->commit(); otp_json(['success'=>false,'code'=>'INVALID_OTP','message'=>'کد واردشده صحیح نیست.'],422); }
    $diagnosticStage = 'token_creation';
    $actionToken = otp_action_token();
    $diagnosticStage = 'consume_or_success_update';
    // `used_at` is the canonical successful-OTP marker. `consumed_at` is
    // intentionally left NULL until register.php consumes the action token.
    // Do not write the optional historical `verified_at` column: it is absent
    // from the production schema.
    $pdo->prepare('UPDATE phone_verification_codes SET used_at = NOW(), verification_token_hash = ?, verification_token_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ? AND used_at IS NULL')->execute([otp_token_hash($actionToken),(int)$row['id']]);
    if ((int)$pdo->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
        throw new RuntimeException('OTP state changed before verification completed.');
    }
    $diagnosticStage = 'transaction_commit';
    $pdo->commit();
    $diagnosticStage = 'response_build';
    otp_json(['success'=>true,'code'=>'OTP_VERIFIED','data'=>['purpose'=>$purpose,'verification_token'=>$actionToken,'expires_in'=>600]]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    otp_diagnostic_log($diagnosticId, $diagnosticStage, $error, $mobile, $purpose, $candidateFound, $candidateExpired, $candidateConsumed, $candidateAttemptsExceeded, $candidateHashMatch);
    otp_json(['success'=>false,'code'=>'OTP_VERIFY_FAILED','message'=>'تأیید کد انجام نشد.','diagnostic_id'=>$diagnosticId],500);
}
