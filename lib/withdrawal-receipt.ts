import { formatPersianAmount, toPersianDigits } from '@/lib/remittance-receipt';

export type WithdrawalReceiptState = 'completed' | 'pending' | 'rejected' | 'cancelled';

export type WithdrawalReceiptModel = {
  id: number;
  status: string;
  receiptState: WithdrawalReceiptState;
  statusLabel: string;
  message: string;
  amountRial: number;
  amountToman: number;
  feeAmountToman: number;
  totalChargedToman: number;
  feeType: string;
  feeValue: string;
  currency: string;
  sourceName: string;
  sourceAccountMasked: string | null;
  destinationCardMasked: string | null;
  recipientName: string | null;
  trackingNumber: string | null;
  receiptTime: string | null;
  supportPhone: string | null;
  website: string;
};

export type WithdrawalReceiptPresentation = WithdrawalReceiptModel & {
  displayAmount: string;
  displayAmountToman: string;
  displayFeeAmount: string;
  displayTotalCharged: string;
  displayFeeLabel: string;
  displayTime: string;
  displayTrackingNumber: string;
  displaySourceAccount: string;
  displayDestinationCard: string;
  displayRecipientName: string;
  stateTone: 'success' | 'pending' | 'danger';
  notice: string[];
  brand: string;
};

export type WithdrawalReceiptData = WithdrawalReceiptModel & {
  fontRegularUri: string;
  fontBoldUri: string;
  logoUri: string;
};

const escapeHtml = (value: string) => value.replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character] || character));

