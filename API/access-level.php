<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../admin_panel/config.php';

function access_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int)($_POST['user_id'] ?? $_POST['id'] ?? 0);
$token = trim((string)($_POST['api_token'] ?? $_POST['token'] ?? $_POST['user_token'] ?? ''));
if ($userId <= 0 || $token === '') {
    access_response(['success' => false, 'code' => 'AUTHENTICATION_REQUIRED', 'message' => 'احراز هویت کاربر انجام نشد.'], 401);
}

try {
    ensure_verification_schema();
    $userStmt = db()->prepare('SELECT id, mobile FROM users WHERE id = ? AND api_token = ? LIMIT 1');
    $userStmt->execute([$userId, $token]);
    $user = $userStmt->fetch();
    if (!$user) access_response(['success' => false, 'code' => 'AUTHENTICATION_FAILED', 'message' => 'اطلاعات احراز هویت معتبر نیست.'], 401);

    $state = verification_state($userId);
    $summary = daily_transaction_summary($userId, $state);
    $pendingStmt = db()->prepare("SELECT id, request_type, status, admin_note, rejection_reason, created_at, reviewed_at FROM verification_upgrade_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $pendingStmt->execute([$userId]);
    $request = $pendingStmt->fetch() ?: null;
    $action = trim((string)($_POST['action'] ?? 'status'));

    if ($action === 'request_upgrade') {
        if (!$state['bronze_eligible']) {
            access_response(['success' => false, 'code' => 'BRONZE_VERIFICATION_REQUIRED', 'message' => 'ابتدا شماره موبایل یا ایمیل خود را تأیید کنید.'], 422);
        }
        if ($state['level'] !== 'bronze') {
            access_response(['success' => true, 'message' => 'سطح کاربری شما قبلاً ارتقاء یافته است.', 'data' => ['status' => 'approved']]);
        }
        db()->beginTransaction();
        $lock = db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $active = db()->prepare("SELECT id FROM verification_upgrade_requests WHERE user_id = ? AND request_type = 'silver' AND status = 'pending' LIMIT 1 FOR UPDATE");
        $active->execute([$userId]);
        if ($active->fetch()) {
            db()->rollBack();
            access_response(['success' => false, 'code' => 'UPGRADE_REQUEST_PENDING', 'message' => 'درخواست ارتقاء شما در حال بررسی است.'], 409);
        }
        $insert = db()->prepare("INSERT INTO verification_upgrade_requests (user_id, request_type, requested_level, status, created_at) VALUES (?, 'silver', 'silver', 'pending', NOW())");
        $insert->execute([$userId]);
        db()->commit();
        access_response(['success' => true, 'message' => 'درخواست ارتقاء ثبت شد.', 'data' => ['status' => 'pending', 'request_type' => 'silver']]);
    }

    $mobile = (string)$user['mobile'];
    $masked = strlen($mobile) > 4 ? substr($mobile, 0, 3) . str_repeat('*', max(0, strlen($mobile) - 5)) . substr($mobile, -2) : '****';
    access_response([
        'success' => true,
        'data' => array_merge($state, $summary, [
            'mobile' => $masked,
            'verification_status' => $state['bronze_eligible'] ? 'verified' : 'unverified',
            'upgrade_request_status' => $request['status'] ?? null,
            'upgrade_request_type' => $request['request_type'] ?? null,
            'rejection_reason' => $request['rejection_reason'] ?? ($request['admin_note'] ?? null),
        ]),
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Access level request failed: ' . $e->getMessage());
    access_response(['success' => false, 'code' => 'ACCESS_LEVEL_FAILED', 'message' => 'دریافت اطلاعات سطح کاربری انجام نشد.'], 500);
}
