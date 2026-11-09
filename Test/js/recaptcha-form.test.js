'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront } = require('./helpers/load-storefront');
const snapshot = read('Test/fixtures/rendered-form.html')
  .replace(/<script src[^>]*><\/script>/g, '')
  .replace(/<link[^>]*>/g, '');

const SCRIPT_URL = 'https://shop.test/static/Furan_FormBuilder/js/ffb-recaptcha.js';
const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));
const jsonResponse = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

// Stand-in for ffb-recaptcha.js; tokenFn decides what getToken resolves to.
function fakeHelper(tokenFn) {
  const log = { created: [], loadApi: 0, tokens: 0, resets: 0 };
  const helper = {
    loadApi() { log.loadApi += 1; return Promise.resolve({}); },
    create(element, cfg) {
      log.created.push({ element, cfg });
      return {
        getToken() { log.tokens += 1; return Promise.resolve().then(() => tokenFn(log)); },
        reset() { log.resets += 1; },
      };
    },
  };
  return { helper, log };
}

function boot(opts = {}) {
  const dom = new JSDOM(snapshot, { runScripts: 'outside-only', url: 'https://shop.test/forms/contact' });
  const w = dom.window;
  const doc = w.document;
  const el = doc.querySelector('[data-ffb-root]');
  const cfg = JSON.parse(el.getAttribute('data-ffb-config'));
  if (opts.recaptcha !== false) {
    cfg.recaptcha = Object.assign({ scriptUrl: SCRIPT_URL, rendering: { sitekey: 'k', hl: 'en' }, invisible: false }, opts.recaptcha || {});
    cfg.messages.recaptchaRequired = 'Please confirm you are not a robot.';
  }
  el.setAttribute('data-ffb-config', JSON.stringify(cfg));
  const calls = [];
  const respond = opts.respond || (() => jsonResponse(200, { success: true, message: 'Thanks!', errors: {}, redirect: null }));
  w.fetch = (url, init) => {
    calls.push({ url, init });
    if (String(url).includes('/token')) return Promise.resolve(jsonResponse(200, { token: 'tok.123' }));
    const r = respond(url, init);
    return r instanceof Error ? Promise.reject(r) : Promise.resolve(r);
  };
  if (opts.helper) {
    w.FuranFormBuilder = { recaptcha: opts.helper };
  }
  loadStorefront(w);
  const q = (s) => doc.querySelector(s);
  const ready = () => (doc.readyState === 'loading' ? new Promise((r) => doc.addEventListener('DOMContentLoaded', r)) : Promise.resolve());
  return { w, doc, q, ready, calls, submitCalls: () => calls.filter((c) => !String(c.url).includes('/token')), form: q('[data-ffb-form]') };
}

