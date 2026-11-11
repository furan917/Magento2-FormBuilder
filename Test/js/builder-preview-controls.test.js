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

test('it previews colour fields without a valid default as black', () => {
  const node = render('color', { default: 'red;background:url(x)' });
  assert.equal(node.querySelector('.ffb-admin-mock-swatch').style.backgroundColor, 'rgb(0, 0, 0)');
  assert.equal(node.querySelector('.ffb-admin-mock-text').textContent, '#000000');
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
