<?php
declare(strict_types=1);

const VERIFICATION_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const VERIFICATION_VIDEO_MAX_BYTES = 35 * 1024 * 1024;

function verification_authenticated_user_id(PDO $pdo, string $token): int
{
    if ($token === '') return 0;
    $statement = $pdo->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1');
    $statement->execute([$token]);
    return (int)$statement->fetchColumn();
}

function verification_validate_uploaded_file(array $file, string $kind, bool $requireHttpUpload = true): array
{
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK || $temporaryPath === '' || !is_file($temporaryPath)
        || ($requireHttpUpload && !is_uploaded_file($temporaryPath))) {
        throw new InvalidArgumentException('UPLOAD_MISSING');
    }

    $actualSize = filesize($temporaryPath);
    $size = $actualSize === false ? 0 : $actualSize;
    $isVideo = $kind === 'video';
    $maximum = $isVideo ? VERIFICATION_VIDEO_MAX_BYTES : VERIFICATION_IMAGE_MAX_BYTES;
    if ($size <= 0 || $size > $maximum) throw new InvalidArgumentException('UPLOAD_SIZE');

    $allowed = $isVideo
        ? ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm']
        : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!is_string($mime) || !isset($allowed[$mime])) throw new InvalidArgumentException('UPLOAD_TYPE');
    if (!$isVideo && @getimagesize($temporaryPath) === false) throw new InvalidArgumentException('UPLOAD_IMAGE');

    return ['tmp_name' => $temporaryPath, 'extension' => $allowed[$mime], 'mime' => $mime];
}

function verification_secure_media_path(string $baseDirectory, ?string $relativePath): ?string
{
    $base = realpath($baseDirectory);
    if ($base === false || !$relativePath) return null;
    $file = realpath(dirname($baseDirectory) . DIRECTORY_SEPARATOR . $relativePath);
    return $file !== false
        && str_starts_with($file, $base . DIRECTORY_SEPARATOR)
        && is_file($file) ? $file : null;
}

function review_verification_request(PDO $pdo, int $requestId, string $decision, string $note, int $adminId, string $mediaDirectory, ?string $finalLevel = null): array
{
    $note = trim($note);
    if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected', 'needs_more_info'], true) || $adminId <= 0) {
        throw new InvalidArgumentException('درخواست بررسی نامعتبر است.');
    }
    if (in_array($decision, ['rejected', 'needs_more_info'], true) && $note === '') {
        throw new InvalidArgumentException('ثبت دلیل رد درخواست الزامی است.');
    }

    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $timestamp = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM verification_upgrade_requests WHERE id = ?' . $lock);
        $statement->execute([$requestId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array(($request['status'] ?? ''), ['pending', 'needs_more_info'], true)) {
            throw new RuntimeException('این درخواست قبلاً بررسی شده یا وجود ندارد.');
        }

        $userId = (int)$request['user_id'];
        $userStatement = $pdo->prepare('SELECT id FROM users WHERE id = ?' . $lock);
        $userStatement->execute([$userId]);
        if (!$userStatement->fetchColumn()) throw new RuntimeException('کاربر درخواست پیدا نشد.');

        $target = strtolower(trim((string)($request['request_type'] ?? $request['requested_level'] ?? '')));
        if (!in_array($target, ['silver', 'gold'], true)) throw new RuntimeException('سطح درخواستی معتبر نیست.');

        if ($finalLevel !== null && $finalLevel !== '') {
            $target = strtolower(trim($finalLevel));
        }
        if (!in_array($target, ['bronze', 'silver', 'gold'], true)) throw new RuntimeException('Invalid verification approval level.');

        if ($decision === 'approved') {
            $levelStatement = $pdo->prepare('SELECT level, phone_verified, phone_verified_at FROM user_verification_levels WHERE user_id = ?' . $lock);
            $levelStatement->execute([$userId]);
            $levelRow = $levelStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $current = match (strtolower(trim((string)($levelRow['level'] ?? 'bronze')))) {
                'gold' => 'gold',
                'silver', 'verified' => 'silver',
                default => 'bronze',
            };
            $requiredFields = $target === 'gold'
                ? ['video_path']
                : ($target === 'silver' ? ['identity_document_path', 'selfie_path'] : []);
            foreach ($requiredFields as $field) {
                if (verification_secure_media_path($mediaDirectory, $request[$field] ?? null) === null) {
                    throw new RuntimeException('فایل‌های لازم احراز هویت در فضای امن موجود نیست.');
                }
            }

            if ($levelRow) {
                $updateLevel = $pdo->prepare('UPDATE user_verification_levels SET level = ?, withdrawal_limit = NULL, updated_at = ? WHERE user_id = ?');
                $updateLevel->execute([$target, $timestamp, $userId]);
            } else {
                $insertLevel = $pdo->prepare('INSERT INTO user_verification_levels (user_id, level, phone_verified, phone_verified_at, withdrawal_limit, updated_at) VALUES (?, ?, 0, NULL, NULL, ?)');
                $insertLevel->execute([$userId, $target, $timestamp]);
            }
        }

        $update = $pdo->prepare("UPDATE verification_upgrade_requests SET status = ?, admin_id = ?, admin_note = ?, rejection_reason = ?, reviewed_at = ? WHERE id = ? AND status IN ('pending', 'needs_more_info')");
        $update->execute([$decision, $adminId, $note !== '' ? $note : null, $decision === 'rejected' ? $note : null, $timestamp, $requestId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('وضعیت درخواست هم‌زمان تغییر کرده است.');
        $pdo->commit();
        return ['request_id' => $requestId, 'user_id' => $userId, 'status' => $decision, 'target_level' => $target];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
