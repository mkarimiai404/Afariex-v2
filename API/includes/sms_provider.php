<?php
declare(strict_types=1);

require_once __DIR__ . '/phone.php';

const MELIPAYAMAK_BODY_ID = 509216;
const MELIPAYAMAK_SEND_WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';
const MELIPAYAMAK_CONNECT_TIMEOUT = 10;

function sms_local_mode(): bool
{
    $host = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    return (strtolower((string)getenv('APP_ENV')) === 'local' || in_array($host, ['localhost', '127.0.0.1'], true)) && !in_array($host, ['afariex.ir', 'www.afariex.ir'], true);
}

function sms_bool_env(string $name, bool $default = false): bool
{
    $value = strtolower(trim((string)getenv($name)));
    return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function sms_diagnostics_enabled(): bool
{
    return sms_local_mode() && sms_bool_env('SMS_DEBUG_DIAGNOSTICS');
}

function sms_safe_provider_message(mixed $value): ?string
{
    if (!is_scalar($value)) return null;
    $message = trim((string)$value);
    if ($message === '') return null;
    $message = preg_replace('/\b\d{4,}\b/', '[redacted-number]', $message) ?? '';
    return substr($message, 0, 160) ?: null;
}

function sms_mask_request_id(?string $requestId): ?string
{
    if ($requestId === null || $requestId === '') return null;
    return str_repeat('*', max(4, strlen($requestId) - 4)) . substr($requestId, -4);
}

function sms_numeric_string(mixed $value): ?string
{
    if (is_int($value)) return (string)$value;
    if (is_float($value) && is_finite($value)) return (string)$value;
    if (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', trim($value)) === 1) return trim($value);
    return null;
}

function sms_pattern_otp_is_ascii(string $otp): bool
{
    return preg_match('/^[0-9]{4,8}$/D', $otp) === 1;
}

function sms_melipayamak_pattern_code(): ?int
{
    $code = trim((string)getenv('MELIPAYAMAK_PATTERN_CODE'));
    if ($code === '' || preg_match('/^[1-9]\d*$/D', $code) !== 1) return null;
    return (int)$code;
}

/** @return array{text: array<int, string>, phone: string, body_id: int} */
function sms_melipayamak_pattern_request(string $phone, string $otp, ?int $bodyId = null): array
{
    $normalizedPhone = normalize_iran_mobile($phone);
    if ($normalizedPhone === null) throw new InvalidArgumentException('Invalid Iranian mobile number');
    if (!sms_pattern_otp_is_ascii($otp)) throw new InvalidArgumentException('OTP must contain ASCII digits');
    $bodyId ??= sms_melipayamak_pattern_code();
    if ($bodyId === null || $bodyId < 1) throw new InvalidArgumentException('Pattern code is not configured');
    return ['text' => [$otp], 'phone' => $normalizedPhone, 'body_id' => $bodyId];
}

function sms_soap_result_value(mixed $response): mixed
{
    if (is_array($response)) {
        return array_key_exists('SendByBaseNumberResult', $response)
            ? $response['SendByBaseNumberResult']
            : null;
    }
    if (is_object($response)) {
        return property_exists($response, 'SendByBaseNumberResult')
            ? $response->SendByBaseNumberResult
            : null;
    }
    return null;
}

function sms_soap_response_code(string $code): string
{
    return match ($code) {
        '0' => 'MELIPAYAMAK_AUTH_FAILED', '-1' => 'MELIPAYAMAK_ACCESS_DISABLED', '-2' => 'MELIPAYAMAK_RECIPIENT_LIMIT',
        '-3' => 'MELIPAYAMAK_SERVICE_LINE_MISSING',
        '-4' => 'MELIPAYAMAK_PATTERN_REJECTED', '-5' => 'MELIPAYAMAK_PATTERN_VARIABLE_MISMATCH', '-6' => 'MELIPAYAMAK_PATTERN_INTERNAL_ERROR',
        '-10' => 'MELIPAYAMAK_VARIABLE_CONTAINS_URL', '-108' => 'MELIPAYAMAK_IP_BLOCKED', '-109' => 'MELIPAYAMAK_ALLOWED_IP_REQUIRED',
        '-110' => 'MELIPAYAMAK_APIKEY_REQUIRED', '2' => 'MELIPAYAMAK_CREDIT_ERROR', '6' => 'MELIPAYAMAK_MAINTENANCE',
        '7' => 'MELIPAYAMAK_FILTERED_TEXT', '10' => 'MELIPAYAMAK_ACCOUNT_INACTIVE', '11' => 'MELIPAYAMAK_NOT_SENT',
        '12' => 'MELIPAYAMAK_DOCUMENTS_INCOMPLETE', '16' => 'MELIPAYAMAK_RECIPIENT_NOT_FOUND', '17' => 'MELIPAYAMAK_EMPTY_TEXT',
        '18' => 'MELIPAYAMAK_INVALID_RECIPIENT', '19' => 'MELIPAYAMAK_HOURLY_LIMIT', default => 'MELIPAYAMAK_UNKNOWN_RESPONSE',
    };
}

function sms_parse_soap_send_response(mixed $response): array
{
    $raw = sms_soap_result_value($response);
    $metadata = [
        'response_type' => get_debug_type($response), 'result_type' => get_debug_type($raw),
        'provider_reason' => null, 'request_id' => null,
    ];
    if (!is_scalar($raw)) {
        $metadata['provider_reason'] = 'MELIPAYAMAK_RESULT_MISSING';
        return ['success' => false, 'code' => 'SMS_PROVIDER_REJECTED', 'request_id' => null, 'diagnostic' => $metadata];
    }
    $receipt = trim((string)$raw);
    if (preg_match('/^\d{16,}$/', $receipt) === 1) {
        $metadata['request_id'] = sms_mask_request_id($receipt);
        return ['success' => true, 'code' => 'SMS_ACCEPTED', 'request_id' => $receipt, 'diagnostic' => $metadata];
    }
    $metadata['provider_reason'] = preg_match('/^-?\d+$/', $receipt) === 1
        ? sms_soap_response_code($receipt)
        : 'MELIPAYAMAK_INVALID_RESPONSE';
    return ['success' => false, 'code' => 'SMS_PROVIDER_REJECTED', 'request_id' => null, 'diagnostic' => $metadata];
}

function sms_classify_provider_response(mixed $response, string $operation = 'send'): array
{
    return $operation === 'send' ? sms_parse_soap_send_response($response) : sms_parse_soap_send_response($response);
}

function sms_public_error_message(string $code): string
{
    return match ($code) {
        'SMS_SOAP_CONNECTION_FAILED' => 'ارتباط با سرویس پیامک برقرار نشد. لطفاً کمی بعد دوباره تلاش کنید.',
        'SMS_PROVIDER_REJECTED' => 'سرویس پیامک درخواست ارسال را نپذیرفت. لطفاً کمی بعد دوباره تلاش کنید.',
        'SMS_CONFIGURATION_ERROR' => 'سرویس پیامک در حال حاضر پیکربندی قابل استفاده‌ای ندارد.',
        default => 'ارسال پیامک به‌دلیل خطای داخلی انجام نشد. لطفاً کمی بعد دوباره تلاش کنید.',
    };
}

function sms_exception_is_connection_failure(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    foreach (['timed out', 'timeout', 'could not connect', 'connection refused', 'failed to load external entity', 'getaddrinfo', 'name resolution', 'network is unreachable', 'http request failed'] as $needle) {
        if (str_contains($message, $needle)) return true;
    }
    return false;
}

function sms_classify_soap_exception(Throwable $error): array
{
    $connectionFailure = sms_exception_is_connection_failure($error);
    if ($connectionFailure) $code = 'SMS_SOAP_CONNECTION_FAILED';
    elseif ($error instanceof SoapFault) $code = 'SMS_PROVIDER_REJECTED';
    else $code = 'SMS_RUNTIME_ERROR';
    return [
        'success' => false,
        'code' => $code,
        'request_id' => null,
        'diagnostic' => [
            'response_type' => 'exception',
            'result_type' => 'none',
            'provider_reason' => $error instanceof SoapFault ? 'SOAP_FAULT' : 'RUNTIME_EXCEPTION',
            'request_id' => null,
        ],
    ];
}

/** @return array<string, mixed> */
function sms_soap_client_options(): array
{
    return [
        'encoding' => 'UTF-8',
        'connection_timeout' => MELIPAYAMAK_CONNECT_TIMEOUT,
        'exceptions' => true,
        'trace' => false,
        'cache_wsdl' => WSDL_CACHE_BOTH,
        'stream_context' => stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]),
    ];
}

