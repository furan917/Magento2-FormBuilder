'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const EAGER = { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const fields = [{ name: 'note' }, { name: 'news', type: 'newsletter', draft: false }];

const tickAndRelease = async (token) => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const t = boot({
    context: EAGER,
    fields,
    token: () => gate.then(() => ({ ok: true, status: 200, json: () => Promise.resolve(token) })),
  });
  t.input('news').checked = true;
  t.fire(t.input('news'), 'change');
  release();
  await flush(40);
  return t;
};

test('it unticks and disables the newsletter box when the token reports impersonation', async () => {
  const t = await tickAndRelease({ token: 'tok.1', impersonating: true, customer: true, access: { allowed: true }, prefill: {}, locked: [] });

  assert.equal(t.input('news').checked, false);
  assert.equal(t.input('news').disabled, true);
  assert.equal(t.q('[data-ffb-lock-note]').hidden, false);
  assert.match(t.input('news').getAttribute('aria-describedby'), /ffb-lock-news/);

  // a visibility pass must not re-enable the box
  t.fire(t.input('note'), 'input');
  t.fire(t.input('note'), 'change');
  assert.equal(t.input('news').disabled, true);
});

test('it leaves the newsletter box alone when the token does not report impersonation', async () => {
  const t = await tickAndRelease({ token: 'tok.1', impersonating: false, customer: false, access: { allowed: true }, prefill: {}, locked: [] });

  assert.equal(t.input('news').checked, true);
  assert.equal(t.input('news').disabled, false);
  assert.equal(t.q('[data-ffb-lock-note]').hidden, true);
});

test('it still locks the newsletter box when access to the form is refused', async () => {
  const t = await tickAndRelease({ token: 'tok.1', impersonating: true, customer: false, access: { allowed: false, reason: 'login', loginUrl: '' } });

  assert.equal(t.input('news').checked, false);
  assert.equal(t.input('news').disabled, true);
});

test('it never saves a newsletter answer in a draft', async () => {
  const t = boot({ fields, draft: { mode: 'session', key: 'ffb:1:1:abc', ttlHours: 24 } });
  t.input('news').checked = true;
  t.fire(t.input('news'), 'change');
  t.input('note').value = 'hello';
  t.fire(t.input('note'), 'input');
  t.w.dispatchEvent(new t.w.Event('pagehide'));
  await flush(40);

  const stored = JSON.stringify(Object.assign({}, t.w.sessionStorage));
  assert.ok(!stored.includes('"news"'), stored);
});
