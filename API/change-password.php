<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/otp.php';
otp_cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') otp_json(['success'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);
$input = otp_input();
$token = trim((string)($input['api_token'] ?? $input['token'] ?? ''));
$verification = trim((string)($input['verification_token'] ?? ''));
$newPassword = (string)($input['new_password'] ?? '');
$confirmation = (string)($input['new_password_confirmation'] ?? $input['confirm_password'] ?? '');
if ($token === '') otp_json(['success'=>false,'code'=>'AUTHENTICATION_REQUIRED'],401);
if ($verification === '') otp_json(['success'=>false,'code'=>'PASSWORD_OTP_REQUIRED','message'=>'ابتدا کد پیامکی را تأیید کنید.'],422);
if (strlen($newPassword) < 8 || !preg_match('/[a-z]/',$newPassword) || !preg_match('/[A-Z]/',$newPassword) || !preg_match('/\d/',$newPassword) || in_array(strtolower($newPassword),['123456','12345678','password','password1','qwerty123','11111111'],true)) otp_json(['success'=>false,'code'=>'WEAK_PASSWORD'],422);
if ($newPassword !== $confirmation) otp_json(['success'=>false,'code'=>'PASSWORD_MISMATCH'],422);
try {
    $pdo = db(); $pdo->beginTransaction();
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1'); $userStmt->execute([$token]); $user = $userStmt->fetch();
    if (!$user) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'AUTHENTICATION_FAILED'],401); }
    $stmt = $pdo->prepare('SELECT id FROM phone_verification_codes WHERE user_id = ? AND purpose = ? AND verification_token_hash = ? AND verification_token_expires_at > NOW() AND consumed_at IS NULL LIMIT 1 FOR UPDATE');
    $stmt->execute([(int)$user['id'],'change_password',otp_token_hash($verification)]); $challenge = $stmt->fetch();
    if (!$challenge) { $pdo->rollBack(); otp_json(['success'=>false,'code'=>'INVALID_OTP'],422); }
    $hash = password_hash($newPassword,PASSWORD_DEFAULT); if (!is_string($hash) || $hash==='') throw new RuntimeException('hash');
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash,(int)$user['id']]);
    $pdo->prepare('UPDATE phone_verification_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$challenge['id']]);
    $pdo->commit(); otp_json(['success'=>true,'code'=>'PASSWORD_CHANGED']);
} catch (Throwable $error) { if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack(); error_log('Password change failure code=PASSWORD_CHANGE_UNAVAILABLE'); otp_json(['success'=>false,'code'=>'PASSWORD_CHANGE_UNAVAILABLE'],500); }
