'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const hiddenSpec = {
  source: {
    type: 'enum', default: 'static', values: ['static', 'query', 'page_url', 'referrer'], label: 'Value source',
    value_labels: { static: 'Fixed value', query: 'Query parameter', page_url: 'Page URL', referrer: 'Referrer' },
    hint: 'Fixed value saves the Default value.',
  },
  query_param: { type: 'string', default: '', label: 'Query parameter', visible_when: { source: ['query'] } },
};
const config = {
  fieldTypes: [{ code: 'hidden', label: 'Hidden field', category: 'input', allowedRules: [], hasOptions: false, settingsSpec: hiddenSpec }],
  rules: [], operators: [], widths: [{ value: 'full', label: 'Full' }], autocomplete: [''], stores: [],
};
const PAGE = 'p_aaaa11';

function mount(settings) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] }, settings: {},
  });
  const f = Object.assign(model.newField('hidden', config, state), { settings });
  state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 0 });
  state = model.select(state, f.id);
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  inspector.render(container, state, config, { apply: (...a) => calls.push(a), select: () => {}, notify: () => {} });
  return { container, calls, q: (key) => container.querySelector('[data-ffb-input="' + key + '"]') };
}

test('it hides a setting until the setting it depends on has a matching value', () => {
  for (const source of ['static', 'page_url', 'referrer', undefined]) {
    assert.equal(mount(source === undefined ? {} : { source }).q('setting-query_param'), null, String(source));
  }
  assert.ok(mount({ source: 'query' }).q('setting-query_param'));
});

test('it shows a dependent setting when the default of the setting it depends on matches', () => {
  const spec = Object.assign({}, hiddenSpec.source, { default: 'query' });
  config.fieldTypes[0].settingsSpec = Object.assign({}, hiddenSpec, { source: spec });
  try {
    assert.ok(mount({}).q('setting-query_param'));
  } finally {
    config.fieldTypes[0].settingsSpec = hiddenSpec;
  }
});

test('it shows a setting hint under its control', () => {
  const { q } = mount({ source: 'static' });
  const control = q('setting-source').closest('.ffb-admin-control');
  const hint = control.nextElementSibling;
  assert.ok(hint && hint.classList.contains('ffb-admin-hint'));
  assert.equal(hint.textContent, 'Fixed value saves the Default value.');
});

test('it shows the translated captions of enum values', () => {
  const opts = [...mount({ source: 'referrer' }).q('setting-source').options].map((o) => [o.value, o.textContent]);
  assert.deepEqual(opts, [['static', 'Fixed value'], ['query', 'Query parameter'], ['page_url', 'Page URL'], ['referrer', 'Referrer']]);
});
