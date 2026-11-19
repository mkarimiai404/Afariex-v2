<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/exchange_rate_service.php';
require_once __DIR__ . '/../admin_panel/remittance_receipt_helpers.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$legacyRate = exchange_rate_normalize_directional_row([
    'source_currency' => 'IRT',
    'destination_currency' => 'AFN',
    'exchange_rate' => '4255.000000000000',
    'toman_per_afn' => '4255.00',
]);
$assert($legacyRate['exchange_rate'] === '0.000235017626', 'legacy Toman-per-AFN rate is exposed as its directional reciprocal');
$assert(exchange_rate_calculate_destination_amount('10000000', $legacyRate['exchange_rate'], '0.00') === '2350.18', '10 million Toman converts by division and rounds to AFN cents');
$assert(exchange_rate_calculate_destination_amount('10000000', $legacyRate['exchange_rate'], '10.00') === '2115.16', 'conversion commission is applied once before rounding');
$assert(exchange_rate_calculate_destination_amount('1', '4255', '0') === '4255.00', 'ordinary directional rate remains destination units per source unit');
$assert(exchange_rate_calculate_destination_amount('100000', '0.000025', '0') === '2.50', 'another supported directional rate keeps its existing behavior');
$assert(exchange_rate_client_amount_matches_calculation('2350.18', '2350.18'), 'client amount matching the server calculation is accepted');
$assert(!exchange_rate_client_amount_matches_calculation('42550000000', '2350.18'), 'client-submitted amount cannot replace the server calculation');

$usdRate = exchange_rate_normalize_directional_row([
    'source_currency' => 'IRT',
    'destination_currency' => 'USD',
    'exchange_rate' => '0.000025',
    'toman_per_afn' => '4255',
]);
$assert($usdRate['exchange_rate'] === '0.000025', 'legacy AFN field does not alter another currency pair');

foreach (['0', '-1', '1.234', '12abc', '99999999999999999'] as $invalidAmount) {
    $assert(exchange_rate_calculate_destination_amount($invalidAmount, '0.5', '0') === null, "invalid source amount {$invalidAmount} is rejected");
}
$assert(exchange_rate_calculate_destination_amount('100', '0', '0') === null, 'zero rate is rejected');
$assert(exchange_rate_calculate_destination_amount('100', '-1', '0') === null, 'negative rate is rejected');
$assert(exchange_rate_calculate_destination_amount('100', '1', '100.01') === null, 'commission above 100 percent is rejected');
$assert(exchange_rate_calculate_destination_amount('100', '1', 'oops') === null, 'malformed commission is rejected');
$assert(exchange_rate_parse_minor_units('10000000000000000') === null, 'excessively large amount is rejected without integer overflow');
$assert(remittance_formatted_amount('2350.18', 2) === '۲,۳۵۰.۱۸', 'AFN receipt formats the exact amount with two decimals');
$assert(remittance_formatted_amount('15000.00', 0) === '۱۵,۰۰۰', 'IRT receipt follows the project zero-decimal precision');
$adminReceiptSource = file_get_contents(__DIR__ . '/../admin_panel/view_receipt.php');
$assert(is_string($adminReceiptSource) && !str_contains($adminReceiptSource, 'remittance_amount_words'), 'admin receipt no longer renders amount in words');

echo "Remittance calculation tests passed ({$assertions} assertions)." . PHP_EOL;
