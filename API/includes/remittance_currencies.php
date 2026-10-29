<?php
declare(strict_types=1);

require_once __DIR__ . '/remittance_destination_countries.php';

function remittance_currency_registry(): array
{
    return [
        'USD' => ['code' => 'USD', 'name' => 'دلار آمریکا', 'symbol' => '$'],
        'AFN' => ['code' => 'AFN', 'name' => 'افغانی', 'symbol' => '؋'],
        'EUR' => ['code' => 'EUR', 'name' => 'یورو', 'symbol' => '€'],
        'GBP' => ['code' => 'GBP', 'name' => 'پوند بریتانیا', 'symbol' => '£'],
        'TRY' => ['code' => 'TRY', 'name' => 'لیر ترکیه', 'symbol' => '₺'],
        'CAD' => ['code' => 'CAD', 'name' => 'دلار کانادا', 'symbol' => 'C$'],
        'CNY' => ['code' => 'CNY', 'name' => 'یوان چین', 'symbol' => '¥'],
        'RUB' => ['code' => 'RUB', 'name' => 'روبل روسیه', 'symbol' => '₽'],
        'PKR' => ['code' => 'PKR', 'name' => 'روپیه پاکستان', 'symbol' => '₨'],
        'TJS' => ['code' => 'TJS', 'name' => 'سامانی تاجیکستان', 'symbol' => 'ЅМ'],
        'UZS' => ['code' => 'UZS', 'name' => 'سوم ازبکستان', 'symbol' => 'soʻm'],
        'TMT' => ['code' => 'TMT', 'name' => 'منات ترکمنستان', 'symbol' => 'm'],
        'KZT' => ['code' => 'KZT', 'name' => 'تنگه قزاقستان', 'symbol' => '₸'],
        'AED' => ['code' => 'AED', 'name' => 'درهم امارات', 'symbol' => 'د.إ'],
        'SAR' => ['code' => 'SAR', 'name' => 'ریال سعودی', 'symbol' => '﷼'],
        'QAR' => ['code' => 'QAR', 'name' => 'ریال قطر', 'symbol' => 'ر.ق'],
        'OMR' => ['code' => 'OMR', 'name' => 'ریال عمان', 'symbol' => 'ر.ع.'],
        'KWD' => ['code' => 'KWD', 'name' => 'دینار کویت', 'symbol' => 'د.ك'],
        'BHD' => ['code' => 'BHD', 'name' => 'دینار بحرین', 'symbol' => '.د.ب'],
        'IQD' => ['code' => 'IQD', 'name' => 'دینار عراق', 'symbol' => 'ع.د'],
    ];
}

function remittance_source_currencies(): array
{
    return ['IRT' => ['code' => 'IRT', 'name' => 'تومان', 'symbol' => 'تومان']];
}

function remittance_destination_currencies(): array
{
    return remittance_currency_registry();
}

function remittance_currency_name(string $currencyCode): ?string
{
    $code = strtoupper(trim($currencyCode));
    $currency = remittance_source_currencies()[$code] ?? remittance_destination_currencies()[$code] ?? null;
    return $currency['name'] ?? null;
}

function remittance_country_destination_currencies(string $countryCode): array
{
    $country = strtoupper(trim($countryCode));
    $regional = [
        'AF' => ['AFN', 'USD'], 'TR' => ['TRY', 'USD', 'EUR'],
        'AE' => ['AED', 'USD'], 'SA' => ['SAR', 'USD'], 'QA' => ['QAR', 'USD'],
        'OM' => ['OMR', 'USD'], 'KW' => ['KWD', 'USD'], 'BH' => ['BHD', 'USD'],
        'IQ' => ['IQD', 'USD'], 'CA' => ['CAD', 'USD'], 'US' => ['USD'],
        'GB' => ['GBP', 'USD'], 'RU' => ['RUB', 'USD'], 'CN' => ['CNY', 'USD'],
        'PK' => ['PKR', 'USD'], 'TJ' => ['TJS', 'USD'], 'UZ' => ['UZS', 'USD'],
        'TM' => ['TMT', 'USD'], 'KZ' => ['KZT', 'USD'],
    ];
    $europe = ['AL','AT','BE','BA','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IS','IE','IT','LV','LT','LU','MT','MD','ME','NL','MK','NO','PL','PT','RO','RS','SK','SI','ES','SE','CH','UA'];
    foreach ($europe as $code) $regional[$code] = ['USD', 'EUR'];
    $arab = ['DZ','KM','DJ','EG','JO','LB','LY','MR','MA','PS','SO','SD','SY','TN','YE'];
    foreach ($arab as $code) $regional[$code] = ['USD'];
    return $regional[$country] ?? ['USD', 'EUR'];
}

function remittance_country_destination_currency_metadata(string $countryCode): array
{
    $registry = remittance_destination_currencies();
    return array_map(static fn (string $code): array => $registry[$code], remittance_country_destination_currencies($countryCode));
}

function remittance_destination_currency_is_supported(string $countryCode, string $currencyCode): bool
{
    return in_array(strtoupper(trim($currencyCode)), remittance_country_destination_currencies($countryCode), true);
}

function remittance_fixed_rate_pairs(): array
{
    return ['IRT|AFN' => ['source_currency' => 'IRT', 'destination_currency' => 'AFN', 'country_code' => 'AF']];
}

function remittance_is_fixed_rate_pair(string $sourceCurrency, string $destinationCurrency): bool
{
    return array_key_exists(strtoupper(trim($sourceCurrency)) . '|' . strtoupper(trim($destinationCurrency)), remittance_fixed_rate_pairs());
}

function remittance_pair_uses_country(string $sourceCurrency, string $destinationCurrency, string $countryCode): bool
{
    $pair = strtoupper(trim($sourceCurrency)) . '|' . strtoupper(trim($destinationCurrency));
    $config = remittance_fixed_rate_pairs()[$pair] ?? null;
    return is_array($config) && strtoupper(trim($countryCode)) === $config['country_code'];
}
