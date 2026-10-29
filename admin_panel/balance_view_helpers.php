<?php
declare(strict_types=1);

function admin_balance_number(mixed $balance): string
{
    if ($balance === null || $balance === '') return '—';
    $raw = trim((string)$balance);
    if (preg_match('/^(-?)(\d+)(?:\.(0+))?$/', $raw, $matches) === 1) {
        $integer = ltrim($matches[2], '0');
        if ($integer === '') $integer = '0';
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer) ?: $integer;
        return ($matches[1] === '-' && $integer !== '0' ? '-' : '') . $grouped;
    }
    return number_format((float)$balance);
}

function admin_balance_with_unit(mixed $balance): string
{
    $number = admin_balance_number($balance);
    return $number === '—' ? $number : $number . ' تومان';
}

/** @return array<string, string> */
function admin_withdrawal_balance_summary(array $row): array
{
    $fields = [
        'مبلغ درخواست برداشت' => 'amount',
        'موجودی قبل از ثبت درخواست' => 'balance_before',
        'موجودی پس از رزرو مبلغ' => 'balance_after',
        'موجودی فعلی حساب' => 'current_balance',
    ];
    $summary = [];
    foreach ($fields as $label => $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
            $summary[$label] = admin_balance_with_unit($row[$field]);
        }
    }
    return $summary;
}

function admin_withdrawal_reservation_note_is_accurate(array $row): bool
{
    return (int)($row['balance_applied'] ?? 0) === 1
        && in_array((string)($row['status'] ?? ''), ['pending', 'approved', 'paid'], true);
}
