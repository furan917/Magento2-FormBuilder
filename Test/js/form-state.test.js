'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const vm = require('node:vm');
const { corePath, core } = require('./helpers/load-storefront');
const fresh = () => {
  const sandbox = { FuranFormBuilder: {}, console: { warn: () => {} } };
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(corePath, 'utf8'), sandbox);
  return sandbox.FuranFormBuilder;
};
const cases = JSON.parse(fs.readFileSync(path.join(root, 'Test/fixtures/form-state.json'), 'utf8'));

// Test-only stand-in for PublicSchema: it marks the fields the server overwrites and the multi-value ones.
const publicShape = (schema) => {
  const copy = JSON.parse(JSON.stringify(schema));
  const walk = (item) => {
    if (item.kind === 'field') {
      if (item.locked || item.type === 'hidden') item.overwritable = true;
      item.multi = ['multiselect', 'checkboxes'].includes(item.type);
    }
    (item.items || []).forEach(walk);
  };
  copy.pages.forEach(walk);
  return copy;
};

const asMap = (value) => (Array.isArray(value) && value.length === 0 ? {} : value);

test('it passes the shared form state fixtures', () => {
  assert.ok(cases.length >= 25);
  for (const c of cases) {
    const state = core.computeState(publicShape(c.schema), c.values, c.today === undefined ? null : c.today, c.exempt || []);
    for (const key of ['visible', 'required', 'values']) {
      for (const [id, expected] of Object.entries(c.expected[key] || {})) {
        assert.deepEqual(state[key][id], expected, `${c.name}: ${key} ${id}`);
      }
    }
    if (c.expected.defaults !== undefined) {
      assert.deepEqual(state.defaults, asMap(c.expected.defaults), `${c.name}: defaults`);
    }
    if (c.expected.hidden_options !== undefined) {
      assert.deepEqual(state.hiddenOptions, asMap(c.expected.hidden_options), `${c.name}: hidden_options`);
    }
  }
});

const oneField = (condition) => ({
  pages: [{ id: 'p_1', kind: 'page', items: [
    { id: 'f_a', kind: 'field', type: 'text', name: 'a' },
    { id: 'f_b', kind: 'field', type: 'text', name: 'b', condition },
  ] }],
});

test('it applies a registered custom condition action', () => {
  const { core, registry } = fresh();
  const seen = [];
  registry.conditionAction('mark_required_twice', (state, id, param, matched) => {
    seen.push([id, param, matched]);
    if (matched) state.require();
  });
  const schema = oneField({ actions: [{ action: 'mark_required_twice', param: 'x' }], match: 'all', rules: [{ field: 'a', operator: 'equals', value: '1' }] });
  assert.equal(core.computeState(schema, { a: '1' }).required.f_b, true);
  assert.equal(core.computeState(schema, { a: '2' }).required.f_b, false);
  assert.deepEqual(seen, [['f_b', 'x', true], ['f_b', 'x', false]]);
  assert.throws(() => registry.conditionAction('show', () => {}), /built in/);
  assert.throws(() => registry.conditionAction('Bad Code', () => {}), /Invalid/);
  assert.equal(core.computeState(oneField({ actions: [{ action: 'unpooled' }], match: 'all', rules: [{ field: 'a', operator: 'equals', value: '1' }] }), { a: '1' }).visible.f_b, true);
});

test('it ignores a single action next to the actions list', () => {
  const rules = [{ field: 'a', operator: 'equals', value: '1' }];
  assert.equal(core.evaluateCondition({ actions: [{ action: 'hide' }], match: 'all', rules }, { a: '1' }), false);
  assert.equal(core.evaluateCondition({ actions: [], action: 'hide', match: 'all', rules }, { a: '1' }), true);
  assert.equal(core.evaluateCondition({ action: 'hide', match: 'all', rules }, { a: '1' }), true);
});
