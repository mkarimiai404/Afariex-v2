<?php
declare(strict_types=1);

function normalize_iran_mobile(string $value): ?string
{
    $value = trim(strtr($value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]));
    $value = strtr($value, [
        '\u{06F0}' => '0', '\u{06F1}' => '1', '\u{06F2}' => '2', '\u{06F3}' => '3', '\u{06F4}' => '4',
        '\u{06F5}' => '5', '\u{06F6}' => '6', '\u{06F7}' => '7', '\u{06F8}' => '8', '\u{06F9}' => '9',
        '\u{0660}' => '0', '\u{0661}' => '1', '\u{0662}' => '2', '\u{0663}' => '3', '\u{0664}' => '4',
        '\u{0665}' => '5', '\u{0666}' => '6', '\u{0667}' => '7', '\u{0668}' => '8', '\u{0669}' => '9',
    ]);
    $value = preg_replace('/[\s().-]+/', '', $value) ?? '';
    if (str_starts_with($value, '+98')) $value = '0' . substr($value, 3);
    elseif (str_starts_with($value, '98')) $value = '0' . substr($value, 2);
    return preg_match('/^09\d{9}$/', $value) === 1 ? $value : null;
}

function mask_iran_mobile(string $mobile): string
{
    return strlen($mobile) >= 5 ? substr($mobile, 0, 3) . str_repeat('*', max(2, strlen($mobile) - 5)) . substr($mobile, -2) : '****';
}