const safeAssetUri = (value: string) => value.replace(/["'()\\\s]/g, (character) => encodeURIComponent(character));

export const formatWithdrawalDate = (value: string | null) => {
  if (!value) return '';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return toPersianDigits(value);
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
};

export const normalizeWithdrawalReceipt = (data: any): WithdrawalReceiptModel => ({
  id: Number(data?.id || 0),
  status: String(data?.status || ''),
  receiptState: ['completed', 'pending', 'rejected', 'cancelled'].includes(String(data?.receipt_state))
    ? data.receipt_state
    : 'pending',
  statusLabel: String(data?.status_label || 'در انتظار انتقال'),
  message: String(data?.message || 'درخواست برداشت در انتظار انتقال است.'),
  amountRial: Number(data?.amount_rial || 0),
  amountToman: Number(data?.amount_toman || 0),
  feeAmountToman: Number(data?.fee?.fee_amount_toman || 0),
  totalChargedToman: Number(data?.fee?.total_charged_toman || data?.amount_toman || 0),
  feeType: String(data?.fee?.fee_type || 'fixed'),
  feeValue: String(data?.fee?.fee_value || '0'),
  currency: String(data?.currency || 'ریال'),
  sourceName: String(data?.source?.name || 'حساب آفاریکس'),
  sourceAccountMasked: data?.source?.account_masked ? String(data.source.account_masked) : null,
  destinationCardMasked: data?.destination?.card_masked ? String(data.destination.card_masked) : null,
  recipientName: data?.destination?.cardholder_name ? String(data.destination.cardholder_name) : null,
  trackingNumber: data?.tracking_number ? String(data.tracking_number) : null,
  receiptTime: data?.receipt_time ? String(data.receipt_time) : null,
  supportPhone: data?.support_phone ? String(data.support_phone) : null,
  website: String(data?.website || 'www.afirex.ir'),
});

export const formatWithdrawalReceipt = (receipt: WithdrawalReceiptModel): WithdrawalReceiptPresentation => {
  const stateTone = receipt.receiptState === 'completed'
    ? 'success'
    : receipt.receiptState === 'pending'
      ? 'pending'
      : 'danger';

  return {
    ...receipt,
    displayAmount: `${formatPersianAmount(receipt.amountRial)} ${receipt.currency}`,
    displayAmountToman: `${formatPersianAmount(receipt.amountToman)} تومان`,
    displayFeeAmount: `${formatPersianAmount(receipt.feeAmountToman)} تومان`,
    displayTotalCharged: `${formatPersianAmount(receipt.totalChargedToman)} تومان`,
    displayFeeLabel: receipt.feeType === 'percentage' && Number(receipt.feeValue || 0) > 0
      ? `کارمزد (${toPersianDigits(receipt.feeValue.replace(/\.?0+$/, ''))}٪)`
      : 'کارمزد',
    displayTime: formatWithdrawalDate(receipt.receiptTime),
    displayTrackingNumber: receipt.trackingNumber ? toPersianDigits(receipt.trackingNumber) : 'ثبت نشده',
    displaySourceAccount: receipt.sourceAccountMasked ? toPersianDigits(receipt.sourceAccountMasked) : 'ثبت نشده',
    displayDestinationCard: receipt.destinationCardMasked ? toPersianDigits(receipt.destinationCardMasked) : 'ثبت نشده',
    displayRecipientName: receipt.recipientName || 'ثبت نشده',
    stateTone,
    notice: [
      'این رسید بر اساس اطلاعات ثبت‌شده در سامانه آفاریکس صادر شده است.',
      'در صورت بروز مشکل با پشتیبانی تماس بگیرید.',
    ],
    brand: 'آفاریکس',
  };
};

export const buildWithdrawalReceiptText = (receipt: WithdrawalReceiptModel) => {
  const view = formatWithdrawalReceipt(receipt);
  return [
    view.brand,
    'رسید انتقال وجه',
    '',
    view.message,
    `انتقال از: ${view.displaySourceAccount}`,
    `نام صاحب حساب: ${view.sourceName}`,
    `به مقصد: ${view.displayDestinationCard}`,
    `گیرنده: ${view.displayRecipientName}`,
    `مبلغ اصلی: ${view.displayAmountToman}`,
    `${view.displayFeeLabel}: ${view.displayFeeAmount}`,
    `مبلغ نهایی کسر شده: ${view.displayTotalCharged}`,
    `مبلغ رسید: ${view.displayAmount}`,
    `شماره پیگیری: ${view.displayTrackingNumber}`,
    `زمان: ${view.displayTime || 'ثبت نشده'}`,
    `وضعیت: ${view.statusLabel}`,
    '',
    ...view.notice,
    view.supportPhone ? `پشتیبانی: ${toPersianDigits(view.supportPhone)}` : null,
    view.website,
  ].filter(Boolean).join('\n');
};

export const buildWithdrawalReceiptHtml = (data: WithdrawalReceiptData) => {
  const view = formatWithdrawalReceipt(data);
  const success = view.stateTone === 'success';
  const pending = view.stateTone === 'pending';
  const toneColor = success ? '#047857' : pending ? '#c2410c' : '#b91c1c';
  const toneBackground = success ? '#ecfdf5' : pending ? '#fff7ed' : '#fef2f2';
  const toneBorder = success ? '#a7f3d0' : pending ? '#fed7aa' : '#fecaca';

  return `<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<style>
@font-face{font-family:Vazirmatn;src:url("${safeAssetUri(data.fontRegularUri)}") format("truetype");font-weight:400}
@font-face{font-family:Vazirmatn;src:url("${safeAssetUri(data.fontBoldUri)}") format("truetype");font-weight:700}
@page{size:A4;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#17324d;font-family:Vazirmatn,Tahoma,sans-serif;direction:rtl}
.page{width:210mm;min-height:297mm;padding:18mm 17mm 15mm;background:#fff;position:relative}.top-line{height:6px;background:#0ebf92;border-radius:8px;margin-bottom:13mm}
.brand{text-align:center}.logo{width:72px;height:72px;object-fit:contain;margin:0 auto 5mm}.brand-name{font-size:25px;font-weight:700;color:#0b8f72}.brand-sub{margin-top:2mm;color:#718096;font-size:11px}
.result{margin:10mm 0 7mm;padding:6mm;background:${toneBackground};border:1px solid ${toneBorder};border-radius:14px;text-align:center}.result-icon{width:13mm;height:13mm;border-radius:50%;margin:0 auto 3mm;background:${toneColor};color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700}.title{font-size:20px;font-weight:700;color:#0f5e50}.message{margin-top:2mm;color:${toneColor};font-size:13px;font-weight:700}
.details{border:1px solid #dce7ef;border-radius:14px;overflow:hidden}.row{display:flex;border-bottom:1px solid #e8eff4;min-height:14mm;align-items:center}.row:last-child{border-bottom:0}.label{width:30%;padding:4mm 5mm;color:#64748b;font-size:12px}.value{width:70%;padding:4mm 5mm;font-size:14px;font-weight:700;color:#17324d;overflow-wrap:anywhere}.ltr{direction:ltr;unicode-bidi:embed;display:inline-block}
.amount{margin-top:8mm;padding:8mm 7mm;text-align:center;background:#0f766e;border-radius:16px;color:#fff}.amount-label{font-size:12px;opacity:.82}.amount-value{margin-top:3mm;font-size:25px;font-weight:700}
.status{margin-top:8mm;display:flex;align-items:center;justify-content:space-between;padding:5mm 6mm;border:1px solid #dce7ef;border-radius:13px}.pill{background:${toneBackground};color:${toneColor};border:1px solid ${toneBorder};border-radius:999px;padding:2.5mm 6mm;font-weight:700}
.notice{margin-top:9mm;padding:6mm;background:#f8fafc;border-right:4px solid #0ebf92;border-radius:10px;font-size:13px;line-height:2.05;color:#334155}.footer{position:absolute;bottom:13mm;left:17mm;right:17mm;text-align:center;border-top:1px solid #dce7ef;padding-top:5mm;color:#0b8f72;font-size:13px;font-weight:700}.footer .site{direction:ltr;unicode-bidi:embed}
</style></head><body><main class="page"><div class="top-line"></div><header class="brand"><img class="logo" src="${escapeHtml(data.logoUri)}" alt="AFIREX"><div class="brand-name">آفاریکس</div><div class="brand-sub">رسید رسمی انتقال وجه</div></header>
<section class="result"><div class="result-icon">${success ? '✓' : pending ? '!' : '×'}</div><div class="title">رسید انتقال وجه</div><div class="message">${escapeHtml(view.message)}</div></section>
<section class="details"><div class="row"><div class="label">انتقال از</div><div class="value"><span class="ltr">${escapeHtml(view.displaySourceAccount)}</span></div></div><div class="row"><div class="label">نام صاحب حساب</div><div class="value">${escapeHtml(view.sourceName)}</div></div><div class="row"><div class="label">به مقصد</div><div class="value"><span class="ltr">${escapeHtml(view.displayDestinationCard)}</span></div></div><div class="row"><div class="label">گیرنده</div><div class="value">${escapeHtml(view.displayRecipientName)}</div></div><div class="row"><div class="label">شماره پیگیری</div><div class="value"><span class="ltr">${escapeHtml(view.displayTrackingNumber)}</span></div></div><div class="row"><div class="label">زمان</div><div class="value">${escapeHtml(view.displayTime || 'ثبت نشده')}</div></div></section>
<section class="details" style="margin-top:8mm"><div class="row"><div class="label">مبلغ اصلی</div><div class="value">${escapeHtml(view.displayAmountToman)}</div></div><div class="row"><div class="label">${escapeHtml(view.displayFeeLabel)}</div><div class="value">${escapeHtml(view.displayFeeAmount)}</div></div><div class="row"><div class="label">مبلغ نهایی کسر شده</div><div class="value">${escapeHtml(view.displayTotalCharged)}</div></div></section>
<section class="amount"><div class="amount-label">مبلغ رسید</div><div class="amount-value">${escapeHtml(view.displayAmount)}</div></section>
<section class="status"><span>وضعیت</span><span class="pill">${escapeHtml(view.statusLabel)}</span></section>
<section class="notice">${view.notice.map(escapeHtml).join('<br>')}</section>
<footer class="footer">${view.supportPhone ? `پشتیبانی: ${escapeHtml(toPersianDigits(view.supportPhone))}<br>` : ''}<span class="site">${escapeHtml(view.website)}</span></footer></main></body></html>`;
};
