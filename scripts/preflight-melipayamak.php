<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
putenv('APP_ENV=local');
putenv('SMS_PROVIDER=melipayamak');
putenv('MELIPAYAMAK_ENABLED=true');
require_once __DIR__ . '/../API/includes/otp.php';
require_once __DIR__ . '/../vendor/autoload.php';

echo 'provider=' . (getenv('SMS_PROVIDER') === 'melipayamak' ? 'melipayamak' : 'unexpected') . PHP_EOL;
echo 'enabled=' . (sms_bool_env('MELIPAYAMAK_ENABLED') ? 'true' : 'false') . PHP_EOL;
echo 'username_loaded=' . (trim((string)getenv('MELIPAYAMAK_USERNAME')) !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'password_loaded=' . (trim((string)getenv('MELIPAYAMAK_PASSWORD')) !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'pattern_code_loaded=' . (sms_melipayamak_pattern_code() !== null ? 'yes' : 'no') . PHP_EOL;
echo 'legacy_body_id_matches_config=' . (sms_melipayamak_pattern_code() === MELIPAYAMAK_BODY_ID ? 'yes' : 'no') . PHP_EOL;
echo 'autoload=' . (is_file(__DIR__ . '/../vendor/autoload.php') ? 'ok' : 'missing') . PHP_EOL;
echo 'sms_soap=' . (class_exists('Melipayamak\\SmsSoap') ? 'ok' : 'missing') . PHP_EOL;
echo 'send_by_base_number=' . (method_exists('Melipayamak\\SmsSoap', 'sendByBaseNumber') ? 'ok' : 'missing') . PHP_EOL;

try {
    if (sms_melipayamak_pattern_code() === null) throw new RuntimeException('Pattern code is not configured');
    $api = new \Melipayamak\MelipayamakApi((string)getenv('MELIPAYAMAK_USERNAME'), (string)getenv('MELIPAYAMAK_PASSWORD'));
    $sms = $api->sms('soap');
    if (!$sms instanceof \Melipayamak\SmsSoap) throw new RuntimeException('SOAP transport unavailable');
    $response = $sms->getCredit();
    $numeric = sms_numeric_string($response);
    $creditStatus = $numeric !== null && (float)$numeric > 0 ? 'available' : ($numeric !== null && (float)$numeric === 0.0 ? 'zero' : 'unavailable');
    echo 'credit_method=ok' . PHP_EOL;
    echo 'response_type=' . get_debug_type($response) . PHP_EOL;
    echo 'credit_status=' . $creditStatus . PHP_EOL;
    echo 'diagnostic_code=' . ($creditStatus === 'available' ? 'PREFLIGHT_OK' : 'MELIPAYAMAK_CREDIT_ERROR') . PHP_EOL;
    exit($creditStatus === 'available' ? 0 : 1);
} catch (Throwable $error) {
    echo 'credit_method=attempted' . PHP_EOL;
    echo 'response_type=exception' . PHP_EOL;
    echo 'diagnostic_code=MELIPAYAMAK_NETWORK_ERROR' . PHP_EOL;
    echo 'exception_class=' . get_class($error) . PHP_EOL;
    exit(1);
}
