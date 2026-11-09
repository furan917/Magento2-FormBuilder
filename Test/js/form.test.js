'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront, source } = require('./helpers/load-storefront');
const snapshot = read('Test/fixtures/rendered-form.html')
  .replace(/<script src[^>]*><\/script>/g, '')
  .replace(/<link[^>]*>/g, '');

const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));

function jsonResponse(status, body) {
  return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) };
}

function boot(opts = {}) {
  const dom = new JSDOM(snapshot, {
    runScripts: 'outside-only',
    url: opts.url || 'https://shop.test/forms/contact',
    ...(opts.referrer ? { referrer: opts.referrer } : {}),
  });
  const w = dom.window;
  const doc = w.document;
  if (opts.config) {
    const el = doc.querySelector('[data-ffb-root]');
    const cfg = JSON.parse(el.getAttribute('data-ffb-config'));
    opts.config(cfg);
    el.setAttribute('data-ffb-config', JSON.stringify(cfg));
  }
  const calls = [];
  w.fetch = opts.fetch || ((url, init) => {
    calls.push({ url, init });
    if (String(url).includes('/token')) {
      return Promise.resolve(jsonResponse(200, { token: 'tok.123' }));
    }
    return Promise.resolve(jsonResponse(200, { success: true, message: 'Thanks!', errors: {}, redirect: null }));
  });
  if (opts.fetch) {
    const inner = opts.fetch;
    w.fetch = (url, init) => { calls.push({ url, init }); return inner(url, init); };
  }
  if (opts.beforeEval) opts.beforeEval(w);
  loadStorefront(w);
  const ready = () => {
    if (doc.readyState === 'loading') return new Promise((r) => doc.addEventListener('DOMContentLoaded', r));
    return Promise.resolve();
  };
  const q = (s) => doc.querySelector(s);
  const fire = (el, type) => el.dispatchEvent(new w.Event(type, { bubbles: true }));
  const type = (name, value) => {
    const el = q(`[name="fields[${name}]"]`);
    el.value = value;
    fire(el, 'input');
    fire(el, 'change');
    return el;
  };
  const choose = (name, value) => {
    const el = q(`[name="fields[${name}]"][value="${value}"]`);
    el.checked = true;
    fire(el, 'change');
    return el;
  };
  const page = (id) => q(`[data-ffb-page="${id}"]`);
  const submitCalls = () => calls.filter((c) => !String(c.url).includes('/token'));
  return { dom, w, doc, q, fire, type, choose, page, calls, submitCalls, ready, rootEl: q('[data-ffb-root]'), form: q('[data-ffb-form]') };
}

const next = (t) => t.q('[data-ffb-next]').click();

async function toPageTwo(t) {
  t.type('name', 'Jane Doe');
  next(t);
}

test('it shows only the first page and adds the js class', async () => {
  const t = boot();
  await t.ready();
  assert.ok(t.rootEl.classList.contains('ffb-js'));
  assert.equal(t.page('p_aaaa01').hidden, false);
  assert.equal(t.page('p_bbbb02').hidden, true);
  assert.equal(t.q('[data-ffb-step="p_aaaa01"]').getAttribute('aria-current'), 'step');
  assert.equal(t.q('[data-ffb-step="p_bbbb02"]').hasAttribute('aria-current'), false);
  assert.equal(t.q('[data-ffb-prev]').hidden, true);
  assert.equal(t.q('[data-ffb-next]').hidden, false);
  assert.equal(t.q('[data-ffb-submit]').hidden, true);
});

test('it blocks next until the current page is valid and focuses the first error', async () => {
  const t = boot();
  await t.ready();
  next(t);
  assert.equal(t.page('p_aaaa01').hidden, false);
  const err = t.q('#ffb-7-name-error');
  assert.equal(err.hidden, false);
  assert.equal(err.textContent, 'This field is required.');
  assert.equal(t.q('#ffb-7-name').getAttribute('aria-invalid'), 'true');
  assert.equal(t.doc.activeElement, t.q('#ffb-7-name'));
  t.type('name', 'Jane Doe');
  assert.equal(err.hidden, true);
  assert.equal(t.q('#ffb-7-name').hasAttribute('aria-invalid'), false);
  next(t);
  assert.equal(t.page('p_aaaa01').hidden, true);
  assert.equal(t.page('p_bbbb02').hidden, false);
  assert.equal(t.q('[data-ffb-step="p_bbbb02"]').getAttribute('aria-current'), 'step');
  assert.equal(t.q('[data-ffb-submit]').hidden, false);
  assert.equal(t.q('[data-ffb-next]').hidden, true);
  t.q('[data-ffb-prev]').click();
  assert.equal(t.page('p_aaaa01').hidden, false);
});

