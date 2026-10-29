<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/../admin_panel/verification_service.php';

function verification_request_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function store_verification_file(array $validated, string $directory, string $kind): string
{
    $name = $kind . '_' . bin2hex(random_bytes(24)) . '.' . $validated['extension'];
    $destination = $directory . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($validated['tmp_name'], $destination)) {
        throw new RuntimeException('STORE_FAILED');
    }
    @chmod($destination, 0600);
    return 'private_verifications/' . $name;
}

function verification_request_target(array $request): string
{
    $requested = normalize_verification_level((string)($request['requested_level'] ?? ''));
    $type = strtolower(trim((string)($request['request_type'] ?? '')));
    return in_array($type, ['silver', 'gold'], true) ? $type : $requested;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    verification_request_response(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    verification_request_response(['success' => false, 'code' => 'UPLOAD_TOO_LARGE', 'message' => 'حجم فایل‌های ارسالی بیش از حد مجاز سرور است.'], 413);
}

$token = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? ''));
$type = strtolower(trim((string)($_POST['request_type'] ?? '')));
if ($token === '') {
    verification_request_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
}
if (!in_array($type, ['silver', 'gold'], true)) {
    verification_request_response(['success' => false, 'code' => 'INVALID_REQUEST_TYPE', 'message' => 'سطح درخواستی معتبر نیست.'], 422);
}

try {
    ensure_verification_schema();
    // The API token is the only account selector. Any client-supplied user_id is ignored.
    $userId = verification_authenticated_user_id(db(), $token);
    if ($userId <= 0) {
        verification_request_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'نشست کاربری معتبر نیست.'], 401);
    }

    $latestStmt = db()->prepare('SELECT id, request_type, requested_level, status, rejection_reason, admin_note, created_at, reviewed_at FROM verification_upgrade_requests WHERE user_id = ? AND (request_type = ? OR requested_level = ?) ORDER BY id DESC LIMIT 1');
    $latestStmt->execute([$userId, $type, $type]);
    $latest = $latestStmt->fetch() ?: null;

    if (trim((string)($_POST['action'] ?? 'submit')) === 'status') {
        verification_request_response(['success' => true, 'data' => $latest ? [
            'id' => (int)$latest['id'],
            'request_type' => verification_request_target($latest),
            'status' => $latest['status'],
            'rejection_reason' => $latest['rejection_reason'] ?? ($latest['admin_note'] ?? null),
            'admin_note' => $latest['admin_note'] ?? null,
            'created_at' => $latest['created_at'] ?? null,
            'reviewed_at' => $latest['reviewed_at'] ?? null,
        ] : ['request_type' => $type, 'status' => null]]);
    }

    $state = verification_state($userId);
    $rank = ['bronze' => 0, 'silver' => 1, 'gold' => 2];
    if (($rank[$state['level']] ?? 0) >= $rank[$type]) {
        verification_request_response(['success' => true, 'code' => 'LEVEL_ALREADY_APPROVED', 'message' => 'این سطح قبلاً برای شما تأیید شده است.', 'data' => ['status' => 'approved', 'request_type' => $type]]);
    }
    if ($type === 'silver' && !$state['bronze_eligible']) {
        verification_request_response(['success' => false, 'code' => 'BRONZE_VERIFICATION_REQUIRED', 'message' => 'ابتدا شماره موبایل یا ایمیل خود را تأیید کنید.'], 422);
    }
    if ($type === 'gold' && $state['level'] !== 'silver') {
        verification_request_response(['success' => false, 'code' => 'SILVER_VERIFICATION_REQUIRED', 'message' => 'برای درخواست سطح طلایی ابتدا باید سطح نقره‌ای را دریافت کنید.'], 422);
    }

    $validated = [];
    try {
        if ($type === 'silver') {
            $validated['identity_document'] = verification_validate_uploaded_file($_FILES['identity_document'] ?? [], 'identity_document');
            $validated['selfie'] = verification_validate_uploaded_file($_FILES['selfie'] ?? [], 'selfie');
        } else {
            $validated['video'] = verification_validate_uploaded_file($_FILES['video'] ?? [], 'video');
        }
    } catch (InvalidArgumentException $error) {
        $field = $type === 'gold' ? 'ویدیوی احراز هویت' : 'تصاویر مدرک هویتی و سلفی';
        verification_request_response(['success' => false, 'code' => 'INVALID_VERIFICATION_EVIDENCE', 'message' => $field . ' معتبر نیست یا حجم آن بیش از حد مجاز است.'], 422);
    }

    db()->beginTransaction();
    $paths = [];
    try {
        $lock = db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $active = db()->prepare("SELECT id FROM verification_upgrade_requests WHERE user_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
        $active->execute([$userId]);
        if ($active->fetchColumn()) {
            db()->rollBack();
            verification_request_response(['success' => false, 'code' => 'VERIFICATION_REQUEST_PENDING', 'message' => 'یک درخواست احراز هویت شما در حال بررسی است.'], 409);
        }

        $directory = __DIR__ . '/../admin_panel/private_verifications';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('DIRECTORY_FAILED');
        }
        @chmod($directory, 0700);
        foreach ($validated as $kind => $file) {
            $paths[$kind] = store_verification_file($file, $directory, $kind);
        }

        $insert = db()->prepare("INSERT INTO verification_upgrade_requests (user_id, request_type, requested_level, status, identity_document_path, selfie_path, video_path, created_at) VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())");
        $insert->execute([$userId, $type, $type, $paths['identity_document'] ?? null, $paths['selfie'] ?? null, $paths['video'] ?? null]);
        $requestId = (int)db()->lastInsertId();
        db()->commit();
        error_log('[KYC_DEBUG] saved request id=' . $requestId . ' user_id=' . $userId
            . ' identity_document=' . (isset($paths['identity_document']) ? 'true' : 'false')
            . ' selfie=' . (isset($paths['selfie']) ? 'true' : 'false')
            . ' video=' . (isset($paths['video']) ? 'true' : 'false'));
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($paths as $path) {
            $file = realpath(__DIR__ . '/../admin_panel/' . $path);
            $base = realpath(__DIR__ . '/../admin_panel/private_verifications');
            if ($file !== false && $base !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) @unlink($file);
        }
        throw $error;
    }

    verification_request_response(['success' => true, 'code' => 'VERIFICATION_SUBMITTED', 'message' => 'درخواست احراز هویت با موفقیت ارسال شد.', 'data' => ['id' => $requestId, 'status' => 'pending', 'request_type' => $type]]);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Verification request failed [' . get_class($error) . ']');
    verification_request_response(['success' => false, 'code' => 'VERIFICATION_REQUEST_FAILED', 'message' => 'ارسال درخواست احراز هویت انجام نشد.'], 500);
}
