<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/otp.php';
otp_cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') otp_json(['success'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);
$input = otp_input(); $token = trim((string)($input['api_token'] ?? $input['token'] ?? '')); $verification = trim((string)($input['verification_token'] ?? ''));
$newPin = otp_normalized_code((string)($input['new_pin'] ?? '')); $confirmation = otp_normalized_code((string)($input['new_pin_confirmation'] ?? $input['confirm_pin'] ?? ''));
if ($token === '') otp_json(['success'=>false,'code'=>'AUTHENTICATION_REQUIRED'],401);
if ($verification === '') otp_json(['success'=>false,'code'=>'PIN_OTP_REQUIRED'],422);
if (!preg_match('/^\d{4}$/',$newPin) || preg_match('/^(\d)\1{3}$/',$newPin) || in_array($newPin,['1234','4321','0123','3210'],true)) otp_json(['success'=>false,'code'=>'WEAK_PIN'],422);
if ($newPin !== $confirmation) otp_json(['success'=>false,'code'=>'PIN_MISMATCH'],422);
try {
    $pdo=db(); $pdo->beginTransaction(); $userStmt=$pdo->prepare('SELECT id FROM users WHERE api_token = ? LIMIT 1'); $userStmt->execute([$token]); $user=$userStmt->fetch();
    if(!$user){$pdo->rollBack();otp_json(['success'=>false,'code'=>'AUTHENTICATION_FAILED'],401);}
    $stmt=$pdo->prepare('SELECT id FROM phone_verification_codes WHERE user_id = ? AND purpose = ? AND verification_token_hash = ? AND verification_token_expires_at > NOW() AND consumed_at IS NULL LIMIT 1 FOR UPDATE'); $stmt->execute([(int)$user['id'],'change_pin',otp_token_hash($verification)]); $challenge=$stmt->fetch();
    if(!$challenge){$pdo->rollBack();otp_json(['success'=>false,'code'=>'INVALID_OTP'],422);}
    $hash=password_hash($newPin,PASSWORD_DEFAULT); if(!is_string($hash)||$hash==='')throw new RuntimeException('hash');
    $pdo->prepare('UPDATE users SET pin_hash = ?, pin = ? WHERE id = ?')->execute([$hash,'',(int)$user['id']]);
    $pdo->prepare('UPDATE phone_verification_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$challenge['id']]); $pdo->commit(); otp_json(['success'=>true,'code'=>'PIN_CHANGED']);
} catch(Throwable $error){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('PIN change failure code=PIN_CHANGE_UNAVAILABLE');otp_json(['success'=>false,'code'=>'PIN_CHANGE_UNAVAILABLE'],500);}