test('it shows and hides fields as conditions change and disables hidden inputs', async () => {
  const t = boot();
  await t.ready();
  const wrapper = t.q('[data-ffb-item="f_mail01"]');
  const input = t.q('#ffb-7-email');
  assert.equal(wrapper.hidden, true);
  assert.equal(input.disabled, true);
  t.choose('contact', 'email');
  assert.equal(wrapper.hidden, false);
  assert.equal(input.disabled, false);
  t.choose('contact', 'phone');
  assert.equal(wrapper.hidden, true);
  assert.equal(input.disabled, true);
});

test('it restores a saved draft and offers to start over', async () => {
  const key = 'ffb:1:7:3dd458cc8832';
  const t = boot({
    beforeEval: (w) => {
      w.sessionStorage.setItem(key, JSON.stringify({ v: 1, savedAt: Date.now(), page: 'p_bbbb02', values: { name: 'Saved Sam', contact: 'email', message: 'hello there world' } }));
    },
  });
  await t.ready();
  assert.equal(t.q('#ffb-7-name').value, 'Saved Sam');
  assert.equal(t.q('[name="fields[contact]"][value="email"]').checked, true);
  assert.equal(t.q('#ffb-7-email').disabled, false);
  assert.equal(t.page('p_bbbb02').hidden, false);
  const status = t.q('[data-ffb-status]');
  assert.ok(status.textContent.includes('We restored your unsent answers.'));
  const btn = status.querySelector('button');
  assert.equal(btn.textContent, 'Start over');
  btn.click();
  assert.equal(t.q('#ffb-7-name').value, '');
  assert.equal(t.w.sessionStorage.getItem(key), null);
  assert.equal(status.textContent, '');
  assert.equal(t.page('p_aaaa01').hidden, false);
  assert.equal(t.page('p_bbbb02').hidden, true);
});

test('it saves drafts for ordinary fields on change', async () => {
  const key = 'ffb:1:7:3dd458cc8832';
  const t = boot();
  await t.ready();
  t.type('name', 'Jane');
  const saved = JSON.parse(t.w.sessionStorage.getItem(key));
  assert.equal(saved.v, 1);
  assert.equal(saved.page, 'p_aaaa01');
  assert.equal(saved.values.name, 'Jane');
});

test('it never stores file hidden or excluded fields in the draft', async () => {
  const key = 'ffb:1:7:3dd458cc8832';
  const t = boot({
    url: 'https://shop.test/forms/contact?utm=news',
    config: (cfg) => { cfg.schema.pages[0].items[0].draft = false; },
  });
  await t.ready();
  assert.equal(t.q('[name="fields[source]"]').value, 'news');
  t.type('name', 'Secret');
  t.type('email', 'a@b.co');
  t.type('message', 'a long enough message');
  t.q('[name="form_key"]').value = 'KEY';
  t.q('[name="ffb_token"]').value = 'TOK';
  t.choose('contact', 'email');
  const raw = t.w.sessionStorage.getItem(key);
  const saved = JSON.parse(raw);
  assert.deepEqual(Object.keys(saved.values).sort(), ['contact', 'email', 'message']);
  for (const bad of ['Secret', 'news', 'KEY', 'TOK', 'cv', 'form_key', 'ffb_token', 'ffb_website']) {
    assert.ok(!raw.includes(bad), bad);
  }
});

