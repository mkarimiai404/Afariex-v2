<?php
declare(strict_types=1);

function remittance_persian_digits(string $value): string
{
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function remittance_under_thousand_words(int $value): string
{
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $parts = [];
    $hundred = intdiv($value, 100);
    $remainder = $value % 100;
    if ($hundred > 0) $parts[] = $hundreds[$hundred];
    if ($remainder > 0 && $remainder < 20) {
        $parts[] = $ones[$remainder];
    } elseif ($remainder >= 20) {
        $parts[] = $tens[intdiv($remainder, 10)];
        if ($remainder % 10 > 0) $parts[] = $ones[$remainder % 10];
    }
    return implode(' و ', $parts);
}

function remittance_integer_words(int $value): string
{
    if ($value === 0) return 'صفر';
    $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون'];
    $parts = [];
    $scale = 0;
    while ($value > 0 && $scale < count($scales)) {
        $chunk = $value % 1000;
        if ($chunk > 0) $parts[] = trim(remittance_under_thousand_words($chunk) . ' ' . $scales[$scale]);
        $value = intdiv($value, 1000);
        $scale++;
    }
    return implode(' و ', array_reverse($parts));
}

function remittance_amount_words(float $amount): string
{
    if (!is_finite($amount) || abs($amount) > 999999999999999) return 'مبلغ نامعتبر';
    $negative = $amount < 0 ? 'منفی ' : '';
    $rounded = round(abs($amount), 2);
    $integer = (int)floor($rounded);
    $fraction = (int)round(($rounded - $integer) * 100);
    $words = remittance_integer_words($integer);
    if ($fraction > 0) {
        $words .= $fraction % 10 === 0
            ? ' ممیز ' . remittance_integer_words(intdiv($fraction, 10)) . ' دهم'
            : ' ممیز ' . remittance_integer_words($fraction) . ' صدم';
    }
    return $negative . $words;
}

function remittance_formatted_amount(float $amount): string
{
    $decimals = floor($amount) === $amount ? 0 : 2;
    return remittance_persian_digits(number_format($amount, $decimals, '.', ','));
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
