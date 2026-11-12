'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront, core } = require('./helpers/load-storefront');

const MESSAGES = {
  order: 'Server: end before start.',
  incomplete: 'Server: both dates.',
  min_days: 'Server: too short.',
  max_days: 'Server: too long.',
  min_date: 'Server: too early.',
  max_date: 'Server: too late.',
};
const rangeField = (settings = {}, extra = {}) => Object.assign({
  id: 'f_range1', kind: 'field', type: 'date_range', name: 'stay', label: 'Stay', required: false, rules: [], draft: true,
  options: [], settings, required_message: 'Required!', messages: MESSAGES,
}, extra);

test('it validates the shared date range fixtures identically to PHP', () => {
  const cases = JSON.parse(read('Test/fixtures/date-range-values.json'));
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.deepEqual(core.normalizeValue(c.field, c.raw), c.normalized, c.name);
    const message = core.validateField(c.field, c.raw, {});
    assert.equal(message === null, c.valid, c.name + ' -> ' + message);
  }
});

test('it shows the server translated message for each date range error', () => {
  const check = (settings, raw, key) => assert.equal(core.validateField(rangeField(settings), raw, {}), MESSAGES[key], key);
  check({}, { start: '2026-01-09', end: '2026-01-08' }, 'order');
  check({}, { start: '2026-01-09', end: '' }, 'incomplete');
  check({ min_days: 3 }, { start: '2026-01-01', end: '2026-01-02' }, 'min_days');
  check({ max_days: 2 }, { start: '2026-01-01', end: '2026-01-09' }, 'max_days');
  check({ min_date: '2026-02-01' }, { start: '2026-01-31', end: '2026-02-03' }, 'min_date');
  check({ max_date: '2026-02-28' }, { start: '2026-02-27', end: '2026-03-01' }, 'max_date');
  assert.equal(core.validateField(rangeField({}, { required: true }), { start: '', end: '' }, {}), 'Required!');
  const bare = rangeField({}, { messages: undefined });
  assert.equal(core.validateField(bare, { start: '2026-01-09', end: '2026-01-08' }, {}), 'The end date must be on or after the start date.');
});

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

// Mirrors view/frontend/templates/item/date_range.phtml.
async function page(opts = {}) {
  const config = {
    schema: { pages: [{ id: 'p_1', items: [rangeField()] }] },
    draft: { mode: 'session', key: 'ffb-test', ttlHours: 1 },
    submitUrl: '/submit', tokenUrl: '/token', messages: {},
  };
  const html = `<div data-ffb-root data-ffb-locale="en_GB" data-ffb-config='${JSON.stringify(config)}'><form data-ffb-form>
    <div data-ffb-page="p_1"><fieldset data-ffb-item="f_range1" data-ffb-name="stay" data-ffb-type="date_range">
      <input type="date" id="ffb-1-stay" name="fields[stay][start]"><input type="date" name="fields[stay][end]">
      <p data-ffb-error hidden></p></fieldset>
      <div data-ffb-summary data-ffb-edit-label="Edit" data-ffb-yes-label="Yes"><dl class="ffb-summary-list"></dl></div></div>
    <button data-ffb-submit type="submit">Go</button></form></div>`;
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  if (opts.storage) {
    dom.window.sessionStorage.setItem('ffb-test', opts.storage);
  }
  dom.window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't' }) });
  loadStorefront(dom.window);
  await settle();
  const doc = dom.window.document;
  const inputs = () => Array.from(doc.querySelectorAll('input[type="date"]'));
  const type = (input, value) => {
    input.value = value;
    input.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  };
  return { w: dom.window, doc, inputs, type };
}

test('it reads and writes the range for drafts and the summary', async () => {
  const t = await page();
  t.type(t.inputs()[0], '2026-01-02');
  t.type(t.inputs()[1], '2026-01-09');
  const saved = JSON.parse(t.w.sessionStorage.getItem('ffb-test'));
  assert.deepEqual(saved.values.stay, { start: '2026-01-02', end: '2026-01-09' });
  assert.equal(t.doc.querySelector('.ffb-summary-row dd').textContent, '2 Jan 2026 – 9 Jan 2026');

  const r = await page({ storage: JSON.stringify(saved) });
  assert.deepEqual(r.inputs().map((i) => i.value), ['2026-01-02', '2026-01-09']);
});

test('it submits the date range values unchanged', async () => {
  const t = await page();
  const bodies = [];
  t.w.fetch = (url, init) => {
    if (init && init.body) { bodies.push(init.body); }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't', success: true, message: 'ok' }) });
  };
  t.type(t.inputs()[0], '2026-12-10');
  t.type(t.inputs()[1], '2026-12-14');
  t.doc.querySelector('[data-ffb-submit]').click();
  await settle();
  const sent = bodies.find((b) => b && typeof b.get === 'function');
  assert.ok(sent, 'a FormData body was posted');
  assert.equal(sent.get('fields[stay][start]'), '2026-12-10');
  assert.equal(sent.get('fields[stay][end]'), '2026-12-14');
});