test('it ignores broken or expired drafts and storage errors', async () => {
  const key = 'ffb:1:7:3dd458cc8832';
  const broken = boot({ beforeEval: (w) => w.sessionStorage.setItem(key, '{not json') });
  await broken.ready();
  assert.equal(broken.w.sessionStorage.getItem(key), null);
  assert.equal(broken.q('[data-ffb-status]').textContent, '');

  const expired = boot({
    beforeEval: (w) => w.sessionStorage.setItem(key, JSON.stringify({ v: 1, savedAt: Date.now() - 25 * 3600 * 1000, page: 'p_aaaa01', values: { name: 'Old' } })),
  });
  await expired.ready();
  assert.equal(expired.w.sessionStorage.getItem(key), null);
  assert.equal(expired.q('#ffb-7-name').value, '');

  const throwing = boot({
    beforeEval: (w) => {
      Object.defineProperty(w, 'sessionStorage', { get() { throw new Error('denied'); }, configurable: true });
    },
  });
  await throwing.ready();
  throwing.type('name', 'Still works');
  assert.equal(throwing.q('#ffb-7-name').value, 'Still works');

  const failingStorage = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
  Object.defineProperty(broken.w, 'sessionStorage', { get: () => failingStorage, configurable: true });
  const sync = broken.w.FuranFormBuilder.createDraftSync({
    cfg: { mode: 'session', key: 'k', ttlHours: 1 },
    formId: 7,
    fields: [{ name: 'a', type: 'text' }],
    readValues: () => ({ a: 'b' }),
    applyValues: () => {},
    getPage: () => 'p',
    isCustomer: () => false,
    isEditing: () => false,
    isLocked: () => false,
    prefilledJson: () => undefined,
  });
  assert.equal(sync.restore(), null);
  sync.save();
});

test('it clears the fix the highlighted fields status once the last error is fixed', async () => {
  const t = boot();
  await t.ready();
  await toPageTwo(t);
  t.q('[data-ffb-submit]').click();
  const status = t.q('[data-ffb-status]');
  assert.equal(status.textContent, 'Please fix the highlighted fields.');
  t.type('message', 'this is long enough');
  t.fire(t.q('[name="fields[message]"]'), 'blur');
  assert.equal(t.q('[data-ffb-name="message"] [data-ffb-error]').hidden, true);
  assert.equal(status.textContent, '');
});

test('it clears the fix the highlighted fields status when a condition hides the only invalid field', async () => {
  const t = boot();
  await t.ready();
  t.type('name', 'Jane Doe');
  t.choose('contact', 'email');
  t.type('email', 'jane@example.com');
  next(t);
  t.type('message', 'this is long enough');
  t.type('email', 'not-an-email');
  t.q('[data-ffb-submit]').click();
  const status = t.q('[data-ffb-status]');
  assert.equal(status.textContent, 'Please fix the highlighted fields.');
  t.choose('contact', 'phone');
  assert.equal(status.textContent, '');
});

test('it sends the form key and token with the submission', async () => {
  const t = boot({ beforeEval: (w) => { w.document.cookie = 'form_key=COOKIEKEY1234567; path=/'; } });
  await t.ready();
  t.type('name', 'Jane Doe');
  next(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  const sub = t.submitCalls()[0];
  assert.ok(sub, 'submit call made');
  assert.equal(sub.url, 'https://shop.test/furanform/form/submit/');
  assert.equal(sub.init.method, 'POST');
  assert.equal(sub.init.credentials, 'same-origin');
  assert.equal(sub.init.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(sub.init.headers.Accept, 'application/json');
  assert.equal(sub.init.body.get('form_key'), 'COOKIEKEY1234567');
  assert.equal(sub.init.body.get('ffb_token'), 'tok.123');
  assert.equal(sub.init.body.get('form_id'), '7');
  assert.equal(sub.init.body.get('fields[name]'), 'Jane Doe');
  const tokenCall = t.calls.find((c) => String(c.url).includes('/token'));
  assert.equal(tokenCall.init.credentials, 'same-origin');
  assert.equal(tokenCall.init.cache, 'no-store');
});

test('it generates a form key when none exists', async () => {
  const t = boot();
  await t.ready();
  const key = t.q('[name="form_key"]').value;
  assert.match(key, /^[A-Za-z0-9]{16}$/);
  assert.ok(t.doc.cookie.includes('form_key=' + key));
});

test('it clears the draft and shows the success message after a successful submit', async () => {
  const key = 'ffb:1:7:3dd458cc8832';
  const t = boot();
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  const ok = t.q('[data-ffb-success]');
  assert.equal(ok.hidden, false);
  assert.equal(ok.textContent, 'Thanks!');
  assert.equal(t.doc.activeElement, ok);
  assert.equal(t.form.hidden, true);
  assert.equal(t.w.sessionStorage.getItem(key), null);
  assert.equal(t.q('#ffb-7-name').value, '');
  assert.match(t.q('[name="form_key"]').value, /^[A-Za-z0-9]{16}$/);
});

test('it shows server field errors and keeps the data after a 422', async () => {
  const t = boot({
    fetch: (url) => Promise.resolve(String(url).includes('/token')
      ? jsonResponse(200, { token: 't' })
      : jsonResponse(422, { success: false, message: 'Bad', errors: { name: 'Name is taken', bogus: 'ignored' }, redirect: null })),
  });
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.page('p_aaaa01').hidden, false);
  assert.equal(t.q('#ffb-7-name-error').textContent, 'Name is taken');
  assert.equal(t.q('#ffb-7-name-error').hidden, false);
  assert.equal(t.q('#ffb-7-name').value, 'Jane Doe');
  assert.equal(t.q('#ffb-7-message').value, 'this is long enough');
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
  assert.equal(t.q('[data-ffb-submit]').hasAttribute('aria-busy'), false);
  assert.equal(t.q('[data-ffb-success]').hidden, true);
});

test('it keeps the data and re-enables submit after a network failure', async () => {
  const t = boot({
    fetch: (url) => (String(url).includes('/token')
      ? Promise.resolve(jsonResponse(200, { token: 't' }))
      : Promise.reject(new Error('offline'))),
  });
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.q('[data-ffb-status]').textContent, 'We could not send the form. Please check your connection and try again.');
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
  assert.equal(t.q('#ffb-7-message').value, 'this is long enough');
  assert.equal(t.form.hidden, false);
});

