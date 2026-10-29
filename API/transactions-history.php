<?php
declare(strict_types=1);

ob_start();

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$sessionPath = session_save_path();
if ($sessionPath === '' || !is_dir($sessionPath) || !is_writable($sessionPath)) {
    $fallbackSessionPath = dirname(__DIR__) . '/tmp/php-sessions';
    if ((is_dir($fallbackSessionPath) || mkdir($fallbackSessionPath, 0700, true)) && is_writable($fallbackSessionPath)) {
        session_save_path($fallbackSessionPath);
    }
}

require_once __DIR__ . '/../admin_panel/config.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

function tx_history_log(string $message, array $context = []): void
{
    $safe = [];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $safe[$key] = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value) || is_string($value) || $value === null) {
            $safe[$key] = $value;
        }
    }
    error_log('[AFARIEX-DEBUG][TX_HISTORY_API] ' . $message . ($safe === [] ? '' : ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        tx_history_log('response json encode failed', ['json_error' => json_last_error_msg()]);
        $statusCode = 500;
        http_response_code($statusCode);
        $json = '{"status":"error","success":false,"code":"TX_HISTORY_JSON_ERROR","message":"خطا در آماده‌سازی پاسخ تاریخچه تراکنش‌ها."}';
    }
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }
    echo $json;
    exit;
}

function tx_history_columns(string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        if (!table_exists($table)) {
            return $cache[$table] = [];
        }
        return $cache[$table] = db()->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $e) {
        tx_history_log($table . ' columns unavailable', ['exception' => $e->getMessage()]);
        return $cache[$table] = [];
    }
}

function tx_history_has_columns(string $table, array $columns): bool
{
    $available = tx_history_columns($table);
    foreach ($columns as $column) {
        if (!in_array($column, $available, true)) return false;
    }
    return true;
}

function tx_history_receipt_url(?string $storedPath): string
{
    try {
        $storedPath = trim((string)$storedPath);
        if ($storedPath === '' || str_contains($storedPath, "\0")) return '';
        if (parse_url($storedPath, PHP_URL_SCHEME) !== null || parse_url($storedPath, PHP_URL_HOST) !== null) return '';

        $path = ltrim(str_replace('\\', '/', rawurldecode($storedPath)), '/');
        if (str_starts_with($path, 'admin_panel/')) {
            $path = substr($path, strlen('admin_panel/'));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') return '';
        }

        $fileName = basename($path);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') return '';
        $candidates = str_starts_with($path, 'uploads/receipts/')
            ? [$path]
            : ['uploads/receipts/' . $fileName];

        $baseRoot = realpath(__DIR__ . '/../admin_panel/uploads/receipts');
        if ($baseRoot === false) return '';
        $baseRoot = rtrim(str_replace('\\', '/', $baseRoot), '/');

        foreach (array_unique($candidates) as $relativePath) {
            $absolute = realpath(__DIR__ . '/../admin_panel/' . $relativePath);
            if ($absolute === false || !is_file($absolute)) continue;
            $absolute = str_replace('\\', '/', $absolute);
            if ($absolute === $baseRoot || str_starts_with($absolute, $baseRoot . '/')) {
                return transaction_history_admin_base_url() . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
            }
        }
    } catch (Throwable $e) {
        tx_history_log('receipt url processing failed', ['exception' => $e->getMessage()]);
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
}

$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')));
$headerToken = '';
if ($authHeader !== '' && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $authMatch) === 1) {
    $headerToken = trim((string)$authMatch[1]);
}

$apiToken = trim((string)($_POST['api_token'] ?? ''));
if ($apiToken === '') $apiToken = trim((string)($_POST['token'] ?? ''));
if ($apiToken === '') $apiToken = trim((string)($_POST['user_token'] ?? ''));
$tokenSource = $apiToken !== '' ? 'request_body' : 'missing';
if ($apiToken === '' && $headerToken !== '') {
    $apiToken = $headerToken;
    $tokenSource = 'authorization_header';
}

tx_history_log('request received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'token_present' => $apiToken !== '',
    'token_source' => $tokenSource,
    'authorization_header_present' => $authHeader !== '',
]);

