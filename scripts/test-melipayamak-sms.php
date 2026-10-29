<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array('--real-sms', $argv, true) || !in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/test-melipayamak-sms.php <one-test-mobile> --real-sms --confirm\n");
    exit(2);
}
if (getenv('ALLOW_REAL_SMS_TEST') !== '1' || strtolower((string)getenv('APP_ENV')) !== 'local') {
    fwrite(STDERR, "Real SMS test is disabled. Set ALLOW_REAL_SMS_TEST=1 and APP_ENV=local for one manual run.\n");
    exit(2);
}

$phone = trim((string)($argv[1] ?? ''));
require_once __DIR__ . '/../API/includes/otp.php';
require_once __DIR__ . '/../vendor/autoload.php';
$phone = normalize_iran_mobile($phone);
if ($phone === null) {
    fwrite(STDERR, "Provide one valid Iranian test mobile number.\n");
    exit(2);
}

// The recipient is intentionally supplied once on the command line; no lookup or batch send is supported.
$provider = 'melipayamak';
$bodyId = sms_melipayamak_pattern_code();
if ($bodyId === null) {
    fwrite(STDERR, "MELIPAYAMAK_PATTERN_CODE is not configured; no SMS sent.\n");
    exit(2);
}
$api = new \Melipayamak\MelipayamakApi((string)getenv('MELIPAYAMAK_USERNAME'), (string)getenv('MELIPAYAMAK_PASSWORD'));
$smsSoap = $api->sms('soap');
if (!$smsSoap instanceof \Melipayamak\SmsSoap) {
    fwrite(STDERR, "SOAP transport unavailable; no SMS sent.\n");
    exit(1);
}
$credit = $smsSoap->getCredit();
$creditValue = sms_numeric_string($credit);
if ($creditValue === null || (float)$creditValue <= 0) {
    fwrite(STDERR, "Credit preflight failed; no SMS sent.\n");
    exit(1);
}
echo "Recipient: " . mask_iran_mobile($phone) . PHP_EOL;
echo "Provider: {$provider}" . PHP_EOL;
echo "SOAP transport: confirmed" . PHP_EOL;
echo "Payload: one-item array" . PHP_EOL;
echo "BodyId: {$bodyId}" . PHP_EOL;
echo "Credit preflight: available" . PHP_EOL;
echo "Environment: local" . PHP_EOL;
echo "Type SEND to send exactly one SMS: ";
$confirmation = trim((string)fgets(STDIN));
if ($confirmation !== 'SEND') {
    echo "Cancelled; no SMS sent." . PHP_EOL;
    exit(0);
}

putenv('SMS_PROVIDER=melipayamak');
putenv('MELIPAYAMAK_ENABLED=true');
$otp = (string)random_int(100000, 999999);
$result = sms_provider_send_otp($phone, $otp, 'manual_real_sms_test');
$diagnostic = $result['diagnostic'] ?? [];
echo 'response_type=' . (string)($diagnostic['response_type'] ?? 'unavailable') . PHP_EOL;
echo 'numeric_result=' . (string)($diagnostic['numeric_result'] ?? 'unavailable') . PHP_EOL;
echo 'stable_internal_code=' . (string)($result['code'] ?? 'MELIPAYAMAK_UNKNOWN_RESPONSE') . PHP_EOL;
echo 'state=' . ($result['success'] ? 'accepted' : 'rejected') . PHP_EOL;
echo 'safe_rec_id=' . (string)($result['request_id'] ?? 'unavailable') . PHP_EOL;
exit($result['success'] ? 0 : 1);
