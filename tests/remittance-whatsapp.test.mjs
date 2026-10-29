import test from 'node:test';
import assert from 'node:assert/strict';
import {
  WHATSAPP_SUPPORT_URL,
  buildVariableRateWhatsAppMessage,
  buildVariableRateWhatsAppUrl,
} from '../lib/remittance-currency-flow.ts';

test('builds the exact Persian variable-rate message', () => {
  assert.equal(
    buildVariableRateWhatsAppMessage('ترکیه', 'تومان', 'لیر ترکیه'),
    [
      'سلام، برای حواله به ترکیه درخواست نرخ روز دارم.',
      '',
      'ارز مبدا: تومان',
      'ارز مقصد: لیر ترکیه',
      '',
      'لطفاً نرخ روز و اطلاعات پرداخت را اعلام کنید.',
    ].join('\n'),
  );
});

test('builds a fully encoded WhatsApp URL with the support number', () => {
  const url = buildVariableRateWhatsAppUrl('امارات متحده عربی', 'تومان', 'درهم امارات');
  assert.ok(url.startsWith(`${WHATSAPP_SUPPORT_URL}?text=`));
  assert.equal(url.includes(' '), false, 'spaces are URL encoded');
  assert.equal(url.includes('\n'), false, 'newlines are URL encoded');
  const text = decodeURIComponent(url.split('?text=')[1]);
  assert.match(text, /امارات متحده عربی/);
  assert.match(text, /ارز مبدا: تومان/);
  assert.match(text, /ارز مقصد: درهم امارات/);
});
