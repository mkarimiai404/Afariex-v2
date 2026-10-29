<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Tehran');

// Load ignored server-side settings without exposing them to Expo or browser code.
$projectRoot = dirname(__DIR__);
$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$declaredEnvironment = strtolower(trim((string)(getenv('AFARIEX_ENV') ?: getenv('APP_ENV') ?: '')));
$isLocalEnvironment = $declaredEnvironment === 'local'
    || ($declaredEnvironment !== 'production' && (
        in_array($serverName, ['localhost', '127.0.0.1'], true)
        || PHP_OS_FAMILY === 'Windows'
    ));

if (!$isLocalEnvironment) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

$loadEnvFile = static function (string $path, bool $overwrite = false): void {
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) === 1 && ($overwrite || getenv($name) === false)) {
            putenv($name . '=' . trim($value, " \t\"'"));
        }
    }
};

$loadEnvFile($projectRoot . DIRECTORY_SEPARATOR . '.env.local');
if (!$isLocalEnvironment) {
    $loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env.production.local', true);
}

$configValue = static function (array $names, string $fallback = ''): string {
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim($value) !== '') return trim($value);
    }
    return $fallback;
};

define('DB_HOST', $configValue(['DB_HOST', 'AFARIEX_DB_HOST'], 'localhost'));
define('DB_NAME', $configValue(['DB_NAME', 'AFARIEX_DB_NAME'], $isLocalEnvironment ? 'afariex_db' : ''));
define('DB_USER', $configValue(['DB_USER', 'AFARIEX_DB_USER'], $isLocalEnvironment ? 'root' : ''));
define('DB_PASS', $configValue(['DB_PASS', 'AFARIEX_DB_PASS'], $isLocalEnvironment ? '' : ''));

if (!$isLocalEnvironment && (DB_NAME === '' || DB_USER === '' || DB_PASS === '')) {
    throw new RuntimeException('Production database configuration is incomplete.');
}
const INITIAL_WITHDRAWAL_LIMIT_TOMAN = 5000000;
const SILVER_DAILY_LIMIT_TOMAN = 100000000;
const GOLD_DAILY_LIMIT_TOMAN = 15000000;

function verification_level_definitions(): array
{
    return [
        'bronze' => ['title' => 'برنزی', 'daily_limit' => INITIAL_WITHDRAWAL_LIMIT_TOMAN, 'next' => 'silver', 'documents' => ['identity_document', 'selfie']],
        'silver' => ['title' => 'نقره‌ای', 'daily_limit' => SILVER_DAILY_LIMIT_TOMAN, 'next' => 'gold', 'documents' => ['video']],
        'gold' => ['title' => 'طلایی', 'daily_limit' => GOLD_DAILY_LIMIT_TOMAN, 'next' => null, 'documents' => []],
    ];
}

