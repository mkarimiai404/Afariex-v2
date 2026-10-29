export const WHATSAPP_SUPPORT_URL = 'https://wa.me/989194998020';

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
