'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const preview = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-preview.js'));

const doc = new JSDOM('<div></div>').window.document;
const opts = [{ label: 'Red', value: 'red' }, { label: 'Green', value: 'green' }, { label: 'Blue', value: 'blue' }];
const item = (type, extra) => Object.assign({ type, name: 'f_' + type, label: 'Label ' + type, placeholder: '', default: '', options: [], settings: {} }, extra || {});
const render = (type, extra, meta) => preview.control(doc, item(type, extra), meta || { code: type });

for (const type of ['date', 'time', 'datetime', 'month']) {
  test(`it marks ${type} previews with a picker icon`, () => {
    const node = render(type);
    assert.ok(node.classList.contains('ffb-admin-mock-input'));
    const icon = node.querySelector('svg.ffb-admin-icon');
    assert.ok(icon, 'picker icon');
    assert.equal(icon.querySelector('path').getAttribute('d'), preview.ICON_PATHS[type]);
  });
}

test('it previews colour fields as a swatch of the default colour', () => {
  const node = render('color', { default: '#1A7F5E' });
  const swatch = node.querySelector('.ffb-admin-mock-swatch');
  assert.ok(swatch);
  assert.equal(swatch.style.backgroundColor, 'rgb(26, 127, 94)');
  assert.equal(node.querySelector('.ffb-admin-mock-text').textContent, '#1a7f5e');
});

test('it previews colour fields without a valid default as not chosen', () => {
  for (const value of ['', 'red;background:url(x)']) {
    const node = render('color', { default: value });
    const swatch = node.querySelector('.ffb-admin-mock-swatch');
    assert.ok(swatch.classList.contains('is-empty'), value);
    assert.equal(swatch.style.backgroundColor, '', value);
    assert.equal(node.textContent.includes('#000000'), false, value);
  }
});

test('it previews number fields with a stepper', () => {
  const node = render('number', { placeholder: 'How many' });
  assert.ok(node.querySelector('.ffb-admin-mock-stepper'));
  assert.equal(node.querySelector('.ffb-admin-mock-text').textContent, 'How many');
});

test('it keeps number prefix and suffix around the stepper', () => {
  const node = render('number', { settings: { prefix: '£', suffix: 'kg' } });
  const affixes = [...node.querySelectorAll('.ffb-admin-mock-affix')].map((n) => n.textContent);
  assert.deepEqual(affixes, ['£', 'kg']);
  assert.ok(node.querySelector('.ffb-admin-mock-stepper'));
});

test('it previews newsletter fields as a labelled checkbox', () => {
  const node = render('newsletter', { label: 'Sign me up for the newsletter' }, { code: 'newsletter' });
  assert.ok(node.querySelector('.ffb-admin-mock-check'));
  assert.equal(node.querySelector('.ffb-admin-mock-text').textContent, 'Sign me up for the newsletter');
  assert.equal(preview.hasOwnLabel({ type: 'newsletter' }), true);
});

test('it previews multi select fields as a list box, not a dropdown', () => {
  const node = render('multiselect', { options: opts });
  assert.ok(node.classList.contains('ffb-admin-mock-listbox'));
  assert.equal(node.querySelector('.ffb-admin-mock-chevron'), null);
  assert.deepEqual([...node.querySelectorAll('.ffb-admin-mock-option')].map((n) => n.textContent), ['Red', 'Green', 'Blue']);
});

test('it caps multi select previews and shows how many options are left', () => {
  const many = Array.from({ length: 7 }, (_, i) => ({ label: 'O' + i, value: 'o' + i }));
  const node = render('multiselect', { options: many });
  assert.equal(node.querySelectorAll('.ffb-admin-mock-option').length, 4);
  assert.match(node.querySelector('.ffb-admin-mock-more').textContent, /3/);
});

test('it keeps single selects as a dropdown', () => {
  const node = render('select', { options: opts });
  assert.ok(node.querySelector('.ffb-admin-mock-chevron'));
});

test('it keeps plain text-like inputs as an empty box', () => {
  for (const type of ['text', 'email', 'tel', 'url', 'suggest']) {
    const node = render(type);
    assert.equal(node.querySelector('svg, .ffb-admin-mock-swatch, .ffb-admin-mock-stepper'), null, type);
  }
});

test('it gives every registered field type its own palette icon', () => {
  const di = require('node:fs').readFileSync(path.resolve(__dirname, '../../etc/di.xml'), 'utf8');
  const pool = di.slice(di.indexOf('FieldType\\Pool'), di.indexOf('</type>', di.indexOf('FieldType\\Pool')));
  const codes = [...pool.matchAll(/<item name="([a-z_]+)" xsi:type="object">/g)].map((m) => m[1]);
  assert.ok(codes.length > 30);
  assert.deepEqual(codes.filter((code) => !preview.ICON_PATHS[code]), []);
});

