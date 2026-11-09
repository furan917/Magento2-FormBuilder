'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const masked = (mask, extra) => Object.assign({ name: 'code', settings: { mask } }, extra);

test('it formats the value when the field loses focus', () => {
  const rule = { rule: 'pattern', value: '[0-9]{2}-[0-9]{2}-[0-9]{2}', message: 'Use dashes.' };
  const t = boot({ fields: [masked('uk_sort_code', { rules: [rule] })] });
  const input = t.input('code');
  input.value = '123456';
  t.fire(input, 'focusout');
  assert.equal(input.value, '12-34-56');
  assert.equal(t.q('[data-ffb-error]').hidden, true);
});

test('it formats a custom mask on blur and leaves a field without a mask alone', () => {
  const t = boot({ fields: [masked('(999) 999-9999'), { name: 'plain' }] });
  t.input('code').value = '5551234567';
  t.fire(t.input('code'), 'focusout');
  assert.equal(t.input('code').value, '(555) 123-4567');
  t.input('plain').value = '123456';
  t.fire(t.input('plain'), 'focusout');
  assert.equal(t.input('plain').value, '123456');
});

test('it sets a numeric keyboard for digit only masks', () => {
  const t = boot({ fields: [
    masked('uk_sort_code'), { name: 'phone', type: 'tel', settings: { mask: 'uk_phone' } },
    { name: 'plate', settings: { mask: 'aa99 aaa' } }, { name: 'plain' },
    { name: 'pin', settings: { mask: '99 99' } },
  ] });
  assert.equal(t.input('code').getAttribute('inputmode'), 'numeric');
  assert.equal(t.input('pin').getAttribute('inputmode'), 'numeric');
  assert.equal(t.input('phone').getAttribute('inputmode'), 'tel');
  assert.equal(t.input('plate').getAttribute('inputmode'), 'text');
  assert.equal(t.input('plain').hasAttribute('inputmode'), false);
});

test('it leaves typing and pasting unblocked', () => {
  const t = boot({ fields: [masked('uk_sort_code')] });
  const input = t.input('code');
  const key = new t.w.KeyboardEvent('keydown', { key: 'x', bubbles: true, cancelable: true });
  input.dispatchEvent(key);
  const paste = new t.w.Event('paste', { bubbles: true, cancelable: true });
  input.dispatchEvent(paste);
  assert.equal(key.defaultPrevented, false);
  assert.equal(paste.defaultPrevented, false);
  input.value = '12 34 56 and more';
  t.fire(input, 'input');
  assert.equal(input.value, '12 34 56 and more');
});

test('it formats a restored draft value', async () => {
  const t = boot({
    fields: [masked('uk_sort_code')],
    draft: { mode: 'local', key: 'ffb:1:1:abc', ttlHours: 24 },
    beforeInit: (w) => w.localStorage.setItem('ffb:1:1:abc', JSON.stringify({
      v: 1, savedAt: Date.now(), page: 'p_aaaa', values: { code: '123456' },
    })),
  });
  await flush();
  assert.equal(t.input('code').value, '12-34-56');
});

test('it formats a prefilled value', async () => {
  const t = boot({
    context: { eager: true, productId: null, loginUrl: '' },
    fields: [masked('uk_sort_code', { prefill: 'customer.firstname' })],
    token: { token: 't', customer: true, access: { allowed: true }, prefill: { code: '123456' }, locked: [], product: null },
  });
  await flush();
  assert.equal(t.input('code').value, '12-34-56');
});
