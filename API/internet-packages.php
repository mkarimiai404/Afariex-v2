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

topup_authenticate_user(topup_request_data());
topup_json([
    'success' => false,
    'status' => 'failed',
    'code' => 'INTERNET_PROVIDER_UNAVAILABLE',
    'message' => 'فهرست بسته‌های اینترنت از سرویس‌دهنده پیکربندی نشده است.',
    'data' => ['packages' => []],
], 503);
