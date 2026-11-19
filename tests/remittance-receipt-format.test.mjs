import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateDestinationAmount } from '../lib/remittance-currency-flow.ts';
import { buildRemittanceReceiptHtml, buildRemittanceReceiptText, formatRemittanceReceipt, formatPersianCurrencyAmount } from '../lib/remittance-receipt.ts';

test('IRT to AFN uses the normalized reciprocal rate', () => {
  assert.equal(calculateDestinationAmount('10000000', '0.000235017626', '0'), '2350.18');
});

test('commission is applied once and decimal precision is rounded half up', () => {
  assert.equal(calculateDestinationAmount('10000000', '0.000235017626', '10'), '2115.16');
  assert.equal(calculateDestinationAmount('100', '0.5', '0'), '50.00');
});

test('malformed and out of range inputs are rejected', () => {
  for (const amount of ['0', '-1', '1.234', 'abc']) {
    assert.equal(calculateDestinationAmount(amount, '0.5', '0'), '');
  }
  assert.equal(calculateDestinationAmount('100', '0', '0'), '');
  assert.equal(calculateDestinationAmount('100', '0.5', '100.01'), '');
});

test('destination receipt keeps currency precision and contains no amount in words', () => {
  const receipt = {
    trackingNumber: '99', date: '', sender: 'Sender', receiver: 'Receiver',
    amountAfghani: '2350.18', amountToman: '10000000', sourceCurrency: 'IRT',
    destinationCurrency: 'AFN', destinationCurrencyPrecision: 2, destination: 'Agency', status: 'pending',
  };
  const presentation = formatRemittanceReceipt(receipt);
  assert.equal(presentation.amount, '۲,۳۵۰.۱۸');
  assert.equal(formatPersianCurrencyAmount('2350', 'AFN', 2), '۲,۳۵۰.۰۰');
  assert.equal(formatPersianCurrencyAmount('15000', 'IRT', 0), '۱۵,۰۰۰');
  const text = buildRemittanceReceiptText(receipt);
  assert.match(text, /مبلغ دریافتی: ۲,۳۵۰\.۱۸ افغانی/);
  assert.doesNotMatch(text, /تریلیون|میلیارد|میلیون|هزار/);
  const html = buildRemittanceReceiptHtml({
    ...receipt,
    fontRegularUri: '', fontBoldUri: '', logoUri: '',
  });
  assert.match(html, /۲,۳۵۰\.۱۸ افغانی/);
  assert.doesNotMatch(html, /amount-words|تریلیون|میلیارد|میلیون/);
});
