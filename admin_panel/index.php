<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';
require_login();
require_permission('view');

$totalAgencies = (int)db()->query('SELECT COUNT(*) FROM agencies')->fetchColumn();
$totalActiveRates = (int)db()->query('SELECT COUNT(*) FROM exchange_rates WHERE is_active = 1')->fetchColumn();
$totalRemittances = (int)db()->query('SELECT COUNT(*) FROM remittances')->fetchColumn();
$sumToman = (float)db()->query('SELECT COALESCE(SUM(amount_toman), 0) FROM remittances')->fetchColumn();
$sumAfghani = (float)db()->query('SELECT COALESCE(SUM(amount_afghani), 0) FROM remittances')->fetchColumn();

$currentRate = db()->query('
    SELECT rate, afn_to_toman, toman_to_afn, effective_date, created_at
    FROM exchange_rates
    WHERE is_active = 1
    ORDER BY id DESC
    LIMIT 1
')->fetch();

$latestRemittanceRows = [];
$stmtLatest = db()->prepare('
    SELECT id, user_id, agency, destination_country, sender, receiver, amount_toman, amount_afghani, status, created_at
    FROM remittances
    ORDER BY id DESC
    LIMIT 8
');
$stmtLatest->execute();
$latestRemittanceRows = $stmtLatest->fetchAll();

render_page_start('داشبورد', 'index');
?>

<style>
/* استایل‌های اختصاصی و ایزوله برای داشبورد جدید */
.modern-admin-panel {
    display: flex;
    flex-direction: column;
    gap: 24px;
    font-family: inherit;
}

.modern-admin-panel * {
    box-sizing: border-box;
}

.md-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.md-card {
    background: #ffffff !important;
    border-radius: 16px !important;
    padding: 24px !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
    border: 1px solid #f1f5f9 !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.md-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08) !important;
}

.md-stat-title {
    color: #64748b !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    margin-bottom: 12px !important;
    display: flex;
    align-items: center;
    gap: 8px;
}

.md-stat-value {
    color: #0f172a !important;
    font-size: 28px !important;
    font-weight: 800 !important;
    letter-spacing: -0.5px;
}

.md-rate-box {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    margin-top: 10px;
    border: 1px dashed #cbd5e1;
}

.md-rate-item {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}
.md-rate-item:last-child {
    border-bottom: none;
}

.md-rate-item strong {
    color: #0f172a;
}

.md-section-title {
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    margin: 0 0 20px 0 !important;
    padding-bottom: 12px;
    border-bottom: 2px solid #f1f5f9;
}

.md-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.md-btn {
    background: #ffffff !important;
    color: #3b82f6 !important;
    border: 1px solid #bfdbfe !important;
    padding: 10px 20px !important;
    border-radius: 10px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.md-btn:hover {
    background: #3b82f6 !important;
    color: #ffffff !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2) !important;
}

.md-table-wrapper {
    overflow-x: auto;
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

.md-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.md-table th {
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    text-align: right !important;
    padding: 16px !important;
    border-bottom: 2px solid #e2e8f0 !important;
}

.md-table td {
    padding: 16px !important;
    color: #334155 !important;
    font-size: 14px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    vertical-align: middle;
}

.md-table tr:hover td {
    background: #fcfcfd !important;
}

.md-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 50px;
    font-size: 12px;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
}
</style>
<style>
.modern-admin-panel .md-card {
    border-radius: 18px !important;
    border: 1px solid #dbe4ef !important;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.06) !important;
}
.modern-admin-panel .md-table-wrapper {
    border-radius: 16px !important;
    border: 1px solid #dbe4ef !important;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05) !important;
    overflow: hidden !important;
}
.modern-admin-panel .md-table th,
.modern-admin-panel .md-table td {
    padding: 16px 18px !important;
    border-bottom: 1px solid #edf2f7 !important;
}
.modern-admin-panel .md-table th {
    background: #f8fafc !important;
    color: #475569 !important;
    font-weight: 800 !important;
}
.modern-admin-panel .md-table tr:hover td {
    background: #fcfdff !important;
}
.modern-admin-panel .md-btn {
    border-radius: 12px !important;
    border: 1px solid #bfdbfe !important;
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
}
.modern-admin-panel .md-btn:hover {
    border-color: #3b82f6 !important;
}
</style>

<div class="modern-admin-panel">
    
    <!-- ردیف اول: آمارهای اصلی -->
    <div class="md-grid">
        <div class="md-card">
            <div class="md-stat-title">🏢 تعداد کل نمایندگی‌ها</div>
            <div class="md-stat-value"><?= e((string)$totalAgencies) ?></div>
        </div>
        <div class="md-card">
            <div class="md-stat-title">📈 تعداد نرخ‌های فعال</div>
            <div class="md-stat-value"><?= e((string)$totalActiveRates) ?></div>
        </div>
        <div class="md-card">
            <div class="md-stat-title">💸 تعداد کل حواله‌ها</div>
            <div class="md-stat-value"><?= e((string)$totalRemittances) ?></div>
        </div>
    </div>

    <!-- ردیف دوم: مجموع مبالغ و نرخ ارز -->
    <div class="md-grid">
        <div class="md-card">
            <div class="md-stat-title">🇮🇷 مجموع مبالغ (تومان)</div>
            <div class="md-stat-value" style="color: #059669 !important;">
                <?= e(number_format($sumToman, 2)) ?> <span style="font-size:14px;color:#64748b;">تومان</span>
            </div>
        </div>
        <div class="md-card">
            <div class="md-stat-title">🇦🇫 مجموع مبالغ (افغانی)</div>
            <div class="md-stat-value" style="color: #2563eb !important;">
                <?= e(number_format($sumAfghani, 2)) ?> <span style="font-size:14px;color:#64748b;">افغانی</span>
            </div>
        </div>
        
        <div class="md-card">
            <div class="md-stat-title">💱 وضعیت نرخ فعلی</div>
            <?php if ($currentRate): ?>
                <div class="md-rate-box">
                    <div class="md-rate-item"><span>Rate:</span> <strong><?= e((string)$currentRate['rate']) ?></strong></div>
                    <div class="md-rate-item"><span>۱ افغانی:</span> <strong><?= e((string)$currentRate['afn_to_toman']) ?> تومان</strong></div>
                    <div class="md-rate-item"><span>۱ تومان:</span> <strong><?= e((string)$currentRate['toman_to_afn']) ?> افغانی</strong></div>
                    <div class="md-rate-item"><span>تاریخ موثر:</span> <strong><?= e((string)$currentRate['effective_date']) ?></strong></div>
                    <div class="md-rate-item"><span>ثبت:</span> <strong><?= e(to_jalali_datetime((string)$currentRate['created_at'])) ?></strong></div>
                </div>
            <?php else: ?>
                <div class="md-rate-box" style="text-align: center; color: #94a3b8;">هیچ نرخ فعالی ثبت نشده است.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- میانبرها -->
    <div class="md-card">
        <h3 class="md-section-title">⚡ دسترسی سریع</h3>
        <div class="md-actions">
            <a class="md-btn" href="agencies.php">مدیریت نمایندگی‌ها</a>
            <a class="md-btn" href="exchange_rates.php">مدیریت نرخ ارز</a>
            <a class="md-btn" href="remittances.php">مدیریت حواله‌ها</a>
            <a class="md-btn" href="users.php">مدیریت کاربران</a>
            <a class="md-btn" href="activity_logs.php">گزارش فعالیت</a>
        </div>
    </div>

    <!-- جدول آخرین حواله ها -->
    <div class="md-card">
        <h3 class="md-section-title">🕒 آخرین حواله‌های ثبت شده</h3>
        <div class="md-table-wrapper">
            <table class="md-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کاربر</th>
                        <th>نمایندگی</th>
                        <th>کشور مقصد</th>
                        <th>فرستنده</th>
                        <th>گیرنده</th>
                        <th>تومان</th>
                        <th>افغانی</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($latestRemittanceRows)): ?>
                    <tr><td colspan="10" style="text-align:center; padding: 20px !important; color:#94a3b8;">رکوردی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($latestRemittanceRows as $row): ?>
                        <tr>
                            <td style="font-weight:bold; color:#0f172a !important;"><?= e((string)$row['id']) ?></td>
                            <td><?= e((string)$row['user_id']) ?></td>
                            <td><?= e($row['agency']) ?></td>
                            <td><?= e(remittance_destination_countries()[(string)($row['destination_country'] ?? '')] ?? '—') ?></td>
                            <td><?= e($row['sender']) ?></td>
                            <td><?= e($row['receiver']) ?></td>
                            <td style="color:#059669 !important; font-weight:600;"><?= e(number_format((float)$row['amount_toman'])) ?></td>
                            <td style="color:#2563eb !important; font-weight:600;"><?= e(number_format((float)$row['amount_afghani'])) ?></td>
                            <td><span class="md-badge"><?= e(status_fa((string)$row['status'])) ?></span></td>
                            <td><span dir="ltr" style="font-size:13px; color:#64748b;"><?= e(jalali_date((string)$row['created_at'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php render_page_end(); ?>
