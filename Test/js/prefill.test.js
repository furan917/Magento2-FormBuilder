'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const EAGER = { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const customerToken = (extra) => Object.assign({
  token: 'tok.1', customer: true, access: { allowed: true, reason: null, loginUrl: '' }, prefill: {}, locked: [], product: null,
}, extra);

test('it fills mapped fields from the token response', async () => {
  const t = boot({
    context: EAGER,
    fields: [{ name: 'first_name', prefill: 'customer.firstname' }, { name: 'other' }],
    token: customerToken({ prefill: { first_name: 'Ann', other: 'nope' } }),
  });
  await flush();
  assert.equal(t.input('first_name').value, 'Ann');
  assert.equal(t.input('other').value, 'nope');
});

test('it makes locked fields read only', async () => {
  const addr = { street: ['1 Main St', ''], city: 'LA', region_id: '12', region: '', postcode: '90001', country_id: 'US' };
  const t = boot({
    context: EAGER,
    fields: [
      { name: 'email', prefill: 'customer.email' }, { name: 'gender', type: 'select', prefill: 'customer.gender' },
      { name: 'pick', type: 'radio', prefill: 'customer.gender' }, { name: 'addr', type: 'address', prefill: 'address.billing' },
    ],
    token: customerToken({ prefill: { email: 'a@b.test', gender: 'b', pick: 'a', addr }, locked: ['email', 'gender', 'pick', 'addr'] }),
  });
  await flush(40);
  assert.equal(t.input('email').readOnly, true);
  const sel = t.input('gender');
  assert.equal(sel.value, 'b');
  assert.deepEqual(Array.from(sel.options).map((o) => o.disabled), [true, true, false]);
  assert.equal(sel.closest('[data-ffb-name]').getAttribute('aria-readonly'), 'true');
  const radios = Array.from(t.doc.querySelectorAll('[name="fields[pick]"]'));
  assert.deepEqual(radios.map((r) => r.disabled), [false, true]);
  // address goes through the region-aware writer: country first, region after the list loaded
  const region = t.q('[name="fields[addr][region_id]"]');
  assert.equal(t.q('[name="fields[addr][country_id]"]').value, 'US');
  assert.equal(region.value, '12');
  assert.equal(region.hidden, false);
  assert.deepEqual(Array.from(region.options).map((o) => o.disabled), [true, false, true]);
  assert.equal(t.q('[name="fields[addr][city]"]').readOnly, true);
});

test('it never overwrites a value the visitor already typed', async () => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const t = boot({
    context: EAGER,
    fields: [{ name: 'first_name', prefill: 'customer.firstname' }, { name: 'last_name', prefill: 'customer.lastname', default: 'Doe' }, { name: 'nick', prefill: 'customer.lastname' }],
    token: () => gate.then(() => ({ ok: true, status: 200, json: () => Promise.resolve(customerToken({ prefill: { first_name: 'Ann', last_name: 'Smith', nick: 'Smith' } })) })),
  });
  t.input('first_name').value = 'Bob';
  t.fire(t.input('first_name'), 'input');
  t.input('nick').value = 'x';
  t.input('nick').value = '';
  t.fire(t.input('nick'), 'input');
  release();
  await flush();
  assert.equal(t.input('first_name').value, 'Bob');
  assert.equal(t.input('last_name').value, 'Smith');
  assert.equal(t.input('nick').value, '');
});

test('it fills product fields from the product context', async () => {
  const product = { id: 5, sku: 'SKU-1', name: 'Widget', url: 'https://shop.test/widget.html', price: 9.5 };
  const fields = [
    { name: 'pname', prefill: 'product.name', locked: true }, { name: 'psku', prefill: 'product.sku', locked: true },
    { name: 'purl', prefill: 'product.url', locked: true }, { name: 'pprice', prefill: 'product.price', locked: true },
  ];
  const ctx = { eager: true, productId: 5, loginUrl: '' };
  const t = boot({ context: ctx, fields, token: { token: 't', customer: false, access: { allowed: true, reason: null }, prefill: {}, locked: [], product } });
  await flush();
  assert.deepEqual(['pname', 'psku', 'purl', 'pprice'].map((n) => t.input(n).value), ['Widget', 'SKU-1', 'https://shop.test/widget.html', '9.5']);
  assert.ok(['pname', 'psku', 'purl', 'pprice'].every((n) => t.input(n).readOnly));

  const none = boot({ context: ctx, fields, token: { token: 't', customer: false, access: { allowed: true }, prefill: {}, locked: [], product: null } });
  await flush();
  assert.equal(none.input('pname').value, '');
  assert.equal(none.input('pname').readOnly, true);
});

test('it never saves locked or untouched prefilled values in the draft', async () => {
  const t = boot({
    context: EAGER,
    draft: { mode: 'local', key: 'ffb:1:1:abc', ttlHours: 24 },
    fields: [
      { name: 'email', prefill: 'customer.email' }, { name: 'first_name', prefill: 'customer.firstname' },
      { name: 'last_name', prefill: 'customer.lastname' }, { name: 'note' },
    ],
    token: customerToken({ prefill: { email: 'a@b.test', first_name: 'Ann', last_name: 'Smith' }, locked: ['email'] }),
  });
  await flush();
  t.input('last_name').value = 'Jones';
  t.input('note').value = 'hello';
  t.fire(t.input('note'), 'input');
  await flush(450);
  const saved = JSON.parse(t.w.localStorage.getItem('ffb:1:1:abc'));
  assert.deepEqual(Object.keys(saved.values).sort(), ['last_name', 'note']);
  assert.equal(saved.values.last_name, 'Jones');
});

test('it locks a field the account has no value for, empties it and never requires it', async () => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const t = boot({
    context: EAGER,
    fields: [{ name: 'tax', prefill: 'customer.taxvat', locked: true, required: true }],
    token: () => gate.then(() => ({ ok: true, status: 200, json: () => Promise.resolve(customerToken({ locked: ['tax'] })) })),
  });
  t.input('tax').value = 'typed';
  t.fire(t.input('tax'), 'input');
  release();
  await flush();
  assert.equal(t.input('tax').value, '');
  assert.equal(t.input('tax').readOnly, true);
  t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush();
  assert.equal(t.q('[data-ffb-name="tax"] [data-ffb-error]').hidden, true);
  assert.equal(t.calls.filter((c) => c.url.endsWith('/submit')).length, 1);
});

test('it drops required from a locked field', async () => {
  const t = boot({
    context: EAGER,
    fields: [{ name: 'tax', prefill: 'customer.taxvat', locked: true, required: true }],
    token: customerToken({ locked: ['tax'] }),
  });
  t.input('tax').setAttribute('required', '');
  t.input('tax').setAttribute('aria-required', 'true');
  await flush();
  assert.equal(t.input('tax').hasAttribute('required'), false);
  assert.equal(t.input('tax').hasAttribute('aria-required'), false);
  assert.equal(t.q('[data-ffb-name="tax"] .ffb-req'), null);
});