function sms_log_diagnostic(string $purpose, string $phone, array $result, ?Throwable $error = null): void
{
    if (($result['success'] ?? false) === true && !sms_diagnostics_enabled()) return;
    $diagnostic = $result['diagnostic'] ?? [];
    error_log('SMS diagnostic code=' . (string)($result['code'] ?? 'SMS_RUNTIME_ERROR') . ' reason=' . (string)($diagnostic['provider_reason'] ?? 'none') . ' purpose=' . $purpose . ' phone=' . mask_iran_mobile($phone) . ' type=' . (string)($diagnostic['response_type'] ?? 'unknown') . ' result_type=' . (string)($diagnostic['result_type'] ?? 'unknown') . ' reference=' . (string)($diagnostic['request_id'] ?? 'none') . ' exception=' . ($error ? get_class($error) : 'none'));
}

function sms_provider_send_otp(string $phone, string $otp, string $purpose): array
{
    $provider = strtolower(trim((string)getenv('SMS_PROVIDER')));
    $enabled = sms_bool_env('MELIPAYAMAK_ENABLED');
    if ($provider === '') $provider = $enabled ? 'melipayamak' : (sms_local_mode() ? 'log' : '');
    $normalizedPhone = normalize_iran_mobile($phone);
    if ($normalizedPhone === null) return ['success' => false, 'code' => 'SMS_CONFIGURATION_ERROR', 'request_id' => null];
    if ($provider === 'log' && sms_local_mode()) {
        error_log('SMS OTP local-log status=accepted purpose=' . $purpose . ' phone=' . mask_iran_mobile($normalizedPhone));
        return ['success' => true, 'code' => 'SMS_LOGGED', 'request_id' => null];
    }
    if ($provider !== 'melipayamak' || !$enabled) return ['success' => false, 'code' => 'SMS_CONFIGURATION_ERROR', 'request_id' => null];
    $username = trim((string)getenv('MELIPAYAMAK_USERNAME'));
    $password = trim((string)getenv('MELIPAYAMAK_PASSWORD'));
    $patternCode = sms_melipayamak_pattern_code();
    if ($username === '' || $password === '' || $patternCode === null || !extension_loaded('soap') || !class_exists('SoapClient')) {
        return ['success' => false, 'code' => 'SMS_CONFIGURATION_ERROR', 'request_id' => null];
    }
    try {
        $request = sms_melipayamak_pattern_request($normalizedPhone, $otp, $patternCode);
        $client = new SoapClient(MELIPAYAMAK_SEND_WSDL, sms_soap_client_options());
        $response = $client->__soapCall('SendByBaseNumber', [[
            'username' => $username,
            'password' => $password,
            'text' => $request['text'],
            'to' => $request['phone'],
            'bodyId' => $request['body_id'],
        ]]);
        $result = sms_parse_soap_send_response($response);
        $result['diagnostic']['payload_type'] = 'array';
        $result['diagnostic']['payload_count'] = count($request['text']);
        $result['diagnostic']['body_id'] = $request['body_id'];
        sms_log_diagnostic($purpose, $request['phone'], $result);
        return $result;
    } catch (InvalidArgumentException $error) {
        $result = ['success' => false, 'code' => 'SMS_CONFIGURATION_ERROR', 'request_id' => null, 'diagnostic' => ['response_type' => 'exception', 'result_type' => 'none', 'provider_reason' => 'INVALID_REQUEST', 'request_id' => null]];
        sms_log_diagnostic($purpose, $normalizedPhone, $result, $error);
        return $result;
    } catch (SoapFault $error) {
        $result = sms_classify_soap_exception($error);
        sms_log_diagnostic($purpose, $normalizedPhone, $result, $error);
        return $result;
    } catch (Throwable $error) {
        $result = sms_classify_soap_exception($error);
        sms_log_diagnostic($purpose, $normalizedPhone, $result, $error);
        return $result;
    }
}