test('it shows the server message for other failures and re-enables submit', async () => {
  const t = boot({
    fetch: (url) => Promise.resolve(String(url).includes('/token')
      ? jsonResponse(200, { token: 't' })
      : jsonResponse(429, { success: false, message: 'Slow down', errors: {}, redirect: null })),
  });
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.q('[data-ffb-status]').textContent, 'Slow down');
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
});

test('it only follows same origin redirects', async () => {
  const cases = [
    ['https://evil.test/x', false],
    ['//evil.test/x', false],
    ['/\\evil.test', false],
    ['javascript:alert(1)', false],
    ['/thank-you', true],
    ['https://shop.test/thanks', true],
  ];
  for (const [redirect, allowed] of cases) {
    const t = boot({
      fetch: (url) => Promise.resolve(String(url).includes('/token')
        ? jsonResponse(200, { token: 't' })
        : jsonResponse(200, { success: true, message: 'ok', errors: {}, redirect })),
    });
    const navigated = [];
    t.w.FuranFormBuilder.navigate = (u) => navigated.push(u);
    await t.ready();
    await toPageTwo(t);
    t.type('message', 'this is long enough');
    t.q('[data-ffb-submit]').click();
    await flush();
    assert.deepEqual(navigated, allowed ? [redirect] : [], redirect);
  }
});

test('it works without require jquery knockout or hyva globals', async () => {
  const t = boot();
  await t.ready();
  for (const g of ['require', 'jQuery', '$', 'ko', 'hyva', 'define']) {
    assert.equal(typeof t.w[g], 'undefined', g);
  }
  assert.ok(t.rootEl.classList.contains('ffb-js'));
  assert.equal(typeof t.w.FuranFormBuilder.init, 'function');
  assert.equal(typeof t.w.FuranFormBuilder.createDraftSync, 'function');
});

test('it keeps inputs on non current pages enabled so their values are submitted', async () => {
  const t = boot();
  await t.ready();
  assert.equal(t.page('p_bbbb02').hidden, true);
  assert.equal(t.q('#ffb-7-message').disabled, false);
  t.type('message', 'hidden page value');
  t.type('name', 'Jane Doe');
  t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(t.page('p_bbbb02').hidden, false);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls()[0].init.body.get('fields[message]'), 'hidden page value');
});

test('it fills hidden fields from the query string page url and referrer', async () => {
  const t = boot({ url: 'https://shop.test/forms/contact?utm=' + 'x'.repeat(300) });
  await t.ready();
  const src = t.q('[name="fields[source]"]');
  assert.equal(src.value, 'x'.repeat(255));
  t.form.reset();
  t.w.FuranFormBuilder.init(t.rootEl);

  const modes = [['page_url', 'https://shop.test/forms/contact?a=1'], ['referrer', 'https://ref.test/page']];
  for (const [source, expected] of modes) {
    const m = boot({
      url: 'https://shop.test/forms/contact?a=1',
      referrer: 'https://ref.test/page',
      config: (cfg) => { cfg.schema.pages[1].items[2].settings.source = source; },
    });
    await m.ready();
    assert.equal(m.q('[name="fields[source]"]').value, expected, source);
  }
  const s = boot({ config: (cfg) => { cfg.schema.pages[1].items[2].settings.source = 'static'; } });
  s.q('[name="fields[source]"]').defaultValue = 'fixed';
  await s.ready();
  assert.equal(s.q('[name="fields[source]"]').value, 'fixed');

  const q = boot({ url: 'https://shop.test/forms/contact?utm=news' });
  await q.ready();
  q.type('name', 'Jane Doe');
  next(q);
  q.type('message', 'this is long enough');
  q.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(q.q('[name="fields[source]"]').value, 'news');
});

