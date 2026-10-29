<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/registration_pin.php';

const AFARIEX_TERMS_VERSION = '1.0';
const AFARIEX_PRIVACY_VERSION = '1.0';

function registration_diagnostic_id(): string
{
    return 'REG-' . strtoupper(bin2hex(random_bytes(4)));
}

function registration_mask_mobile(?string $mobile): string
{
    $value = (string)$mobile;
    return strlen($value) >= 4 ? substr($value, 0, 2) . '******' . substr($value, -2) : '**********';
}

function registration_diagnostic_log(string $id, string $stage, ?string $mobile, array $flags = [], ?Throwable $error = null, string $reason = ''): void
{
    $sqlState = $error instanceof PDOException ? (string)($error->errorInfo[0] ?? '') : '-';
    $driverCode = $error instanceof PDOException ? (string)($error->errorInfo[1] ?? $error->getCode()) : '-';
    $message = $error ? preg_replace('/\s+/', ' ', $error->getMessage()) : $reason;
    $message = preg_replace('/(password|passwd|secret|token|otp|pin|api[_ -]?key|hash)\s*[=:]?\s*[^,; ]+/i', '$1=[redacted]', $message ?: 'none') ?? 'redacted';
    $message = preg_replace('/\b[a-f0-9]{32,}\b/i', '[redacted]', $message) ?? $message;
    $message = substr($message, 0, 240);
    $parts = ['AFARIEX_REGISTER_DIAG', 'timestamp=' . date('c'), 'id=' . $id, 'stage=' . $stage,
        'class=' . ($error ? get_class($error) : '-'), 'sqlstate=' . ($sqlState !== '' ? $sqlState : '-'),
        'driver_code=' . ($driverCode !== '' ? $driverCode : '-'), 'mobile=' . registration_mask_mobile($mobile),
        'message=' . $message];
    foreach ($flags as $name => $value) $parts[] = $name . '=' . ($value ? 'yes' : 'no');
    error_log(implode(' ', $parts));
}

otp_cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') otp_json(['success'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);

$input = otp_input();
$diagnosticId = registration_diagnostic_id();
$mobile = normalize_iran_mobile((string)($input['mobile'] ?? ''));
$fullName = trim((string)($input['full_name'] ?? ''));
$password = (string)($input['password'] ?? '');
$referralInput = strtoupper(trim((string)($input['referral_code'] ?? '')));
$registrationToken = trim((string)($input['registration_token'] ?? $input['verification_token'] ?? ''));
$diagnosticStage = 'request_validation';
$consentValue = $input['consent'] ?? null;
$consentAccepted = $consentValue === true || $consentValue === 1 || $consentValue === '1' || $consentValue === 'true';
$securePassword = strlen($password) >= 8 && preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password);

if (!$consentAccepted) { registration_diagnostic_log($diagnosticId, 'request_validation', $mobile, [], null, 'CONSENT_REQUIRED'); otp_json(['success'=>false,'code'=>'CONSENT_REQUIRED','message'=>'برای ادامه ثبتنام، ابتدا قوانین و سیاست حفظ حریم خصوصی را مطالعه و تأیید کنید.'],422); }
if ($mobile === null) { registration_diagnostic_log($diagnosticId, 'mobile_normalization', null, [], null, 'INVALID_MOBILE'); otp_json(['success'=>false,'code'=>'INVALID_MOBILE','message'=>'شماره موبایل معتبر نیست.'],422); }
if ($fullName === '' || !$securePassword) { registration_diagnostic_log($diagnosticId, 'request_validation', $mobile, [], null, 'INVALID_REGISTRATION_DATA'); otp_json(['success'=>false,'code'=>'INVALID_REGISTRATION_DATA','message'=>'اطلاعات ثبت‌نام معتبر نیست.'],422); }
if ($registrationToken === '') { registration_diagnostic_log($diagnosticId, 'request_validation', $mobile, [], null, 'REGISTRATION_OTP_REQUIRED'); otp_json(['success'=>false,'code'=>'REGISTRATION_OTP_REQUIRED','message'=>'تأیید شماره موبایل لازم است.'],422); }

