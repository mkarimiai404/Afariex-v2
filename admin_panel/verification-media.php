<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/verification_service.php';

require_login();
require_permission('view');
ensure_verification_schema();

$requestId = (int)($_GET['request_id'] ?? 0);
$field = trim((string)($_GET['field'] ?? ''));
$download = (string)($_GET['download'] ?? '') === '1';
$allowed = ['identity_document_path', 'selfie_path', 'video_path'];
if ($requestId <= 0 || !in_array($field, $allowed, true)) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare("SELECT {$field} AS media_path FROM verification_upgrade_requests WHERE id = ? LIMIT 1");
$stmt->execute([$requestId]);
$path = trim((string)$stmt->fetchColumn());
$file = verification_secure_media_path(__DIR__ . '/private_verifications', $path);
if ($file === null) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('X-Content-Type-Options: nosniff');
$extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
$safeExtension = preg_replace('/[^a-z0-9]/', '', $extension) ?: '';
$downloadName = 'verification-evidence' . ($safeExtension !== '' ? '.' . $safeExtension : '');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($file);