test('it blocks oversized or disallowed files before sending', async () => {
  const t = boot();
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  const cv = t.q('#ffb-7-cv');
  const setFile = (name, size) => Object.defineProperty(cv, 'files', { value: [{ name, size }], configurable: true });
  setFile('cv.exe', 100);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls().length, 0);
  assert.equal(t.q('#ffb-7-cv-error').hidden, false);
  assert.notEqual(t.q('#ffb-7-cv-error').textContent, '');
  setFile('cv.pdf', 3 * 1024 * 1024);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls().length, 0);
  assert.equal(t.q('#ffb-7-cv-error').hidden, false);
  setFile('CV.PDF', 1024);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls().length, 1);
});

test('it initialises each root only once even if the script loads twice', async () => {
  const t = boot();
  await t.ready();
  const initFn = t.w.FuranFormBuilder.init;
  t.w.eval(source('ffb-form.js'));
  assert.equal(t.w.FuranFormBuilder.init, initFn);
  t.w.FuranFormBuilder.init(t.rootEl);
  assert.equal(t.rootEl.dataset.ffbReady, '1');
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls().length, 1);
});

test('it handles enter key navigation and ignores enter in textareas', async () => {
  const t = boot();
  await t.ready();
  const enter = (el) => {
    const ev = new t.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    el.dispatchEvent(ev);
    return ev;
  };
  const nameEl = t.type('name', 'Jane Doe');
  const ev = enter(nameEl);
  assert.equal(ev.defaultPrevented, true);
  assert.equal(t.page('p_bbbb02').hidden, false);
  const area = t.q('#ffb-7-message');
  t.type('message', 'this is long enough');
  const ev2 = enter(area);
  assert.equal(ev2.defaultPrevented, false);
  await flush();
  assert.equal(t.submitCalls().length, 0);
});

test('it updates the range output on input', async () => {
  const t = boot();
  await t.ready();
  const wrap = t.doc.createElement('div');
  wrap.setAttribute('data-ffb-name', 'r');
  wrap.setAttribute('data-ffb-item', 'f_range1');
  wrap.innerHTML = '<input type="range" name="fields[r]" value="3"><output data-ffb-output>0</output>';
  t.form.querySelector('.ffb-grid').appendChild(wrap);
  const r = wrap.querySelector('input');
  r.value = '4';
  t.fire(r, 'input');
  assert.equal(wrap.querySelector('output').textContent, '4');
});

test('it reads the config from the root data attribute', async () => {
  const t = boot();
  await t.ready();
  assert.equal(t.rootEl.hasAttribute('data-ffb-config'), true);
  assert.equal(t.doc.querySelector('script[data-ffb-config]'), null);
  assert.ok(t.rootEl.classList.contains('ffb-js'));
});

test('it still works when scripts are moved to the end of the body', async () => {
  const t = boot({ beforeEval: (w) => {
    const body = w.document.body;
    Array.from(body.querySelectorAll('script')).forEach((s) => body.appendChild(s));
  } });
  await t.ready();
  assert.ok(t.rootEl.classList.contains('ffb-js'));
  assert.equal(t.page('p_aaaa01').hidden, false);
  assert.equal(t.page('p_bbbb02').hidden, true);
});

test('it trims and normalises values in the browser like the server', async () => {
  const t = boot();
  await t.ready();
  t.type('name', '  Jane\u0001\u007f Doe\n  ');
  next(t);
  t.type('message', '\n  line one  \nline two\u0000  \n\n');
  t.q('[data-ffb-submit]').click();
  await flush();
  const body = t.submitCalls()[0].init.body;
  assert.equal(body.get('fields[name]'), 'Jane Doe');
  assert.equal(body.get('fields[message]'), 'line one  \nline two');
});

