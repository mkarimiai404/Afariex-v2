<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'http://localhost:19006', 'http://127.0.0.1:19006', 'https://afariex.ir'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function orders_log(string $code, string $message, string $apiToken = ''): void
{
    if ($apiToken !== '') {
        $message = str_replace($apiToken, '[REDACTED]', $message);
    }
    $message = preg_replace('/((?:api[_-]?token|access[_-]?token|password|secret)\s*[=:]\s*)[^\s,;]+/i', '$1[REDACTED]', $message) ?? '[message redacted]';
    error_log('[ORDERS_API][' . $code . '] ' . $message);
}

function json_response(array $payload, int $status = 200): never
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        orders_log('ORDERS_JSON_ENCODE_FAILED', json_last_error_msg());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":false,"code":"ORDERS_JSON_ENCODE_FAILED"}';
        exit;
    }
    http_response_code($status);
    echo $json;
    exit;
}

function orders_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];

    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $error) {
        orders_log('ORDERS_SCHEMA_CHECK_FAILED', $error->getMessage());
        return $cache[$table] = [];
    }
}

function orders_has_columns(array $available, array $required): bool
{
    foreach ($required as $column) {
        if (!in_array($column, $available, true)) return false;
    }
    return true;
}

try {
    require_once __DIR__ . '/../admin_panel/config.php';
    $input = $_POST;
    if (!$input && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }
    $apiToken = trim((string)($input['api_token'] ?? $input['token'] ?? $input['user_token'] ?? ''));
    if ($apiToken === '') json_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED'], 401);

    $pdo = db();
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $userStmt->execute([$apiToken]);
    $user = $userStmt->fetch();
    if (!$user) json_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED'], 401);
    $userId = (int)$user['id'];
    $orders = [];
    $feeTableExists = table_exists('service_fee_transactions');
    $feeColumns = $feeTableExists ? orders_table_columns($pdo, 'service_fee_transactions') : [];
    $feeTransactionReady = $feeTableExists && orders_has_columns($feeColumns, ['source_type', 'source_id', 'fee_amount', 'total_amount']);
    $feeRemittanceReady = $feeTransactionReady && orders_has_columns($feeColumns, ['configured_fee_type', 'configured_fee_value']);

    if (table_exists('transactions')) {
        $fetchTransactions = static function (bool $includeFees) use ($pdo, $userId): array {
            $feeSelect = $includeFees
                ? ', COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, t.amount) AS total_amount'
                : ', 0 AS fee_amount, t.amount AS total_amount';
            $feeJoin = $includeFees
                ? "LEFT JOIN service_fee_transactions sft ON sft.source_type COLLATE utf8mb4_persian_ci = (CASE WHEN t.type IN ('withdraw', 'withdrawal') THEN 'withdrawal' ELSE t.type END) COLLATE utf8mb4_persian_ci AND sft.source_id = t.id"
                : '';
            $stmt = $pdo->prepare("SELECT t.id, t.amount, t.type, t.status, t.description, t.created_at {$feeSelect} FROM transactions t {$feeJoin} WHERE t.user_id = ? ORDER BY t.id DESC");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        };
        try {
            $transactionRows = $fetchTransactions($feeTransactionReady);
        } catch (Throwable $feeError) {
            if (!$feeTransactionReady) throw $feeError;
            orders_log('ORDERS_TRANSACTION_FEE_JOIN_FAILED', $feeError->getMessage(), $apiToken);
            $transactionRows = $fetchTransactions(false);
        }
        foreach ($transactionRows as $row) {
            $type = strtolower(trim((string)($row['type'] ?? '')));
            if (!in_array($type, ['deposit', 'withdraw', 'withdrawal'], true)) continue;
            $orders[] = [
                'id' => 'transaction-' . (int)$row['id'],
                'type' => $type === 'deposit' ? 'deposit' : 'withdrawal',
                'title' => $type === 'deposit' ? 'افزایش موجودی' : 'برداشت وجه',
                'amount' => (float)($row['amount'] ?? 0),
                'currency' => 'تومان',
                'status' => strtolower(trim((string)($row['status'] ?? 'pending'))),
                'created_at' => $row['created_at'] ?? null,
                'description' => $type === 'deposit' ? 'درخواست افزایش موجودی کیف پول' : 'درخواست برداشت از کیف پول',
                'metadata' => [
                    'fee_amount' => (int)($row['fee_amount'] ?? 0),
                    'total_amount' => (int)($row['total_amount'] ?? $row['amount'] ?? 0),
                ],
            ];
        }
    }

    if (table_exists('remittances')) {
        require_once __DIR__ . '/includes/remittance_destination_countries.php';
        $remittanceColumns = orders_table_columns($pdo, 'remittances');
        $countrySelect = in_array('destination_country', $remittanceColumns, true)
            ? 'r.destination_country'
            : 'NULL AS destination_country';
        $fetchRemittances = static function (bool $includeFees) use ($pdo, $userId, $countrySelect): array {
            $feeSelect = $includeFees
                ? ', COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, r.amount_toman) AS total_amount, COALESCE(sft.configured_fee_type, \'fixed\') AS fee_type, COALESCE(sft.configured_fee_value, 0) AS fee_value'
                : ', 0 AS fee_amount, r.amount_toman AS total_amount, \'fixed\' AS fee_type, 0 AS fee_value';
            $feeJoin = $includeFees
                ? "LEFT JOIN service_fee_transactions sft ON sft.source_type COLLATE utf8mb4_persian_ci = 'remittance' COLLATE utf8mb4_persian_ci AND sft.source_id = r.id"
                : '';
            $stmt = $pdo->prepare('SELECT r.id, r.amount_toman, r.amount_afghani, r.status, r.created_at, ' . $countrySelect . ', r.sender, r.receiver, r.agency, (SELECT a.address FROM agencies a WHERE BINARY a.name = BINARY r.agency ORDER BY a.id DESC LIMIT 1) AS agency_address ' . $feeSelect . ' FROM remittances r ' . $feeJoin . ' WHERE r.user_id = ? ORDER BY r.id DESC');
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        };
        try {
            $remittanceRows = $fetchRemittances($feeRemittanceReady);
        } catch (Throwable $feeError) {
            if (!$feeRemittanceReady) throw $feeError;
            orders_log('ORDERS_REMITTANCE_FEE_JOIN_FAILED', $feeError->getMessage(), $apiToken);
            $remittanceRows = $fetchRemittances(false);
        }
        foreach ($remittanceRows as $row) {
            $orders[] = [
                'id' => 'remittance-' . (int)$row['id'],
                'type' => 'remittance',
                'title' => 'حواله',
                'amount' => (float)($row['amount_toman'] ?? 0),
                'currency' => 'تومان',
                'status' => strtolower(trim((string)($row['status'] ?? 'pending'))),
                'created_at' => $row['created_at'] ?? null,
                'description' => 'درخواست حواله ثبت‌شده' . (isset(remittance_destination_countries()[(string)($row['destination_country'] ?? '')]) ? ' - ' . remittance_destination_countries()[(string)$row['destination_country']] : ''),
                'metadata' => [
                    'tracking_number' => (int)$row['id'],
                    'sender' => trim((string)($row['sender'] ?? '')),
                    'receiver' => trim((string)($row['receiver'] ?? '')),
                    'amount_afghani' => (float)($row['amount_afghani'] ?? 0),
                    'amount_toman' => (int)($row['amount_toman'] ?? 0),
                    'fee_amount' => (int)($row['fee_amount'] ?? 0),
                    'total_amount' => (int)($row['total_amount'] ?? $row['amount_toman'] ?? 0),
                    'fee_type' => (string)($row['fee_type'] ?? 'fixed'),
                    'fee_value' => (string)($row['fee_value'] ?? '0'),
                    'destination' => trim((string)($row['agency_address'] ?? '')) !== '' ? trim((string)$row['agency_address']) : trim((string)($row['agency'] ?? '')),
                    'destination_country' => $row['destination_country'] ?? null,
                    'destination_country_name' => remittance_destination_countries()[(string)($row['destination_country'] ?? '')] ?? null,
                ],
            ];
        }
    }

    usort($orders, static fn (array $left, array $right): int => strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? '')));
    json_response([
        'success' => true,
        'code' => 'ORDERS_LOADED',
        'data' => [
            'orders' => $orders,
            'total' => count($orders),
            'latest_activity' => $orders[0]['created_at'] ?? null,
        ],
    ]);
} catch (Throwable $error) {
    orders_log('ORDERS_UNAVAILABLE', $error->getMessage(), isset($apiToken) ? $apiToken : '');
    json_response(['success' => false, 'code' => 'ORDERS_UNAVAILABLE'], 500);
}
