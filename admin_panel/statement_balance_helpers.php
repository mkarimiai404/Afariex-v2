<?php
declare(strict_types=1);

function statement_assign_running_balances(array $rows, array $currencySummaries): array
{
    $effectsByCurrency = [];
    foreach ($rows as $row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'IRT')));
        $effectsByCurrency[$currency] = ($effectsByCurrency[$currency] ?? 0.0) + (float)($row['effect'] ?? 0);
    }

    $runningByCurrency = [];
    foreach ($currencySummaries as $currency => $summary) {
        $runningByCurrency[$currency] = !empty($summary['wallet_tracked'])
            ? (float)($summary['wallet_balance'] ?? 0) - (float)($effectsByCurrency[$currency] ?? 0)
            : 0.0;
    }

    foreach ($rows as &$row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'IRT')));
        $tracked = !empty($currencySummaries[$currency]['wallet_tracked']);
        $balanceBefore = $row['balance_before'] ?? null;
        $balanceAfter = $row['balance_after'] ?? null;

        if ($tracked && $balanceBefore !== null && is_numeric($balanceBefore)) {
            $runningByCurrency[$currency] = (float)$balanceBefore;
        }
        $row['running_balance_before'] = $runningByCurrency[$currency] ?? 0.0;
        if ($tracked && $balanceAfter !== null && is_numeric($balanceAfter)) {
            $runningByCurrency[$currency] = (float)$balanceAfter;
        } else {
            $runningByCurrency[$currency] = ($runningByCurrency[$currency] ?? 0.0) + (float)($row['effect'] ?? 0);
        }

        $row['running_balance'] = $runningByCurrency[$currency];
        $row['balance_tracked'] = $tracked;
    }
    unset($row);

    return $rows;
}
