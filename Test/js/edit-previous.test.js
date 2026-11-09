'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const EAGER = { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const token = (extra) => Object.assign({
  token: 'tok.1', customer: true, access: { allowed: true, reason: null, loginUrl: '' }, prefill: {}, locked: [], product: null, previous: {},
}, extra);
const draftOpts = { mode: 'local', key: 'ffb:1:1:abc', ttlHours: 24 };

test('it fills the previous answers and lets them win over prefill', async () => {
  const t = boot({
    context: EAGER,
    fields: [{ name: 'first_name', prefill: 'customer.firstname' }, { name: 'note' }],
    token: token({ prefill: { first_name: 'Ann' }, previous: { first_name: 'Annie', note: 'hello' } }),
  });
  await flush();
  assert.equal(t.input('first_name').value, 'Annie');
  assert.equal(t.input('note').value, 'hello');
});

test('it keeps the server value in locked fields over the previous answer', async () => {
  const t = boot({
    context: EAGER,
    fields: [{ name: 'email', prefill: 'customer.email', locked: true }],
    token: token({ prefill: { email: 'a@b.test' }, locked: ['email'], previous: { email: 'old@b.test' } }),
  });
  await flush();
  assert.equal(t.input('email').value, 'a@b.test');
  assert.equal(t.input('email').readOnly, true);
});

test('it does not write unchanged previous-filled values to drafts', async () => {
  const t = boot({
    context: EAGER, draft: draftOpts,
    fields: [{ name: 'note' }, { name: 'extra' }],
    token: token({ previous: { note: 'hello' } }),
  });
  await flush();
  t.input('extra').value = 'typed';
  t.fire(t.input('extra'), 'input');
  await flush(450);
  const saved = JSON.parse(t.w.localStorage.getItem('ffb:1:1:abc'));
  assert.deepEqual(saved.values && Object.keys(saved.values).sort(), ['extra']);
});

test('it tells the customer they are changing their earlier answer', async () => {
  const t = boot({ context: EAGER, fields: [{ name: 'note' }], token: token({ previous: { note: 'hello' } }) });
  await flush();
  const note = t.w.document.querySelector('[data-ffb-editing]');
  assert.ok(note);
  assert.equal(note.getAttribute('role'), 'status');
  assert.equal(note.textContent, 'You are changing your earlier answer.');
});

test('it shows no editing notice for a first answer', async () => {
  const t = boot({ context: EAGER, fields: [{ name: 'note' }], token: token() });
  await flush();
  assert.equal(t.w.document.querySelector('[data-ffb-editing]'), null);
});
