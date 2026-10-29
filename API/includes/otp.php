<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../../admin_panel/config.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/sms_provider.php';

function otp_cors(): void
{
    if (ob_get_level() > 0) ob_clean();
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    $allowed = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed, true)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
}

function otp_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function otp_input(): array
{
    if (!empty($_POST)) return $_POST;
    $raw = trim((string)file_get_contents('php://input'));
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false || str_starts_with($raw, '{')) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function otp_purpose(string $purpose): bool { return in_array($purpose, ['registration', 'change_password', 'change_pin'], true); }

function otp_dev_code_allowed(): bool
{
    return sms_local_mode() && sms_bool_env('OTP_DEV_RETURN_CODE') && !in_array(strtolower((string)($_SERVER['SERVER_NAME'] ?? '')), ['afariex.ir', 'www.afariex.ir'], true);
}

function otp_action_token(): string { return bin2hex(random_bytes(32)); }

function otp_token_hash(string $token): string
{
    $secret = (string)getenv('OTP_TOKEN_SECRET');
    return hash_hmac('sha256', $token, $secret !== '' ? $secret : hash('sha256', __FILE__));
}

function otp_normalized_code(string $code): string
{
    return trim(strtr($code, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
}

function otp_requester_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $secret = (string)getenv('OTP_RATE_LIMIT_SECRET');
    return hash_hmac('sha256', $ip, $secret !== '' ? $secret : hash('sha256', __FILE__));
}
