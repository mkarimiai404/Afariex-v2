<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/lib/topup_common.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$assert(topup_provider_result_class(1) === 'success', 'code 1 is a confirmed top-up success');
$assert(topup_provider_result_class(11) === 'failed' && topup_provider_result_class(-11) === 'failed', '11 variants are explicit failures');
$assert(topup_provider_message(11) === topup_provider_message(-11), '11 variants share a message');
$assert(topup_provider_result_class(12) === 'failed' && topup_provider_result_class(-12) === 'failed', '12 variants are explicit failures');
$assert(topup_provider_message(12) === topup_provider_message(-12), '12 variants share a message');
$assert(topup_provider_result_class(77) === 'pending' && topup_provider_result_class(-77) === 'pending', '77 variants stay pending');
$assert(topup_provider_message(77) === topup_provider_message(-77), '77 variants share a message');
$assert(topup_provider_result_class(-33) === 'failed', 'insufficient provider credit is an explicit failure');
$assert(topup_provider_result_class(-91) === 'pending' && topup_provider_result_class(-200) === 'pending', 'temporary and unknown provider outcomes stay pending');
$assert(topup_provider_result_class(9876) === 'pending', 'unrecognized provider codes stay pending');
$assert(topup_provider_result_class(topup_provider_code([])) === 'pending', 'missing provider code remains ambiguous');

$assert(topup_provider_status_class(['final_status' => 'succeeded']) === 'success', 'trans_status succeeded is accepted');
$assert(topup_provider_status_class(['payment_status' => 'paid']) === 'success', 'trans_status paid is accepted');
$assert(topup_provider_status_class(['final_status' => 'failed']) === 'failed', 'trans_status failed is accepted');
$assert(topup_provider_status_class(['final_status' => 'refund_provider']) === 'failed', 'trans_status refund_provider is accepted');
$assert(topup_provider_status_class(['payment_status' => 'refund']) === 'failed', 'trans_status refund is accepted');
$assert(topup_provider_status_class(['code' => 1]) === 'pending', 'code 1 alone does not finalize trans_status');
$assert(topup_provider_status_class(['status' => 'success']) === 'pending', 'undocumented status fields stay pending');
$assert(topup_provider_status_class(['final_status' => 'succeeded', 'payment_status' => 'refund']) === 'pending', 'contradictory trans_status fields stay pending');

echo "Top-up provider classification tests passed ({$assertions} assertions)." . PHP_EOL;
