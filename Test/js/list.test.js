'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');

const listField = {
  id: 'f_list01', kind: 'field', type: 'list', name: 'guests', label: 'Guests', required: true, rules: [], draft: true,
  options: [], settings: { max_items: 3 }, required_message: 'Required!', type_message: 'Up to 3!',
};

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

// Mirrors view/frontend/templates/item/list.phtml.
async function page(opts = {}) {
  const config = {
    schema: { pages: [{ id: 'p_1', items: [listField] }] },
    draft: { mode: 'session', key: 'ffb-test', ttlHours: 1 },
    submitUrl: '/submit', tokenUrl: '/token', messages: {},
  };
  const html = `<div data-ffb-root data-ffb-config='${JSON.stringify(config)}'><form data-ffb-form>
    <div data-ffb-page="p_1"><fieldset data-ffb-item="f_list01" data-ffb-name="guests" data-ffb-type="list">
      <div data-ffb-list data-ffb-list-max="3" data-ffb-list-item="Item %1" data-ffb-list-remove-label="Remove item %1">
        <ol><li data-ffb-list-row><input type="text" id="ffb-1-guests" name="fields[guests][]" aria-label="Item 1">
          <button type="button" data-ffb-list-remove hidden aria-label="Remove item 1">x</button></li></ol>
        <button type="button" data-ffb-list-add>Add another</button>
      </div><p data-ffb-error hidden></p></fieldset></div>
    <button data-ffb-submit type="submit">Go</button></form></div>`;
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  if (opts.storage) {
    dom.window.sessionStorage.setItem('ffb-test', opts.storage);
  }
  dom.window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't' }) });
  loadStorefront(dom.window);
  await settle();
  const doc = dom.window.document;
  const inputs = () => Array.from(doc.querySelectorAll('[data-ffb-list-row] input'));
  const removes = () => Array.from(doc.querySelectorAll('[data-ffb-list-remove]'));
  const add = doc.querySelector('[data-ffb-list-add]');
  const type = (input, value) => {
    input.value = value;
    input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  };
  return { w: dom.window, doc, inputs, removes, add, type };
}

test('it adds rows up to the maximum, focuses the new row and hides the add button at the limit', async () => {
  const t = await page();
  assert.equal(t.removes()[0].hidden, true);
  t.add.click();
  assert.equal(t.inputs().length, 2);
  assert.equal(t.doc.activeElement, t.inputs()[1]);
  assert.equal(t.inputs()[1].id, '');
  assert.equal(t.inputs()[1].name, 'fields[guests][]');
  assert.equal(t.inputs()[1].getAttribute('aria-label'), 'Item 2');
  assert.deepEqual(t.removes().map((b) => b.hidden), [false, false]);
  t.add.click();
  assert.equal(t.inputs().length, 3);
  assert.equal(t.add.hidden, true);
});

test('it removes a row, renumbers the rest and focuses the previous row', async () => {
  const t = await page();
  t.add.click();
  t.add.click();
  t.type(t.inputs()[0], 'Ann');
  t.type(t.inputs()[1], 'Bob');
  t.type(t.inputs()[2], 'Cy');
  t.removes()[1].click();
  assert.deepEqual(t.inputs().map((i) => i.value), ['Ann', 'Cy']);
  assert.equal(t.inputs()[1].getAttribute('aria-label'), 'Item 2');
  assert.equal(t.removes()[1].getAttribute('aria-label'), 'Remove item 2');
  assert.equal(t.doc.activeElement, t.inputs()[0]);
  assert.equal(t.add.hidden, false);
});

test('it saves the items as a draft and restores the same number of rows', async () => {
  const t = await page();
  t.type(t.inputs()[0], 'Ann');
  t.add.click();
  t.type(t.inputs()[1], 'Bob');
  t.inputs()[1].dispatchEvent(new t.w.Event('change', { bubbles: true }));
  const saved = JSON.parse(t.w.sessionStorage.getItem('ffb-test'));
  assert.deepEqual(saved.values.guests, ['Ann', 'Bob']);

  const r = await page({ storage: JSON.stringify(saved) });
  assert.deepEqual(r.inputs().map((i) => i.value), ['Ann', 'Bob']);
  assert.equal(r.inputs()[0].id, 'ffb-1-guests');
});

test('it requires at least one non-empty item', async () => {
  const t = await page();
  t.add.click();
  t.doc.querySelector('form').dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  const error = t.doc.querySelector('[data-ffb-error]');
  assert.equal(error.hidden, false);
  assert.equal(error.textContent, 'Required!');
});
