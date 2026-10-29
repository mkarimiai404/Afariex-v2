<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/includes/sms_provider.php';

// Synthetic in-memory fixtures only; this script never loads credentials or opens a network connection.
$otp = '123456';
$request = sms_melipayamak_pattern_request('+989121234567', $otp, 509216);

$assertions = [
    $request['text'] === [$otp] && sms_pattern_otp_is_ascii($request['text'][0]),
    $request['phone'] === '09121234567',
    $request['body_id'] === 509216,
    count($request['text']) === 1,
    !array_key_exists('sender', $request),
];

echo 'payload_text_type=' . get_debug_type($request['text']) . PHP_EOL;
echo 'otp_array_ascii=' . ($assertions[0] ? 'yes' : 'no') . PHP_EOL;
echo 'recipient_single_normalized=' . ($assertions[1] ? 'yes' : 'no') . PHP_EOL;
echo 'body_id_509216=' . ($assertions[2] ? 'yes' : 'no') . PHP_EOL;
echo 'sender_absent=' . ($assertions[4] ? 'yes' : 'no') . PHP_EOL;
exit(in_array(false, $assertions, true) ? 1 : 0);
