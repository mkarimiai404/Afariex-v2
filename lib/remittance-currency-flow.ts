export const WHATSAPP_SUPPORT_URL = 'https://wa.me/989194998020';

/**
 * `exchange_rate` is normalized by the API as destination currency units per
 * one source unit. Amounts are rounded once to the destination's two decimals.
 */
export const calculateDestinationAmount = (
  amountSource: string | number,
  rateValue: string | number,
  commissionValue: string | number = 0,
) => {
  const normalizedAmount = String(amountSource).replace(/[,_\s]/g, '');
  const amountParts = normalizedAmount.match(/^(\d+)(?:\.(\d{1,2}))?$/);
  const rateText = String(rateValue).trim().toLowerCase();
  const rateParts = rateText.match(/^(\d+)(?:\.(\d*))?(?:e([+-]?\d+))?$/);
  const commissionParts = String(commissionValue).trim().match(/^(\d+)(?:\.(\d*))?$/);
  if (!amountParts || !rateParts || !commissionParts) return '';

  try {
    const amountScale = (amountParts[2] || '').length;
    const amountMinor = BigInt(`${amountParts[1]}${amountParts[2] || ''}`);
    let rateDigits = BigInt(`${rateParts[1]}${rateParts[2] || ''}`);
    let rateScale = (rateParts[2] || '').length - Number(rateParts[3] || 0);
    if (rateScale < 0) {
      rateDigits *= 10n ** BigInt(-rateScale);
      rateScale = 0;
    }
    while (rateScale > 0 && rateDigits % 10n === 0n) {
      rateDigits /= 10n;
      rateScale -= 1;
    }
    const commissionDigits = BigInt(`${commissionParts[1]}${commissionParts[2] || ''}`);
    const commissionScale = (commissionParts[2] || '').length;
    const hundredPercent = 100n * (10n ** BigInt(commissionScale));
    if (amountMinor <= 0n || rateDigits <= 0n || commissionDigits > hundredPercent) return '';

    const numerator = amountMinor * rateDigits * 100n
      * (hundredPercent - commissionDigits);
    const denominator = (10n ** BigInt(rateScale + amountScale)) * hundredPercent;
    const wholeCents = numerator / denominator;
    const remainder = numerator % denominator;
    const roundedCents = wholeCents + (remainder * 2n >= denominator ? 1n : 0n);
    return `${roundedCents / 100n}.${String(roundedCents % 100n).padStart(2, '0')}`;
  } catch {
    return '';
  }
};

export const buildVariableRateWhatsAppMessage = (
  countryName: string,
  sourceCurrencyName: string,
  destinationCurrencyName: string,
) => [
  `سلام، برای حواله به ${countryName} درخواست نرخ روز دارم.`,
  '',
  `ارز مبدا: ${sourceCurrencyName}`,
  `ارز مقصد: ${destinationCurrencyName}`,
  '',
  'لطفاً نرخ روز و اطلاعات پرداخت را اعلام کنید.',
].join('\n');

export const buildVariableRateWhatsAppUrl = (
  countryName: string,
  sourceCurrencyName: string,
  destinationCurrencyName: string,
) => `${WHATSAPP_SUPPORT_URL}?text=${encodeURIComponent(
  buildVariableRateWhatsAppMessage(countryName, sourceCurrencyName, destinationCurrencyName)
)}`;
