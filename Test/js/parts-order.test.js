'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const snapshot = fs.readFileSync(path.resolve(__dirname, '../fixtures/rendered-form.html'), 'utf8')
  .replace(/<script src[^>]*><\/script>/g, '')
  .replace(/<link[^>]*>/g, '');

// A part method called while the factory is still being built means the parts are already installed.
const partsInstalled = (call) => {
  try {
    call();
    return true;
  } catch (e) {
    return false;
  }
};

test('it creates drafts, submit and reCAPTCHA before any part is installed', async () => {
  const dom = new JSDOM(snapshot, { runScripts: 'outside-only', url: 'https://shop.test/forms/contact' });
  const w = dom.window;
  const el = w.document.querySelector('[data-ffb-root]');
  const cfg = JSON.parse(el.getAttribute('data-ffb-config'));
  cfg.recaptcha = { scriptUrl: 'https://shop.test/static/r.js', rendering: { sitekey: 'k', hl: 'en' }, invisible: false };
  el.setAttribute('data-ffb-config', JSON.stringify(cfg));
  w.fetch = () => new Promise(() => {});

  const order = [];
  const insertBefore = w.Node.prototype.insertBefore;
  w.Node.prototype.insertBefore = function (node, ref) {
    if (node.hasAttribute && node.hasAttribute('data-ffb-recaptcha')) { order.push('recaptcha'); }
    return insertBefore.call(this, node, ref);
  };
  const extension = (win) => {
    const ns = win.FuranFormBuilder;
    const draft = ns.createDraftSync;
    ns.createDraftSync = (o) => {
      order.push(partsInstalled(() => o.readValues()) ? 'draft after parts' : 'draft');
      return draft(o);
    };
    const create = ns.submit.create;
    ns.submit.create = (o) => {
      order.push(partsInstalled(() => o.getTokenUrl()) ? 'submit after parts' : 'submit');
      return create(o);
    };
  };
  loadStorefront(w, [extension]);
  await new Promise((resolve) => setTimeout(resolve, 20));
  assert.deepEqual(order, ['recaptcha', 'draft', 'submit']);
});
