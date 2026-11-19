<?php
declare(strict_types=1);

function receipt_normalize_currency_amount(string $input, int $precision): ?string
{
    $value = strtr(trim($input), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '',
    ]);
    $value = str_replace([',', ' '], '', $value);
    $precision = max(0, min(4, $precision));
    $pattern = $precision === 0 ? '/^(?:0|[1-9]\d*)$/' : '/^(?:0|[1-9]\d*)(?:\.\d{1,' . $precision . '})?$/';
    if (preg_match($pattern, $value) !== 1 || preg_match('/[1-9]/', str_replace('.', '', $value)) !== 1) return null;
    return $value;
}

function receipt_format_currency_amount(mixed $amount, string $currency, array $precisions): string
{
    $raw = trim((string)$amount);
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $raw, $parts) !== 1) return '0';
    $precision = $currency === 'IRT' ? 0 : max(0, min(4, (int)($precisions[$currency] ?? 2)));
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]) ?: $parts[2];
    $fraction = substr(str_pad((string)($parts[3] ?? ''), $precision, '0'), 0, $precision);
    return ($parts[1] ?? '') . $integer . ($precision > 0 ? '.' . $fraction : '');
}