try {
    $diagnosticStage = 'mobile_normalization';
    $pdo = db();
    $diagnosticStage = 'transaction_begin';
    $pdo->beginTransaction();
    $diagnosticStage = 'verification_token_lookup';
    $otp = $pdo->prepare('SELECT id FROM phone_verification_codes WHERE user_id IS NULL AND mobile = ? AND purpose = ? AND verification_token_hash = ? AND verification_token_expires_at > NOW() AND consumed_at IS NULL LIMIT 1 FOR UPDATE');
    $otp->execute([$mobile, 'registration', otp_token_hash($registrationToken)]);
    $challenge = $otp->fetch();
    if (!$challenge) { registration_diagnostic_log($diagnosticId, 'verification_token_state', $mobile, ['verification_token_row_found'=>false,'verification_token_expired'=>false,'verification_token_consumed'=>false,'verification_token_hash_match'=>false], null, 'REGISTRATION_OTP_INVALID'); $pdo->rollBack(); otp_json(['success'=>false,'code'=>'REGISTRATION_OTP_INVALID','message'=>'تأیید ثبت‌نام معتبر یا فعال نیست.'],422); }

    $diagnosticStage = 'verification_token_state';
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['verification_token_row_found'=>true,'verification_token_expired'=>false,'verification_token_consumed'=>false,'verification_token_hash_match'=>true]);

    $diagnosticStage = 'duplicate_user_check';
    $duplicate = $pdo->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
    $duplicate->execute([$mobile]);
    if ($duplicate->fetch()) { registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['duplicate_user'=>true], null, 'MOBILE_ALREADY_REGISTERED'); $pdo->rollBack(); otp_json(['success'=>false,'code'=>'MOBILE_ALREADY_REGISTERED','message'=>'این شماره موبایل قبلاً ثبت شده است.'],409); }
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['duplicate_user'=>false]);

    $diagnosticStage = 'schema_detection';
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['transaction_active'=>$pdo->inTransaction()]);
    if (!in_array('pin_hash', $columns, true)) { registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, [], null, 'PIN_SYSTEM_UNAVAILABLE'); $pdo->rollBack(); otp_json(['success'=>false,'code'=>'PIN_SYSTEM_UNAVAILABLE'],503); }
    $consentColumns = ['terms_accepted_at', 'terms_version', 'privacy_accepted_at', 'privacy_version'];
    if (array_diff($consentColumns, $columns)) { registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, [], null, 'CONSENT_STORAGE_UNAVAILABLE'); $pdo->rollBack(); otp_json(['success'=>false,'code'=>'CONSENT_STORAGE_UNAVAILABLE','message'=>'ثبت‌نام در حال حاضر امکان‌پذیر نیست.'],503); }

    $referrerId = null;
    if ($referralInput !== '') {
        if (!in_array('referral_code', $columns, true) || !in_array('referred_by_user_id', $columns, true)) {
            $pdo->rollBack(); otp_json(['success'=>false,'code'=>'INVALID_REFERRAL_CODE','message'=>'کد معرف معتبر نیست.'],422);
        }
        $referrer = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
        $referrer->execute([$referralInput]);
        $referrerId = $referrer->fetchColumn();
        if ($referrerId === false) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'INVALID_REFERRAL_CODE','message'=>'کد معرف معتبر نیست.'],422); }
    }

    $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $firstName = (string)($parts[0] ?? '');
    $lastName = implode(' ', array_slice($parts, 1));
    $generatedPin = registration_generate_pin();
    $diagnosticStage = 'api_token_generation';
    $apiToken = in_array('api_token', $columns, true) ? bin2hex(random_bytes(32)) : null;
    // Keep registration compatible with the existing users table contract.
    // Older/current deployments store the generated PIN in pin_code, while
    // some installations have the newer pin column.
    $pinColumn = in_array('pin', $columns, true) ? 'pin' : (in_array('pin_code', $columns, true) ? 'pin_code' : null);
    if ($pinColumn === null) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'PIN_SYSTEM_UNAVAILABLE'],503); }
    $fields = ['full_name', 'mobile', $pinColumn, 'pin_hash', 'password', 'role', 'created_at', 'first_name', 'last_name'];
    $values = [$fullName, $mobile, '', password_hash($generatedPin,PASSWORD_DEFAULT), password_hash($password,PASSWORD_DEFAULT), 'user', date('Y-m-d H:i:s'), $firstName, $lastName];
    $diagnosticStage = 'consent_persistence';
    $acceptedAt = date('Y-m-d H:i:s');
    array_push($fields, 'terms_accepted_at', 'terms_version', 'privacy_accepted_at', 'privacy_version');
    array_push($values, $acceptedAt, AFARIEX_TERMS_VERSION, $acceptedAt, AFARIEX_PRIVACY_VERSION);
    if (in_array('referral_code', $columns, true)) { $fields[]='referral_code'; $values[] = strtoupper(bin2hex(random_bytes(5))); }
    if ($referrerId !== null) { $fields[]='referred_by_user_id'; $values[] = (int)$referrerId; }
    if ($apiToken !== null) { $fields[]='api_token'; $values[] = $apiToken; }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $diagnosticStage = 'user_insert';
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['transaction_active'=>$pdo->inTransaction()]);
    $stmt = $pdo->prepare('INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($values);
    $userId = (int)$pdo->lastInsertId();
    $diagnosticStage = 'verification_level_initialization';
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['transaction_active'=>$pdo->inTransaction()]);
    $diagnosticStage = 'verification_token_consume';
    $pdo->prepare('UPDATE phone_verification_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$challenge['id']]);
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['verification_token_consumed'=>true,'transaction_active'=>$pdo->inTransaction()]);
    $diagnosticStage = 'transaction_commit';
    $pdo->commit();
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['transaction_active'=>$pdo->inTransaction()]);

    $diagnosticStage = 'response_build';
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile);
    otp_json(['success'=>true,'status'=>'success','code'=>'REGISTRATION_COMPLETED','data'=>[
        'id'=>$userId,
        'mobile'=>$mobile,
        'full_name'=>$fullName,
        'api_token'=>$apiToken,
        'pin'=>$generatedPin,
    ]]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    registration_diagnostic_log($diagnosticId, $diagnosticStage, $mobile, ['transaction_active'=>isset($pdo) && $pdo->inTransaction()], $error);
    otp_json(['success'=>false,'code'=>'REGISTRATION_FAILED','message'=>'ثبت‌نام انجام نشد.','diagnostic_id'=>$diagnosticId],500);
}
