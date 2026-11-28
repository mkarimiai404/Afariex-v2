export const toPersianDigits = (value: string | number) => String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);

export const formatPersianAmount = (input: number | string) => {
  const numeric = Number(input) || 0;
  return toPersianDigits(new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(numeric));
};

export const formatPersianCurrencyAmount = (input: number | string, currency: string, registeredPrecision?: number) => {
  const match = String(input).trim().match(/^(-?)(\d+)(?:\.(\d+))?$/);
  if (!match) return '۰';
  const precision = 0;
  const integer = match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const meaningfulFraction = (match[3] || '').replace(/0+$/, '');
  const fractionalDigits = 0;
  const fraction = fractionalDigits > 0
    ? (match[3] || '').padEnd(fractionalDigits, '0').slice(0, fractionalDigits)
    : '';
  const formatted = `${match[1]}${integer}${fractionalDigits > 0 ? `.${fraction}` : ''}`;
  return toPersianDigits(formatted);
};

export const customerRemittanceStatus = (status: string) => {
  const normalized = status.trim().toLowerCase();
  // The remittance workflow stores new requests as pending; only an explicit
  // ready state means the recipient can collect the money.
  if (normalized === 'ready') return 'آماده پرداخت';
  if (normalized === 'approved') return 'تایید شده';
  if (normalized === 'paid') return 'پرداخت شده';
  if (['rejected', 'cancelled', 'canceled'].includes(normalized)) return 'نیازمند پیگیری';
  return 'در حال پردازش';
};

export const customerTrackingNumber = (value: string | number) => {
  const raw = String(value).trim();
  return /^\d+$/.test(raw) ? `AFR-${raw.padStart(6, '0')}` : raw;
};

const escapeHtml = (value: string) => value.replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character] || character));

