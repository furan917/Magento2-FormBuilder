'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const EDIT = '1800000000.55.' + 'a'.repeat(64);
const PAGE = 'https://shop.test/forms/x';
const response = (extra) => Object.assign({
  token: 'tok.1', customer: true, access: { allowed: true, reason: null, loginUrl: '' }, prefill: {}, locked: [], product: null, previous: {},
}, extra);
const submit = (t) => t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
const posts = (t) => t.calls.filter((c) => c.url.endsWith('/furanform/form/submit'));

test('it reads the edit token from the page hash and posts it back', async () => {
  const t = boot({
    url: PAGE + '#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response({ previous: { note: 'old' }, edit: EDIT }),
  });
  await flush();
  const request = new URL(t.tokenCalls()[0].url);
  assert.equal(request.searchParams.get('edit'), EDIT);
  assert.equal(t.input('note').value, 'old');
  assert.equal(t.w.location.hash, '#ffb-edit=' + EDIT, 'kept until the submit succeeds');

  submit(t);
  await flush(30);
  assert.equal(posts(t).length, 1);
  assert.equal(posts(t)[0].init.body.get('ffb_edit'), EDIT);
  assert.equal(t.w.location.hash, '');
  assert.equal(t.w.location.pathname, '/forms/x');
});

test('it does not post an edit token the token endpoint did not accept', async () => {
  const t = boot({
    url: PAGE + '?a=1#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response(),
  });
  await flush();
  submit(t);
  await flush(30);
  assert.equal(posts(t)[0].init.body.has('ffb_edit'), false);
  assert.equal(t.w.location.search, '?a=1');
});

test('it ignores a malformed edit hash', async () => {
  const t = boot({ url: PAGE + '#ffb-edit=<b>x</b>', fields: [{ name: 'note' }], token: response() });
  await flush();
  assert.equal(t.tokenCalls().length, 0);
});

test('it keeps the edit hash when the submit fails', async () => {
  const t = boot({ url: PAGE + '#ffb-edit=' + EDIT, fields: [{ name: 'note' }], token: response({ edit: EDIT }) });
  await flush();
  const fetch = t.w.fetch;
  t.w.fetch = (url, init) => (String(url).endsWith('/submit')
    ? Promise.resolve({ ok: false, status: 400, json: () => Promise.resolve({ success: false, message: 'expired' }) })
    : fetch(url, init));
  submit(t);
  await flush(30);
  assert.equal(t.w.location.hash, '#ffb-edit=' + EDIT);
});

test('it says the edit link is no longer valid when the token endpoint refuses it', async () => {
  const t = boot({
    url: PAGE + '#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response(),
    messages: { editInvalid: 'This edit link is no longer valid.' },
  });
  await flush();
  const notices = t.doc.querySelectorAll('[data-ffb-edit-invalid]');
  assert.equal(notices.length, 1);
  assert.equal(notices[0].textContent, 'This edit link is no longer valid.');
  assert.equal(notices[0].getAttribute('role'), 'status');
  assert.equal(t.q('[data-ffb-editing]'), null);
  assert.equal(t.w.location.hash, '');
  t.input('note').dispatchEvent(new t.w.Event('focusin', { bubbles: true }));
  await flush();
  assert.equal(t.doc.querySelectorAll('[data-ffb-edit-invalid]').length, 1);
});

test('it shows no edit notice when the edit link is accepted or access is refused', async () => {
  const messages = { editInvalid: 'This edit link is no longer valid.' };
  const accepted = boot({ url: PAGE + '#ffb-edit=' + EDIT, fields: [{ name: 'note' }], token: response({ edit: EDIT }), messages });
  const gated = boot({
    url: PAGE + '#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response({ access: { allowed: false, reason: 'group', loginUrl: '' } }),
    messages,
  });
  await flush();
  assert.equal(accepted.q('[data-ffb-edit-invalid]'), null);
  assert.equal(gated.q('[data-ffb-edit-invalid]'), null);
});

test('it asks a signed-out visitor to sign in and keeps their edit link', async () => {
  const t = boot({
    url: PAGE + '#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response({ customer: false }),
    messages: { editInvalid: 'This edit link is no longer valid.', editSignIn: 'Please sign in to change your earlier answer.' },
  });
  await flush();
  assert.equal(t.q('[data-ffb-edit-invalid]').textContent, 'Please sign in to change your earlier answer.');
  assert.equal(t.w.location.hash, '#ffb-edit=' + EDIT);
});

test('it shows no invalid link notice when a survey answer is refilled instead', async () => {
  const t = boot({
    url: PAGE + '#ffb-edit=' + EDIT,
    fields: [{ name: 'note' }],
    token: response({ previous: { note: 'mine' } }),
    messages: { editInvalid: 'This edit link is no longer valid.' },
  });
  await flush();
  assert.equal(t.q('[data-ffb-edit-invalid]'), null);
  assert.equal(t.input('note').value, 'mine');
});
