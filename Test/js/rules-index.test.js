'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const src = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/js/rules-index.js'), 'utf8');

function boot() {
  const dom = new JSDOM(`<form data-ffb-rules-filters action="/rules" method="get"><select name="sort"><option value="name">A</option><option value="usage">B</option></select></form>
    <section data-ffb-builtin>
      <div data-ffb-builtin-tools hidden><input data-ffb-builtin-search>
        <button data-ffb-builtin-chip="" aria-pressed="true"></button><button data-ffb-builtin-chip="Phone" aria-pressed="false"></button></div>
      <div data-ffb-builtin-group="Postcodes"><table><tbody>
        <tr data-ffb-builtin-row data-text="uk postcode sw1a 1aa"></tr><tr data-ffb-builtin-row data-text="us zip code 90210"></tr></tbody></table></div>
      <div data-ffb-builtin-group="Phone"><table><tbody><tr data-ffb-builtin-row data-text="uk phone number 020"></tr></tbody></table></div>
      <p data-ffb-builtin-empty hidden></p>
    </section>`, { runScripts: 'outside-only', url: 'https://admin.test/' });
  const w = dom.window;
  w.submitted = 0;
  w.HTMLFormElement.prototype.submit = function () { w.submitted++; };
  w.eval(src);
  return w;
}
const visible = (w) => Array.from(w.document.querySelectorAll('[data-ffb-builtin-row]'))
  .filter((r) => !r.hidden && !r.closest('[data-ffb-builtin-group]').hidden).map((r) => r.getAttribute('data-text'));

test('it shows the built-in search tools only when scripts run', () => {
  assert.equal(boot().document.querySelector('[data-ffb-builtin-tools]').hidden, false);
});

test('it filters built-in rules by text across groups', () => {
  const w = boot();
  const input = w.document.querySelector('[data-ffb-builtin-search]');
  input.value = 'UK';
  input.dispatchEvent(new w.Event('input'));
  assert.deepEqual(visible(w), ['uk postcode sw1a 1aa', 'uk phone number 020']);
});

test('it limits built-in rules to the chosen category', () => {
  const w = boot();
  w.document.querySelector('[data-ffb-builtin-chip="Phone"]').click();
  assert.deepEqual(visible(w), ['uk phone number 020']);
  assert.equal(w.document.querySelector('[data-ffb-builtin-chip="Phone"]').getAttribute('aria-pressed'), 'true');
});

test('it says so when no built-in rule matches', () => {
  const w = boot();
  const input = w.document.querySelector('[data-ffb-builtin-search]');
  input.value = 'nothing';
  input.dispatchEvent(new w.Event('input'));
  assert.equal(w.document.querySelector('[data-ffb-builtin-empty]').hidden, false);
});

test('it applies a saved-rule dropdown change straight away', () => {
  const w = boot();
  const select = w.document.querySelector('select');
  select.value = 'usage';
  select.dispatchEvent(new w.Event('change'));
  assert.equal(w.submitted, 1);
});
