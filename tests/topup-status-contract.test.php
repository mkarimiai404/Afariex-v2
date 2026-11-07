<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/lib/topup_common.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$assert(
    topup_status_snapshot(['status' => 'pending', 'recharge_status' => 'pending']) === ['status' => 'pending', 'reconciliation_required' => false],
    'pending records remain pending'
);
$assert(
    topup_status_snapshot(['status' => 'success', 'recharge_status' => 'completed']) === ['status' => 'success', 'reconciliation_required' => false],
    'matching completed records are successful'
);
$assert(
    topup_status_snapshot(['status' => 'failed', 'recharge_status' => 'refunded']) === ['status' => 'failed', 'reconciliation_required' => false],
    'a failed transaction is final only with a recorded refund'
);
$assert(
    topup_status_snapshot(['status' => 'failed', 'recharge_status' => 'failed'])['reconciliation_required'],
    'failed without a refund marker requires reconciliation'
);
$assert(
    topup_status_snapshot(['status' => 'success', 'recharge_status' => 'refunded'])['reconciliation_required'],
    'contradictory terminal records require reconciliation'
);
$assert(
    topup_status_snapshot(['status' => 'pending', 'recharge_status' => null])['reconciliation_required'],
    'transaction without a recharge record requires reconciliation'
);

$endpoint = file_get_contents(__DIR__ . '/../API/topup-status.php');
$client = file_get_contents(__DIR__ . '/../app/topup.tsx');
$create = file_get_contents(__DIR__ . '/../API/topup.php');
$migration = file_get_contents(__DIR__ . '/../database/migrations/019_topup_client_request_id.php');
$claimMigration = file_get_contents(__DIR__ . '/../database/migrations/021_topup_provider_claims.php');
$assert(is_string($endpoint) && is_string($client) && is_string($create) && is_string($migration) && is_string($claimMigration), 'top-up source files are readable');

$assert(str_contains($endpoint, "t.id = ?") && str_contains($endpoint, "(r.trans_id = ? OR r.ref_code = ?)") && str_contains($endpoint, "t.client_request_id = ?"), 'status lookup falls back through transaction, provider reference, and client request IDs');
$assert(str_contains($endpoint, "t.user_id = ? AND t.type = 'topup'") && str_contains($endpoint, 'FOR UPDATE'), 'status lookups are owner-scoped and row-locked');
$assert(str_contains($endpoint, 'TOPUP_TRANSACTION_NOT_VISIBLE_YET') && str_contains($endpoint, "'retryable' => true") && str_contains($endpoint, "'status' => 'pending'"), 'not-found responses remain retryable and financially pending');
$assert(str_contains($endpoint, 'TOPUP_RECHARGE_RECORD_MISSING') && str_contains($endpoint, 'topup_status_snapshot'), 'missing recharge records are routed to reconciliation');
$providerCallPosition = strpos($endpoint, '(new InaxClient())->transStatus');
$reconciliationCheck = strpos($endpoint, 'topup_status_snapshot($transaction)');
$evidenceCheck = strpos($endpoint, 'topup_refund_evidence($transaction,');
$assert($reconciliationCheck !== false && $evidenceCheck !== false && $providerCallPosition !== false && $reconciliationCheck < $providerCallPosition && $evidenceCheck < $providerCallPosition, 'missing or conflicting recharge/debit records stop before provider access');
$terminalCheckPosition = strpos($endpoint, 'if ($snapshot[\'status\'] !== \'pending\')');
$assert($terminalCheckPosition !== false && $providerCallPosition !== false && $terminalCheckPosition < $providerCallPosition, 'consistent terminal records are answered before contacting the provider');
$assert(str_contains($create, 'topup_find_client_request') && str_contains($create, 'wallet_debit('), 'create flow checks idempotency before debit');
$assert(str_contains($migration, 'uq_topup_client_request') && str_contains($migration, 'uq_recharge_client_request'), 'client request identifiers have database uniqueness constraints');
$assert(str_contains($create, '$pdo->beginTransaction();') && str_contains($create, 'wallet_debit('), 'wallet debit and idempotency reservation share a transaction');
$common = file_get_contents(__DIR__ . '/../API/lib/topup_common.php');
$refundBody = substr($common, strpos($common, 'function topup_refund_transaction('));
$assert(str_contains($refundBody, 'FOR UPDATE') && str_contains($refundBody, 'topup_refund_already_finalized') && str_contains($common, "'refunded', 'completed', 'failed'"), 'refund helper locks and checks durable refund/terminal markers');
$assert(!str_contains($refundBody, "\$updates['balance_after'] = \$credit['balance_after']"), 'refund does not destroy the original debit balance snapshots');
$assert(str_contains($endpoint, '$finalSnapshot[\'status\']') && str_contains($endpoint, 'topup_refund_transaction('), 'status response is built from persisted state after provider reconciliation');
$assert(str_contains($endpoint, 'catch (InaxNetworkException $error)') && str_contains($endpoint, "'retryable' => true"), 'provider timeouts and network failures remain non-final and retryable');
$globalTry = strpos($endpoint, 'ob_start();');
$globalCatch = strrpos($endpoint, '} catch (Throwable $error) {');
$assert($globalTry !== false && $globalCatch !== false && $globalTry < $globalCatch
    && str_contains($endpoint, "'code' => 'INTERNAL_SERVER_ERROR'")
    && str_contains($endpoint, "'retryable' => true")
    && str_contains($endpoint, 'ob_end_clean()'), 'uncaught runtime errors are buffered and converted to retryable JSON without leaking output');