function normalize_verification_level(string $level): string
{
    return match (strtolower(trim($level))) {
        'gold' => 'gold',
        'silver', 'verified' => 'silver',
        default => 'bronze',
    };
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // خطای سینتکس اینجا برطرف شد
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensure_verification_schema(): void
{
    static $ready = false;
    if ($ready) return;

    db()->exec('
        CREATE TABLE IF NOT EXISTS user_verification_levels (
            user_id INT NOT NULL PRIMARY KEY,
            level VARCHAR(32) NOT NULL DEFAULT \'initial\',
            phone_verified TINYINT(1) NOT NULL DEFAULT 0,
            phone_verified_at DATETIME NULL,
            withdrawal_limit DECIMAL(20,2) NULL,
            custom_remittance_limit DECIMAL(20,2) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    db()->exec('
        CREATE TABLE IF NOT EXISTS verification_upgrade_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_type VARCHAR(16) NOT NULL DEFAULT \'silver\',
            requested_level VARCHAR(32) NOT NULL DEFAULT \'verified\',
            status ENUM(\'pending\', \'approved\', \'rejected\', \'needs_more_info\') NOT NULL DEFAULT \'pending\',
            identity_document_path VARCHAR(255) NULL,
            selfie_path VARCHAR(255) NULL,
            video_path VARCHAR(255) NULL,
            admin_id INT NULL,
            admin_note TEXT NULL,
            rejection_reason TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            INDEX idx_upgrade_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $requestColumns = db()->query('SHOW COLUMNS FROM verification_upgrade_requests')->fetchAll(PDO::FETCH_COLUMN, 0);
    $requestAdds = [
        'request_type' => "ALTER TABLE verification_upgrade_requests ADD request_type VARCHAR(16) NOT NULL DEFAULT 'silver' AFTER user_id",
        'identity_document_path' => 'ALTER TABLE verification_upgrade_requests ADD identity_document_path VARCHAR(255) NULL AFTER status',
        'selfie_path' => 'ALTER TABLE verification_upgrade_requests ADD selfie_path VARCHAR(255) NULL AFTER identity_document_path',
        'video_path' => 'ALTER TABLE verification_upgrade_requests ADD video_path VARCHAR(255) NULL AFTER selfie_path',
        'rejection_reason' => 'ALTER TABLE verification_upgrade_requests ADD rejection_reason TEXT NULL AFTER admin_note',
    ];
    foreach ($requestAdds as $column => $sql) {
        if (!in_array($column, $requestColumns, true)) db()->exec($sql);
    }
    $verificationColumns = db()->query('SHOW COLUMNS FROM user_verification_levels')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('phone_verified_at', $verificationColumns, true)) {
        db()->exec('ALTER TABLE user_verification_levels ADD phone_verified_at DATETIME NULL AFTER phone_verified');
    }
    db()->exec('
        CREATE TABLE IF NOT EXISTS phone_verification_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            mobile VARCHAR(32) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone_code_user (user_id, created_at),
            INDEX idx_phone_code_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $ready = true;
}

function verification_state(int $userId, bool $forUpdate = false): array
{
    ensure_verification_schema();
    $sql = 'SELECT level, phone_verified, phone_verified_at, withdrawal_limit, custom_remittance_limit FROM user_verification_levels WHERE user_id = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $level = normalize_verification_level((string)($row['level'] ?? 'bronze'));
    $definitions = verification_level_definitions();
    $definition = $definitions[$level];
    $emailVerified = false;
    $userColumns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('email_verified', $userColumns, true)) {
        $emailStmt = db()->prepare('SELECT email_verified FROM users WHERE id = ?');
        $emailStmt->execute([$userId]);
        $emailVerified = !empty($emailStmt->fetchColumn());
    } elseif (in_array('email_verified_at', $userColumns, true)) {
        $emailStmt = db()->prepare('SELECT email_verified_at FROM users WHERE id = ?');
        $emailStmt->execute([$userId]);
        $emailVerified = !empty($emailStmt->fetchColumn());
    }
    return [
        'level' => $level,
        'level_title' => $definition['title'],
        // The explicit flag is authoritative. Older verified rows may not have a timestamp.
        'phone_verified' => !empty($row['phone_verified']),
        'phone_verified_at' => $row['phone_verified_at'] ?? null,
        'email_verified' => $emailVerified,
        'bronze_eligible' => !empty($row['phone_verified']) || $emailVerified,
        'daily_limit' => $definition['daily_limit'],
        'custom_remittance_limit' => $row['custom_remittance_limit'] !== null ? (float)$row['custom_remittance_limit'] : null,
        'withdrawal_limit' => $definition['daily_limit'],
        'next_level' => $definition['next'],
        'next_level_documents' => $definition['documents'],
    ];
}

function daily_transaction_usage(int $userId, bool $forUpdate = false): float
{
    $transactionColumns = db()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN, 0);
    $adminExclusion = in_array('description', $transactionColumns, true) ? " AND (description IS NULL OR description NOT LIKE 'Manual operation %')" : '';
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM transactions
        WHERE user_id = ? AND type IN ('withdraw', 'withdrawal')
        AND status IN ('approved', 'processing', 'pending', 'paid')
        AND DATE(created_at) = CURDATE()" . $adminExclusion;
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $total = (float)$stmt->fetchColumn();
    if (table_exists('remittances')) {
        // Remittances reserve/deduct wallet funds and count while pending or completed;
        // rejected, cancelled, and reversed records do not consume the allowance.
        $remittanceStmt = db()->prepare("SELECT COALESCE(SUM(amount_toman), 0) FROM remittances WHERE user_id = ? AND status IN ('pending', 'processing', 'approved', 'paid') AND DATE(created_at) = CURDATE()");
        $remittanceStmt->execute([$userId]);
        $total += (float)$remittanceStmt->fetchColumn();
    }
    return $total;
}

function daily_remittance_usage(int $userId): float
{
    if (!table_exists('remittances')) return 0.0;
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount_toman), 0) FROM remittances WHERE user_id = ? AND status IN ('pending', 'processing', 'approved', 'paid') AND DATE(created_at) = CURDATE()");
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

function daily_transaction_summary(int $userId, ?array $state = null): array
{
    $state ??= verification_state($userId);
    $used = daily_transaction_usage($userId);
    $limit = $state['daily_limit'];
    return [
        'daily_limit' => $limit,
        'used_today' => $used,
        'remaining_today' => max(0, (float)$limit - $used),
        'remittance_used_today' => daily_remittance_usage($userId),
    ];
}

function is_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_or_fail(?string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

function current_role(): string
{
    return (string)($_SESSION['admin_role'] ?? 'viewer');
}

function can(string $permission): bool
{
    $role = current_role();
    $matrix = [
        'admin' => ['view', 'create', 'edit', 'delete'],
        'editor' => ['view', 'create', 'edit'],
        'viewer' => ['view'],
    ];
    $allowed = $matrix[$role] ?? ['view'];
    return in_array($permission, $allowed, true);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        flash('error', 'شما دسترسی لازم برای این عملیات را ندارید.');
        header('Location: index.php');
        exit;
    }
}

function table_exists(string $tableName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function log_activity(string $action, string $entity, ?int $entityId = null, ?string $description = null): void
{
    if (!table_exists('activity_logs')) {
        return;
    }
    $stmt = db()->prepare('
        INSERT INTO activity_logs (user_id, action, entity, entity_id, description, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        (int)($_SESSION['admin_id'] ?? 0),
        $action,
        $entity,
        $entityId,
        $description,
    ]);
}

function jalali_date(?string $dateTime): string
{
    if (!$dateTime) return '-';
    try {
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::SHORT,
                IntlDateFormatter::SHORT,
                'Asia/Tehran',
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd HH:mm'
            );
            $timestamp = strtotime($dateTime);
            if ($timestamp !== false) {
                $formatted = $formatter->format($timestamp);
                if ($formatted !== false) {
                    return (string)$formatted;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return (string)$dateTime;
}

function to_jalali_datetime($date_string)
{
    if (empty($date_string)) return '';
    $formatter = new IntlDateFormatter(
        'fa_IR@calendar=persian',
        IntlDateFormatter::FULL,
        IntlDateFormatter::FULL,
        'Asia/Tehran',
        IntlDateFormatter::TRADITIONAL,
        'yyyy/MM/dd HH:mm:ss'
    );
    $timestamp = strtotime((string)$date_string);
    return $timestamp ? (string)$formatter->format($timestamp) : (string)$date_string;
}

function status_fa(string $status): string
{
    return match ($status) {
        'pending' => 'در انتظار',
        'approved' => 'تایید شده',
        'rejected' => 'رد شده',
        'paid' => 'پرداخت شده',
        'suspended' => 'تعلیق شده',
        default => $status,
    };
}

function normalize_persian_digits(string $value): string
{
    return strtr($value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function jalali_input_to_gregorian_datetime(?string $value, bool $endOfDay = false): ?string
{
    $value = trim(normalize_persian_digits((string)$value));
    if ($value === '') {
        return null;
    }

    $value = str_replace('-', '/', $value);
    $suffix = $endOfDay ? ' 23:59:59' : ' 00:00:00';

    if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $value) && class_exists(IntlDateFormatter::class)) {
        try {
            $formatter = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'Asia/Tehran',
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd'
            );
            $timestamp = $formatter->parse($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp) . $suffix;
            }
        } catch (Throwable $e) {
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp) . $suffix;
    }

    return null;
}

function export_xls_table(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . e((string)$header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . e((string)$cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit;
}

function render_print_table_view(string $title, array $headers, array $rows, string $subtitle = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?></title>
        <style>
            body {
                margin: 0;
                padding: 24px;
                background: #f8fafc;
                color: #111827;
                font-family: Tahoma, Arial, sans-serif;
                direction: rtl;
            }
            .print-sheet {
                max-width: 1200px;
                margin: 0 auto;
                background: #fff;
                border: 1px solid #dbe4ef;
                border-radius: 18px;
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
                overflow: hidden;
            }
            .print-header {
                padding: 22px 24px 14px;
                border-bottom: 1px solid #e5e7eb;
            }
            .print-header h1 {
                margin: 0;
                font-size: 22px;
                color: #0f172a;
            }
            .print-header p {
                margin: 8px 0 0;
                color: #64748b;
                font-size: 14px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
            }
            .print-table th,
            .print-table td {
                border: 1px solid #e5e7eb;
                padding: 12px 14px;
                text-align: right;
                vertical-align: middle;
                font-size: 13px;
            }
            .print-table th {
                background: #f8fafc;
                color: #334155;
                font-weight: 800;
            }
            .print-table tbody tr:nth-child(even) td {
                background: #fcfdff;
            }
            @media print {
                body {
                    background: #fff;
                    padding: 0;
                }
                .print-sheet {
                    box-shadow: none;
                    border: none;
                    border-radius: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="print-sheet">
            <div class="print-header">
                <h1><?= e($title) ?></h1>
                <?php if (trim($subtitle) !== ''): ?>
                    <p><?= e($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <table class="print-table">
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                            <th><?= e((string)$header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?= e((string)$cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
            window.onload = function () {
                window.print();
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}
