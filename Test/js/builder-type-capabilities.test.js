'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const PAGE = 'p_aaaa11';
const type = (code, flags) => Object.assign({
  code, label: code, category: 'input', allowedRules: ['matches'], settingsSpec: {}, hasOptions: false,
  personalByDefault: false, conditionSource: true,
}, flags);
const config = {
  fieldTypes: [
    type('text'),
    type('acme_ssn', { personalByDefault: true }),
    type('acme_blob', { conditionSource: false }),
  ],
  rules: [{ code: 'matches', label: 'Matches', valueType: 'field' }],
  personalAutocomplete: ['given-name', 'bday'],
};

function setup(fields) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }, { id: 'p_bbbb22', title: 'Two', condition: null, items: [] }] }, settings: {},
  });
  const made = fields.map(([code, patch]) => {
    const f = Object.assign(model.newField(code, config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  return { state, made };
}

test('it defaults personal from the server flag in the builder', () => {
  assert.equal(model.isPersonal({ type: 'acme_ssn' }, config), true);
  assert.equal(model.isPersonal({ type: 'text' }, config), false);
  assert.equal(model.isPersonal({ type: 'acme_ssn', personal: false }, config), false);
  assert.equal(model.isPersonal({ type: 'text', autocomplete: 'bday' }, config), true);
  assert.equal(model.isPersonal({ type: 'text', autocomplete: 'email' }, config), false);
});

test('it offers only condition source types as condition sources', () => {
  const { state, made } = setup([
    ['text', { name: 'first', label: 'First' }],
    ['acme_blob', { name: 'blob', label: 'Blob' }],
    ['text', { name: 'last', label: 'Last', rules: [{ rule: 'matches', value: 'blob', message: '' }] }],
  ]);
  assert.deepEqual(inspector.referenceable(state, made[2].id, config).map((o) => o.value), ['first']);
  const msgs = model.validate(state, config).map((m) => m.message);
  assert.ok(msgs.some((m) => /must match a field that appears earlier/.test(m)));

  const skipped = model.updatePage(state, PAGE, { skip: { target: 'p_bbbb22', rules: [{ field: 'blob', operator: 'equals', value: 'x' }] } });
  assert.ok(model.validate(skipped, config).some((m) => /Skip ahead/.test(m.message)));
  const allowed = model.updatePage(state, PAGE, { skip: { target: 'p_bbbb22', rules: [{ field: 'first', operator: 'equals', value: 'x' }] } });
  assert.ok(!model.validate(allowed, config).some((m) => /Skip ahead/.test(m.message)));
});

test('it treats an unknown type as neither personal nor a condition source', () => {
  assert.equal(model.isPersonal({ type: 'ghost' }, config), false);
  assert.equal(model.isConditionSource('ghost', config), false);
  assert.equal(model.isConditionSource('text', config), true);
  const { state, made } = setup([['text', { name: 'a', label: 'A' }], ['text', { name: 'b', label: 'B' }]]);
  state.form.schema.pages[0].items[0].type = 'ghost';
  assert.deepEqual(inspector.referenceable(state, made[1].id, config).map((o) => o.value), []);
});