test('it previews selects with the empty label, not the first option', () => {
  assert.equal(render('select', { options: opts }).querySelector('.ffb-admin-mock-text').textContent, 'Please select');
  assert.ok(render('select', { options: opts }).classList.contains('is-placeholder'));
  assert.equal(render('select', { options: opts, placeholder: 'Ignored' }).querySelector('.ffb-admin-mock-text').textContent, 'Please select');
  assert.equal(render('select', { options: opts, settings: { empty_label: 'Choose a colour' } }).querySelector('.ffb-admin-mock-text').textContent, 'Choose a colour');
  assert.equal(render('country').querySelector('.ffb-admin-mock-text').textContent, 'Please select');
});

test('it previews a select with a default as that option chosen', () => {
  const node = render('select', { options: opts, default: 'green' });
  assert.equal(node.querySelector('.ffb-admin-mock-text').textContent, 'Green');
  assert.equal(node.classList.contains('is-placeholder'), false);
});

test('it previews ratings as empty stars unless a default is set', () => {
  const empty = render('rating', { settings: { max: 5 } });
  assert.equal(empty.querySelectorAll('.ffb-admin-mock-star').length, 5);
  assert.equal(empty.querySelectorAll('.ffb-admin-mock-star.is-filled').length, 0);
  const rated = render('rating', { settings: { max: 5 }, default: '3' });
  assert.equal(rated.querySelectorAll('.ffb-admin-mock-star.is-filled').length, 3);
  assert.equal(render('rating', { settings: { max: 1 } }).querySelectorAll('.ffb-admin-mock-star').length, 3);
});

test('it previews address parts in the storefront order', () => {
  const parts = [...render('address').querySelectorAll('.ffb-admin-mock-text')].map((n) => n.textContent);
  assert.deepEqual(parts, ['Country', 'Street address', 'City', 'Region', 'Postcode']);
  assert.ok(render('address').firstChild.querySelector('.ffb-admin-mock-chevron'));
});

const content = (type, extra) => preview.content(doc, Object.assign({ kind: 'content', type, text: '' }, extra || {}));

test('it styles notices by their allowed style', () => {
  assert.ok(content('notice', { text: 'Hi' }).classList.contains('is-info'));
  assert.ok(content('notice', { style: 'success' }).classList.contains('is-success'));
  assert.ok(content('notice', { style: 'warning' }).classList.contains('is-warning'));
  const odd = content('notice', { style: 'x" onclick="y' });
  assert.ok(odd.classList.contains('is-info'));
  assert.equal(odd.className.includes('onclick'), false);
  const titled = content('notice', { title: '<b>T</b>', text: 'Body' });
  assert.equal(titled.querySelector('.ffb-admin-mock-notice-title').textContent, '<b>T</b>');
  assert.equal(titled.querySelector('b'), null);
});

test('it previews the summary as a review block with placeholder rows', () => {
  const node = content('summary', { title: 'Check your answers' });
  assert.equal(node.querySelector('.ffb-admin-mock-summary-title').textContent, 'Check your answers');
  assert.ok(node.querySelectorAll('.ffb-admin-mock-summary-row').length >= 2);
});

test('it renders rich text through the shared renderer when it is loaded', () => {
  const ns = globalThis.FuranFormBuilder = globalThis.FuranFormBuilder || {};
  const seen = [];
  ns.builderMarkdown = { render(d, text) { seen.push(text); const f = d.createDocumentFragment(); f.appendChild(d.createElement('h3')).textContent = 'Rendered'; return f; } };
  try {
    const node = content('rich_text', { text: '# Rendered' });
    assert.ok(node.classList.contains('ffb-admin-mock-rich'));
    assert.equal(node.querySelector('h3').textContent, 'Rendered');
    assert.deepEqual(seen, ['# Rendered']);
  } finally {
    delete ns.builderMarkdown;
  }
});

test('it falls back to plain text when the rich text renderer is missing or fails', () => {
  const plain = content('rich_text', { text: '**<img src=x>**' });
  assert.equal(plain.textContent, '**<img src=x>**');
  assert.equal(plain.querySelector('img'), null);
  const ns = globalThis.FuranFormBuilder = globalThis.FuranFormBuilder || {};
  ns.builderMarkdown = { render() { throw new Error('boom'); } };
  try {
    assert.equal(content('rich_text', { text: 'Plain' }).textContent, 'Plain');
  } finally {
    delete ns.builderMarkdown;
  }
});

test('it keeps rich text preview links out of the tab order', () => {
  const ns = globalThis.FuranFormBuilder = globalThis.FuranFormBuilder || {};
  ns.builderMarkdown = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-markdown.js'));
  try {
    const node = content('rich_text', { text: '## Title\n\nSee [docs](https://example.com) and **this**.' });
    assert.equal(node.querySelector('h3').textContent, 'Title');
    assert.ok(node.querySelector('strong'));
    assert.equal(node.querySelector('a').getAttribute('tabindex'), '-1');
  } finally {
    delete ns.builderMarkdown;
  }
});
