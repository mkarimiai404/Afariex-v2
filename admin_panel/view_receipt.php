<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/remittance_receipt_helpers.php';
require_once __DIR__ . '/balance_view_helpers.php';
require_once __DIR__ . '/../API/includes/remittance_destination_countries.php';

require_login();
require_permission('view');

$id = (int)($_GET['id'] ?? 0);
$remittance = null;
if ($id > 0) {
    $stmt = db()->prepare(
        'SELECT r.id, r.user_id, r.agency, r.destination_country, r.sender, r.receiver, r.amount_afghani, r.status, r.created_at, u.balance AS user_balance,
            (SELECT a.address FROM agencies a WHERE BINARY a.name = BINARY r.agency ORDER BY a.id DESC LIMIT 1) AS agency_address
         FROM remittances r
         LEFT JOIN users u ON u.id = r.user_id
         WHERE r.id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $remittance = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$remittance) {
    render_page_start('نمایش رسید حواله', 'remittances');
    echo '<div class="alert error">رسید یافت نشد.</div>';
    render_page_end();
    exit;
}

$trackingNumber = remittance_customer_tracking_number((int)$remittance['id']);
$createdAt = to_jalali_datetime((string)$remittance['created_at']);
$amountAfghani = (float)$remittance['amount_afghani'];
$destination = trim((string)($remittance['agency_address'] ?? ''));
if ($destination === '') $destination = trim((string)$remittance['agency']);
$destinationCountry = remittance_destination_countries()[(string)($remittance['destination_country'] ?? '')] ?? '';
$statusText = remittance_customer_status((string)$remittance['status']);

render_page_start('تأییدیه حواله', 'remittances');
?>
<style>
@font-face{font-family:'ReceiptVazirmatn';src:url('fonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;font-style:normal;font-display:swap}
@font-face{font-family:'ReceiptVazirmatn';src:url('fonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;font-style:normal;font-display:swap}
@page{size:A4;margin:0}
.receipt-actions{max-width:210mm;margin:0 auto 14px;display:flex;justify-content:flex-end}
.admin-current-balance{max-width:210mm;margin:0 auto 14px;padding:16px 18px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:16px;font-weight:700;display:flex;justify-content:space-between;gap:16px;align-items:center}.admin-current-balance strong{font-size:20px;color:#047857;white-space:nowrap;direction:rtl}
.remittance-receipt{width:210mm;min-height:297mm;margin:0 auto;background:#fff;color:#17324d;padding:18mm 17mm 15mm;position:relative;direction:rtl;font-family:'ReceiptVazirmatn',Tahoma,sans-serif;box-shadow:0 18px 45px rgba(15,23,42,.10)}
.remittance-receipt *{box-sizing:border-box;font-family:'ReceiptVazirmatn',Tahoma,sans-serif}.receipt-accent{height:6px;background:#0ebf92;border-radius:8px;margin-bottom:15mm}.receipt-brand{text-align:center}.receipt-logo{width:72px;height:72px;object-fit:contain;display:block;margin:0 auto 5mm}.receipt-brand-name{font-size:25px;font-weight:700;color:#0b8f72}.receipt-brand-subtitle{margin-top:2mm;color:#718096;font-size:11px}
.confirmation-title{margin:11mm 0 8mm;padding:6mm;background:#f0fdfa;border:1px solid #99f6e4;border-radius:14px;text-align:center;font-size:19px;font-weight:700;color:#0f5e50}.tracking-number{color:#0b8f72;direction:ltr;unicode-bidi:embed;display:inline-block}.receipt-details{border:1px solid #dce7ef;border-radius:14px;overflow:hidden}.receipt-row{display:flex;border-bottom:1px solid #e8eff4;min-height:15mm;align-items:center}.receipt-row:last-child{border-bottom:0}.receipt-label{width:28%;padding:4mm 5mm;color:#64748b;font-size:12px}.receipt-value{width:72%;padding:4mm 5mm;font-size:14px;font-weight:700;color:#17324d;overflow-wrap:anywhere}
.receipt-amount{margin-top:8mm;padding:8mm 7mm;text-align:center;background:#0f766e;border-radius:16px;color:#fff}.amount-label{font-size:12px;opacity:.82}.amount-value{margin-top:3mm;font-size:24px;font-weight:700;line-height:1.8}.amount-words{font-size:17px}.amount-currency{font-size:15px}.receipt-status-row{margin-top:8mm;display:flex;align-items:center;justify-content:space-between;padding:5mm 6mm;border:1px solid #dce7ef;border-radius:13px}.receipt-status{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:2.5mm 6mm;font-weight:700}.receipt-notice{margin-top:9mm;padding:6mm;background:#f8fafc;border-right:4px solid #0ebf92;border-radius:10px;font-size:13px;line-height:2.05;color:#334155}.receipt-signature{position:absolute;bottom:13mm;left:17mm;right:17mm;text-align:center;border-top:1px solid #dce7ef;padding-top:5mm;color:#0b8f72;font-size:13px;font-weight:700;direction:ltr}
@media(max-width:900px){.remittance-receipt{width:100%;min-height:auto;padding:28px 22px}.receipt-actions,.admin-current-balance{max-width:none}.admin-current-balance{align-items:flex-start;flex-direction:column}.receipt-signature{position:static;margin-top:44px}.amount-value{font-size:20px}}
@media print{html,body{background:#fff!important}.sidebar,.topbar,.receipt-actions,.admin-current-balance{display:none!important}.app-shell,.content{display:block!important;height:auto!important;overflow:visible!important;padding:0!important;margin:0!important;background:#fff!important}.remittance-receipt{width:210mm!important;min-height:297mm!important;margin:0!important;padding:18mm 17mm 15mm!important;box-shadow:none!important;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>

<div class="receipt-actions"><button type="button" class="btn btn-primary" onclick="window.print()">چاپ یا ذخیره PDF</button></div>
<aside class="admin-current-balance"><span>موجودی فعلی کاربر</span><strong><?= e(admin_balance_with_unit($remittance['user_balance'] ?? null)) ?></strong></aside>
<article class="remittance-receipt" lang="fa" dir="rtl">
  <div class="receipt-accent"></div>
  <header class="receipt-brand">
    <img class="receipt-logo" src="../assets/images/logo.png" alt="Afariex">
    <div class="receipt-brand-name">صرافی آفارایکس</div>
    <div class="receipt-brand-subtitle">تأییدیه رسمی ثبت حواله</div>
  </header>

  <h1 class="confirmation-title">تأییدیه شماره حواله: <span class="tracking-number"><?= e(remittance_persian_digits($trackingNumber)) ?></span></h1>

  <section class="receipt-details">
    <div class="receipt-row"><div class="receipt-label">تاریخ</div><div class="receipt-value"><?= e($createdAt) ?></div></div>
    <div class="receipt-row"><div class="receipt-label">فرستنده</div><div class="receipt-value"><?= e((string)$remittance['sender']) ?></div></div>
    <div class="receipt-row"><div class="receipt-label">گیرنده</div><div class="receipt-value"><?= e((string)$remittance['receiver']) ?></div></div>
    <?php if ($destinationCountry !== ''): ?><div class="receipt-row"><div class="receipt-label">کشور مقصد</div><div class="receipt-value"><?= e($destinationCountry) ?></div></div><?php endif; ?>
    <div class="receipt-row"><div class="receipt-label">مقصد</div><div class="receipt-value"><?= e($destination) ?></div></div>
  </section>

  <section class="receipt-amount">
    <div class="amount-label">مبلغ حواله</div>
    <div class="amount-value"><?= e(remittance_formatted_amount($amountAfghani)) ?> <span class="amount-words">«<?= e(remittance_amount_words($amountAfghani)) ?>»</span> <span class="amount-currency">افغانی</span></div>
  </section>

  <section class="receipt-status-row"><span>وضعیت حواله</span><span class="receipt-status"><?= e($statusText) ?></span></section>
  <section class="receipt-notice">مشتری گرامی، حواله شما با موفقیت ثبت شد.<br>لطفاً هنگام دریافت وجه، اصل تذکره یا کارت شناسایی معتبر به همراه داشته باشید.</section>
  <footer class="receipt-signature">AfaraX Exchange</footer>
</article>

<?php render_page_end(); ?>
