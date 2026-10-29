import test from 'node:test';
import assert from 'node:assert/strict';
import { formatMoneyInput, formatMoneyInputAtCursor, moneyInputWords, normalizeMoneyInput } from '../lib/money-input.ts';

test('normalizes Western, Persian, and Arabic-Indic digits and displays grouped amounts', () => {
  assert.equal(normalizeMoneyInput('۶۰٬۰۰۰٬۰۰۰'), '60000000');
  assert.equal(normalizeMoneyInput('٦٠,٠٠٠,٠٠٠'), '60000000');
  assert.equal(formatMoneyInput('60000000'), '60,000,000');
  assert.equal(formatMoneyInput('000'), '0');
});

test('keeps the caret attached to the same digit while formatting edits', () => {
  assert.deepEqual(formatMoneyInputAtCursor('60000000', 2), { value: '60,000,000', cursor: 2 });
  assert.deepEqual(formatMoneyInputAtCursor('6090,000', 4), { value: '6,090,000', cursor: 5 });
  assert.deepEqual(formatMoneyInputAtCursor('60000000', 0), { value: '60,000,000', cursor: 0 });
});

test('renders exact Persian words for zero, small, zero-containing, thousand, million, and database maximum amounts', () => {
  assert.equal(moneyInputWords('0'), 'صفر تومان');
  assert.equal(moneyInputWords('1'), 'یک تومان');
  assert.equal(moneyInputWords('999'), 'نهصد و نود و نه تومان');
  assert.equal(moneyInputWords('1000005'), 'یک میلیون و پنج تومان');
  assert.equal(moneyInputWords('1500000'), 'یک میلیون و پانصد هزار تومان');
  assert.equal(moneyInputWords('60000000'), 'شصت میلیون تومان');
  assert.match(moneyInputWords('999999999999999999'), /^نهصد و نود و نه کوادریلیون و نهصد و نود و نه تریلیون/);
  assert.equal(moneyInputWords(''), '');
});