$phaseOneCommit = strpos($endpoint, '$pdo->commit();', strpos($endpoint, '// Phase 1'));
$disconnect = strpos($endpoint, 'db_disconnect();', strpos($endpoint, '// No transaction or row lock'));
$phaseTwoTransaction = strpos($endpoint, '$pdo->beginTransaction();', strpos($endpoint, '// Phase 2'));
$freshRead = strpos($endpoint, '$freshStatement = $pdo->prepare', $providerCallPosition);
$assert($phaseOneCommit !== false && $providerCallPosition !== false && $phaseOneCommit < $disconnect && $disconnect < $providerCallPosition && $phaseTwoTransaction !== false && $freshRead !== false && $providerCallPosition < $phaseTwoTransaction && $phaseTwoTransaction < $freshRead, 'provider call is between released claim and finalization database sessions');
$assert(str_contains($endpoint, 'provider_operation_token') && str_contains($endpoint, 'provider_operation_lease_until') && str_contains($endpoint, 'TOPUP_PROVIDER_CLAIM_EXPIRED'), 'provider claim is token-checked and expires for crash recovery');
$assert(str_contains($endpoint, 'hash_equals((string)($fresh[\'provider_operation_token\'] ?? \'\'), $claimToken)') && str_contains($endpoint, 'if ($freshSnapshot[\'status\'] !== \'pending\')'), 'stale provider responses cannot overwrite a newer terminal outcome');
$assert(str_contains($endpoint, 'topup_clear_provider_claim($pdo, $transactionId, $claimToken)') && str_contains($endpoint, 'catch (InaxNetworkException $error)'), 'network failures release their claim and remain pending for retry');
$assert(str_contains($create, "'purchase'") && str_contains($create, 'topup_provider_claim_lease_seconds()') && str_contains($claimMigration, 'provider_operation_lease_until'), 'purchase holds a recoverable provider lease that blocks status overlap');
$assert(str_contains($claimMigration, "'balance_applied' => 'TINYINT(1) NOT NULL DEFAULT 0'") && str_contains($create, "'balance_applied'") && str_contains($common, "throw new RuntimeException('TOPUP_FINANCIAL_EVIDENCE_MISSING')"), 'missing debit markers fail closed while new debits explicitly persist their applied marker');
$purchaseProviderCall = strpos($create, '(new InaxService())->buyTopup(');
$purchaseDisconnect = strpos($create, 'db_disconnect();', $purchaseProviderCall === false ? 0 : $purchaseProviderCall - 500);
$assert($purchaseProviderCall !== false && $purchaseDisconnect !== false && $purchaseDisconnect < $purchaseProviderCall && strpos($create, '$pdo = db();', $purchaseProviderCall) > $purchaseProviderCall, 'purchase releases its committed PDO connection during Inax and reconnects for finalization');
$assert(str_contains($common, 'string $providerClaimToken') && str_contains($common, 'topup_clear_provider_claim($pdo, $transactionId, $providerClaimToken)'), 'completed pending/success/refund work requires and releases only its matching provider claim inside the transaction');
$assert(str_contains($common, "throw new RuntimeException('TOPUP_PROVIDER_CLAIM_LOST')") && str_contains($common, 'hash_equals((string)($lockedTransaction[\'provider_operation_token\'] ?? \'\'), $providerClaimToken)'), 'purchase writes and refunds are fenced by the current provider claim token');
$assert(str_contains($create, '$providerClaimToken') && str_contains($endpoint, '$claimToken'), 'purchase and status finalizers pass their own lease token when persisting provider outcomes');

