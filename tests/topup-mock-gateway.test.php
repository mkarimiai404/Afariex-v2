<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/lib/InaxClient.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

putenv('APP_ENV=staging');
putenv('TOPUP_MOCK_GATEWAY=1');
$client = new InaxClient();
$purchase = $client->topupWithOptions('MCI', 20000, '09120000000', 'TEST-ORDER', 'normal');
$status = $client->transStatus('TEST-ORDER');
$assert(($purchase['mock'] ?? false) === true && ($purchase['code'] ?? null) === 1, 'mock purchase returns deterministic success');
$assert(($status['mock'] ?? false) === true && ($status['payment_status'] ?? null) === 'paid', 'mock status returns deterministic success');

putenv('APP_ENV=production');
try {
    new InaxClient();
    $assert(false, 'mock mode must be rejected in production');
} catch (InaxConfigurationException $error) {
    $assert($error->getMessage() === 'TOPUP_MOCK_GATEWAY_FORBIDDEN_IN_ENVIRONMENT', 'production mock guard has the expected error');
}

putenv('TOPUP_MOCK_GATEWAY');
putenv('APP_ENV');
echo "Top-up mock gateway tests passed (3 assertions).\n";
