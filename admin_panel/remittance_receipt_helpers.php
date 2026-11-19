<?php
declare(strict_types=1);

function remittance_persian_digits(string $value): string
{
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function remittance_formatted_amount(string|int|float $amount, int $precision = 2): string
{
    $raw = trim((string)$amount);
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $raw, $parts) !== 1) return '۰';
    $precision = max(0, min(4, $precision));
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]) ?: $parts[2];
    $meaningfulFraction = rtrim((string)($parts[3] ?? ''), '0');
    $fractionDigits = max($precision, strlen($meaningfulFraction));
    $fraction = $fractionDigits > 0 ? substr(str_pad((string)($parts[3] ?? ''), $fractionDigits, '0'), 0, $fractionDigits) : '';
    return remittance_persian_digits(($parts[1] ?? '') . $integer . ($fractionDigits > 0 ? '.' . $fraction : ''));
}

function remittance_customer_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['approved', 'paid', 'completed', 'ready'], true)) return 'آماده پرداخت';
    if (in_array($status, ['rejected', 'cancelled', 'canceled'], true)) return 'نیازمند پیگیری';
    return 'در حال پردازش';
}

function remittance_customer_tracking_number(int $id): string
{
    return 'AFR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}
