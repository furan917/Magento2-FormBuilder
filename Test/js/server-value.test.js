'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/actions-form');

const when = (field, value) => ({ field, operator: 'equals', value });

test('a required server value field with no value never stops the visitor', async () => {
  const t = boot({ pages: [
    { id: 'p1', fields: [{ name: 'a' }, { name: 'src', required: true, server_value: true, required_message: 'Required' }] },
    { id: 'p2', fields: [{ name: 'b' }] },
  ] });
  await flush();
  t.click('[data-ffb-next]');
  assert.deepEqual(t.pageShown(), ['p2']);
  assert.equal(t.q('[data-ffb-name="src"] [data-ffb-error]').hidden, true);
  assert.equal(t.q('[name="fields[src]"]').hasAttribute('aria-invalid'), false);
});

test('a require action never makes a server value field required', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'src', server_value: true, condition: { actions: [{ action: 'require' }], match: 'all', rules: [when('a', '1')] } },
  ] }] });
  await flush();
  t.type('a', '1');
  assert.equal(t.q('[data-ffb-name="src"] .ffb-req'), null);
  assert.equal(t.q('[name="fields[src]"]').hasAttribute('aria-required'), false);
  t.q('[name="fields[src]"]').focus();
  t.fire(t.q('[name="fields[src]"]'), 'focusout');
  assert.equal(t.q('[data-ffb-name="src"] [data-ffb-error]').hidden, true);
});

test('a product prefilled server value field is locked like a locked field', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [{ name: 'sku', server_value: true, prefill: 'product.sku' }] }] });
  await flush();
  assert.equal(t.q('[name="fields[sku]"]').readOnly, true);
  assert.equal(t.q('[data-ffb-name="sku"]').getAttribute('aria-readonly'), 'true');
});
