<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/config.php';

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
    $row = null;
    if (table_exists('exchange_rates')) {
        $stmt = db()->query('SELECT id, rate, afn_to_toman, toman_to_afn, effective_date FROM exchange_rates WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
