<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/remittance_currencies.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'http://localhost:3000', 'https://afariex.ir'];
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowedOrigins, true) ? $origin : '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$registry = remittance_destination_currencies();
$groups = [];
foreach (remittance_destination_groups() as $group => $countries) {
    $groupCountries = [];
    foreach ($countries as $code => $name) {
        $groupCountries[] = [
            'code' => $code,
            'name' => $name,
            'destination_currencies' => remittance_country_destination_currency_metadata($code),
        ];
    }
    $groups[] = ['group' => $group, 'title' => $group, 'countries' => $groupCountries];
}
$fixedRatePairs = array_values(remittance_fixed_rate_pairs());
foreach ($fixedRatePairs as &$pair) $pair['fixed_rate'] = true;
unset($pair);

echo json_encode([
    'success' => true,
    'data' => [
        'groups' => $groups,
        'source_currencies' => array_values(remittance_source_currencies()),
        'destination_currencies' => array_values($registry),
        'fixed_rate_pairs' => $fixedRatePairs,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
