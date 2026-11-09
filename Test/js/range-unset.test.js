'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const submitBody = async (t) => {
  t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush(30);
  const call = t.calls.find((c) => c.url.includes('/submit'));
  return call ? call.init.body : null;
};

test('an untouched slider without a default is sent as unanswered', async () => {
  const t = boot({ fields: [{ name: 'hours', type: 'range' }] });
  await flush();
  assert.equal(t.q('[data-ffb-output]').textContent, 'Not set');
  const body = await submitBody(t);
  assert.ok(body);
  assert.equal(body.has('fields[hours]'), false);
});

test('moving the slider sets it and shows the value', async () => {
  const t = boot({ fields: [{ name: 'hours', type: 'range' }] });
  const r = t.input('hours');
  r.value = '4';
  t.fire(r, 'input');
  assert.equal(r.hasAttribute('data-ffb-unset'), false);
  assert.equal(t.q('[data-ffb-output]').textContent, '4');
  const body = await submitBody(t);
  assert.equal(body.get('fields[hours]'), '4');
});

test('choosing the starting value by keyboard or click also counts as set', () => {
  const t = boot({ fields: [{ name: 'a', type: 'range' }, { name: 'b', type: 'range' }] });
  t.input('a').dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
  t.input('b').dispatchEvent(new t.w.Event('pointerup', { bubbles: true }));
  assert.equal(t.input('a').hasAttribute('data-ffb-unset'), false);
  assert.equal(t.input('b').hasAttribute('data-ffb-unset'), false);
  assert.equal(t.q('[data-ffb-name="a"] [data-ffb-output]').textContent, '0');
});

test('a required slider that was never set fails validation instead of submitting 0', async () => {
  const t = boot({ fields: [{ name: 'hours', type: 'range', required: true }] });
  const body = await submitBody(t);
  assert.equal(body, null);
  assert.equal(t.q('[data-ffb-error]').hidden, false);
});

test('a slider with a configured default behaves as set', async () => {
  const t = boot({ fields: [{ name: 'hours', type: 'range', default: '5' }] });
  const body = await submitBody(t);
  assert.equal(body.get('fields[hours]'), '5');
});

test('prefilled or restored values set the slider and an empty value clears it', async () => {
  const t = boot({
    context: { eager: true, productId: null, loginUrl: '' },
    fields: [{ name: 'hours', type: 'range' }],
    token: { token: 'tok.1', customer: true, access: { allowed: true }, prefill: {}, locked: [], previous: { hours: '7' } },
  });
  await flush(40);
  assert.equal(t.input('hours').hasAttribute('data-ffb-unset'), false);
  assert.equal(t.q('[data-ffb-output]').textContent, '7');
});
