<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/statement_balance_helpers.php';

$rows = [
    ['currency' => 'AFN', 'effect' => 100, 'balance_before' => '15', 'balance_after' => '115'],
    ['currency' => 'IRT', 'effect' => 9000, 'balance_before' => '50000', 'balance_after' => '59000'],
    ['currency' => 'AFN', 'effect' => -25, 'balance_before' => '115', 'balance_after' => '90'],
    ['currency' => 'USD', 'effect' => 30, 'balance_before' => null, 'balance_after' => null],
    ['currency' => 'USD', 'effect' => -10, 'balance_before' => null, 'balance_after' => null],
    ['currency' => 'AFN', 'effect' => 5, 'balance_before' => null, 'balance_after' => null],
];
$summaries = [
    'IRT' => ['wallet_tracked' => true, 'wallet_balance' => 59000],
    'AFN' => ['wallet_tracked' => true, 'wallet_balance' => 95],
    'USD' => ['wallet_tracked' => true, 'wallet_balance' => 20],
];
$result = statement_assign_running_balances($rows, $summaries);

$expected = [115.0, 59000.0, 90.0, 30.0, 20.0, 95.0];
foreach ($expected as $index => $balance) {
    if ($result[$index]['running_balance'] !== $balance) {
        throw new RuntimeException("Running balance mismatch at row {$index}: expected {$balance}, got {$result[$index]['running_balance']}");
    }
    if ($result[$index]['balance_tracked'] !== true) {
        throw new RuntimeException("Tracked currency was not marked at row {$index}");
    }
}

$filteredAfnRows = array_values(array_filter($result, static fn(array $row): bool => $row['currency'] === 'AFN'));
if (array_column($filteredAfnRows, 'running_balance') !== [115.0, 90.0, 95.0]) {
    throw new RuntimeException('AFN filter must retain the AFN-only running balance sequence');
}
if (array_column($result, 'running_balance_before') !== [15.0, 50000.0, 115.0, 0.0, 30.0, 90.0]) {
    throw new RuntimeException('Each transaction must expose its currency-specific balance before the operation');
}

echo "User statement balance tests passed (14 assertions)." . PHP_EOL;
