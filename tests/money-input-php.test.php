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
$assert(normalize_admin_money_amount('۱٬۲۳۴٬۵۶۷') === '1234567', 'persian grouped digits are normalized');
$assert(normalize_admin_money_amount('١٬٢٣٤') === '1234', 'arabic-indic grouped digits are normalized');
$assert(normalize_admin_money_amount('0001000') === '1000', 'leading zeros are removed');
$assert(normalize_admin_money_amount('12٫5') === '12.5', 'persian decimal separator is normalized');
$assert(normalize_admin_money_amount('0') === null, 'zero is rejected');
$assert(normalize_admin_money_amount('') === null, 'empty input is rejected');
$assert(normalize_admin_money_amount('1,2,3') === null, 'invalid grouping is rejected');
$assert(normalize_admin_money_amount('10.123') === null, 'more than two decimals is rejected');
$assert(normalize_admin_money_amount('-1000') === null, 'negative amount is rejected');
$assert(normalize_admin_money_amount('1e3') === null, 'scientific notation is rejected');

echo "PHP money input tests passed ({$assertions} assertions)." . PHP_EOL;
