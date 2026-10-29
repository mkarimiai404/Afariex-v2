export type TopupReceiptStatus = 'success' | 'failed' | 'pending';

export type TopupReceiptModel = {
  transactionId: string;
  mobile: string;
  operator: string;
  operatorLabel: string;
  chargeType?: 'normal' | 'amazing';
  amount: number;
  commissionAmount?: number;
  totalAmount?: number;
  status: TopupReceiptStatus;
  clientRequestId?: string;
  refCode?: string;
  trackingCode?: string;
  orderId?: string;
  message?: string;
  createdAt?: string;
};

export const topupOperatorLabel = (operator: string) => ({ MCI: 'همراه اول', MTN: 'ایرانسل', RTL: 'رایتل' }[operator] || operator);

export const topupStatusLabel = (status: TopupReceiptStatus) => ({ success: 'موفق', failed: 'ناموفق', pending: 'در انتظار' }[status]);

export const formatTopupAmount = (amount: number | string) => {
  const value = Number(amount) || 0;
  return value.toLocaleString('fa-IR') + ' تومان';
};

export const formatTopupMobile = (mobile: string) => mobile.replace(/(09\d{3})(\d{3})(\d{4})/, '$1 $2 $3');

export const buildTopupReceiptText = (receipt: TopupReceiptModel) => [
  'رسید خرید شارژ آفاریکس',
  `وضعیت: ${topupStatusLabel(receipt.status)}`,
  `شماره موبایل: ${formatTopupMobile(receipt.mobile)}`,
  `اپراتور: ${receipt.operatorLabel}`,
  `مبلغ: ${formatTopupAmount(receipt.amount)}`,
  (receipt.commissionAmount ?? 0) > 0 ? `کارمزد: ${formatTopupAmount(receipt.commissionAmount ?? 0)}` : '',
  (receipt.commissionAmount ?? 0) > 0 ? `مبلغ نهایی کسرشده: ${formatTopupAmount(receipt.totalAmount ?? receipt.amount)}` : '',
  receipt.refCode ? `کد مرجع: ${receipt.refCode}` : '',
  receipt.trackingCode ? `کد پیگیری: ${receipt.trackingCode}` : '',
  receipt.orderId ? `شماره سفارش: ${receipt.orderId}` : '',
  receipt.message || '',
].filter(Boolean).join('\n');
