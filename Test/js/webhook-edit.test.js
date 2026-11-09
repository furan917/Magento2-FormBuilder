'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const src = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/js/webhook-edit.js'), 'utf8');

function boot(html, clipboard) {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/' });
  if (clipboard) {
    Object.defineProperty(dom.window.navigator, 'clipboard', { value: clipboard, configurable: true });
  }
  dom.window.eval(src);
  return dom.window;
}

const scopeHtml = (disabled) => `<form>
  <input type="radio" name="scope_type" value="store" data-ffb-scope-type checked ${disabled}>
  <input type="radio" name="scope_type" value="website" data-ffb-scope-type ${disabled}>
  <fieldset data-ffb-scope-panel="website"></fieldset><fieldset data-ffb-scope-panel="store"></fieldset>
  <select data-ffb-field-mode ${disabled}><option value="all" selected>A</option><option value="selected">S</option></select>
  <textarea data-ffb-field-names></textarea></form>`;

test('it shows only the fieldset of the chosen scope', () => {
  const w = boot(scopeHtml(''));
  const d = w.document;
  assert.equal(d.querySelector('[data-ffb-scope-panel="store"]').hidden, false);
  assert.equal(d.querySelector('[data-ffb-scope-panel="website"]').hidden, true);
  const website = d.querySelector('input[value="website"]');
  website.checked = true;
  website.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(d.querySelector('[data-ffb-scope-panel="store"]').hidden, true);
  assert.equal(d.querySelector('[data-ffb-scope-panel="website"]').hidden, false);
});

test('it disables the field names while all non personal fields are sent', () => {
  const w = boot(scopeHtml(''));
  const d = w.document;
  const names = d.querySelector('textarea');
  assert.equal(names.disabled, true);
  const mode = d.querySelector('select');
  mode.value = 'selected';
  mode.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(names.disabled, false);
});

test('it does nothing on a read only or locked form', () => {
  const w = boot(scopeHtml('disabled'));
  const d = w.document;
  assert.equal(d.querySelector('[data-ffb-scope-panel="website"]').hidden, false);
  assert.equal(d.querySelector('textarea').disabled, false);
});

const secretHtml = `<section tabindex="-1" data-ffb-secret-section>
  <input id="s" value="whsec_abc" readonly>
  <button type="button" data-ffb-copy-secret="s" data-ffb-copied="Copied" hidden>Copy</button>
  <span role="status" data-ffb-copy-status></span></section>`;

test('it copies the secret and announces it', async () => {
  const written = [];
  const w = boot(secretHtml, { writeText: (t) => { written.push(t); return Promise.resolve(); } });
  const d = w.document;
  const button = d.querySelector('button');
  assert.equal(button.hidden, false);
  assert.equal(d.activeElement, d.querySelector('section'));
  button.click();
  await new Promise((r) => setTimeout(r, 0));
  assert.deepEqual(written, ['whsec_abc']);
  assert.equal(d.querySelector('[role="status"]').textContent, 'Copied');
});

test('it keeps the copy button hidden when the clipboard is unavailable', () => {
  const w = boot(secretHtml);
  assert.equal(w.document.querySelector('button').hidden, true);
});
