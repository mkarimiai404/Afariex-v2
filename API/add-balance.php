<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost:8081', 'http://127.0.0.1:8081', 'https://afariex.ir'];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
require_once __DIR__ . '/includes/subcustomer.php';

function add_balance_log(string $message, array $context = []): void
{
    $safe = [];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $safe[$key] = $value ? 'true' : 'false';
        } elseif (is_scalar($value) || $value === null) {
            $safe[$key] = $value;
        }
    }
    error_log('[ADD_BALANCE_API] ' . $message . ($safe === [] ? '' : ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function upload_error_response(int $errorCode): array
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ['UPLOAD_TOO_LARGE', 'حجم تصویر فیش از حد مجاز سرور بیشتر است.', 413],
        UPLOAD_ERR_PARTIAL => ['UPLOAD_PARTIAL', 'آپلود تصویر فیش کامل انجام نشد.', 400],
        UPLOAD_ERR_NO_FILE => ['UPLOAD_MISSING', 'تصویر فیش ارسال نشده است.', 422],
        UPLOAD_ERR_NO_TMP_DIR => ['UPLOAD_TMP_MISSING', 'پوشه موقت آپلود روی سرور در دسترس نیست.', 500],
        UPLOAD_ERR_CANT_WRITE => ['UPLOAD_WRITE_FAILED', 'ذخیره تصویر فیش روی سرور انجام نشد.', 500],
        UPLOAD_ERR_EXTENSION => ['UPLOAD_EXTENSION_BLOCKED', 'آپلود تصویر فیش توسط تنظیمات سرور متوقف شد.', 500],
        default => ['UPLOAD_FAILED', 'آپلود تصویر فیش انجام نشد.', 400],
    };
}

function ini_size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (float)$value;
    return (int)match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function transaction_has_column(string $column): bool
{
    static $columns = null;
    if ($columns === null) {
        $columns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    return in_array($column, $columns, true);
}

function authenticated_deposit_user_id(?string $apiToken, int $legacyUserId): int
{
    if ($apiToken !== null && $apiToken !== '') {
        $stmt = db()->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
        $stmt->execute([$apiToken]);
        $userId = (int)($stmt->fetchColumn() ?: 0);
        add_balance_log('auth result', ['mode' => 'api_token', 'authenticated' => $userId > 0]);
        return $userId;
    }

    if ($legacyUserId > 0) {
        $stmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$legacyUserId]);
        $userId = (int)($stmt->fetchColumn() ?: 0);
        add_balance_log('auth result', ['mode' => 'legacy_user_id', 'authenticated' => $userId > 0]);
        return $userId;
    }

    add_balance_log('auth result', ['mode' => 'missing', 'authenticated' => false]);
    return 0;
}

function save_deposit_receipt_upload(array $file): string
{
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    add_balance_log('file upload error code', ['code' => $errorCode]);
    if ($errorCode !== UPLOAD_ERR_OK) {
        [$code, $message, $status] = upload_error_response($errorCode);
        json_response(['success' => false, 'code' => $code, 'message' => $message], $status);
    }

    $serverMaxSize = min(
        ini_size_to_bytes((string)ini_get('upload_max_filesize')),
        ini_size_to_bytes((string)ini_get('post_max_size'))
    );
    $appMaxSize = 5 * 1024 * 1024;
    $maxSize = $serverMaxSize > 0 ? min($appMaxSize, $serverMaxSize) : $appMaxSize;
    if ((int)($file['size'] ?? 0) > $maxSize) {
        json_response(['success' => false, 'code' => 'UPLOAD_TOO_LARGE', 'message' => 'حجم تصویر فیش نباید بیشتر از ۵ مگابایت باشد.'], 413);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    add_balance_log('upload tmp path exists', ['exists' => $tmpName !== '' && is_uploaded_file($tmpName)]);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        json_response(['success' => false, 'code' => 'UPLOAD_TMP_MISSING', 'message' => 'فایل موقت آپلود روی سرور در دسترس نیست.'], 500);
    }

    $uploadDir = __DIR__ . '/../admin_panel/uploads/receipts';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        add_balance_log('destination directory', ['path' => $uploadDir, 'exists' => false]);
        json_response(['success' => false, 'code' => 'UPLOAD_DIR_MISSING', 'message' => 'پوشه ذخیره تصویر فیش روی سرور در دسترس نیست.'], 500);
    }
    add_balance_log('destination directory', ['path' => $uploadDir, 'exists' => is_dir($uploadDir)]);
    add_balance_log('destination writable', ['writable' => is_writable($uploadDir)]);
    if (!is_writable($uploadDir)) {
        json_response(['success' => false, 'code' => 'UPLOAD_WRITE_FAILED', 'message' => 'پوشه ذخیره تصویر فیش قابل نوشتن نیست.'], 500);
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        json_response(['success' => false, 'code' => 'INVALID_FILE_TYPE', 'message' => 'فرمت تصویر فیش باید jpg، jpeg یا png باشد.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowedMimes = ['image/jpeg', 'image/png'];
    if (!in_array($mime, $allowedMimes, true)) {
        json_response(['success' => false, 'code' => 'INVALID_FILE_TYPE', 'message' => 'فایل انتخابی تصویر معتبر نیست.'], 422);
    }

    $extension = $mime === 'image/png' ? 'png' : ($extension === 'jpeg' ? 'jpeg' : 'jpg');
    $fileName = 'deposit_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $uploadDir . '/' . $fileName;
    $moved = move_uploaded_file($tmpName, $destination);
    add_balance_log('move_uploaded_file result', ['moved' => $moved]);
    if (!$moved) {
        json_response(['success' => false, 'code' => 'UPLOAD_WRITE_FAILED', 'message' => 'ذخیره تصویر فیش روی سرور انجام نشد.'], 500);
    }

    return 'uploads/receipts/' . $fileName;
}

add_balance_log('request received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'file_uploads' => ini_get('file_uploads'),
    'memory_limit' => ini_get('memory_limit'),
]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'روش درخواست مجاز نیست.'], 405);
}

