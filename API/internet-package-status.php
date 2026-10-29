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
if ($clientRequestId === null) {
    topup_json(['success' => false, 'code' => 'INVALID_CLIENT_REQUEST_ID', 'message' => 'شناسه درخواست معتبر نیست.'], 422);
}

topup_json([
    'success' => false,
    'status' => 'failed',
    'code' => 'INTERNET_PROVIDER_UNAVAILABLE',
    'message' => 'پیگیری بسته اینترنت تا زمان پیکربندی سرویس‌دهنده فعال نیست؛ این شناسه از طرف این endpoint ثبت نشده است.',
    'data' => ['client_request_id' => $clientRequestId, 'tracking_code' => null],
], 503);
