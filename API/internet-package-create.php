<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/topup_common.php';

topup_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    topup_json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

$data = topup_request_data();
topup_authenticate_user($data);
$clientRequestId = topup_validate_client_request_id($data['client_request_id'] ?? null);
$mobile = topup_normalize_mobile($data['mobile'] ?? '');
$operator = strtoupper(trim((string)($data['operator'] ?? '')));
$packageId = trim((string)($data['package_id'] ?? ''));

if ($clientRequestId === null) {
    topup_json(['success' => false, 'status' => 'failed', 'code' => 'INVALID_CLIENT_REQUEST_ID', 'message' => 'شناسه درخواست باید یک UUID نسخه ۴ معتبر باشد.'], 422);
}
if ($mobile === null) {
    topup_json(['success' => false, 'status' => 'failed', 'code' => 'INVALID_MOBILE', 'message' => 'شماره موبایل باید به شکل ۰۹xxxxxxxxx باشد.'], 422);
}
if (!isset(topup_operator_rules()[$operator])) {
    topup_json(['success' => false, 'status' => 'failed', 'code' => 'INVALID_OPERATOR', 'message' => 'اپراتور انتخاب‌شده معتبر نیست.'], 422);
}
if ($packageId === '' || strlen($packageId) > 64 || preg_match('/\A[a-zA-Z0-9._:-]+\z/', $packageId) !== 1) {
    topup_json(['success' => false, 'status' => 'failed', 'code' => 'INVALID_PACKAGE', 'message' => 'شناسه بسته اینترنت معتبر نیست.'], 422);
}

topup_json([
    'success' => false,
    'status' => 'failed',
    'code' => 'INTERNET_PROVIDER_UNAVAILABLE',
    'message' => 'خرید بسته اینترنت تا زمان پیکربندی سرویس‌دهنده فعال نیست؛ مبلغی از کیف پول کسر نشده است.',
    'data' => [
        'client_request_id' => $clientRequestId,
        'tracking_code' => null,
        'remaining_balance' => null,
    ],
], 503);