test('it fetches a fresh token after a successful submission', async () => {
  let issued = 0;
  const t = boot({ fetch: (url) => {
    if (String(url).includes('/token')) {
      issued += 1;
      return Promise.resolve(jsonResponse(200, { token: 'tok.' + issued }));
    }
    return Promise.resolve(jsonResponse(200, { success: true, message: 'Thanks!', errors: {}, redirect: null }));
  } });
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(t.submitCalls()[0].init.body.get('ffb_token'), 'tok.1');
  assert.equal(t.q('[name="ffb_token"]').value, '');

  t.type('name', 'Again');
  await flush();
  assert.equal(issued, 2);
  assert.equal(t.q('[name="ffb_token"]').value, 'tok.2');
});

test('it keeps the token after a validation error response', async () => {
  let issued = 0;
  const t = boot({ fetch: (url) => {
    if (String(url).includes('/token')) {
      issued += 1;
      return Promise.resolve(jsonResponse(200, { token: 'tok.' + issued }));
    }
    return Promise.resolve(jsonResponse(422, { success: false, errors: { name: 'Bad' } }));
  } });
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
  t.type('name', 'Again');
  await flush();
  assert.equal(issued, 1);
  assert.equal(t.q('[name="ffb_token"]').value, 'tok.1');
});

test('it survives a malformed form key cookie', async () => {
  const t = boot({ beforeEval: (w) => { w.document.cookie = 'form_key=%E0%A4%A; path=/'; } });
  await t.ready();
  assert.ok(t.rootEl.classList.contains('ffb-js'));
  assert.match(t.q('[name="form_key"]').value, /^[A-Za-z0-9]{16}$/);
});

const REF = '0192f3a4-7b3c-7d1e-8a2f-5c6d7e8f9a0b';

function bootWithReference(opts = {}) {
  return boot({
    config: (cfg) => {
      cfg.showReference = opts.show !== false;
      Object.assign(cfg.messages, { reference: 'Your reference', copy: 'Copy', copied: 'Copied' });
    },
    fetch: (url) => Promise.resolve(String(url).includes('/token')
      ? jsonResponse(200, { token: 'tok.123' })
      : jsonResponse(200, { success: true, message: opts.message || 'Thanks!', reference: REF, errors: {}, redirect: null })),
    beforeEval: opts.clipboard ? (w) => Object.defineProperty(w.navigator, 'clipboard', { value: opts.clipboard }) : undefined,
  });
}

async function submitValid(t) {
  await t.ready();
  await toPageTwo(t);
  t.type('message', 'this is long enough');
  t.q('[data-ffb-submit]').click();
  await flush();
}

test('it shows the reference on its own labelled line under the success message', async () => {
  const t = bootWithReference({ clipboard: { writeText: () => Promise.resolve() } });
  await submitValid(t);

  const line = t.q('[data-ffb-success] .ffb-reference');
  assert.ok(line);
  assert.equal(t.q('[data-ffb-success] .ffb-success-text').textContent, 'Thanks!');
  assert.equal(line.querySelector('.ffb-reference-label').textContent, 'Your reference');
  assert.equal(line.querySelector('code').textContent, REF);
  assert.equal(line.querySelector('button').textContent, 'Copy');
  assert.equal(line.querySelector('button').type, 'button');
});

test('it leaves the reference line out when the message already shows the reference', async () => {
  const t = bootWithReference({ message: 'Booked. Quote ' + REF + ' if you call us.' });
  await submitValid(t);

  assert.equal(t.q('[data-ffb-success] .ffb-reference'), null);
  assert.equal(t.q('[data-ffb-success]').textContent, 'Booked. Quote ' + REF + ' if you call us.');
});

test('it leaves the reference line out when the form does not show it', async () => {
  const t = bootWithReference({ show: false });
  await submitValid(t);

  assert.equal(t.q('[data-ffb-success] .ffb-reference'), null);
  assert.equal(t.q('[data-ffb-success]').textContent, 'Thanks!');
});

test('it copies the reference and says so', async () => {
  const copied = [];
  const t = bootWithReference({ clipboard: { writeText: (v) => { copied.push(v); return Promise.resolve(); } } });
  await submitValid(t);

  const button = t.q('.ffb-reference button');
  button.click();
  await flush();
  assert.deepEqual(copied, [REF]);
  assert.equal(button.textContent, 'Copied');
});

test('it offers no copy button where the browser has no clipboard access', async () => {
  const t = bootWithReference();
  await submitValid(t);

  assert.ok(t.q('.ffb-reference code'));
  assert.equal(t.q('.ffb-reference button'), null);
});