if ((string)ini_get('file_uploads') !== '1') {
    json_response(['success' => false, 'code' => 'UPLOAD_DISABLED', 'message' => 'آپلود فایل روی سرور فعال نیست.'], 500);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes = ini_size_to_bytes((string)ini_get('post_max_size'));
if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes && $_POST === [] && $_FILES === []) {
    json_response(['success' => false, 'code' => 'UPLOAD_TOO_LARGE', 'message' => 'حجم درخواست از حد مجاز سرور بیشتر است.'], 413);
}

$apiToken = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? $_POST['user_token'] ?? ''));
$legacyUserId = (int)($_POST['user_id'] ?? $_POST['id'] ?? $_POST['uid'] ?? 0);
$amountRaw = normalize_persian_digits(trim((string)($_POST['amount'] ?? '')));
$amountRaw = str_replace([',', '٬', ' '], '', $amountRaw);
$trackingCode = trim(normalize_persian_digits((string)($_POST['tracking_code'] ?? '')));

if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amountRaw) || (float)$amountRaw <= 0) {
    json_response(['success' => false, 'code' => 'INVALID_AMOUNT', 'message' => 'مبلغ واریزی معتبر نیست.'], 422);
}

if ($trackingCode === '') {
    $trackingCode = (string)random_int(100000, 999999999);
}

try {
    $userId = authenticated_deposit_user_id($apiToken, $legacyUserId);
    if ($userId <= 0) {
        json_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }

    try {
        subcustomer_require_verified(db(), $userId);
    } catch (RuntimeException $verificationError) {
        if ($verificationError->getMessage() === 'SUBCUSTOMER_VERIFICATION_REQUIRED') {
            json_response(['success' => false, 'code' => 'SUBCUSTOMER_VERIFICATION_REQUIRED', 'message' => 'ابتدا تایید تلفن و احراز هویت را تکمیل کنید.'], 403);
        }
        throw $verificationError;
    }

    if (!isset($_FILES['receipt']) || !is_array($_FILES['receipt'])) {
        add_balance_log('file upload error code', ['code' => UPLOAD_ERR_NO_FILE]);
        json_response(['success' => false, 'code' => 'UPLOAD_MISSING', 'message' => 'تصویر فیش ارسال نشده است.'], 422);
    }

    $receiptImagePath = save_deposit_receipt_upload($_FILES['receipt']);

    $requiredColumns = ['user_id', 'amount', 'type', 'status', 'receipt_image', 'created_at'];
    foreach ($requiredColumns as $column) {
        if (!transaction_has_column($column)) {
            add_balance_log('DB insert failure', ['reason' => 'missing_column', 'column' => $column]);
            json_response(['success' => false, 'code' => 'TRANSACTIONS_SCHEMA_INCOMPATIBLE', 'message' => 'ساختار جدول تراکنش‌ها برای ثبت فیش کامل نیست.'], 500);
        }
    }

    $columns = ['user_id', 'amount', 'type', 'status', 'receipt_image', 'created_at'];
    $placeholders = ['?', '?', "'deposit'", "'pending'", '?', 'NOW()'];
    $values = [$userId, $amountRaw, $receiptImagePath];
    if (transaction_has_column('tracking_code')) {
        $columns[] = 'tracking_code';
        $placeholders[] = '?';
        $values[] = $trackingCode;
    }
    if (transaction_has_column('description')) {
        $columns[] = 'description';
        $placeholders[] = '?';
        $values[] = 'شارژ کیف پول';
    }
    if (transaction_has_column('balance_applied')) {
        $columns[] = 'balance_applied';
        $placeholders[] = '0';
    }

    add_balance_log('DB insert started');
    $sql = 'INSERT INTO transactions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
    $transactionId = (int)db()->lastInsertId();
    add_balance_log('DB insert success', ['transaction_id' => $transactionId]);

    json_response([
        'success' => true,
        'code' => 'DEPOSIT_RECEIPT_SUBMITTED',
        'message' => 'فیش واریزی با موفقیت ثبت شد.',
        'data' => [
            'transaction_id' => $transactionId,
            'tracking_code' => $trackingCode,
        ],
    ]);
} catch (Throwable $e) {
    add_balance_log('exception message', ['message' => $e->getMessage()]);
    json_response(['success' => false, 'code' => 'DEPOSIT_RECEIPT_FAILED', 'message' => 'ثبت فیش واریزی انجام نشد.'], 500);
}
