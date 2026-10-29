<?php
declare(strict_types=1);

/** @return array<string, string> */
function withdrawal_admin_status_labels(): array
{
    return [
        'pending' => 'در انتظار بررسی',
        'approved' => 'تأیید شده / آماده پرداخت',
        'paid' => 'پرداخت شده',
        'rejected' => 'رد شده',
    ];
}

/** @return string[] */
function withdrawal_admin_status_options(string $currentStatus): array
{
    return match ($currentStatus) {
        'pending' => ['pending', 'approved', 'rejected'],
        'approved' => ['approved', 'paid'],
        'paid' => ['paid'],
        'rejected' => ['rejected'],
        default => [],
    };
}

function withdrawal_admin_transition_action(string $currentStatus, string $targetStatus): ?string
{
    if ($currentStatus === $targetStatus && in_array($currentStatus, ['pending', 'approved', 'paid', 'rejected'], true)) {
        return null;
    }
    return match ($currentStatus . ':' . $targetStatus) {
        'pending:approved' => 'approve',
        'pending:rejected' => 'reject',
        'approved:paid' => 'paid',
        default => throw new DomainException('INVALID_STATE_TRANSITION'),
    };
}
