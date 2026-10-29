<?php
declare(strict_types=1);

function normalize_admin_money_amount(string $value): ?string
{
    $normalized = normalize_persian_digits(trim($value));
    $normalized = str_replace(['٫', '٬'], ['.', ','], $normalized);
    if (preg_match('/^(?:\d{1,3}(?:,\d{3})*|\d+)(?:\.\d{1,2})?$/', $normalized) !== 1) {
        return null;
    }
    $numeric = str_replace(',', '', $normalized);
    [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');
    $integer = ltrim($integer, '0');
    if ($integer === '' && rtrim($fraction, '0') === '') {
        return null;
    }
    $integer = $integer === '' ? '0' : $integer;
    return $fraction === '' ? $integer : $integer . '.' . $fraction;
}

function render_money_input_helpers(): void
{
    ?>
    <style>
      .money-amount-words { margin-top:6px;color:#0f766e;font-size:12px;font-weight:700;min-height:18px; }
    </style>
    <script>
      window.AfariexMoneyInputs = window.AfariexMoneyInputs || (function () {
        const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
        const arabicDigits = '٠١٢٣٤٥٦٧٨٩';
        const ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        const tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        const hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        const scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون'];

        function clean(value) {
          return String(value ?? '')
            .replace(/[۰-۹]/g, digit => String(persianDigits.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String(arabicDigits.indexOf(digit)))
            .replace(/[ ,٬\s]/g, '')
            .replace(/\D/g, '')
            .replace(/^0+(?=\d)/, '');
        }

        function cleanDecimal(value) {
          const translated = String(value ?? '')
            .replace(/[۰-۹]/g, digit => String(persianDigits.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String(arabicDigits.indexOf(digit)))
            .replace(/٫/g, '.')
            .replace(/[,٬\s]/g, '');
          if (!/^\d*(?:\.\d*)?$/.test(translated)) return translated;
          const pieces = translated.split('.');
          return pieces.join('.');
        }

        function format(value) {
          return clean(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function formatDecimal(value) {
          const decimal = cleanDecimal(value);
          if (!/^\d*(?:\.\d*)?$/.test(decimal)) return String(value ?? '');
          const pieces = decimal.split('.');
          pieces[0] = pieces[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
          return pieces.join('.');
        }

        function underThousand(value) {
          const parts = [];
          const hundred = Math.floor(value / 100);
          const remainder = value % 100;
          if (hundred) parts.push(hundreds[hundred]);
          if (remainder < 20) {
            if (remainder) parts.push(ones[remainder]);
          } else {
            parts.push(tens[Math.floor(remainder / 10)]);
            const one = remainder % 10;
            if (one) parts.push(ones[one]);
          }
          return parts.join(' و ');
        }

        function integerWords(digits) {
          if (!digits) return '';
          if (/^0+$/.test(digits)) return 'صفر';
          const groups = [];
          let scale = 0;
          for (let end = digits.length; end > 0; end -= 3, scale += 1) {
            if (scale >= scales.length) return '';
            const chunk = Number(digits.slice(Math.max(0, end - 3), end));
            if (chunk) groups.unshift([underThousand(chunk), scales[scale]].filter(Boolean).join(' '));
          }
          return groups.join(' و ');
        }

        function words(value, unit = 'تومان', allowDecimal = false) {
          const decimal = allowDecimal ? cleanDecimal(value) : clean(value);
          if (!decimal) return '';
          const pieces = decimal.split('.');
          const whole = integerWords(pieces[0] || '0');
          if (!whole) return 'مبلغ خارج از محدوده نوشتاری';
          const fraction = pieces[1] || '';
          const fractionWords = fraction ? ` ممیز ${fraction.split('').map(digit => ones[Number(digit)] || 'صفر').join(' ')}` : '';
          return `${whole}${fractionWords} ${unit}`;
        }

        function refresh(input) {
          if (!input) return;
          const oldValue = input.value;
          const start = input.selectionStart ?? oldValue.length;
          const end = input.selectionEnd ?? start;
          const decimalInput = input.hasAttribute('data-money-decimal');
          const newValue = decimalInput ? formatDecimal(oldValue) : format(oldValue);
          if (decimalInput && !/^\d*(?:\.\d*)?$/.test(cleanDecimal(oldValue))) {
            const invalidTarget = input.dataset.moneyWordsTarget ? document.getElementById(input.dataset.moneyWordsTarget) : input.parentElement?.querySelector('.money-amount-words, .persian-amount');
            if (invalidTarget) invalidTarget.textContent = '';
            return;
          }
          if (oldValue !== newValue) {
            const digitCleaner = decimalInput ? cleanDecimal : clean;
            const startCount = digitCleaner(oldValue.slice(0, start)).replace('.', '').length;
            const endCount = digitCleaner(oldValue.slice(0, end)).replace('.', '').length;
            input.value = newValue;
            const positionAfterDigits = count => {
              if (!count) return 0;
              let found = 0;
              for (let index = 0; index < newValue.length; index += 1) {
                if (/\d/.test(newValue[index])) found += 1;
                if (found === count) return index + 1;
              }
              return newValue.length;
            };
            input.setSelectionRange(positionAfterDigits(startCount), positionAfterDigits(endCount));
          }
          const targetId = input.dataset.moneyWordsTarget;
          const target = targetId ? document.getElementById(targetId) : input.parentElement?.querySelector('.money-amount-words, .persian-amount');
          if (target) target.textContent = words(input.value, input.dataset.moneyUnit || 'تومان', decimalInput);
        }

        function apply(root) {
          (root || document).querySelectorAll('input[data-money-input], input[data-money-decimal]').forEach(function (input) {
            refresh(input);
            if (input.dataset.moneyInputReady === '1') return;
            input.dataset.moneyInputReady = '1';
            input.addEventListener('input', function () { refresh(input); });
          });
          (root || document).querySelectorAll('form').forEach(function (form) {
            if (form.dataset.moneySubmitReady === '1') return;
            form.dataset.moneySubmitReady = '1';
            form.addEventListener('submit', function () {
              form.querySelectorAll('input[data-money-input], input[data-money-decimal]').forEach(function (input) {
                input.value = input.hasAttribute('data-money-decimal') ? cleanDecimal(input.value) : clean(input.value);
              });
            });
          });
        }

        document.addEventListener('DOMContentLoaded', function () { apply(document); });
        return { apply, clean, cleanDecimal, format, formatDecimal, refresh, words };
      })();
    </script>
    <?php
}
