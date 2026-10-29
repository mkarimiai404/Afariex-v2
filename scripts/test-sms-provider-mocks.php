<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/includes/sms_provider.php';

$failed = 0;
$assert = static function (bool $condition, string $name) use (&$failed): void {
    echo $name . '=' . ($condition ? 'ok' : 'failed') . PHP_EOL;
    if (!$condition) $failed++;
};

$otp = '654321';
$request = sms_melipayamak_pattern_request('+989121234567', $otp, 509216);
$assert($request['text'] === [$otp], 'one_variable_payload');
$assert(sms_pattern_otp_is_ascii($request['text'][0]) && !preg_match('/[^0-9]/', $request['text'][0]), 'ascii_otp');
$assert($request['body_id'] === 509216, 'body_id');
$assert($request['phone'] === '09121234567', 'normalized_recipient');
$assert(!array_key_exists('sender', $request), 'no_sender');
$assert(normalize_iran_mobile('09121234567') === '09121234567', 'mobile_09');
$assert(normalize_iran_mobile('989121234567') === '09121234567', 'mobile_989');
$assert(normalize_iran_mobile('+989121234567') === '09121234567', 'mobile_plus989');
$assert(normalize_iran_mobile('۰۹۱۲۱۲۳۴۵۶۷') === '09121234567', 'mobile_persian_digits');
$assert(normalize_iran_mobile('0912123456') === null, 'mobile_invalid');
$assert(sms_melipayamak_pattern_code() === null || sms_melipayamak_pattern_code() > 0, 'configured_pattern_code_valid');

$response = static fn(string $value): object => (object)['SendByBaseNumberResult' => $value];
$accepted = sms_parse_soap_send_response($response('1234567890123456'));
$assert($accepted['success'] && $accepted['code'] === 'SMS_ACCEPTED' && $accepted['request_id'] === '1234567890123456', 'accepted_16_digit_rec_id');
$assert(!sms_parse_soap_send_response($response('123456789012345'))['success'], 'reject_15_digit_numeric');
$assert(!sms_parse_soap_send_response($response('12345'))['success'], 'reject_short_positive');
$assert(sms_parse_soap_send_response($response('0'))['diagnostic']['provider_reason'] === 'MELIPAYAMAK_AUTH_FAILED', 'auth_failed');
$assert(sms_parse_soap_send_response($response('19'))['diagnostic']['provider_reason'] === 'MELIPAYAMAK_HOURLY_LIMIT', 'positive_error_code');
$assert(sms_parse_soap_send_response($response('-3'))['diagnostic']['provider_reason'] === 'MELIPAYAMAK_SERVICE_LINE_MISSING', 'service_line_missing');
$assert(sms_parse_soap_send_response($response('-5'))['diagnostic']['provider_reason'] === 'MELIPAYAMAK_PATTERN_VARIABLE_MISMATCH', 'variable_mismatch');
$assert(sms_parse_soap_send_response($response('-110'))['diagnostic']['provider_reason'] === 'MELIPAYAMAK_APIKEY_REQUIRED', 'apikey_required');
$missingResult = sms_parse_soap_send_response((object)['OtherResult' => '1234567890123456']);
$assert(!$missingResult['success'] && $missingResult['diagnostic']['provider_reason'] === 'MELIPAYAMAK_RESULT_MISSING', 'missing_result_property');

$soapFault = sms_classify_soap_exception(new SoapFault('Server', 'Provider rejected request'));
$assert(!$soapFault['success'] && $soapFault['code'] === 'SMS_PROVIDER_REJECTED', 'soap_fault');
$timeout = sms_classify_soap_exception(new RuntimeException('Connection timed out'));
$assert(!$timeout['success'] && $timeout['code'] === 'SMS_SOAP_CONNECTION_FAILED', 'timeout_network_exception');
$runtime = sms_classify_soap_exception(new RuntimeException('Unexpected runtime state'));
$assert(!$runtime['success'] && $runtime['code'] === 'SMS_RUNTIME_ERROR', 'runtime_exception');

$diagnostic = sms_parse_soap_send_response($response('1234567890123456'));
$diagnostic['diagnostic']['payload_type'] = 'array';
$diagnostic['diagnostic']['body_id'] = 509216;
$serialized = json_encode($diagnostic, JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, $otp) && !str_contains($serialized, 'username') && !str_contains($serialized, 'password'), 'safe_diagnostics');
$logFile = tempnam(sys_get_temp_dir(), 'afariex-sms-test-');
if ($logFile !== false) {
    ini_set('error_log', $logFile);
    putenv('APP_ENV=local');
    putenv('SMS_DEBUG_DIAGNOSTICS=true');
    sms_log_diagnostic('offline_test', '09121234567', $diagnostic);
    $logContents = (string)file_get_contents($logFile);
    $assert(!str_contains($logContents, $otp) && !str_contains($logContents, 'username') && !str_contains($logContents, 'password'), 'safe_logs');
    unlink($logFile);
} else {
    $assert(false, 'safe_logs');
}

exit($failed === 0 ? 0 : 1);
