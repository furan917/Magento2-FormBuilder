'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const config = {
  fieldTypes: [
    { code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: ['matches'], settingsSpec: {}, hasOptions: false },
    { code: 'consent', personalByDefault: false, conditionSource: true, label: 'Consent', category: 'choice', allowedRules: [], hasOptions: false, settingsSpec: { details: { type: 'string', default: '', max_length: 1000 } } },
    { code: 'radio', personalByDefault: false, conditionSource: true, label: 'Radio', category: 'choice', allowedRules: [], settingsSpec: {}, hasOptions: true },
    {
      code: 'matrix', personalByDefault: false, conditionSource: false, label: 'Matrix', category: 'choice', allowedRules: [], hasOptions: true,
      settingsSpec: { rows: { type: 'list', default: ['Row 1', 'Row 2'], multiline: true }, require_all: { type: 'bool', default: true } },
    },
  ],
  rules: [{ code: 'matches', label: 'Matches', valueType: 'field' }],
  operators: [{ code: 'equals', label: 'equals' }],
  widths: [{ value: 'full', label: 'Full' }],
  autocomplete: [''],
  stores: [],
};

const PAGE = 'p_aaaa11';
function setup(fields, selectedIndex) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] },
    settings: {},
  });
  const made = fields.map(([type, patch]) => {
    const f = Object.assign(model.newField(type, config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  state = model.select(state, made[selectedIndex].id);
  return { state, made };
}

function mount(state) {
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  inspector.render(container, state, config, { apply: (...a) => calls.push(a), select: () => {}, notify: () => {} });
  const q = (key) => container.querySelector('[data-ffb-input="' + key + '"]');
  const fire = (node, type) => node.dispatchEvent(new dom.window.Event(type, { bubbles: true }));
  return { container, calls, q, fire };
}

test('it defaults new consent fields to required', () => {
  const state = model.createState({ id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0], schema: { version: 1, pages: [{ id: PAGE, title: '', condition: null, items: [] }] }, settings: {} });
  assert.equal(model.newField('consent', config, state).required, true);
  assert.equal(model.newField('text', config, state).required, false);
});

test('it seeds new matrix fields with default rows and columns', () => {
  const state = model.createState({ id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0], schema: { version: 1, pages: [{ id: PAGE, title: '', condition: null, items: [] }] }, settings: {} });
  const field = model.newField('matrix', config, state);
  assert.deepEqual(field.settings.rows, ['Row 1', 'Row 2']);
  assert.equal(field.options.length, 2);
});

test('it edits matrix rows one per line so commas survive', () => {
  const { state } = setup([['matrix', { name: 'm', label: 'M' }]], 0);
  const ui = mount(state);
  assert.equal(ui.q('setting-rows').tagName, 'TEXTAREA');
  ui.q('setting-rows').value = 'Quality, overall\n\n  Price  ';
  ui.fire(ui.q('setting-rows'), 'change');
  assert.deepEqual(ui.calls[0][2].settings.rows, ['Quality, overall', 'Price']);
});

test('it edits the option description', () => {
  const { state, made } = setup([['radio', { name: 'r', label: 'R' }]], 0);
  const ui = mount(state);
  ui.q('option-0-description').value = 'Plain text';
  ui.fire(ui.q('option-0-description'), 'change');
  assert.equal(ui.calls[0][2].options[0].description, 'Plain text');
  assert.equal(ui.calls[0][1], made[0].id);
});

test('it uses a textarea for long string settings', () => {
  const { state } = setup([['consent', { name: 'c', label: 'C' }]], 0);
  const ui = mount(state);
  assert.equal(ui.q('setting-details').tagName, 'TEXTAREA');
});

test('it excludes matrix fields from condition and matches lists', () => {
  const { state } = setup([
    ['text', { name: 'first', label: 'First' }],
    ['matrix', { name: 'grid', label: 'Grid' }],
    ['text', { name: 'plain', label: 'Plain', condition: { action: 'show', match: 'all', rules: [{ field: 'plain', operator: 'equals', value: '' }] }, rules: [{ rule: 'matches', value: 'plain', message: '' }] }],
  ], 2);
  const ui = mount(state);
  const condOptions = Array.from(ui.q('cond-0-field').options).map((o) => o.value);
  assert.deepEqual(condOptions, ['first']);
  const matchOptions = Array.from(ui.q('rule-0-value').options).map((o) => o.value);
  assert.deepEqual(matchOptions, ['first']);
});

const preview = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-preview.js'));

function contentState(type) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: '', condition: null, items: [] }] }, settings: {},
  });
  const item = model.newContent(type);
  state = model.addItem(state, item, { pageId: PAGE, sectionId: null, index: 0 });
  return { state: model.select(state, item.id), item };
}

test('it seeds new rich text, notice and summary content items', () => {
  assert.equal(model.newContent('rich_text').text, '');
  const notice = model.newContent('notice');
  assert.deepEqual([notice.text, notice.style, notice.title], ['', 'info', '']);
  assert.equal(model.newContent('summary').title, 'Review your answers');
});

test('it edits rich text, notice and summary content in the inspector', () => {
  let t = mount(contentState('rich_text').state);
  t.q('text').value = '**x**';
  t.fire(t.q('text'), 'input');
  t.fire(t.q('text'), 'change');
  assert.ok(t.calls.some((c) => c[0] === 'updateItem' && c[2].text === '**x**'));

  t = mount(contentState('notice').state);
  assert.ok(t.q('title') && t.q('text'));
  t.q('style').value = 'warning';
  t.fire(t.q('style'), 'change');
  assert.ok(t.calls.some((c) => c[0] === 'updateItem' && c[2].style === 'warning'));

  t = mount(contentState('summary').state);
  assert.ok(t.q('title'));
  assert.equal(t.q('text'), null);
});

test('it previews the new content types as text only', () => {
  const doc = new JSDOM('').window.document;
  const rich = preview.content(doc, { type: 'rich_text', text: '<img src=x onerror=alert(1)> **b**' });
  assert.equal(rich.querySelectorAll('img, strong').length, 0);
  assert.equal(rich.textContent, '<img src=x onerror=alert(1)> **b**');
  const notice = preview.content(doc, { type: 'notice', title: '<b>T</b>', text: 'body' });
  assert.equal(notice.querySelectorAll('b').length, 0);
  assert.equal(notice.textContent, '<b>T</b>body');
  assert.equal(preview.content(doc, { type: 'summary', title: 'Review' }).textContent, 'Review');
  ['rich_text', 'notice', 'summary'].forEach((c) => assert.ok(preview.ICON_PATHS[c]));
});