const safeAssetUri = (value: string) => value.replace(/["'()\\\s]/g, (character) => encodeURIComponent(character));

export type RemittanceReceiptModel = {
  trackingNumber: string;
  date: string;
  sender: string;
  receiver: string;
  amountAfghani: number | string;
  destinationCurrencyPrecision?: number;
  amountToman?: number | string;
  sourceCurrency?: string;
  destinationCurrency?: string;
  commissionPercentage?: number | string;
  exchangeRate?: number | string;
  feeAmount?: number | string;
  totalCharged?: number | string;
  feeType?: string;
  feeValue?: number | string;
  destination: string;
  destinationCountry?: string;
  status: string;
};

export type RemittanceReceiptPresentation = RemittanceReceiptModel & {
  tracking: string;
  amount: string;
  originalAmountToman: string;
  feeAmountToman: string;
  totalChargedToman: string;
  sourceCurrencyLabel: string;
  destinationCurrencyLabel: string;
  exchangeCommissionLabel: string;
  exchangeRateLabel: string;
  feeLabel: string;
  customerStatus: string;
  brand: string;
  notice: string[];
  footer: string;
};

export type RemittanceReceiptData = RemittanceReceiptModel & {
  fontRegularUri: string;
  fontBoldUri: string;
  logoUri: string;
};

export const formatRemittanceReceipt = (data: RemittanceReceiptModel): RemittanceReceiptPresentation => ({
  ...data,
  tracking: toPersianDigits(customerTrackingNumber(data.trackingNumber)),
  amount: formatPersianCurrencyAmount(data.amountAfghani, data.destinationCurrency || 'AFN', data.destinationCurrencyPrecision),
  originalAmountToman: formatPersianAmount(data.amountToman ?? 0),
  feeAmountToman: formatPersianAmount(data.feeAmount ?? 0),
  totalChargedToman: formatPersianAmount(data.totalCharged ?? data.amountToman ?? 0),
  sourceCurrencyLabel: data.sourceCurrency === 'IRT' || !data.sourceCurrency ? 'تومان' : data.sourceCurrency,
  destinationCurrencyLabel: data.destinationCurrency === 'AFN' || !data.destinationCurrency ? 'افغانی' : data.destinationCurrency,
  exchangeCommissionLabel: `${toPersianDigits(String(data.commissionPercentage ?? 0))}٪ از مبلغ ارز مقصد`,
  exchangeRateLabel: `۱ ${data.sourceCurrency || 'IRT'} = ${toPersianDigits(String(data.exchangeRate ?? ''))} ${data.destinationCurrency || 'AFN'}`,
  feeLabel: data.feeType === 'percentage' && Number(data.feeValue || 0) > 0
    ? `کارمزد (${toPersianDigits(String(data.feeValue).replace(/\.?0+$/, ''))}٪)`
    : 'کارمزد',
  customerStatus: customerRemittanceStatus(data.status),
  brand: 'صرافی آفارایکس',
  notice: [
    'مشتری گرامی، حواله شما با موفقیت ثبت شد.',
    'لطفاً هنگام دریافت وجه، اصل تذکره یا کارت شناسایی معتبر به همراه داشته باشید.',
  ],
  footer: 'AfaraX Exchange',
});

export const buildRemittanceReceiptText = (data: RemittanceReceiptModel) => {
  const receipt = formatRemittanceReceipt(data);
  return [receipt.brand, '', `✅ تأییدیه شماره حواله: ${receipt.tracking}`, ` تاریخ: ${receipt.date}`, ` فرستنده: ${receipt.sender}`, ` گیرنده: ${receipt.receiver}`, ...(receipt.destinationCountry ? [` کشور مقصد: ${receipt.destinationCountry}`] : []), ...(receipt.exchangeRate ? [`نرخ تبدیل: ${receipt.exchangeRateLabel}`] : []), ` مبلغ حواله: ${receipt.originalAmountToman} ${receipt.sourceCurrencyLabel}`, `${receipt.feeLabel}: ${receipt.feeAmountToman} ${receipt.sourceCurrencyLabel}`, ...(Number(receipt.commissionPercentage || 0) > 0 ? [`کارمزد تبدیل: ${receipt.exchangeCommissionLabel}`] : []), ` مبلغ نهایی کسر شده: ${receipt.totalChargedToman} ${receipt.sourceCurrencyLabel}`, ` مبلغ دریافتی: ${receipt.amount} ${receipt.destinationCurrencyLabel}`, ` مقصد: ${receipt.destination}`, `وضعیت: ${receipt.customerStatus}`, '', ...receipt.notice, '', receipt.footer].join('\n');
};

export const buildRemittanceReceiptHtml = (data: RemittanceReceiptData) => {
  const receipt = formatRemittanceReceipt(data);
  return `<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<style>
@font-face{font-family:Vazirmatn;src:url("${safeAssetUri(data.fontRegularUri)}") format("truetype");font-weight:400}
@font-face{font-family:Vazirmatn;src:url("${safeAssetUri(data.fontBoldUri)}") format("truetype");font-weight:700}
@page{size:A4;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#17324d;font-family:Vazirmatn,Tahoma,sans-serif;direction:rtl}
.page{width:210mm;min-height:297mm;padding:18mm 17mm 15mm;background:#fff;position:relative}.top-line{height:6px;background:#06EFF4;border-radius:8px;margin-bottom:15mm}
.header{text-align:center}.logo{width:72px;height:72px;object-fit:contain;margin:0 auto 5mm}.brand{font-size:25px;font-weight:700;color:#06EFF4}.subtitle{margin-top:2mm;color:#718096;font-size:11px}
.confirmation{margin:11mm 0 8mm;padding:6mm;background:#f0fdfa;border:1px solid #99f6e4;border-radius:14px;text-align:center;font-size:19px;font-weight:700;color:#0f5e50}.tracking{color:#06EFF4;direction:ltr;unicode-bidi:embed;display:inline-block}
.details{border:1px solid #dce7ef;border-radius:14px;overflow:hidden}.row{display:flex;border-bottom:1px solid #e8eff4;min-height:15mm;align-items:center}.row:last-child{border-bottom:0}.label{width:28%;padding:4mm 5mm;color:#64748b;font-size:12px}.value{width:72%;padding:4mm 5mm;font-size:14px;font-weight:700;color:#17324d}
.amount-box{margin-top:8mm;padding:8mm 7mm;text-align:center;background:#043D41;border-radius:16px;color:#fff}.amount-label{font-size:12px;opacity:.82}.amount-value{margin-top:3mm;font-size:24px;font-weight:700;line-height:1.8;direction:ltr;unicode-bidi:isolate}.currency{font-size:15px}
.status-box{margin-top:8mm;display:flex;align-items:center;justify-content:space-between;padding:5mm 6mm;border:1px solid #dce7ef;border-radius:13px}.status-pill{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:2.5mm 6mm;font-weight:700}
.notice{margin-top:9mm;padding:6mm;background:#f8fafc;border-right:4px solid #06EFF4;border-radius:10px;font-size:13px;line-height:2.05;color:#334155}.footer{position:absolute;bottom:13mm;left:17mm;right:17mm;text-align:center;border-top:1px solid #dce7ef;padding-top:5mm;color:#06EFF4;font-size:13px;font-weight:700;direction:ltr}
</style></head><body><main class="page"><div class="top-line"></div><header class="header"><img class="logo" src="${escapeHtml(data.logoUri)}" alt="Afariex"><div class="brand">صرافی آفارایکس</div><div class="subtitle">تأییدیه رسمی ثبت حواله</div></header>
<section class="confirmation">تأییدیه شماره حواله: <span class="tracking">${escapeHtml(receipt.tracking)}</span></section>
<section class="details"><div class="row"><div class="label">تاریخ</div><div class="value">${escapeHtml(data.date)}</div></div><div class="row"><div class="label">فرستنده</div><div class="value">${escapeHtml(data.sender)}</div></div><div class="row"><div class="label">گیرنده</div><div class="value">${escapeHtml(data.receiver)}</div></div>${data.destinationCountry ? `<div class="row"><div class="label">کشور مقصد</div><div class="value">${escapeHtml(data.destinationCountry)}</div></div>` : ''}${data.exchangeRate ? `<div class="row"><div class="label">نرخ تبدیل</div><div class="value">${escapeHtml(receipt.exchangeRateLabel)}</div></div>` : ''}<div class="row"><div class="label">نمایندگی / مقصد</div><div class="value">${escapeHtml(data.destination)}</div></div></section>
<section class="details" style="margin-top:8mm"><div class="row"><div class="label">مبلغ حواله</div><div class="value">${receipt.originalAmountToman} ${escapeHtml(receipt.sourceCurrencyLabel)}</div></div><div class="row"><div class="label">${escapeHtml(receipt.feeLabel)}</div><div class="value">${receipt.feeAmountToman} ${escapeHtml(receipt.sourceCurrencyLabel)}</div></div>${Number(receipt.commissionPercentage || 0) > 0 ? `<div class="row"><div class="label">کارمزد تبدیل</div><div class="value">${escapeHtml(receipt.exchangeCommissionLabel)}</div></div>` : ''}<div class="row"><div class="label">مبلغ نهایی کسر شده</div><div class="value">${receipt.totalChargedToman} ${escapeHtml(receipt.sourceCurrencyLabel)}</div></div></section>
<section class="amount-box"><div class="amount-label">مبلغ دریافتی حواله</div><div class="amount-value" dir="ltr"><bdi>${receipt.amount} ${escapeHtml(receipt.destinationCurrencyLabel)}</bdi></div></section>
<section class="status-box"><span>وضعیت حواله</span><span class="status-pill">${receipt.customerStatus}</span></section>
<section class="notice">${receipt.notice.map(escapeHtml).join('<br>')}</section>
<footer class="footer">${receipt.footer}</footer></main></body></html>`;
};
