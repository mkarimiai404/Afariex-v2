<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';
require_once __DIR__ . '/../API/includes/remittance_currencies.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$countries = remittance_destination_countries();
$countryCodes = array_keys($countries);
$assert(count($countryCodes) === count(array_unique($countryCodes)), 'country codes are unique');

foreach (['AF', 'DE', 'FR', 'IT', 'ES', 'PT', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'IE', 'PL', 'CZ', 'HU', 'RO', 'BG', 'GR', 'HR', 'SI', 'SK', 'LT', 'LV', 'EE', 'LU', 'MT', 'CY', 'AE', 'SA', 'QA', 'OM', 'KW', 'BH', 'JO', 'LB', 'EG', 'IQ', 'CA', 'TJ', 'PK', 'CN', 'RU', 'US', 'TR', 'GB', 'UZ', 'TM', 'KZ'] as $code) {
    $assert(isset($countries[$code]), "required country {$code} is present");
}

$currencies = remittance_destination_currencies();
foreach (['USD', 'AFN', 'EUR', 'GBP', 'TRY', 'CAD', 'CNY', 'RUB', 'PKR', 'TJS', 'UZS', 'TMT', 'KZT', 'AED', 'SAR', 'QAR', 'OMR', 'KWD', 'BHD', 'IQD'] as $code) {
    $assert(isset($currencies[$code]), "required destination currency {$code} is present");
}
$assert(isset(remittance_source_currencies()['IRT']), 'Toman remains the primary supported source currency');

$assert(remittance_country_destination_currencies('AF') === ['AFN', 'USD'], 'Afghanistan supports AFN and USD');
$assert(remittance_country_destination_currencies('TR') === ['TRY', 'USD', 'EUR'], 'Turkey supports TRY, USD, EUR');
$assert(remittance_country_destination_currencies('AE') === ['AED', 'USD'], 'UAE supports AED and USD');
$assert(remittance_country_destination_currencies('DE') === ['USD', 'EUR'], 'default European mapping supports USD and EUR');

$assert(remittance_is_fixed_rate_pair('IRT', 'AFN') === true, 'IRT to AFN is the only fixed-rate pair');
$assert(remittance_is_fixed_rate_pair('IRT', 'USD') === false, 'IRT to USD is variable-rate');
$assert(remittance_is_fixed_rate_pair('USD', 'AFN') === false, 'USD source is variable-rate');
$assert(remittance_pair_uses_country('IRT', 'AFN', 'AF') === true, 'fixed pair is bound to Afghanistan');
$assert(remittance_pair_uses_country('IRT', 'AFN', 'TR') === false, 'fixed AFN pair cannot bypass country validation');

echo "Remittance currency registry tests passed ({$assertions} assertions)." . PHP_EOL;
