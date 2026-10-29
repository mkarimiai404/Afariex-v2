<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../admin_panel/config.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
if (ob_get_level() > 0) {
    ob_clean();
}

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $rows = [];
    if (table_exists('agencies')) {
        $stmt = db()->query('SELECT id, name, address FROM agencies ORDER BY name ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Agencies API query or serialization failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'AGENCIES_UNAVAILABLE',
        'message' => 'دریافت فهرست نمایندگی‌ها انجام نشد.',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