if ($apiToken === '') {
    tx_history_log('auth rejected reason=missing_api_token');
    json_response(['status' => 'error', 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
}

$requestedFilter = strtolower(trim((string)($_POST['filter'] ?? 'all')));
$allowedFilters = ['all', 'deposit', 'withdraw', 'remittance'];
if (!in_array($requestedFilter, $allowedFilters, true)) {
    tx_history_log('invalid filter rejected', ['filter' => $requestedFilter]);
    json_response(['status' => 'error', 'code' => 'INVALID_FILTER', 'message' => 'فیلتر تاریخچه تراکنش‌ها معتبر نیست.'], 400);
}

function transaction_history_admin_base_url(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return 'https://afariex.ir/admin_panel/';
    }

    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');

    return $scheme . '://' . $host . '/admin_panel/';
}

try {
    $authenticatedUserStmt = db()->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $authenticatedUserStmt->execute([$apiToken]);
    $authenticatedUserId = (int)($authenticatedUserStmt->fetchColumn() ?: 0);
    if ($authenticatedUserId <= 0) {
        json_response(['status' => 'error', 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }
    $userId = $authenticatedUserId;
} catch (Throwable $e) {
    tx_history_log('authentication failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
    json_response(['status' => 'error', 'code' => 'TX_HISTORY_AUTH_ERROR', 'message' => 'خطا در بررسی نشست کاربری.'], 500);
}

$where = ['t.user_id = ?'];
$params = [$userId];

try {
    $transactionColumns = tx_history_columns('transactions');
    $requiredTransactionColumns = ['id', 'user_id', 'amount', 'type', 'status', 'created_at'];
    foreach ($requiredTransactionColumns as $column) {
        if (!in_array($column, $transactionColumns, true)) {
            tx_history_log('transactions schema missing required column', ['column' => $column]);
            json_response(['status' => 'error', 'code' => 'TX_HISTORY_SCHEMA_ERROR', 'message' => 'ساختار جدول تراکنش‌ها برای نمایش تاریخچه کامل نیست.'], 500);
        }
    }

    $hasTrackingCode = in_array('tracking_code', $transactionColumns, true);
    $hasWithdrawalReference = in_array('withdrawal_reference', $transactionColumns, true);
    $trackingSelect = $hasTrackingCode && $hasWithdrawalReference
        ? "CASE WHEN t.type IN ('withdraw', 'withdrawal') THEN COALESCE(NULLIF(t.withdrawal_reference, ''), t.tracking_code) ELSE t.tracking_code END AS tracking_code"
        : ($hasTrackingCode ? 't.tracking_code AS tracking_code' : "'' AS tracking_code");
    $receiptSelect = in_array('receipt_image', $transactionColumns, true) ? "COALESCE(t.receipt_image, '') AS receipt_image" : "'' AS receipt_image";
    $descriptionSelect = in_array('description', $transactionColumns, true) ? "COALESCE(t.description, '') AS description" : "'' AS description";
    $feeTableReady = tx_history_has_columns('service_fee_transactions', ['source_type', 'source_id', 'fee_amount', 'total_amount']);
    $feeTransactionSelect = $feeTableReady
        ? 'COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, t.amount) AS total_amount'
        : '0 AS fee_amount, t.amount AS total_amount';
    $feeTransactionJoin = $feeTableReady
        ? "LEFT JOIN service_fee_transactions sft ON sft.source_type COLLATE utf8mb4_unicode_ci = CASE WHEN t.type IN ('withdraw', 'withdrawal') THEN 'withdrawal' ELSE t.type END COLLATE utf8mb4_unicode_ci AND sft.source_id = t.id"
        : '';
    $sql = "
        SELECT
            t.id,
            t.id AS raw_id,
            t.user_id,
            u.mobile AS source,
            t.amount,
            {$trackingSelect},
            t.type,
            t.status,
            {$receiptSelect},
            t.created_at,
            {$descriptionSelect},
            {$feeTransactionSelect},
            '' AS receipt_full_url
        FROM transactions t
        INNER JOIN users u ON u.id = t.user_id
        {$feeTransactionJoin}
    ";

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY t.id DESC';

    try {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $feeJoinError) {
            if (!$feeTableReady) throw $feeJoinError;
            tx_history_log('transactions fee join failed; retrying without fees', [
                'exception_class' => get_class($feeJoinError),
                'exception' => $feeJoinError->getMessage(),
            ]);
            $fallbackSelect = '0 AS fee_amount, t.amount AS total_amount';
            $fallbackSql = str_replace($feeTransactionSelect, $fallbackSelect, $sql);
            $fallbackSql = str_replace($feeTransactionJoin, '', $fallbackSql);
            $stmt = db()->prepare($fallbackSql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as &$row) {
            $row['receipt_full_url'] = tx_history_receipt_url($row['receipt_image'] ?? null);
        }
        unset($row);
    } catch (Throwable $e) {
        tx_history_log('transactions query failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
        json_response(['status' => 'error', 'code' => 'TX_HISTORY_TRANSACTIONS_ERROR', 'message' => 'خطا در دریافت تراکنش‌های کیف پول.'], 500);
    }

    if (tx_history_has_columns('remittances', ['id', 'user_id', 'amount_toman', 'status', 'created_at'])) {
        require_once __DIR__ . '/includes/remittance_destination_countries.php';
        $remittanceColumns = tx_history_columns('remittances');
        $hasRemittanceCountry = in_array('destination_country', $remittanceColumns, true);
        $remittanceCountrySelect = $hasRemittanceCountry ? 'r.destination_country' : "NULL AS destination_country";
        $remittanceWhere = [];
        $remittanceParams = [];
        $remittanceWhere[] = 'r.user_id = ?';
        $remittanceParams[] = $userId;
        $remittanceDescriptionSelect = in_array('description', tx_history_columns('remittances'), true) ? "COALESCE(r.description, '') AS description" : "'' AS description";
        $feeRemittanceSelect = $feeTableReady
            ? 'COALESCE(sft.fee_amount, 0) AS fee_amount, COALESCE(sft.total_amount, r.amount_toman) AS total_amount'
            : '0 AS fee_amount, r.amount_toman AS total_amount';
        $feeRemittanceJoin = $feeTableReady
            ? "LEFT JOIN service_fee_transactions sft ON sft.source_type COLLATE utf8mb4_unicode_ci = 'remittance' COLLATE utf8mb4_unicode_ci AND sft.source_id = r.id"
            : '';

        $remittanceSql = "
            SELECT
                CONCAT('remittance-', r.id) AS id,
                r.id AS raw_id,
                r.user_id,
                u.mobile AS source,
                r.amount_toman AS amount,
                '' AS tracking_code,
                'remittance' AS type,
                r.status,
                '' AS receipt_image,
                r.created_at,
                {$remittanceCountrySelect},
                {$remittanceDescriptionSelect},
                {$feeRemittanceSelect},
                '' AS receipt_full_url
            FROM remittances r
            INNER JOIN users u ON u.id = r.user_id
            {$feeRemittanceJoin}
        ";
        if ($remittanceWhere !== []) {
            $remittanceSql .= ' WHERE ' . implode(' AND ', $remittanceWhere);
        }
        try {
            try {
                $remittanceStmt = db()->prepare($remittanceSql . ' ORDER BY r.id DESC');
                $remittanceStmt->execute($remittanceParams);
                $remittanceRows = $remittanceStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $feeJoinError) {
                if (!$feeTableReady) throw $feeJoinError;
                tx_history_log('remittance fee join failed; retrying without fees', [
                    'exception_class' => get_class($feeJoinError),
                    'exception' => $feeJoinError->getMessage(),
                ]);
                $fallbackSelect = '0 AS fee_amount, r.amount_toman AS total_amount';
                $fallbackSql = str_replace($feeRemittanceSelect, $fallbackSelect, $remittanceSql);
                $fallbackSql = str_replace($feeRemittanceJoin, '', $fallbackSql);
                $remittanceStmt = db()->prepare($fallbackSql . ' ORDER BY r.id DESC');
                $remittanceStmt->execute($remittanceParams);
                $remittanceRows = $remittanceStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $countryNames = $hasRemittanceCountry ? remittance_destination_countries() : [];
            foreach ($remittanceRows as &$remittanceRow) {
                $countryName = $countryNames[(string)($remittanceRow['destination_country'] ?? '')] ?? null;
                if ($countryName !== null) {
                    $remittanceRow['description'] = trim((string)$remittanceRow['description']) . ' — مقصد: ' . $countryName;
                }
            }
            unset($remittanceRow);
            $rows = array_merge($rows, $remittanceRows);
            usort($rows, static fn (array $left, array $right): int => strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? '')));
        } catch (Throwable $e) {
            tx_history_log('remittance query failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
        }
    }

    if (tx_history_has_columns('user_debt_payments', ['id', 'user_id', 'amount', 'created_at'])) {
        $debtSourceSelect = in_array('source_transaction_id', tx_history_columns('user_debt_payments'), true)
            ? "COALESCE(CAST(p.source_transaction_id AS CHAR), '') AS tracking_code"
            : "'' AS tracking_code";
        try {
            $debtPaymentStmt = db()->prepare("
            SELECT
                CONCAT('debt-payment-', p.id) AS id,
                p.id AS raw_id,
                p.user_id,
                u.mobile AS source,
                p.amount,
                {$debtSourceSelect},
                'debt_payment' AS type,
                'approved' AS status,
                '' AS receipt_image,
                p.created_at,
                'تسویه بدهی' AS description,
                0 AS fee_amount,
                p.amount AS total_amount,
                '' AS receipt_full_url
            FROM user_debt_payments p
            INNER JOIN users u ON u.id = p.user_id
            WHERE p.user_id = ?
            ORDER BY p.id DESC
        ");
            $debtPaymentStmt->execute([$userId]);
            $debtRows = $debtPaymentStmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = array_merge($rows, $debtRows);
            usort($rows, static fn (array $left, array $right): int => strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? '')));
        } catch (Throwable $e) {
            tx_history_log('debt payments query failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
        }
    }

    json_response([
        'status' => 'success',
        'success' => true,
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    tx_history_log('history endpoint failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
    json_response(['status' => 'error', 'code' => 'TX_HISTORY_DB_ERROR', 'message' => 'خطا در دریافت تاریخچه تراکنش‌ها.'], 500);
}
