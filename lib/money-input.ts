const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
const ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩';

const ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
const TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
const HUNDREDS = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
const SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون'];

export const normalizeMoneyInput = (value: string | number) => String(value ?? '')
  .replace(/[۰-۹]/g, (digit) => String(PERSIAN_DIGITS.indexOf(digit)))
  .replace(/[٠-٩]/g, (digit) => String(ARABIC_DIGITS.indexOf(digit)))
  .replace(/[ ,٬\s]/g, '')
  .replace(/\D/g, '')
  .replace(/^0+(?=\d)/, '');

export const formatMoneyInput = (value: string | number) => {
  const digits = normalizeMoneyInput(value);
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
};

/** Format text while keeping the caret after the same count of entered digits. */
export const formatMoneyInputAtCursor = (value: string, cursorPosition: number) => {
  const beforeCursor = value.slice(0, Math.max(0, cursorPosition));
  const digitCount = normalizeMoneyInput(beforeCursor).length;
  const formatted = formatMoneyInput(value);
  if (digitCount === 0) return { value: formatted, cursor: 0 };
  let seenDigits = 0;
  let cursor = formatted.length;
  for (let index = 0; index < formatted.length; index += 1) {
    if (/\d/.test(formatted[index])) seenDigits += 1;
    if (seenDigits === digitCount) {
      cursor = index + 1;
      break;
    }
  }
  return { value: formatted, cursor };
};

const underThousandToWords = (value: number) => {
  const parts: string[] = [];
  const hundred = Math.floor(value / 100);
  const remainder = value % 100;
  if (hundred) parts.push(HUNDREDS[hundred]);
  if (remainder < 20) {
    if (remainder) parts.push(ONES[remainder]);
  } else {
    parts.push(TENS[Math.floor(remainder / 10)]);
    const one = remainder % 10;
    if (one) parts.push(ONES[one]);
  }
  return parts.join(' و ');
};

const integerToPersianWords = (digits: string) => {
  if (!digits || /^0+$/.test(digits)) return 'صفر';
  const normalized = digits.replace(/^0+/, '');
  const groups: string[] = [];
  let scale = 0;
  for (let end = normalized.length; end > 0; end -= 3, scale += 1) {
    if (scale >= SCALES.length) return '';
    const start = Math.max(0, end - 3);
    const chunk = Number(normalized.slice(start, end));
    if (chunk) groups.unshift([underThousandToWords(chunk), SCALES[scale]].filter(Boolean).join(' '));
  }
  return groups.join(' و ');
};

export const moneyInputWords = (value: string | number) => {
  const digits = normalizeMoneyInput(value);
  if (!digits) return '';
  const words = integerToPersianWords(digits);
  return words ? `${words} تومان` : 'مبلغ خارج از محدوده نوشتاری';
};