$guardPosition = strpos($client, 'tryAcquireInFlight(submitInFlight)');
$firstSubmitAwait = strpos($client, 'await savePendingTopup(userId, pendingReceipt)');
$assert(str_contains($client, 'const submitInFlight = useRef(false);') && $guardPosition !== false && $firstSubmitAwait !== false && $guardPosition < $firstSubmitAwait, 'submit lock is acquired synchronously before the first await');
$assert(str_contains($client, 'releaseInFlight(submitInFlight);') && str_contains($client, 'finally {'), 'submit lock is released in finally');
$statusLockPosition = strpos($client, 'statusRequestInFlight.current = true;', strpos($client, 'const checkStatus = async () =>'));
$statusAwaitPosition = strpos($client, 'await requestTopupStatus(', strpos($client, 'const checkStatus = async () =>'));
$assert($statusLockPosition !== false && $statusAwaitPosition !== false && $statusLockPosition < $statusAwaitPosition, 'manual status checks lock synchronously before awaiting');
$statusCheckBody = substr($client, strpos($client, 'const checkStatus = async () =>'), strpos($client, 'useEffect(() => {', strpos($client, 'const checkStatus = async () =>')) - strpos($client, 'const checkStatus = async () =>'));
$assert(str_contains($statusCheckBody, 'describeStatusCheckError(error)')
    && str_contains($statusCheckBody, 'setResult(updated)')
    && str_contains($statusCheckBody, 'setPollingRestart((current) => current + 1)')
    && !str_contains($statusCheckBody, 'Alert.alert(diagnostic.'), 'status check errors stay inline and restart bounded polling instead of opening an alert');
$assert(str_contains($client, 'maxStatusChecks = 12') && str_contains($client, 'maxWindowMs = 600000') && str_contains($client, 'maxStatusCallMs = 120000'), 'automatic polling has synchronized attempt, call-timeout, and elapsed-time bounds');
$assert(str_contains($client, 'pollingWindow.current?.key !== pollingKey') && str_contains($client, 'checks: 0'), 'poll start and counter reset only for a different request identifier');
$assert(str_contains($client, 'onPress={checkStatus}') && str_contains($client, "status: 'pending'"), 'manual retry remains available and unresolved responses remain pending');
$assert(str_contains($client, 'checks: number') && str_contains($client, 'activePollingWindow.checks >= maxStatusChecks'), 'poll attempts are held in request-keyed ref state');
$assert(str_contains($client, 'TOPUP_TRANSACTION_NOT_VISIBLE_YET') && str_contains($client, "status: 'pending'"), '404 is not rendered as financial failure');
$assert(str_contains($client, 'payload?.retryable === false') && str_contains($client, '!stopPolling'), 'non-retryable reconciliation responses stop automatic polling');

echo "Top-up status contract tests passed ({$assertions} assertions)." . PHP_EOL;
