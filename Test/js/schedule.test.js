'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const ctx = { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const closedToken = (state, message) => ({ token: null, today: '2026-11-07', schedule: { state, message } });
const closedOf = (t) => t.q('[data-ffb-closed]');

test('it shows the closed message in the browser when the token says closed', async () => {
  const t = boot({ context: ctx, fields: [{ name: 'a' }], token: closedToken('closed', 'We <b>closed</b> in July.') });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.ok(t.el.contains(closedOf(t)));
  assert.equal(closedOf(t).textContent, 'We <b>closed</b> in July.');
  assert.equal(closedOf(t).querySelector('b'), null);
  assert.equal(closedOf(t).getAttribute('role'), 'status');
});

test('it shows the not yet open message the same way', async () => {
  const t = boot({ context: ctx, fields: [{ name: 'a' }], token: closedToken('not_yet', 'Opens in July.') });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.equal(closedOf(t).textContent, 'Opens in July.');
});

test('it keeps the form for an open token and does not post without a token', async () => {
  const open = boot({ context: ctx, fields: [{ name: 'a' }], token: { token: 'tok.1' } });
  await flush();
  assert.equal(open.form.hidden, false);
  assert.equal(closedOf(open), null);

  const t = boot({ context: ctx, fields: [{ name: 'a' }], token: closedToken('closed', 'Closed.') });
  await flush();
  t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush();
  assert.equal(t.calls.filter((c) => c.url.endsWith('/submit')).length, 0);
});

test('it moves focus to the closed message when the form closes under the visitor', async () => {
  const t = boot({ context: ctx, fields: [{ name: 'a' }], token: closedToken('closed', 'Closed.') });
  t.q('[name="fields[a]"]').focus();
  await flush();
  assert.equal(closedOf(t).getAttribute('tabindex'), '-1');
  assert.equal(t.doc.activeElement, closedOf(t));
});

test('it leaves focus alone when it was not inside the form', async () => {
  const t = boot({ context: ctx, fields: [{ name: 'a' }], token: closedToken('closed', 'Closed.') });
  await flush();
  assert.notEqual(t.doc.activeElement, closedOf(t));
});
