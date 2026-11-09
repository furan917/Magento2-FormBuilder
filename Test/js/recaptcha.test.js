'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const src = fs.readFileSync(path.resolve(__dirname, '../../view/frontend/web/js/ffb-recaptcha.js'), 'utf8');
const SCRIPT_URL = 'https://shop.test/static/ffb-recaptcha.js';

function boot(opts = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><form id="a"><div class="slot"></div></form><form id="b"><div class="slot"></div></form></body></html>', {
    runScripts: 'outside-only',
    url: 'https://shop.test/forms/x',
  });
  const w = dom.window;
  const rendered = [];
  if (opts.grecaptcha) {
    w.grecaptcha = {
      ready: (cb) => cb(),
      render: (el, params) => { rendered.push({ el, params }); return rendered.length - 1; },
      getResponse: () => 'tok-checkbox',
      execute: () => {},
      reset: () => {},
      ...opts.grecaptcha,
    };
  }
  w.eval(src);
  const slots = w.document.querySelectorAll('.slot');
  return { w, rendered, slots, api: w.FuranFormBuilder.recaptcha };
}

const googleScripts = (w) => w.document.querySelectorAll('script[src*="/recaptcha/api.js"]');
const flush = () => new Promise((r) => setTimeout(r, 5));

const checkbox = { rendering: { sitekey: 'k', size: 'normal', theme: 'light', hl: 'de' }, invisible: false };

test('it loads the Google script once for several forms on a page', async () => {
  const { w, slots, api } = boot();
  const a = api.create(slots[0], checkbox);
  const b = api.create(slots[1], checkbox);
  const pa = a.getToken();
  const pb = b.getToken();

  const scripts = googleScripts(w);
  assert.equal(scripts.length, 1);
  const url = new URL(scripts[0].src);
  assert.equal(url.searchParams.get('render'), 'explicit');
  assert.equal(url.searchParams.get('onload'), 'ffbRecaptchaOnload');
  assert.equal(url.searchParams.get('hl'), 'de');

  w.grecaptcha = {
    render: () => 1, getResponse: () => 'tok', execute() {}, reset() {},
  };
  w.ffbRecaptchaOnload();
  assert.equal(await pa, 'tok');
  assert.equal(await pb, 'tok');
  assert.equal(googleScripts(w).length, 1);
});

test('it reuses an already loaded Google reCAPTCHA API instead of loading it again', async () => {
  const { w, slots, api, rendered } = boot({ grecaptcha: {} });
  const token = await api.create(slots[0], checkbox).getToken();

  assert.equal(token, 'tok-checkbox');
  assert.equal(googleScripts(w).length, 0);
  assert.equal(rendered.length, 1);
});

test('it waits for an api.js script another widget already added instead of adding a second', async () => {
  const { w, slots, api, rendered } = boot();
  const s = w.document.createElement('script');
  s.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
  w.document.head.appendChild(s);

  const pending = api.create(slots[0], checkbox).getToken();
  await flush();
  assert.equal(rendered.length, 0);
  w.grecaptcha = { render: () => 3, getResponse: () => 'late', execute() {}, reset() {} };
  assert.equal(await pending, 'late');
  assert.equal(googleScripts(w).length, 1);
});

test('it renders a checkbox widget for the v2 checkbox type', async () => {
  const { slots, api, rendered } = boot({ grecaptcha: {} });
  const widget = api.create(slots[0], checkbox);
  await widget.getToken();
  await widget.getToken();

  assert.equal(rendered.length, 1);
  assert.equal(rendered[0].el, slots[0]);
  assert.equal(rendered[0].params.sitekey, 'k');
  assert.equal(rendered[0].params.size, 'normal');
  assert.equal(rendered[0].params.hl, 'de');
});

test('it resolves the invisible token from the render callback and reuses a pending execute', async () => {
  const executed = [];
  const { slots, api, rendered } = boot({ grecaptcha: { execute: (id) => executed.push(id) } });
  const widget = api.create(slots[0], { rendering: { sitekey: 'k', size: 'invisible' }, invisible: true });
  const first = widget.getToken();
  await flush();
  const second = widget.getToken();
  await flush();
  rendered[0].params.callback('tok-inv');

  assert.equal(await first, 'tok-inv');
  assert.equal(await second, 'tok-inv');
  assert.deepEqual(executed, [0, 0]);
});

test('it rejects a pending invisible token on error and resets the widget', async () => {
  const resets = [];
  const { slots, api, rendered } = boot({ grecaptcha: { reset: (id) => resets.push(id) } });
  const widget = api.create(slots[0], { rendering: { sitekey: 'k' }, invisible: true });
  const pending = widget.getToken();
  await flush();
  rendered[0].params['error-callback']();
  await assert.rejects(pending);

  widget.reset();
  assert.deepEqual(resets, [0]);
});
