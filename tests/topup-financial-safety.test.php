<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/lib/topup_common.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$valid = [
    'id' => 42,
    'user_id' => 7,
    'amount' => 1000,
    'status' => 'pending',
    'refund_applied' => 0,
    'status' => 'pending',
    'refund_applied' => 0,
    'topup_mobile' => '09123456789',
    'topup_operator' => 'MCI',
    'topup_charge_type' => 'normal',
    'balance_before' => '5000.00',
    'balance_after' => '3990.00',
    'balance_applied' => 1,
    'recharge_user_id' => 7,
    'mobile' => '09123456789',
    'operator' => 'MCI',
    'charge_type' => 'normal',
    'recharge_amount' => 1000,
    'total_cost' => 1010,
    'order_id' => '170000000000000000',
    'idempotency_key' => '170000000000000000',
    'client_request_id' => '8d0c8931-4e08-40eb-bfe8-14def888e32c',
    'recharge_client_request_id' => '8d0c8931-4e08-40eb-bfe8-14def888e32c',
    'recharge_status' => 'pending',
    'fee_user_id' => 7,
    'fee_source_type' => 'topup',
    'fee_service_code' => 'mobile_recharge',
    'fee_source_id' => 42,
    'fee_original_amount' => 1000,
    'fee_amount' => 10,
    'fee_total_amount' => 1010,
    'fee_status' => 'pending',
];

$evidence = topup_refund_evidence($valid);
$assert($evidence === ['valid' => true, 'amount_toman' => 1010], 'authoritative refund equals the confirmed wallet debit and fee total in Toman');
$successEvidence = $valid;
$successEvidence['status'] = 'success';
$successEvidence['recharge_status'] = 'completed';
$successEvidence['fee_status'] = 'collected';
$assert(topup_refund_evidence($successEvidence, 'success')['valid'], 'successful terminal records preserve and verify the original debit snapshot');
$refundedEvidence = $valid;
$refundedEvidence['status'] = 'failed';
$refundedEvidence['refund_applied'] = 1;
$refundedEvidence['recharge_status'] = 'refunded';
$refundedEvidence['fee_status'] = 'refunded';
$assert(topup_refund_evidence($refundedEvidence, 'refunded')['valid'], 'refunded terminal records retain auditable debit evidence');
$refundedEvidence['balance_after'] = '5000.00';
$assert(!topup_refund_evidence($refundedEvidence, 'refunded')['valid'], 'a post-refund balance snapshot cannot replace the original debit evidence');
$successEvidence = $valid;
$successEvidence['status'] = 'success';
$successEvidence['recharge_status'] = 'completed';
$successEvidence['fee_status'] = 'collected';
$assert(topup_refund_evidence($successEvidence, 'success')['valid'], 'successful terminal records preserve and verify the original debit snapshot');
$refundedEvidence = $valid;
$refundedEvidence['status'] = 'failed';
$refundedEvidence['refund_applied'] = 1;
$refundedEvidence['recharge_status'] = 'refunded';
$refundedEvidence['fee_status'] = 'refunded';
$assert(topup_refund_evidence($refundedEvidence, 'refunded')['valid'], 'refunded terminal records retain auditable debit evidence');
$refundedEvidence['balance_after'] = '5000.00';
$assert(!topup_refund_evidence($refundedEvidence, 'refunded')['valid'], 'a post-refund balance snapshot cannot replace the original debit evidence');
$assert(topup_money_minor_units('1.05') === 105 && topup_money_minor_units('-0.25') === -25, 'wallet DECIMAL values are compared exactly in hundredths');
$assert(topup_money_minor_units('1.005') === null, 'unexpected precision fails closed');

foreach ([
    ['recharge_user_id', 99, 'different recharge owner is rejected'],
    ['mobile', '09999999999', 'different destination mobile is rejected'],
    ['operator', 'MTN', 'different recharge operator is rejected'],
    ['charge_type', 'amazing', 'different charge type is rejected'],
    ['recharge_amount', 999, 'different recharge face value is rejected'],
    ['total_cost', 1009, 'recharge total that differs from the fee record is rejected'],
    ['fee_total_amount', 1011, 'fee ledger total that differs from the wallet debit is rejected'],
    ['balance_after', '3989.99', 'wallet debit delta that differs by one cent is rejected'],
    ['fee_user_id', 99, 'fee record for a different owner is rejected'],
    ['fee_source_id', 41, 'fee record for a different transaction is rejected'],
    ['fee_service_code', 'other_service', 'fee for another service is rejected'],
    ['recharge_status', 'completed', 'refund evidence is accepted only for a pending recharge'],
    ['fee_status', 'collected', 'already collected fee requires reconciliation'],
    ['balance_applied', 0, 'wallet debit marker must confirm the debit'],
    ['recharge_client_request_id', 'different-request-id', 'recharge idempotency key must match'],
] as $case) {
    [$key, $value, $message] = $case;
    $candidate = $valid;
    $candidate[$key] = $value;
    $assert(topup_refund_evidence($candidate)['valid'] === false, $message);
}

$missing = $valid;
unset($missing['balance_before']);
$assert(topup_refund_evidence($missing)['code'] === 'TOPUP_FINANCIAL_EVIDENCE_MISSING', 'missing debit evidence fails closed');
$assert(topup_status_snapshot(['status' => 'success', 'recharge_status' => 'refunded'])['reconciliation_required'], 'conflicting terminal outcomes require reconciliation');
$assert(topup_status_snapshot(['status' => 'pending', 'recharge_status' => null])['reconciliation_required'], 'missing recharge rows require reconciliation');
$assert(topup_refund_already_finalized(['refund_applied' => 1], ['status' => 'pending']), 'refund marker prevents a second credit');
$assert(topup_refund_already_finalized(['refund_applied' => 0], ['status' => 'refunded']), 'refunded recharge state prevents a second credit');
$assert(topup_refund_already_finalized(['refund_applied' => 0], ['status' => 'completed']), 'successful recharge can never be refunded');
$assert(!topup_refund_already_finalized(['refund_applied' => 0], ['status' => 'pending']), 'only unresolved unrefunded rows can reach the refund evidence gate');
$assert(topup_provider_status_class(['code' => -91]) === 'pending' && topup_provider_status_class([]) === 'pending', 'provider timeouts and ambiguous replies are never classified as definitive failures');

$originalTimeout = getenv('INAX_TIMEOUT');
putenv('INAX_TIMEOUT=180');
$assert(topup_provider_claim_lease_seconds() === 150, 'crash-recovery lease exceeds the capped provider timeout');
if ($originalTimeout === false) putenv('INAX_TIMEOUT');
else putenv('INAX_TIMEOUT=' . $originalTimeout);

echo "Top-up financial safety tests passed ({$assertions} assertions)." . PHP_EOL;
