<?php
declare(strict_types=1);

function normalize_admin_money_amount(string $value, int $precision = 2): ?string
{
    $normalized = normalize_persian_digits(trim($value));
    $normalized = str_replace(['٫', '٬'], ['.', ','], $normalized);
    $precision = max(0, min(4, $precision));
    $decimalPattern = $precision === 0 ? '' : '(?:\.\d{1,' . $precision . '})?';
    if (preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)' . $decimalPattern . '$/D', $normalized) !== 1) {
        return null;
    }
    $numeric = str_replace(',', '', $normalized);
    [$integer, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');
    if (strlen(ltrim($integer, '0')) > 16 || !is_finite((float)$numeric) || (float)$numeric <= 0) {
        return null;
    }
    $integer = ltrim($integer, '0');
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

        function translateDigits(value) {
          return String(value ?? '')
            .replace(/[۰-۹]/g, digit => String(persianDigits.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String(arabicDigits.indexOf(digit)));
        }

        function clean(value) {
          return translateDigits(value).replace(/[ ,٬\s]/g, '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        }

        function cleanDecimal(value) {
          const translated = translateDigits(value).replace(/٫/g, '.').replace(/٬/g, ',');
          const pieces = translated.split('.');
          if (pieces.length > 2 || !/^(?:\d{1,3}(?:,\d{3})+|\d+)$/.test(pieces[0]) || (pieces.length === 2 && !/^\d*$/.test(pieces[1]))) return translated;
          const integer = pieces[0].replace(/,/g, '');
          return pieces.length === 2 ? `${integer}.${pieces[1]}` : integer;
        }

        // Remove visual grouping only when editing; do not rewrite the value on each keystroke.
        function ungroupForEditing(value, decimalInput) {
          let translated = translateDigits(value).replace(/٫/g, '.').replace(/٬/g, ',');
          translated = translated.replace(/,/g, '');
          return decimalInput ? translated : translated.replace(/\D/g, '');
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
          if (remainder < 20) { if (remainder) parts.push(ones[remainder]); }
          else { parts.push(tens[Math.floor(remainder / 10)]); const one = remainder % 10; if (one) parts.push(ones[one]); }
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
          if (!decimal || (allowDecimal && !/^\d*(?:\.\d*)?$/.test(decimal))) return '';
          const pieces = decimal.split('.');
          const whole = integerWords(pieces[0] || '0');
          if (!whole) return 'مبلغ خارج از محدوده نوشتاری';
          const fraction = pieces[1] || '';
          const fractionWords = fraction ? ` ممیز ${fraction.split('').map(digit => ones[Number(digit)] || 'صفر').join(' ')}` : '';
          return `${whole}${fractionWords} ${unit}`;
        }

        function wordsTarget(input) {
          const targetId = input.dataset.moneyWordsTarget;
          return targetId ? document.getElementById(targetId) : input.parentElement?.querySelector('.money-amount-words, .persian-amount');
        }

        function refresh(input, formatValue = true) {
          if (!input) return;
          const decimalInput = input.hasAttribute('data-money-decimal');
          if (formatValue) {
            const newValue = decimalInput ? formatDecimal(input.value) : format(input.value);
            if (newValue !== input.value) input.value = newValue;
          }
          const current = input.value;
          const valid = decimalInput ? /^\d*(?:\.\d*)?$/.test(cleanDecimal(current)) : true;
          const target = wordsTarget(input);
          if (target) target.textContent = valid ? words(current, input.dataset.moneyUnit || 'تومان', decimalInput) : '';
        }

        function apply(root) {
          (root || document).querySelectorAll('input[data-money-input], input[data-money-decimal]').forEach(function (input) {
            refresh(input, true);
            if (input.dataset.moneyInputReady === '1') return;
            input.dataset.moneyInputReady = '1';
            input.addEventListener('focus', function () {
              const decimalInput = input.hasAttribute('data-money-decimal');
              const oldValue = input.value;
              const caret = input.selectionStart ?? oldValue.length;
              const before = ungroupForEditing(oldValue.slice(0, caret), decimalInput);
              const editable = ungroupForEditing(oldValue, decimalInput);
              if (editable !== oldValue) {
                input.value = editable;
                const position = Math.min(before.length, editable.length);
                try { input.setSelectionRange(position, position); } catch (_) {}
              }
            });
            // Leave the user's text and caret untouched while typing; this avoids keystroke
            // feedback loops and lets arbitrarily long digit sequences be entered normally.
            input.addEventListener('input', function () { refresh(input, false); });
            input.addEventListener('blur', function () { refresh(input, true); });
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
