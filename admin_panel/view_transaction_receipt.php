<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

require_login();
require_permission('view');

$id = (int)($_GET['id'] ?? 0);
$flash = get_flash();

$transaction = null;

if ($id > 0) {
    $stmt = db()->prepare(
        'SELECT t.id, t.user_id, t.amount, t.tracking_code, t.status, t.created_at, u.mobile, u.first_name, u.last_name
         FROM transactions t
         LEFT JOIN users u ON t.user_id = u.id
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$transaction) {
    render_page_start('نمایش رسید فیش', 'receipts');
    echo '<div class="alert error">رسید یافت نشد.</div>';
    render_page_end();
    exit;
}

$status = (string)($transaction['status'] ?? '');
$statusText = match ($status) {
    'pending' => 'در انتظار',
    'approved' => 'تایید شده',
    'rejected' => 'رد شده',
    'paid' => 'پرداخت شده',
    default => 'نامشخص',
};

$createdAt = to_jalali_datetime((string)$transaction['created_at']);
$receiptNumber = 'TRX-' . str_pad((string)$transaction['id'], 6, '0', STR_PAD_LEFT);
$fullName = trim(trim((string)($transaction['first_name'] ?? '')) . ' ' . trim((string)($transaction['last_name'] ?? '')));
$fullName = $fullName !== '' ? $fullName : 'نامشخص';
$userMobile = trim((string)($transaction['mobile'] ?? ''));
$userMobile = $userMobile !== '' ? $userMobile : 'نامشخص';
$trackingCode = trim((string)($transaction['tracking_code'] ?? ''));
$trackingCode = $trackingCode !== '' ? $trackingCode : '---';
$stampVisible = in_array($status, ['approved', 'paid'], true) || $status === 'rejected';

render_page_start('نمایش رسید فیش', 'receipts');
?>

<style>
    .receipt-shell {
        max-width: 980px;
        margin: 0 auto;
        padding: 8px 0;
    }

    .receipt-paper {
        position: relative;
        overflow: hidden;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        border: 1px solid #dbe4ef;
        border-radius: 18px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
        padding: 28px;
    }

    .receipt-paper::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at top right, rgba(37, 99, 235, 0.08), transparent 28%),
            radial-gradient(circle at bottom left, rgba(5, 150, 105, 0.08), transparent 24%);
        pointer-events: none;
    }

    .receipt-header,
    .receipt-grid,
    .receipt-footer {
        position: relative;
        z-index: 1;
    }

    .receipt-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 22px;
    }

    .receipt-title {
        margin: 0;
        color: #0f172a;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .receipt-subtitle {
        margin-top: 6px;
        color: #64748b;
        font-size: 14px;
        line-height: 1.7;
    }

    .receipt-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .receipt-id {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 800;
    }

    .receipt-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        border: 1px solid #a7f3d0;
        background: #ecfdf5;
        color: #047857;
    }

    .receipt-status.is-rejected {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .receipt-status.is-pending {
        border-color: #fed7aa;
        background: #fff7ed;
        color: #c2410c;
    }

    .receipt-status.is-unknown {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #475569;
    }

    .receipt-stamp {
        position: absolute;
        top: 26px;
        left: 24px;
        z-index: 2;
        transform: rotate(-16deg);
        border: 4px solid rgba(5, 150, 105, 0.28);
        color: rgba(5, 150, 105, 0.84);
        background: rgba(236, 253, 245, 0.72);
        border-radius: 14px;
        padding: 10px 18px;
        font-size: 34px;
        font-weight: 900;
        letter-spacing: 2px;
        text-transform: uppercase;
        box-shadow: 0 12px 30px rgba(5, 150, 105, 0.12);
        pointer-events: none;
        user-select: none;
    }

    .receipt-stamp.is-rejected {
        border-color: rgba(185, 28, 28, 0.28);
        color: rgba(185, 28, 28, 0.84);
        background: rgba(254, 242, 242, 0.76);
        box-shadow: 0 12px 30px rgba(185, 28, 28, 0.12);
    }

    .receipt-stamp.is-pending {
        border-color: rgba(217, 119, 6, 0.28);
        color: rgba(217, 119, 6, 0.84);
        background: rgba(255, 247, 237, 0.76);
        box-shadow: 0 12px 30px rgba(217, 119, 6, 0.12);
    }

    .receipt-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 14px;
        margin-top: 14px;
    }

    .receipt-cell {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px 16px;
        min-height: 82px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .receipt-label {
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .receipt-value {
        color: #0f172a;
        font-size: 16px;
        font-weight: 800;
        line-height: 1.55;
        word-break: break-word;
    }

    .receipt-value strong {
        color: #1d4ed8;
    }

    .receipt-footer {
        margin-top: 22px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .receipt-print {
        height: 42px;
        padding: 0 18px;
        border: 1px solid #1d4ed8;
        border-radius: 10px;
        background: #2563eb;
        color: #fff;
        font-weight: 800;
        cursor: pointer;
        transition: 160ms ease;
    }

    .receipt-print:hover {
        background: #1d4ed8;
    }

    .receipt-note {
        margin-top: 14px;
        color: #64748b;
        font-size: 13px;
        line-height: 1.7;
        position: relative;
        z-index: 1;
    }

    @media print {
        .app-shell .sidebar,
        .topbar,
        .content > .alert,
        .content > .topbar,
        .receipt-footer,
        .receipt-note {
            display: none !important;
        }

        .content {
            padding: 0 !important;
        }

        .receipt-shell {
            max-width: none;
        }

        .receipt-paper {
            box-shadow: none;
            border-color: #cbd5e1;
        }

        .receipt-stamp {
            border-color: rgba(5, 150, 105, 0.2);
            color: rgba(5, 150, 105, 0.55);
            background: transparent;
        }
    }
</style>

<div class="receipt-shell">
    <?php if (($flash['type'] ?? '') === 'error'): ?>
        <div class="alert error"><?= e((string)($flash['message'] ?? '')) ?></div>
    <?php endif; ?>

    <div class="receipt-paper">
        <div class="receipt-stamp <?= $status === 'rejected' ? 'is-rejected' : ($status === 'pending' ? 'is-pending' : '') ?>">
            <?= $status === 'rejected' ? 'REJECTED' : 'APPROVED' ?>
        </div>

        <div class="receipt-header">
            <div>
                <h1 class="receipt-title">رسید فیش واریزی</h1>
                <div class="receipt-subtitle">
                    تاریخ صدور: <?= e((string)$createdAt) ?><br>
                    این رسید به صورت خودکار از اطلاعات ثبت‌شده در جدول تراکنش‌ها و کاربران تولید شده است.
                </div>
            </div>

            <div class="receipt-meta">
                <div class="receipt-id">شناسه رسید: <?= e($receiptNumber) ?></div>
                <div class="receipt-status <?= $status === 'rejected' ? 'is-rejected' : ($status === 'pending' ? 'is-pending' : ($status === 'approved' || $status === 'paid' ? '' : 'is-unknown')) ?>">
                    <?= e($statusText) ?>
                </div>
            </div>
        </div>

        <div class="receipt-grid">
            <div class="receipt-cell">
                <span class="receipt-label">شناسه تراکنش</span>
                <span class="receipt-value">#<?= e((string)$transaction['id']) ?></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">نام کاربر</span>
                <span class="receipt-value"><?= e($fullName) ?></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">موبایل کاربر</span>
                <span class="receipt-value" dir="ltr"><?= e($userMobile) ?></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">مبلغ (تومان)</span>
                <span class="receipt-value"><strong><?= number_format((float)$transaction['amount']) ?></strong></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">کد پیگیری</span>
                <span class="receipt-value" dir="ltr"><?= e($trackingCode) ?></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">تاریخ</span>
                <span class="receipt-value"><?= e((string)$createdAt) ?></span>
            </div>
            <div class="receipt-cell">
                <span class="receipt-label">وضعیت</span>
                <span class="receipt-value"><?= e($statusText) ?></span>
            </div>
        </div>

        <div class="receipt-note">
            وضعیت تراکنش بر اساس مقدار ثبت‌شده در سیستم نمایش داده می‌شود. در حالت تایید یا رد، مهر مربوطه روی رسید ظاهر می‌شود.
        </div>

        <div class="receipt-footer">
            <button type="button" class="receipt-print" onclick="window.print()">چاپ رسید</button>
        </div>
    </div>
</div>

<?php render_page_end(); ?>
