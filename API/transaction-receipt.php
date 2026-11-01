<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
require_once __DIR__ . '/../admin_panel/config.php';
function transaction_receipt_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$input = $_POST;
if (!$input && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
}
$token = trim((string)($input['api_token'] ?? ''));
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') transaction_receipt_json(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
if ($token === '' || !$id) transaction_receipt_json(['success' => false, 'message' => 'درخواست رسید معتبر نیست.'], 422);
try {
    $user = db()->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $user->execute([$token]);
    $userId = (int)($user->fetchColumn() ?: 0);
    if (!$userId) transaction_receipt_json(['success' => false, 'message' => 'احراز هویت لازم است.'], 401);
    $stmt = db()->prepare("SELECT id, amount, type, status, created_at, description, tracking_code, receipt_image FROM transactions WHERE id = ? AND user_id = ? AND type NOT IN ('withdraw', 'withdrawal') LIMIT 1");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    if (!$row) transaction_receipt_json(['success' => false, 'message' => 'رسید پیدا نشد.'], 404);
    $path = trim((string)($row['receipt_image'] ?? ''));
    $imageUrl = '';
    if ($path !== '' && !str_contains($path, '..') && parse_url($path, PHP_URL_SCHEME) === null) {
        $name = basename(str_replace('\\', '/', $path));
        $file = __DIR__ . '/../admin_panel/uploads/receipts/' . $name;
        if (is_file($file)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'afariex.ir'));
            $imageUrl = $scheme . '://' . $host . '/admin_panel/uploads/receipts/' . rawurlencode($name);
        }
    }
    transaction_receipt_json(['success' => true, 'data' => [
        'id' => (string)$row['id'], 'amount' => (string)$row['amount'], 'type' => (string)$row['type'],
        'status' => (string)$row['status'], 'created_at' => (string)$row['created_at'],
        'description' => (string)($row['description'] ?? ''), 'tracking_code' => (string)($row['tracking_code'] ?? ''),
        'receipt_full_url' => $imageUrl,
    ]]);
} catch (Throwable $error) {
    error_log('[TRANSACTION_RECEIPT] ' . $error->getMessage());
    transaction_receipt_json(['success' => false, 'message' => 'دریافت رسید انجام نشد.'], 500);
}
