<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/receipt_currency_helpers.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(receipt_normalize_currency_amount('1500.25', 2) === '1500.25', 'two-decimal amount is retained exactly');
$assert(receipt_normalize_currency_amount('۱۵۰۰٫۲۵', 2) === '1500.25', 'Persian digits and decimal separator normalize');
$assert(receipt_normalize_currency_amount('1.234', 3) === '1.234', 'three-decimal currency amount is accepted');
$assert(receipt_normalize_currency_amount('1.234', 2) === null, 'excess fractional precision is rejected');
$assert(receipt_normalize_currency_amount('0', 2) === null, 'zero amount is rejected');
$assert(receipt_normalize_currency_amount('-5', 2) === null, 'negative amount is rejected');
$assert(receipt_format_currency_amount('15000.5', 'USD', ['USD' => 2]) === '15,000.50', 'currency formatting preserves configured fractional digits');
$assert(receipt_format_currency_amount('15000.00', 'IRT', ['IRT' => 2]) === '15,000', 'Toman display remains integer formatted');

echo "Receipt currency helper tests passed ({$assertions} assertions).\n";
