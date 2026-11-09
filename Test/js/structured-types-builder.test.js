'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));
const preview = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-preview.js'));
const i18n = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-i18n.js'));
const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/translatable-texts.json'), 'utf8'));

const PAGE = 'p_aaaa11';
const STRUCTURED = ['date_range', 'address', 'map'];
const config = {
  fieldTypes: [
    { code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: ['matches'], settingsSpec: {}, hasOptions: false },
    ...STRUCTURED.map((code) => ({ code, personalByDefault: code !== 'date_range', conditionSource: false, label: code, category: 'advanced', allowedRules: [], settingsSpec: {}, hasOptions: false })),
  ],
  rules: [{ code: 'matches', label: 'Matches', valueType: 'field' }],
  operators: [{ code: 'equals', label: 'equals' }],
  widths: [{ value: 'full', label: 'Full' }],
  autocomplete: [''],
  stores: [],
};

function setup(fields, selectedIndex) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] }, settings: {},
  });
  const made = fields.map(([type, patch]) => {
    const f = Object.assign(model.newField(type, config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  return { state: model.select(state, made[selectedIndex].id), made };
}

test('it previews date range address and map fields in the builder', () => {
  const doc = new JSDOM('').window.document;
  const range = preview.control(doc, { type: 'date_range', settings: { start_label: 'Arrive', end_label: '' } }, config.fieldTypes[1]);
  assert.match(range.textContent, /Arrive/);
  assert.match(range.textContent, /End date/);
  assert.equal(range.getAttribute('aria-hidden'), 'true');
  const address = preview.control(doc, { type: 'address', settings: {} }, config.fieldTypes[2]);
  assert.match(address.textContent, /Street address/);
  assert.match(address.textContent, /Postcode/);
  const map = preview.control(doc, { type: 'map', settings: { height: 300 } }, config.fieldTypes[3]);
  assert.match(map.textContent, /Click the map to place a pin/);
  STRUCTURED.forEach((c) => assert.ok(preview.ICON_PATHS[c], c));
});

test('it lists the new translatable settings identically in PHP and the builder', () => {
  const specs = {
    date_range: {
      start_label: { type: 'string', translatable: true, max_length: 60, default: '' },
      end_label: { type: 'string', translatable: true, max_length: 60, default: '' },
      min_days: { type: 'int', default: 0 },
    },
  };
  const schema = { version: 1, pages: [{ id: 'p_bbbb01', title: '', condition: null, items: [
    { id: 'f_bbbb02', kind: 'field', type: 'date_range', name: 'stay', label: 'Stay', help: '', placeholder: '', rules: [], options: [],
      settings: { start_label: 'Arrive', end_label: 'Leave', min_days: 2 }, condition: null },
  ] }] };
  const actual = i18n.collect(schema, {}, specs).filter((e) => e.path[2] === 'settings').map((e) => [e.path, e.text, e.max]);
  assert.deepEqual(actual, [
    [['items', 'f_bbbb02', 'settings', 'start_label'], 'Arrive', 60],
    [['items', 'f_bbbb02', 'settings', 'end_label'], 'Leave', 60],
  ]);
  assert.ok(fixture.expected.some((e) => e[0].join('.') === 'items.f_aaaa08.settings.start_label'));
});

test('it rejects conditions that reference a date range address or map field', () => {
  STRUCTURED.forEach((type) => {
    const { state } = setup([
      [type, { name: 'where', label: 'Where' }],
      ['text', { name: 'later', label: 'Later', condition: { action: 'show', match: 'all', rules: [{ field: 'where', operator: 'equals', value: 'x' }] } }],
    ], 1);
    const msgs = model.validate(state, config).map((m) => m.message);
    assert.ok(msgs.some((m) => /not a field earlier/.test(m)), type);
  });
});

test('it rejects a matches rule that names a date range address or map field', () => {
  STRUCTURED.forEach((type) => {
    const { state } = setup([
      [type, { name: 'where', label: 'Where' }],
      ['text', { name: 'later', label: 'Later', rules: [{ rule: 'matches', value: 'where', message: '' }] }],
    ], 1);
    const msgs = model.validate(state, config).map((m) => m.message);
    assert.ok(msgs.some((m) => /must match a field that appears earlier/.test(m)), type);
  });
});

test('it leaves date range address and map fields out of the condition field picker', () => {
  const { state } = setup([
    ['text', { name: 'first', label: 'First' }],
    ['date_range', { name: 'stay', label: 'Stay' }],
    ['address', { name: 'home', label: 'Home' }],
    ['map', { name: 'spot', label: 'Spot' }],
    ['text', { name: 'last', label: 'Last' }],
  ], 4);
  assert.deepEqual(inspector.referenceable(state, state.selectedId, config).map((o) => o.value), ['first']);
});