async function fillAndSubmit(t) {
  await t.ready();
  const set = (name, value) => {
    const input = t.q(`[name="fields[${name}]"]`);
    input.value = value;
    input.dispatchEvent(new t.w.Event('input', { bubbles: true }));
    input.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  };
  set('name', 'Jane Doe');
  t.q('[data-ffb-next]').click();
  set('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
}

const helperScripts = (t) => Array.from(t.doc.querySelectorAll('script')).filter((s) => s.getAttribute('src') === SCRIPT_URL);
const focus = (t) => t.q('#ffb-7-name').dispatchEvent(new t.w.Event('focusin', { bubbles: true }));

test('it loads the reCAPTCHA helper script only for forms whose config has reCAPTCHA', async () => {
  const plain = boot({ recaptcha: false });
  await plain.ready();
  focus(plain);
  await flush();
  assert.equal(plain.doc.querySelectorAll('script[src]').length, 0);
  assert.equal(plain.q('[data-ffb-recaptcha]'), null);

  const t = boot();
  await t.ready();
  focus(t);
  focus(t);
  await flush();
  const scripts = helperScripts(t);
  assert.equal(scripts.length, 1);
  assert.equal(scripts[0].async, true);
  const container = t.q('[data-ffb-recaptcha]');
  assert.ok(container.classList.contains('ffb-recaptcha'));
  assert.equal(container.nextElementSibling, t.q('[data-ffb-submit]').closest('.ffb-nav'));
});

test('it does not load the Google script before the visitor interacts with the form', async () => {
  const { helper, log } = fakeHelper(() => '');
  const t = boot({ helper, recaptcha: { invisible: true } });
  await t.ready();
  await flush();
  assert.equal(log.loadApi, 0);
  assert.equal(log.tokens, 0);
  assert.equal(log.created.length, 0);
  assert.equal(helperScripts(t).length, 0);
  focus(t);
  await flush();
  assert.equal(log.loadApi, 1);
});

test('it shows a message and does not post when the checkbox is not ticked', async () => {
  const { helper } = fakeHelper(() => '');
  const t = boot({ helper });
  await fillAndSubmit(t);
  assert.equal(t.q('[data-ffb-status]').textContent, 'Please confirm you are not a robot.');
  assert.equal(t.submitCalls().length, 0);
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
});

test('it executes the invisible widget before posting for invisible and v3 types', async () => {
  const { helper, log } = fakeHelper(() => 'tok-inv');
  const t = boot({ helper, recaptcha: { invisible: true } });
  await fillAndSubmit(t);
  assert.equal(log.tokens, 1);
  assert.equal(log.created[0].cfg.invisible, true);
  assert.equal(t.submitCalls().length, 1);
});

test('it lets the visitor submit again after closing the invisible challenge', async () => {
  let release;
  const { helper, log } = fakeHelper(() => new Promise((resolve) => { release = resolve; }));
  const t = boot({ helper, recaptcha: { invisible: true } });
  await fillAndSubmit(t);
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
  assert.equal(t.submitCalls().length, 0);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(log.tokens, 2);
  release('tok-late');
  await flush();
  assert.equal(t.submitCalls().length, 1);
});

test('it posts the token as g-recaptcha-response', async () => {
  const { helper } = fakeHelper(() => 'tok-abc');
  const t = boot({ helper });
  await fillAndSubmit(t);
  const body = t.submitCalls()[0].init.body;
  assert.equal(body.get('g-recaptcha-response'), 'tok-abc');
});

test('it resets the widget after any unsuccessful response or network failure', async () => {
  for (const status of [400, 403, 413, 422, 429, 500]) {
    const { helper, log } = fakeHelper(() => 'tok');
    const t = boot({ helper, respond: () => jsonResponse(status, { success: false, message: 'No', errors: {} }) });
    await fillAndSubmit(t);
    assert.equal(log.resets, 1, 'status ' + status);
  }
  const { helper, log } = fakeHelper(() => 'tok');
  const t = boot({ helper, respond: () => new Error('offline') });
  await fillAndSubmit(t);
  assert.equal(log.resets, 1);
});

test('it never stores the reCAPTCHA response in drafts', async () => {
  const { helper } = fakeHelper(() => 'tok-secret');
  const t = boot({ helper });
  await t.ready();
  const stray = t.doc.createElement('textarea');
  stray.name = 'g-recaptcha-response';
  stray.value = 'tok-secret';
  t.form.appendChild(stray);
  const input = t.q('[name="fields[name]"]');
  input.value = 'Jane';
  input.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  const raw = t.w.sessionStorage.getItem('ffb:1:7:3dd458cc8832');
  assert.ok(raw);
  assert.ok(!raw.includes('g-recaptcha-response'));
  assert.ok(!raw.includes('tok-secret'));
});

test('it places the reCAPTCHA widget above the form buttons', async () => {
  const t = boot();
  await t.ready();
  const box = t.q('[data-ffb-recaptcha]');
  const nav = t.q('[data-ffb-submit]').closest('.ffb-nav');
  assert.ok(box.compareDocumentPosition(nav) & t.w.Node.DOCUMENT_POSITION_FOLLOWING, 'widget comes before the buttons');
});
