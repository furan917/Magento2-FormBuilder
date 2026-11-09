'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront, core } = require('./helpers/load-storefront');
const cases = JSON.parse(read('Test/fixtures/field-values.json'));

test('it passes every case in the shared field values fixture', () => {
  assert.ok(cases.length > 0);
  for (const c of cases) {
    const field = Object.assign({}, c.field);
    const normalized = core.normalizeValue(field, c.raw);
    assert.deepEqual(normalized, c.normalized, c.name);
    const error = core.validateField(field, c.raw, {});
    assert.equal(error === null, c.valid, `${c.name}: ${error}`);
    if (c.message) assert.equal(error, c.message, c.name);
  }
});

test('it uses the rows message from the public schema for incomplete matrices', () => {
  const field = { name: 'm', type: 'matrix', required: true, rows_message: 'Translated rows', options: [{ label: 'Y', value: 'y' }], settings: { rows: ['A', 'B'] } };
  assert.equal(core.validateField(field, { 0: 'y' }, {}), 'Translated rows');
  assert.equal(core.validateField(field, { 0: 'y', 1: 'y' }, {}), null);
});

test('it validates already normalised matrix values the same way', () => {
  const field = { name: 'm', type: 'matrix', required: true, options: [{ label: 'Y', value: 'y' }], settings: { rows: ['A'] } };
  assert.equal(core.validateField(field, ['A: Y'], {}), null);
});

test('it treats an empty answer map as empty', () => {
  assert.equal(core.isEmpty({}), true);
  assert.equal(core.isEmpty({ 0: 'y' }), false);
});

const matrixField = {
  id: 'f_matrix1', kind: 'field', type: 'matrix', name: 'grid', label: 'Grid', required: true, rules: [], draft: true,
  options: [{ label: 'Good', value: 'g' }, { label: 'Bad', value: 'b' }],
  settings: { rows: ['Speed', 'Price'], require_all: true }, required_message: 'Required!', rows_message: 'Every row!',
};

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

async function page(opts = {}) {
  const radios = [0, 1].map((r) => ['g', 'b'].map((v) => `<input type="radio" name="fields[grid][${r}]" value="${v}">`).join('')).join('');
  const config = {
    schema: { pages: [{ id: 'p_1', items: [matrixField] }] },
    draft: { mode: 'session', key: 'ffb-test', ttlHours: 1 },
    submitUrl: '/submit', tokenUrl: '/token', messages: {},
  };
  const html = `<div data-ffb-root data-ffb-config='${JSON.stringify(config)}'><form data-ffb-form>
    <div data-ffb-page="p_1"><fieldset data-ffb-item="f_matrix1" data-ffb-name="grid" data-ffb-type="matrix">${radios}<p data-ffb-error hidden></p></fieldset></div>
    <button data-ffb-submit type="submit">Go</button></form></div>`;
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  if (opts.storage) {
    dom.window.sessionStorage.setItem('ffb-test', opts.storage);
  }
  dom.window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't' }) });
  loadStorefront(dom.window);
  await settle();
  return dom.window;
}

test('it saves and restores the matrix answer map as a draft', async () => {
  const w = await page();
  const radio = w.document.querySelector('[name="fields[grid][1]"][value="b"]');
  radio.checked = true;
  radio.dispatchEvent(new w.Event('change', { bubbles: true }));
  const saved = JSON.parse(w.sessionStorage.getItem('ffb-test'));
  assert.deepEqual(saved.values.grid, { 1: 'b' });

  const restored = await page({ storage: JSON.stringify(saved) });
  const checked = Array.from(restored.document.querySelectorAll('input:checked')).map((c) => c.name + '=' + c.value);
  assert.deepEqual(checked, ['fields[grid][1]=b']);
});

test('it shows the incomplete rows error when submitting a partly answered matrix', async () => {
  const w = await page();
  w.document.querySelector('[name="fields[grid][0]"][value="g"]').checked = true;
  w.document.querySelector('form').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  const error = w.document.querySelector('[data-ffb-error]');
  assert.equal(error.hidden, false);
  assert.equal(error.textContent, 'Every row!');
});

test('it shows the library format message for a type error only when the answer breaks that format too', () => {
  const phone = { name: 'p', type: 'tel', rules: [], type_message: 'Bad phone', format: { pattern: '0[0-9]{10}', message: 'UK phone please' } };
  assert.equal(core.validateField(phone, '12', {}), 'UK phone please');
  const email = { name: 'e', type: 'email', rules: [], type_message: 'Bad email', format: { pattern: '[^h]*', message: 'No links' } };
  assert.equal(core.validateField(email, 'abc', {}), 'Bad email');
});
