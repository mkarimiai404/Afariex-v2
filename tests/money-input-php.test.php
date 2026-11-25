<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/config.php';
require_once __DIR__ . '/../admin_panel/money_input_helpers.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$assert(normalize_admin_money_amount('1,000') === '1000', 'western grouped amount is normalized');
$irtManualAmount = normalize_admin_money_amount('20,000', 2);
$assert($irtManualAmount === '20000', 'IRT manual input of 20,000 normalizes to the exact unconverted Toman amount');
$afnAmount = normalize_admin_money_amount('10,000', 2);
$usdAmount = normalize_admin_money_amount('10,000', 2);
$assert($afnAmount === '10000', 'AFN amount is normalized without converting its currency');
$assert($usdAmount === '10000', 'USD amount is normalized without converting its currency');
$assert(normalize_admin_money_amount('1.234', 3) === '1.234', 'three-decimal supported currencies retain their precision');
$assert(normalize_admin_money_amount('1.234', 2) === null, 'excess currency precision is rejected');
$assert(normalize_admin_money_amount('۱٬۲۳۴٬۵۶۷') === '1234567', 'persian grouped digits are normalized');
$assert(normalize_admin_money_amount('١٬٢٣٤') === '1234', 'arabic-indic grouped digits are normalized');
$assert(normalize_admin_money_amount('0001000') === '1000', 'leading zeros are removed');
$assert(normalize_admin_money_amount('12٫5') === '12.5', 'persian decimal separator is normalized');
$assert(normalize_admin_money_amount('0') === null, 'zero is rejected');
$assert(normalize_admin_money_amount('') === null, 'empty input is rejected');
$assert(normalize_admin_money_amount('1,2,3') === null, 'malformed grouping cannot be silently reinterpreted');
$assert(normalize_admin_money_amount('10.123') === null, 'more than two decimals is rejected');
$assert(normalize_admin_money_amount('-1000') === null, 'negative amount is rejected');
$assert(normalize_admin_money_amount('1e3') === null, 'scientific notation is rejected');
$assert(normalize_admin_money_amount('99999999999999999') === null, 'amounts beyond the wallet decimal range are rejected');

echo "PHP money input tests passed ({$assertions} assertions)." . PHP_EOL;
