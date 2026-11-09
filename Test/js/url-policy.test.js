'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const EVIL = 'https://evil.test';
const CDN_SCRIPT = 'https://cdn.test/static/version1/frontend/Magento/luma/en_US/Furan_FormBuilder/js/ffb-form.js';

const patchConfig = (patch, currentScript) => (w) => {
  const el = w.document.querySelector('[data-ffb-root]');
  const config = JSON.parse(el.getAttribute('data-ffb-config'));
  patch(config);
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  if (currentScript) {
    Object.defineProperty(w.document, 'currentScript', { configurable: true, get: () => ({ src: currentScript }) });
  }
};

const scripts = (doc) => Array.from(doc.querySelectorAll('script[src]')).map((s) => s.src);

test('it never posts answers to a submit URL on another origin', async () => {
  const t = boot({
    fields: [{ name: 'note' }],
    beforeInit: patchConfig((c) => { c.submitUrl = EVIL + '/collect'; }),
  });
  t.input('note').value = 'secret';
  t.fire(t.input('note'), 'input');
  t.fire(t.form, 'submit');
  await flush(30);

  assert.equal(t.calls.some((c) => c.url.startsWith(EVIL)), false);
  assert.equal(t.q('[data-ffb-status]').textContent, 'Net');
});

test('it never fetches a token or server draft from another origin', async () => {
  const t = boot({
    fields: [{ name: 'note' }],
    context: { eager: true, productId: null, loginUrl: '' },
    draft: { mode: 'local', key: 'ffb:1:1:abc', ttlHours: 24, serverUrl: EVIL + '/draft' },
    beforeInit: patchConfig((c) => { c.tokenUrl = EVIL + '/token?form_id=1'; }),
  });
  t.fire(t.input('note'), 'input');
  await flush(30);

  assert.deepEqual(t.calls.filter((c) => c.url.startsWith(EVIL)), []);
});

test('it drops a sign-in link that points to another origin', async () => {
  const t = boot({
    fields: [{ name: 'note' }],
    context: { eager: true, productId: null, loginUrl: EVIL + '/login' },
    token: { token: 'tok.1', customer: false, access: { allowed: false, reason: 'login', loginUrl: EVIL + '/login2' } },
  });
  await flush(30);

  assert.ok(t.q('[data-ffb-gate]'));
  assert.equal(t.doc.querySelectorAll('[data-ffb-gate] a').length, 0);
});

test('it keeps a same-origin sign-in link', async () => {
  const t = boot({
    fields: [{ name: 'note' }],
    context: { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' },
    token: { token: 'tok.1', customer: false, access: { allowed: false, reason: 'login' } },
  });
  await flush(30);

  assert.equal(t.doc.querySelectorAll('[data-ffb-gate] a').length, 2);
});

test('it loads scripts only from the page origin, the static directory it was served from, or Google', async () => {
  const load = async (scriptUrl) => {
    const t = boot({
      fields: [{ name: 'note' }],
      beforeInit: patchConfig((c) => {
        c.recaptcha = { scriptUrl, rendering: { sitekey: 'k' }, invisible: true };
      }, CDN_SCRIPT),
    });
    t.fire(t.input('note'), 'focusin');
    await flush(20);
    return scripts(t.doc);
  };

  assert.deepEqual(await load(EVIL + '/x.js'), []);
  assert.deepEqual(await load('https://cdn.test/other/x.js'), []);
  assert.deepEqual(await load('javascript:alert(1)'), []);
  const fromCdn = 'https://cdn.test/static/version1/frontend/Magento/luma/en_US/Furan_FormBuilder/js/ffb-recaptcha.js';
  assert.deepEqual(await load(fromCdn), [fromCdn]);
  assert.deepEqual(await load('https://shop.test/static/x/Furan_FormBuilder/js/ffb-recaptcha.js'),
    ['https://shop.test/static/x/Furan_FormBuilder/js/ffb-recaptcha.js']);
  assert.deepEqual(await load('https://www.gstatic.com/recaptcha/x.js'), ['https://www.gstatic.com/recaptcha/x.js']);
});
