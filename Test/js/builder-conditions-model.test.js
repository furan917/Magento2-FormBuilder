'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));

const P1 = 'p_aaaa11';
const P2 = 'p_bbbb22';
const P3 = 'p_cccc33';

function field(name, patch) {
  return Object.assign({
    id: 'f_' + name, kind: 'field', type: 'text', name, label: name, placeholder: '', help: '', default: '', width: 'full',
    required: false, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
  }, patch || {});
}

const TYPES = { fieldTypes: [{ code: 'text', conditionSource: true, personalByDefault: false }] };

function stateOf(pages) {
  return model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: pages.map(([id, items, extra]) => Object.assign({ id, title: id, condition: null, items }, extra || {})) },
    settings: {},
  });
}

const eq = (f, v) => ({ field: f, operator: 'equals', value: v });
const cond = (rules, extra) => Object.assign({ actions: [{ action: 'show' }], match: 'all', rules }, extra || {});
const itemOf = (state, id) => state.form.schema.pages.flatMap((p) => p.items).find((i) => i.id === id);

test('it renames a field inside a nested group and in value field references', () => {
  const nested = cond([
    eq('a', 'x'),
    { match: 'any', rules: [eq('b', 'y'), { match: 'all', rules: [{ field: 'c', operator: 'equals', value_field: 'a' }, eq('a', 'z')] }] },
  ]);
  let state = stateOf([[P1, [field('a'), field('b'), field('c'), field('d', { condition: nested })]]]);
  state = model.updateItem(state, 'f_a', { name: 'alpha' });
  const rules = itemOf(state, 'f_d').condition.rules;
  assert.equal(rules[0].field, 'alpha');
  assert.equal(rules[1].rules[0].field, 'b');
  assert.equal(rules[1].rules[1].rules[0].value_field, 'alpha');
  assert.equal(rules[1].rules[1].rules[1].field, 'alpha');
  assert.equal(JSON.stringify(rules).includes('"a"'), false);
});

test('it removes rules inside nested groups and drops groups left empty', () => {
  const nested = cond([
    eq('b', 'x'),
    { match: 'any', rules: [eq('a', 'y'), { match: 'all', rules: [{ field: 'b', operator: 'equals', value_field: 'a' }] }] },
  ]);
  let state = stateOf([[P1, [field('a'), field('b'), field('d', { condition: nested })]]]);
  state = model.removeItem(state, 'f_a');
  assert.deepEqual(itemOf(state, 'f_d').condition.rules, [eq('b', 'x')]);
  assert.equal(state.lastRemovedRefs.length, 2);
  state = model.removeItem(state, 'f_b');
  assert.equal(itemOf(state, 'f_d').condition, null);
});

const skip = (rules, target) => ({ match: 'all', rules, target });
const pageOf = (state, id) => state.form.schema.pages.find((p) => p.id === id);

test('it clears a page skip when its target page is removed', () => {
  let state = stateOf([
    [P1, [field('a')], { skip: skip([eq('a', 'x')], P3) }],
    [P2, [field('b')]],
    [P3, [field('c')]],
  ]);
  state = model.removePage(state, P3);
  assert.equal(pageOf(state, P1).skip, null);
  assert.deepEqual(state.lastRemovedRefs.map((r) => [r.itemId, r.kind]), [[P1, 'skip']]);

  const kept = model.removePage(stateOf([
    [P1, [field('a')], { skip: skip([eq('a', 'x')], P3) }],
    [P2, [field('b')]],
    [P3, [field('c')]],
  ]), P2);
  assert.equal(pageOf(kept, P1).skip.target, P3);
});

test('it renames and removes fields inside a page skip condition', () => {
  const nested = skip([eq('a', 'x'), { match: 'any', rules: [{ field: 'b', operator: 'equals', value_field: 'a' }, eq('b', 'q')] }], P2);
  const base = () => stateOf([[P1, [field('a'), field('b')], { skip: JSON.parse(JSON.stringify(nested)) }], [P2, [field('c')]]]);
  const renamed = model.updateItem(base(), 'f_a', { name: 'alpha' });
  const rules = pageOf(renamed, P1).skip.rules;
  assert.equal(rules[0].field, 'alpha');
  assert.equal(rules[1].rules[0].value_field, 'alpha');

  const removed = model.removeItem(base(), 'f_a');
  assert.deepEqual(pageOf(removed, P1).skip.rules, [{ match: 'any', rules: [eq('b', 'q')] }]);
  assert.equal(pageOf(removed, P1).skip.target, P2);
  assert.deepEqual(removed.lastRemovedRefs.map((r) => [r.itemId, r.kind, r.field]), [[P1, 'skip', 'a'], [P1, 'skip', 'a']]);
});

