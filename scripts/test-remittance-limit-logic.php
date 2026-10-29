<?php
declare(strict_types=1);

function allowed(float $combinedLimit, float $withdrawals, float $remittances, ?float $custom, float $requested): bool
{
    return $withdrawals + $remittances + $requested <= $combinedLimit
        && ($custom === null || $remittances + $requested <= $custom);
}

$cases = [
    [true, 'custom 2m + withdrawal 5m + remittance .5m allows 1.5m', [100000000, 5000000, 500000, 2000000, 1500000]],
    [false, 'custom 2m + remittance 2m blocks more', [100000000, 5000000, 2000000, 2000000, 1]],
    [true, 'NULL custom uses combined limit only', [10000000, 5000000, 4000000, null, 1000000]],
    [false, 'combined level exhausted despite custom remaining', [10000000, 9000000, 1000000, 5000000, 1]],
];
foreach ($cases as [$expected, $name, $args]) {
    if (allowed(...$args) !== $expected) throw new RuntimeException("Fixture failed: {$name}");
}
if (array_key_exists('custom_remittance_limit', ['amount_toman' => 1])) throw new RuntimeException('Client payload fixture failed');
echo 'Remittance limit logic fixtures passed.' . PHP_EOL;
