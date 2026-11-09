'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadCore, source } = require('./helpers/load-storefront');

const flush = () => new Promise((resolve) => setTimeout(resolve, 10));
const reply = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

// Only core and ffb-submit.js are loaded: the factory gets everything it uses as inputs.
function build(opts = {}) {
  const dom = new JSDOM('<!doctype html><body><form><input name="ffb_token"><input name="form_key"><input name="a" value="v"><button type="submit">Go</button></form></body>', {
    runScripts: 'outside-only', url: 'https://shop.test/forms/x',
  });
  const w = dom.window;
  loadCore(w);
  w.eval(source('ffb-submit.js'));
  const form = w.document.querySelector('form');
  const timers = [];
  const clock = { now: 1000 };
  w.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  Object.defineProperty(w.performance, 'now', { value: () => clock.now, configurable: true });
  const sent = [];
  const answers = (opts.submits || []).slice();
  w.fetch = (url, init) => {
    if (String(url).includes('/token')) { return Promise.resolve(reply(200, opts.token || { token: 'tok.1' })); }
    sent.push({ url: String(url), init });
    const answer = answers.shift() || { status: 200, body: { success: true, message: 'Thanks' } };
    return Promise.resolve(reply(answer.status, answer.body));
  };
  const log = { status: [], tokens: [], success: [], settled: 0 };
  const submitter = w.FuranFormBuilder.submit.create({
    form,
    tokenInput: form.querySelector('[name="ffb_token"]'),
    keyInput: form.querySelector('[name="form_key"]'),
    submitBtn: form.querySelector('button'),
    messages: { sending: 'Sending', generic: 'Oops', network: 'Net' },
    submitUrl: 'https://shop.test/furanform/form/submit',
    getTokenUrl: () => 'https://shop.test/furanform/form/token?form_id=1',
    productId: null,
    getEditToken: () => '',
    recaptcha: opts.recaptcha || null,
    prepare: () => {},
    setStatus: (text) => log.status.push(text),
    onToken: (data) => { log.tokens.push(data); return null; },
    onSettled: () => { log.settled += 1; },
    onSuccess: (data) => log.success.push(data),
    onFieldErrors: () => false,
  });
  return { w, form, submitter, timers, clock, sent, log };
}

test('it sends through the submit factory and waits for acceptance', async () => {
  const t = build({ token: { token: 'tok.9', notBefore: 3 } });
  t.submitter.start();
  await flush();
  assert.equal(t.sent.length, 0);
  assert.equal(t.log.status[0], 'Sending');
  assert.equal(t.timers.length, 1);
  assert.equal(t.timers[0].ms, 4000);
  assert.equal(t.form.querySelector('[name="ffb_token"]').value, 'tok.9');
  assert.equal(t.form.querySelector('button').getAttribute('aria-busy'), 'true');

  t.timers[0].fn();
  await flush();
  assert.equal(t.sent.length, 1);
  assert.equal(t.sent[0].init.body.get('ffb_token'), 'tok.9');
  assert.equal(t.sent[0].init.body.get('a'), 'v');
  assert.deepEqual(t.log.success, [{ success: true, message: 'Thanks' }]);
});

test('it retries once after a too early answer and keeps the token', async () => {
  const t = build({ submits: [{ status: 425, body: {} }, { status: 200, body: { success: true } }] });
  t.submitter.start();
  await flush();
  assert.equal(t.sent.length, 1);
  assert.equal(t.timers.at(-1).ms, 1000);
  t.timers.at(-1).fn();
  await flush();
  assert.equal(t.sent.length, 2);
  assert.equal(t.log.success.length, 1);
});

test('it drops the token after a refused submit so the next one fetches a fresh one', async () => {
  const t = build({ submits: [{ status: 403, body: { message: 'Denied' } }] });
  t.submitter.start();
  await flush();
  assert.equal(t.form.querySelector('[name="ffb_token"]').value, '');
  assert.equal(t.log.status.at(-1), 'Denied');
  assert.equal(t.submitter.isBusy(), false);
});

test('it resolves the form key from Hyva first, then the cookie, then a new key', () => {
  const t = build();
  const { resolveFormKey } = t.w.FuranFormBuilder.submit;
  t.w.hyva = { getFormKey: () => 'from-hyva' };
  assert.equal(resolveFormKey(), 'from-hyva');
  t.w.hyva = undefined;
  t.w.document.cookie = 'form_key=from-cookie';
  assert.equal(resolveFormKey(), 'from-cookie');
});
