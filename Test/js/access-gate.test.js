'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const LOGIN = 'https://shop.test/furanform/account/login';
const ctx = (extra) => Object.assign({ eager: true, productId: null, loginUrl: LOGIN }, extra);
const denied = (reason) => ({
  token: 'tok.1', customer: false, access: { allowed: false, reason, loginUrl: LOGIN }, prefill: {}, locked: [], product: null,
});
const gateOf = (t) => t.q('[data-ffb-gate]');
const links = (t) => Array.from(gateOf(t).querySelectorAll('a'));

test('it replaces the form with a login prompt when the token response says login is needed', async () => {
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: denied('login') });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.ok(t.el.contains(gateOf(t)));
  assert.match(gateOf(t).textContent, /Please log in to fill in this form\./);
  assert.deepEqual(links(t).map((a) => a.textContent), ['Log in', 'Create an account']);
});

test('it shows a not allowed message when the customer group is not allowed', async () => {
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: denied('group') });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.match(gateOf(t).textContent, /Your account may not fill in this form\./);
});

test('it shows an already answered message for a survey the customer has answered', async () => {
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: denied('answered') });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.match(gateOf(t).textContent, /You have already answered\./);
  assert.equal(links(t).length, 0);
});

test('it builds the login link with the url encoded page address', async () => {
  const page = 'https://shop.test/forms/x?a=1&b=two words';
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: denied('login'), url: page });
  await flush();
  const enc = encodeURIComponent(t.w.location.href);
  const [login, register] = links(t).map((a) => a.getAttribute('href'));
  assert.equal(login, LOGIN + '?return=' + enc);
  assert.equal(register, LOGIN + '?return=' + enc + '&register=1');
});

test('it fetches the token on load only when the form needs customer context', async () => {
  const lazy = boot({ context: ctx({ eager: false }), fields: [{ name: 'a' }] });
  await flush();
  assert.equal(lazy.tokenCalls().length, 0);

  const eager = boot({ context: ctx({ productId: 7 }), fields: [{ name: 'a' }] });
  await flush();
  assert.equal(eager.tokenCalls().length, 1);
  assert.equal(new URL(eager.tokenCalls()[0].url).searchParams.get('product_id'), '7');
  assert.equal(new URL(eager.tokenCalls()[0].url).searchParams.get('form_id'), '1');
});

test('it posts the product id with the submission', async () => {
  const t = boot({ context: ctx({ eager: false, productId: 7 }), fields: [{ name: 'a' }] });
  t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush();
  const post = t.calls.find((c) => c.url.endsWith('/submit'));
  assert.equal(post.init.body.get('ffb_product_id'), '7');

  const plain = boot({ context: ctx({ eager: false }), fields: [{ name: 'a' }] });
  plain.form.dispatchEvent(new plain.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush();
  assert.equal(plain.calls.find((c) => c.url.endsWith('/submit')).init.body.has('ffb_product_id'), false);
});

test('it makes signing in the main action and registering the secondary one', async () => {
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: denied('login') });
  await flush();
  const [login, register] = links(t);
  assert.ok(login.classList.contains('ffb-btn-primary'));
  assert.ok(register.classList.contains('ffb-gate-secondary'));
  assert.ok(gateOf(t).querySelector('.ffb-gate-actions').contains(login));
});

test('it offers no sign in or registration to a signed-in customer refused by group', async () => {
  const t = boot({ context: ctx(), fields: [{ name: 'a' }], token: Object.assign(denied('group'), { customer: true }) });
  await flush();
  assert.equal(t.form.hidden, true);
  assert.equal(gateOf(t).querySelectorAll('a').length, 0);
});