test('it clears a page skip when its last rule is removed', () => {
  const base = () => stateOf([[P1, [field('a'), field('b')], { skip: skip([eq('a', 'x')], P2) }], [P2, [field('c')]]]);
  const removed = model.removeItem(base(), 'f_a');
  assert.equal(pageOf(removed, P1).skip, null);
  assert.equal(removed.lastRemovedRefs.length, 1);

  const emptied = model.updatePage(base(), P1, { skip: skip([], P2) });
  assert.equal(pageOf(emptied, P1).skip, null);
  const edited = model.updatePage(base(), P1, { skip: skip([eq('b', 'y')], P2) });
  assert.deepEqual(pageOf(edited, P1).skip, skip([eq('b', 'y')], P2));
});

test('it flags a skip whose target is no longer a later page after moving pages', () => {
  const messages = (state) => model.validate(state, TYPES).map((e) => [e.itemId, e.message]);
  let state = stateOf([[P1, [field('a')], { skip: skip([eq('a', 'x')], P2) }], [P2, [field('b')]], [P3, [field('c')]]]);
  state.form.identifier = 'c';
  assert.deepEqual(messages(state), []);
  state = model.movePage(state, P2, 0);
  assert.deepEqual(messages(state), [[P1, 'Skip ahead on page "p_aaaa11" must jump to a later page.']]);

  const moved = model.moveItem(stateOf([[P1, [field('a'), field('b')], { skip: skip([eq('b', 'x')], P2) }], [P2, [field('c')]]]), 'f_b', { pageId: P2, sectionId: null, index: 0 });
  assert.deepEqual(messages(moved), [[P1, 'Skip ahead on page "p_aaaa11" uses "b", which is not a field on or before that page.']]);
});

test('it flags nested rules that point at fields later in the form', () => {
  const messages = (state) => model.validate(state, TYPES).map((e) => e.message);
  const bad = cond([{ match: 'any', rules: [eq('a', 'x'), { field: 'a', operator: 'equals', value_field: 'z' }] }]);
  const state = stateOf([[P1, [field('a'), field('d', { condition: bad }), field('z')]]]);
  assert.deepEqual(messages(state), ['Condition references "z", which is not a field earlier in the form.']);
});

test('it keeps option based actions in step with the options of their field', () => {
  const actions = cond([eq('a', 'x')], { actions: [{ action: 'show' }, { action: 'hide_options', param: ['one', 'two'] }, { action: 'set_value', param: 'two' }] });
  const options = [{ label: 'One', value: 'one' }, { label: 'Two', value: 'two' }];
  const base = () => stateOf([[P1, [field('a'), field('s', { type: 'select', options, condition: JSON.parse(JSON.stringify(actions)) })]]]);
  const renamed = model.updateItem(base(), 'f_s', { options: [{ label: 'One', value: 'uno' }, { label: 'Two', value: 'two' }] });
  assert.deepEqual(itemOf(renamed, 'f_s').condition.actions, [{ action: 'show' }, { action: 'hide_options', param: ['uno', 'two'] }, { action: 'set_value', param: 'two' }]);
  const removed = model.updateItem(base(), 'f_s', { options: [{ label: 'One', value: 'one' }] });
  assert.deepEqual(itemOf(removed, 'f_s').condition.actions, [{ action: 'show' }, { action: 'hide_options', param: ['one'] }]);
  assert.equal(removed.lastRemovedRefs.length, 1);
  const gone = model.updateItem(base(), 'f_s', { options: [{ label: 'Three', value: 'three' }] });
  assert.deepEqual(itemOf(gone, 'f_s').condition.actions, [{ action: 'show' }]);
});
