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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if (!table_exists('exchange_rates')) {
        throw new RuntimeException('Exchange rate table is unavailable.');
    }

    $columns = db()->query('SHOW COLUMNS FROM exchange_rates')->fetchAll(PDO::FETCH_COLUMN, 0);
    $rateColumn = in_array('afn_to_toman', $columns, true)
        ? 'afn_to_toman'
        : (in_array('rate', $columns, true) ? 'rate' : null);
    if ($rateColumn === null || !in_array('is_active', $columns, true)) {
        throw new RuntimeException('Exchange rate schema is incomplete.');
    }

    $dateColumn = in_array('effective_date', $columns, true)
        ? 'effective_date'
        : (in_array('created_at', $columns, true) ? 'created_at' : null);
    $dateSelect = $dateColumn !== null ? "`{$dateColumn}`" : 'NULL';
    $stmt = db()->query("SELECT id, `{$rateColumn}` AS toman_per_afn, {$dateSelect} AS effective_date FROM exchange_rates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $activeRate = $stmt->fetch(PDO::FETCH_ASSOC);
    $rows = $activeRate ? [$activeRate] : [];
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['success' => false, 'code' => 'EXCHANGE_RATE_UNAVAILABLE', 'data' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
