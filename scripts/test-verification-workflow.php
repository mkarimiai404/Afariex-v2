<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/verification_service.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};
$expectException = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'afariex-verification-' . bin2hex(random_bytes(8));
$mediaDirectory = $fixtureRoot . DIRECTORY_SEPARATOR . 'private_verifications';
if (!mkdir($mediaDirectory, 0700, true) && !is_dir($mediaDirectory)) throw new RuntimeException('Could not create fixture directory.');

try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, api_token TEXT UNIQUE NOT NULL)');
    $pdo->exec('CREATE TABLE user_verification_levels (user_id INTEGER PRIMARY KEY, level TEXT NOT NULL, phone_verified INTEGER NOT NULL DEFAULT 0, phone_verified_at TEXT NULL, withdrawal_limit NUMERIC NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE verification_upgrade_requests (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, request_type TEXT NOT NULL, requested_level TEXT NOT NULL, status TEXT NOT NULL, identity_document_path TEXT NULL, selfie_path TEXT NULL, video_path TEXT NULL, admin_id INTEGER NULL, admin_note TEXT NULL, rejection_reason TEXT NULL, created_at TEXT NOT NULL, reviewed_at TEXT NULL)');
    $pdo->exec("INSERT INTO users (id, api_token) VALUES (1, 'owner-token'), (2, 'other-token')");
    $pdo->exec("INSERT INTO user_verification_levels (user_id, level, phone_verified, phone_verified_at, updated_at) VALUES (1, 'bronze', 1, '2026-08-01 12:00:00', '2026-08-01 12:00:00'), (2, 'bronze', 1, '2026-08-01 12:00:00', '2026-08-01 12:00:00')");

    $assert(verification_authenticated_user_id($pdo, 'owner-token') === 1, 'token resolves its owning account');
    $assert(verification_authenticated_user_id($pdo, 'other-token') === 2, 'a different token cannot select the first account');
    $assert(verification_authenticated_user_id($pdo, 'missing-token') === 0, 'invalid token is rejected');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if ($png === false) throw new RuntimeException('Invalid embedded fixture.');
    $identityFile = $mediaDirectory . DIRECTORY_SEPARATOR . 'identity.png';
    $selfieFile = $mediaDirectory . DIRECTORY_SEPARATOR . 'selfie.png';
    file_put_contents($identityFile, $png);
    file_put_contents($selfieFile, $png);
    $validated = verification_validate_uploaded_file(['tmp_name' => $identityFile, 'size' => strlen($png), 'error' => UPLOAD_ERR_OK], 'identity_document', false);
    $assert($validated['extension'] === 'png', 'real image content is accepted using server MIME detection');
    $spoof = $mediaDirectory . DIRECTORY_SEPARATOR . 'spoof.jpg';
    file_put_contents($spoof, 'not an image');
    $expectException(fn() => verification_validate_uploaded_file(['tmp_name' => $spoof, 'size' => filesize($spoof), 'error' => UPLOAD_ERR_OK], 'identity_document', false), 'extension-only upload spoof is rejected');
    $oversized = $mediaDirectory . DIRECTORY_SEPARATOR . 'oversized.png';
    $oversizedHandle = fopen($oversized, 'wb');
    if ($oversizedHandle === false || !ftruncate($oversizedHandle, VERIFICATION_IMAGE_MAX_BYTES + 1)) throw new RuntimeException('Could not create oversized fixture.');
    fclose($oversizedHandle);
    $expectException(fn() => verification_validate_uploaded_file(['tmp_name' => $oversized, 'size' => 1, 'error' => UPLOAD_ERR_OK], 'identity_document', false), 'actual file size is enforced even when declared size is misleading');
    $assert(verification_secure_media_path($mediaDirectory, 'private_verifications/identity.png') === $identityFile, 'stored evidence resolves inside the private directory');
    $assert(verification_secure_media_path($mediaDirectory, '../verification_service.php') === null, 'path traversal cannot escape private storage');
    $assert(trim((string)file_get_contents(__DIR__ . '/../admin_panel/private_verifications/.htaccess')) === 'Require all denied', 'direct HTTP access is denied by the storage guard');

    $insert = $pdo->prepare("INSERT INTO verification_upgrade_requests (id, user_id, request_type, requested_level, status, identity_document_path, selfie_path, created_at) VALUES (1, 1, 'silver', 'silver', 'pending', 'private_verifications/identity.png', 'private_verifications/selfie.png', '2026-08-09 12:00:00')");
    $insert->execute();
    $result = review_verification_request($pdo, 1, 'approved', 'fixture approval', 90, $mediaDirectory);
    $assert($result['user_id'] === 1 && $result['target_level'] === 'silver', 'admin approval targets the request owner and requested level');
    $assert($pdo->query('SELECT level FROM user_verification_levels WHERE user_id = 1')->fetchColumn() === 'silver', 'approval promotes the owner to silver');
    $assert($pdo->query('SELECT level FROM user_verification_levels WHERE user_id = 2')->fetchColumn() === 'bronze', 'approval does not alter another user');
    $assert($pdo->query('SELECT status FROM verification_upgrade_requests WHERE id = 1')->fetchColumn() === 'approved', 'approved status is persisted');
    $expectException(fn() => review_verification_request($pdo, 1, 'rejected', 'late change', 90, $mediaDirectory), 'a reviewed request cannot be decided twice');

    $pdo->exec("INSERT INTO verification_upgrade_requests (id, user_id, request_type, requested_level, status, identity_document_path, selfie_path, created_at) VALUES (2, 2, 'silver', 'silver', 'pending', 'private_verifications/identity.png', 'private_verifications/selfie.png', '2026-08-09 12:05:00')");
    $expectException(fn() => review_verification_request($pdo, 2, 'rejected', '', 90, $mediaDirectory), 'rejection requires an auditable reason');
    $assert($pdo->query('SELECT status FROM verification_upgrade_requests WHERE id = 2')->fetchColumn() === 'pending', 'failed rejection leaves the fixture unchanged');
    review_verification_request($pdo, 2, 'rejected', 'document unreadable', 90, $mediaDirectory);
    $rejected = $pdo->query('SELECT status, rejection_reason, admin_id FROM verification_upgrade_requests WHERE id = 2')->fetch();
    $assert($rejected['status'] === 'rejected' && $rejected['rejection_reason'] === 'document unreadable' && (int)$rejected['admin_id'] === 90, 'rejection records reason and reviewer');
    $assert($pdo->query('SELECT level FROM user_verification_levels WHERE user_id = 2')->fetchColumn() === 'bronze', 'rejection does not promote the user');

    $videoFile = $mediaDirectory . DIRECTORY_SEPARATOR . 'identity.mp4';
    file_put_contents($videoFile, 'isolated-video-fixture');
    $pdo->exec("INSERT INTO verification_upgrade_requests (id, user_id, request_type, requested_level, status, video_path, created_at) VALUES (3, 1, 'gold', 'gold', 'pending', 'private_verifications/identity.mp4', '2026-08-09 12:10:00')");
    review_verification_request($pdo, 3, 'approved', 'gold fixture approval', 90, $mediaDirectory);
    $assert($pdo->query('SELECT level FROM user_verification_levels WHERE user_id = 1')->fetchColumn() === 'gold', 'gold approval follows silver and promotes exactly one level');

    $pdo->exec("INSERT INTO verification_upgrade_requests (id, user_id, request_type, requested_level, status, video_path, created_at) VALUES (4, 2, 'gold', 'gold', 'pending', 'private_verifications/identity.mp4', '2026-08-09 12:15:00')");
    $expectException(fn() => review_verification_request($pdo, 4, 'approved', 'invalid jump', 90, $mediaDirectory), 'bronze user cannot skip directly to gold');
    $assert($pdo->query('SELECT status FROM verification_upgrade_requests WHERE id = 4')->fetchColumn() === 'pending', 'invalid level jump rolls back the request decision');

    echo "Verification workflow fixtures passed ({$assertions} assertions)." . PHP_EOL;
} finally {
    if (is_dir($mediaDirectory)) {
        foreach (scandir($mediaDirectory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') @unlink($mediaDirectory . DIRECTORY_SEPARATOR . $name);
        }
        @rmdir($mediaDirectory);
    }
    @rmdir($fixtureRoot);
}
