<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/config.php';

// لود کردن فایل‌های کمکی در صورت وجود
if (file_exists(__DIR__ . '/includes/phone.php')) {
    require_once __DIR__ . '/includes/phone.php';
}
if (file_exists(__DIR__ . '/includes/subcustomer.php')) {
    require_once __DIR__ . '/includes/subcustomer.php';
}

header('Content-Type: application/json; charset=utf-8');

function subcustomer_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    subcustomer_json(['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'متد ارسالی باید POST باشد.'], 405);
}

// دریافت توکن و فیلدها از POST یا Header
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$bearerToken = '';
if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $bearerToken = trim($matches[1]);
}

$token = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? $_POST['user_token'] ?? $bearerToken ?? ''));
$fullName = trim((string)($_POST['full_name'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$mobileInput = trim((string)($_POST['mobile'] ?? $_POST['phone'] ?? ''));
$password = (string)($_POST['password'] ?? '');

// نرمال‌سازی شماره موبایل
$mobile = preg_replace('/\D+/', '', $mobileInput);
if (str_starts_with($mobile, '98') && strlen($mobile) === 12) {
    $mobile = '0' . substr($mobile, 2);
}

// بررسی فیلدهای اجباری
if ($token === '') {
    subcustomer_json(['success' => false, 'code' => 'NO_TOKEN', 'message' => 'توکن لاگین معتبر نیست. لطفاً مجدداً وارد شوید.'], 401);
}
if ($fullName === '') {
    subcustomer_json(['success' => false, 'code' => 'NO_NAME', 'message' => 'نام و نام خانوادگی را وارد کنید.'], 422);
}
if (strlen($username) < 3) {
    subcustomer_json(['success' => false, 'code' => 'BAD_USERNAME', 'message' => 'نام کاربری باید حداقل ۳ کاراکتر باشد.'], 422);
}
if (strlen($mobile) < 10) {
    subcustomer_json(['success' => false, 'code' => 'BAD_MOBILE', 'message' => 'شماره موبایل نامعتبر است.'], 422);
}
if (strlen($password) < 6) {
    subcustomer_json(['success' => false, 'code' => 'WEAK_PASSWORD', 'message' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.'], 422);
}

try {
    $pdo = db();

    // استخراج لیست ستون‌های جدول users برای حفظ سازگاری دیتابیس
    $colsStmt = $pdo->query("SHOW COLUMNS FROM users");
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // بررسی دسترسی والد
    $canSubExpr = in_array('can_create_subuser', $columns, true) ? 'can_create_subuser' : '0';
    $parentStmt = $pdo->prepare("SELECT id, role, {$canSubExpr} AS can_sub FROM users WHERE api_token = ? LIMIT 1");
    $parentStmt->execute([$token]);
    $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$parent) {
        subcustomer_json([
            'success' => false,
            'code' => 'PARENT_NOT_FOUND',
            'message' => 'نشست کاربری نامعتبر است. لطفاً خارج شده و مجدداً وارد شوید.'
        ], 401);
    }

    if ((int)($parent['can_sub'] ?? 0) !== 1) {
        subcustomer_json([
            'success' => false,
            'code' => 'NO_PERMISSION',
            'message' => 'اجازه ایجاد مشتری زیرمجموعه برای این حساب فعال نیست.'
        ], 403);
    }

    // بررسی عدم تکراری بودن نام کاربری و شماره موبایل
    $checkStmt = $pdo->prepare("SELECT id, username, mobile FROM users WHERE username = ? OR mobile = ? LIMIT 1");
    $checkStmt->execute([$username, $mobile]);
    $dup = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
        $which = ($dup['username'] === $username) ? 'نام کاربری' : 'شماره موبایل';
        subcustomer_json([
            'success' => false,
            'code' => 'DUPLICATE',
            'message' => "این {$which} قبلاً ثبت شده است."
        ], 409);
    }

    // تفکیک نام و نام خانوادگی
    $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $firstName = $parts[0] ?? '';
    $lastName = implode(' ', array_slice($parts, 1));

    $insertData = [
        'full_name'  => $fullName,
        'mobile'     => $mobile,
        'username'   => $username,
        'password'   => password_hash($password, PASSWORD_DEFAULT),
        'role'       => 'subcustomer',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if (in_array('parent_id', $columns, true)) {
        $insertData['parent_id'] = (int)$parent['id'];
    }
    if (in_array('referred_by_user_id', $columns, true)) {
        $insertData['referred_by_user_id'] = (int)$parent['id'];
    }
    if (in_array('first_name', $columns, true)) {
        $insertData['first_name'] = $firstName;
    }
    if (in_array('last_name', $columns, true)) {
        $insertData['last_name'] = $lastName;
    }
    if (in_array('api_token', $columns, true)) {
        $insertData['api_token'] = bin2hex(random_bytes(32));
    }
    if (in_array('referral_code', $columns, true)) {
        $insertData['referral_code'] = strtoupper(bin2hex(random_bytes(4)));
    }

    // اجرای کوئری درج کاربر
    $fieldNames = array_keys($insertData);
    $placeholders = implode(', ', array_fill(0, count($fieldNames), '?'));
    $sql = "INSERT INTO users (" . implode(', ', $fieldNames) . ") VALUES (" . $placeholders . ")";
    
    $insStmt = $pdo->prepare($sql);
    $insStmt->execute(array_values($insertData));
    $newId = (int)$pdo->lastInsertId();

    // ایجاد وضعیت احراز هویت اولیه در صورت وجود جدول
    try {
        $verification = $pdo->prepare(
            "INSERT INTO user_verification_levels (user_id, level, phone_verified, updated_at)
             VALUES (?, 'initial', 0, NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)"
        );
        $verification->execute([$newId]);
    } catch (Throwable $ignored) {
        // عدم توقف عملیات در صورت عدم وجود این جدول اختیاری
    }

    subcustomer_json([
        'success' => true,
        'code' => 'SUBCUSTOMER_CREATED',
        'message' => 'مشتری با موفقیت ایجاد شد.',
        'data' => [
            'id' => $newId,
            'username' => $username,
            'mobile' => $mobile,
        ]
    ]);

} catch (Throwable $e) {
    // ثبت خطای داخلی در لاگ سرور به جای افشای جزئیات دیتابیس به کلاینت
    error_log('Subcustomer creation failed: ' . $e->getMessage());
    subcustomer_json([
        'success' => false,
        'code' => 'SERVER_ERROR',
        'message' => 'ایجاد مشتری زیرمجموعه انجام نشد. لطفاً با پشتیبانی تماس بگیرید.'
    ], 500);
}
