<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/lib/InaxClient.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'روش درخواست مجاز نیست.'], JSON_UNESCAPED_UNICODE); exit; }
try {
    require_login();
    require_permission('view');
    echo json_encode(['success' => true, 'data' => (new InaxClient())->credit()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InaxConfigurationException $error) {
    http_response_code(503); echo json_encode(['success' => false, 'code' => 'INAX_CONFIGURATION_ERROR', 'message' => 'تنظیمات سرویس خرید شارژ روی سرور کامل نیست.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(502); echo json_encode(['success' => false, 'code' => 'INAX_CREDIT_ERROR', 'message' => 'دریافت اعتبار پنل خرید شارژ انجام نشد.'], JSON_UNESCAPED_UNICODE);
}