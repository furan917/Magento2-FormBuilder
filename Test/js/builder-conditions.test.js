'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const OPERATORS = [
  { code: 'equals', label: 'equals', needsValue: true, types: ['text', 'select', 'date', 'number'] },
  { code: 'is_empty', label: 'is empty', needsValue: false, types: ['text', 'select', 'date', 'number'] },
  { code: 'is_one_of', label: 'is one of', needsValue: true, types: ['text', 'select', 'date', 'number'] },
];
const config = {
  fieldTypes: [
    { code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, relativeDate: null },
    { code: 'select', personalByDefault: false, conditionSource: true, label: 'Select', category: 'choice', allowedRules: [], settingsSpec: {}, hasOptions: true, relativeDate: null },
    { code: 'date', personalByDefault: false, conditionSource: true, label: 'Date', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, relativeDate: 'Y-m-d' },
    { code: 'number', personalByDefault: false, conditionSource: true, label: 'Number', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, relativeDate: null },
  ],
  operators: OPERATORS,
  actions: [
    { code: 'show', label: 'Show', targets: ['page', 'section', 'field', 'content'], param: null, types: ['text', 'select', 'date', 'number'] },
    { code: 'hide', label: 'Hide', targets: ['page', 'section', 'field', 'content'], param: null, types: ['text', 'select', 'date', 'number'] },
    { code: 'require', label: 'Require', targets: ['field'], param: null, types: ['text', 'select', 'date', 'number'] },
    { code: 'set_value', label: 'Set value', targets: ['field'], param: 'value', types: ['text', 'select', 'date', 'number'] },
    { code: 'hide_options', label: 'Hide options', targets: ['field'], param: 'options', types: ['select'] },
  ],
  conditionLimits: { maxLevels: 3, maxEntries: 25, maxRelativeDays: 3650 },
  widths: [{ value: 'full', label: 'Full' }],
  autocomplete: [''],
  prefillSources: [],
  customerGroups: [],
  stores: [{ value: 0, label: 'All' }],
  rules: [],
  ruleLibrary: [],
  urls: {},
};

const P1 = 'p_aaaa11';
const P2 = 'p_bbbb22';
const P3 = 'p_cccc33';

function field(type, patch) {
  return Object.assign({
    id: 'f_' + patch.name.padEnd(6, 'x').slice(0, 8), kind: 'field', type, label: patch.name, placeholder: '', help: '', default: '',
    width: 'full', required: false, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
  }, patch);
}

function build(pages, selectedId) {
  const state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: pages.map(([id, items, extra]) => Object.assign({ id, title: id, condition: null, items }, extra || {})) },
    settings: {},
  });
  return model.select(state, selectedId);
}

function mount(state, cfg) {
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  const actions = { apply: (...a) => calls.push(a), select: (id) => calls.push(['SELECT', id]), notify: (m) => calls.push(['NOTIFY', m]) };
  inspector.render(container, state, cfg || config, actions);
  const q = (key) => container.querySelector('[data-ffb-input="' + key + '"]');
  const fire = (node, type) => node.dispatchEvent(new dom.window.Event(type, { bubbles: true }));
  const keys = () => Array.from(container.querySelectorAll('[data-ffb-input]')).map((n) => n.getAttribute('data-ffb-input'));
  return { container, calls, q, fire, keys };
}

const rule = (f, extra) => Object.assign({ field: f, operator: 'equals', value: 'x' }, extra || {});

test('it adds a nested group to a condition', () => {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a')] };
  const state = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx');
  const ui = mount(state);
  ui.q('cond-add-group').click();
  assert.deepEqual(ui.calls[0][2].condition.rules[1], { match: 'all', rules: [{ field: 'a', operator: 'equals', value: '' }] });
  assert.equal(ui.container.querySelectorAll('fieldset.ffb-admin-cond-group').length, 1);
});

test('it stops adding groups at the depth limit', () => {
  const deep = { match: 'all', rules: [{ match: 'any', rules: [rule('a')] }] };
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [deep] };
  const state = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx');
  const ui = mount(state);
  const keys = ui.keys();
  assert.ok(keys.includes('cond-add-group'));
  assert.ok(keys.includes('cond-g0-add-group'));
  assert.ok(!keys.includes('cond-g0-g0-add-group'));
  assert.ok(keys.includes('cond-g0-g0-add-rule'));
  assert.match(ui.container.querySelector('[data-ffb-hint="cond-depth-limit"]').textContent, /3 levels/);
  const legends = Array.from(ui.container.querySelectorAll('legend')).map((l) => l.textContent);
  assert.deepEqual(legends, ['Rules', 'Nested group (level 2 of 3)', 'Nested group (level 3 of 3)']);
});

test('it stops adding rules and groups at the entry limit', () => {
  const rules = Array.from({ length: 25 }, () => rule('a'));
  const state = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: { actions: [{ action: 'show' }], match: 'all', rules } })]]], 'f_bxxxxx');
  const ui = mount(state);
  assert.ok(!ui.keys().includes('cond-add-rule'));
  assert.ok(!ui.keys().includes('cond-add-group'));
  assert.ok(ui.container.querySelector('[data-ffb-hint="cond-entry-limit"]'));
});

const optionsOf = (select) => Array.from(select.options).map((o) => o.value);
const SHOWN = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a')] };

test('it lists actions valid for the selected item', () => {
  const content = { id: 'c_head11', kind: 'content', type: 'heading', text: 'Hi', level: 2, width: 'full', condition: SHOWN };
  const pageState = build([[P1, [field('text', { name: 'a' })]], [P2, [], { condition: SHOWN }]], P2);
  assert.deepEqual(optionsOf(mount(pageState).q('cond-action-0')), ['show', 'hide']);

  const text = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: SHOWN })]]], 'f_bxxxxx');
  assert.deepEqual(optionsOf(mount(text).q('cond-action-0')), ['show', 'hide', 'require', 'set_value']);

  const select = build([[P1, [field('text', { name: 'a' }), field('select', { name: 'b', condition: SHOWN })]]], 'f_bxxxxx');
  assert.deepEqual(optionsOf(mount(select).q('cond-action-0')), ['show', 'hide', 'require', 'set_value', 'hide_options']);

  const heading = build([[P1, [field('text', { name: 'a' }), content]]], 'c_head11');
  assert.deepEqual(optionsOf(mount(heading).q('cond-action-0')), ['show', 'hide']);

  const hiding = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: Object.assign({}, SHOWN, { actions: [{ action: 'hide' }] }) })]]], 'f_bxxxxx');
  assert.deepEqual(optionsOf(mount(hiding).q('cond-action-0')), ['show', 'hide', 'require', 'set_value']);
});

test('it adds and removes actions without repeating a code or mixing show with hide', () => {
  const state = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: SHOWN })]]], 'f_bxxxxx');
  const ui = mount(state);
  ui.q('cond-add-action').click();
  assert.deepEqual(ui.calls[0][2].condition.actions, [{ action: 'show' }, { action: 'require' }]);
  assert.equal(ui.q('cond-action-0-remove'), null);

  const two = Object.assign({}, SHOWN, { actions: [{ action: 'show' }, { action: 'require' }] });
  const ui2 = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: two })]]], 'f_bxxxxx'));
  assert.deepEqual(optionsOf(ui2.q('cond-action-1')), ['require', 'set_value']);
  assert.deepEqual(optionsOf(ui2.q('cond-action-0')), ['show', 'hide', 'set_value']);
  ui2.q('cond-action-1-remove').click();
  assert.deepEqual(ui2.calls[0][2].condition.actions, [{ action: 'show' }]);
  assert.equal(ui2.calls[0][2].condition.action, undefined);
});

const OPTS = [{ label: 'One', value: 'one' }, { label: 'Two', value: 'two' }, { label: 'Three', value: 'three' }];

test('it edits the parameter of a set value action', () => {
  const cond = { actions: [{ action: 'show' }, { action: 'set_value', param: 'old' }], match: 'all', rules: [rule('a')] };
  const text = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx'));
  assert.equal(text.q('cond-action-1-param').value, 'old');
  text.q('cond-action-1-param').value = 'new';
  text.fire(text.q('cond-action-1-param'), 'change');
  assert.deepEqual(text.calls[0][2].condition.actions, [{ action: 'show' }, { action: 'set_value', param: 'new' }]);

  const pick = mount(build([[P1, [field('text', { name: 'a' }), field('select', { name: 'b', options: OPTS, condition: cond })]]], 'f_bxxxxx'));
  assert.deepEqual(optionsOf(pick.q('cond-action-1-param')), ['one', 'two', 'three']);
  pick.q('cond-action-1-param').value = 'two';
  pick.fire(pick.q('cond-action-1-param'), 'change');
  assert.deepEqual(pick.calls[0][2].condition.actions[1], { action: 'set_value', param: 'two' });
});

test('it gives a new parameter action a valid default and edits the hidden options', () => {
  const sel = (cond) => mount(build([[P1, [field('text', { name: 'a' }), field('select', { name: 'b', options: OPTS, condition: cond })]]], 'f_bxxxxx'));
  const ui = sel(SHOWN);
  ui.q('cond-add-action').click();
  assert.deepEqual(ui.calls[0][2].condition.actions, [{ action: 'show' }, { action: 'require' }]);

  const swap = sel(Object.assign({}, SHOWN, { actions: [{ action: 'show' }, { action: 'require' }] }));
  swap.q('cond-action-1').value = 'hide_options';
  swap.fire(swap.q('cond-action-1'), 'change');
  assert.deepEqual(swap.calls[0][2].condition.actions[1], { action: 'hide_options', param: ['one'] });
  swap.q('cond-action-1').value = 'set_value';
  swap.fire(swap.q('cond-action-1'), 'change');
  assert.deepEqual(swap.calls[1][2].condition.actions[1], { action: 'set_value', param: 'one' });

  const hid = sel(Object.assign({}, SHOWN, { actions: [{ action: 'show' }, { action: 'hide_options', param: ['one'] }] }));
  assert.equal(hid.q('cond-action-1-param-0').checked, true);
  assert.equal(hid.q('cond-action-1-param-1').checked, false);
  hid.q('cond-action-1-param-2').checked = true;
  hid.fire(hid.q('cond-action-1-param-2'), 'change');
  assert.deepEqual(hid.calls[0][2].condition.actions[1], { action: 'hide_options', param: ['one', 'three'] });
  hid.q('cond-action-1-param-0').checked = false;
  hid.fire(hid.q('cond-action-1-param-0'), 'change');
  assert.deepEqual(hid.calls[1][2].condition.actions[1].param, []);
});

test('it switches a rule value to another earlier field', () => {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a')] };
  const ui = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'z' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx'));
  assert.deepEqual(optionsOf(ui.q('cond-0-source')), ['value', 'value_field']);
  ui.q('cond-0-source').value = 'value_field';
  ui.fire(ui.q('cond-0-source'), 'change');
  assert.deepEqual(ui.calls[0][2].condition.rules[0], { field: 'a', operator: 'equals', value_field: 'a' });

  const linked = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a', { value: undefined, value_field: 'z' })] };
  const two = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'z' }), field('text', { name: 'b', condition: JSON.parse(JSON.stringify(linked)) })]]], 'f_bxxxxx'));
  assert.equal(two.q('cond-0-value-field').value, 'z');
  assert.equal(two.q('cond-0-value'), null);
  two.q('cond-0-value-field').value = 'a';
  two.fire(two.q('cond-0-value-field'), 'change');
  assert.deepEqual(two.calls[0][2].condition.rules[0], { field: 'a', operator: 'equals', value_field: 'a' });
  two.q('cond-0-source').value = 'value';
  two.fire(two.q('cond-0-source'), 'change');
  assert.deepEqual(two.calls[1][2].condition.rules[0], { field: 'a', operator: 'equals', value: '' });

  const noValue = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: { actions: [{ action: 'show' }], match: 'all', rules: [rule('a', { value_field: 'a', value: undefined })] } })]]], 'f_bxxxxx'));
  noValue.q('cond-0-operator').value = 'is_empty';
  noValue.fire(noValue.q('cond-0-operator'), 'change');
  assert.deepEqual(noValue.calls[0][2].condition.rules[0], { field: 'a', operator: 'is_empty', value: '' });
});

test('it offers days from today only for date fields', () => {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [rule('d', { operator: 'equals' })] };
  const dated = mount(build([[P1, [field('date', { name: 'd' }), field('text', { name: 't' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx'));
  assert.deepEqual(optionsOf(dated.q('cond-0-source')), ['value', 'value_field', 'value_date']);
  dated.q('cond-0-source').value = 'value_date';
  dated.fire(dated.q('cond-0-source'), 'change');
  assert.deepEqual(dated.calls[0][2].condition.rules[0], { field: 'd', operator: 'equals', value_date: { days: 0 } });

  const plain = mount(build([[P1, [field('date', { name: 'd' }), field('text', { name: 't' }), field('text', { name: 'b', condition: Object.assign({}, cond, { rules: [rule('t')] }) })]]], 'f_bxxxxx'));
  assert.ok(!optionsOf(plain.q('cond-0-source')).includes('value_date'));

  const days = { actions: [{ action: 'show' }], match: 'all', rules: [{ field: 'd', operator: 'equals', value_date: { days: 5 } }] };
  const set = mount(build([[P1, [field('date', { name: 'd' }), field('text', { name: 'b', condition: days })]]], 'f_bxxxxx'));
  assert.equal(set.q('cond-0-days').value, '5');
  assert.equal(set.q('cond-0-days').max, '3650');
  set.q('cond-0-days').value = '99999';
  set.fire(set.q('cond-0-days'), 'change');
  assert.deepEqual(set.calls[0][2].condition.rules[0].value_date, { days: 3650 });
  set.q('cond-0-days').value = '-12.7';
  set.fire(set.q('cond-0-days'), 'change');
  assert.deepEqual(set.calls[1][2].condition.rules[0].value_date, { days: -12 });

  const moved = mount(build([[P1, [field('date', { name: 'd' }), field('text', { name: 't' }), field('text', { name: 'b', condition: days })]]], 'f_bxxxxx'));
  moved.q('cond-0-field').value = 't';
  moved.fire(moved.q('cond-0-field'), 'change');
  assert.deepEqual(moved.calls[0][2].condition.rules[0], { field: 't', operator: 'equals', value: '' });
});

test('it drops a nested group when its last rule is removed', () => {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a'), { match: 'any', rules: [rule('a')] }] };
  const ui = mount(build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx'));
  ui.q('cond-g1-0-remove').click();
  assert.deepEqual(ui.calls[0][2].condition.rules, [rule('a')]);
});

const SKIP = { match: 'all', rules: [rule('a')], target: P3 };

test("it offers the page's own fields for a skip condition", () => {
  const state = build([
    [P1, [field('text', { name: 'e' })]],
    [P2, [field('text', { name: 'a' }), field('date', { name: 'm', type: 'date_range' }), field('text', { name: 'b' })]],
    [P3, [field('text', { name: 'c' })]],
  ], P2);
  const ui = mount(state);
  ui.q('add-skip').click();
  assert.deepEqual(ui.calls[0], ['updatePage', P2, { skip: { match: 'all', rules: [{ field: 'e', operator: 'equals', value: '' }], target: P3 } }]);

  const withSkip = build([
    [P1, [field('text', { name: 'e' })]],
    [P2, [field('text', { name: 'a' }), field('text', { name: 'b' })], { skip: SKIP }],
    [P3, [field('text', { name: 'c' })]],
  ], P2);
  const shown = mount(withSkip);
  assert.deepEqual(optionsOf(shown.q('skip-0-field')), ['e', 'a', 'b']);
  assert.deepEqual(optionsOf(shown.q('skip-target')), [P3]);

  const last = mount(build([[P1, [field('text', { name: 'e' })]], [P2, []]], P2));
  assert.equal(last.q('add-skip'), null);
  assert.equal(last.q('skip-target'), null);
});

test('it edits a page skip condition with a later target page', () => {
  const pages = (skip) => [
    [P1, [field('text', { name: 'a' })], { skip }],
    [P2, [field('text', { name: 'b' })]],
    [P3, [field('text', { name: 'c' })]],
  ];
  const ui = mount(build(pages({ match: 'all', rules: [rule('a')], target: P2 }), P1));
  assert.deepEqual(optionsOf(ui.q('skip-target')), [P2, P3]);
  ui.q('skip-target').value = P3;
  ui.fire(ui.q('skip-target'), 'change');
  assert.deepEqual(ui.calls[0], ['updatePage', P1, { skip: { match: 'all', rules: [rule('a')], target: P3 } }]);
  ui.q('skip-0-value').value = 'y';
  ui.fire(ui.q('skip-0-value'), 'change');
  assert.equal(ui.calls[1][2].skip.rules[0].value, 'y');
  ui.q('skip-add-rule').click();
  assert.equal(ui.calls[2][2].skip.rules.length, 2);
  ui.q('skip-match').value = 'any';
  ui.fire(ui.q('skip-match'), 'change');
  assert.equal(ui.calls[3][2].skip.match, 'any');
  ui.q('skip-0-remove').click();
  assert.deepEqual(ui.calls[4], ['updatePage', P1, { skip: null }]);
  ui.q('skip-remove').click();
  assert.deepEqual(ui.calls[5], ['updatePage', P1, { skip: null }]);
});

test('it asks for a later target page when the saved target is not one', () => {
  const ui = mount(build([[P1, [field('text', { name: 'a' })], { skip: { match: 'all', rules: [rule('a')], target: 'p_gone99' } }], [P2, []]].map((p) => (p.length === 3 ? p : [p[0], p[1], p[2]])), P1));
  assert.deepEqual(optionsOf(ui.q('skip-target')), ['', P2]);
  assert.equal(ui.q('skip-target').value, '');
});

const CONTACT = [{ label: 'Email', value: 'email' }, { label: 'Phone', value: 'phone' }];

function choiceRule(r) {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [r] };
  return build([
    [P1, [field('select', { name: 'contact', options: CONTACT })]],
    [P2, [field('text', { name: 'phone', condition: cond })]],
  ], 'f_phonex');
}

test('it offers the options of a choice field on an earlier page when a rule compares one value', () => {
  const ui = mount(choiceRule(rule('contact', { value: 'phone' })));
  const picker = ui.q('cond-0-value');
  assert.equal(picker.tagName, 'SELECT');
  assert.deepEqual(Array.from(picker.options).map((o) => [o.value, o.textContent]), [['', 'Choose an option'], ['email', 'Email'], ['phone', 'Phone']]);
  assert.equal(picker.value, 'phone');
  picker.value = 'email';
  ui.fire(picker, 'change');
  assert.equal(ui.calls[0][2].condition.rules[0].value, 'email');
});

test('it keeps a stored value that is no longer one of the options and says so', () => {
  const picker = mount(choiceRule(rule('contact', { value: 'fax' }))).q('cond-0-value');
  assert.equal(picker.value, 'fax');
  assert.equal(picker.selectedOptions[0].textContent, 'fax (option no longer exists)');
});

test('it ticks options for is one of and stores them one per line', () => {
  const ui = mount(choiceRule(rule('contact', { operator: 'is_one_of', value: 'email' })));
  const box = (v) => ui.q('cond-0-value').querySelector('input[value="' + v + '"]');
  assert.equal(box('email').checked, true);
  assert.equal(box('phone').checked, false);
  box('phone').checked = true;
  ui.fire(box('phone'), 'change');
  assert.equal(ui.calls[0][2].condition.rules[0].value, 'email\nphone');
});

test('it keeps the free text value for fields without options', () => {
  const cond = { actions: [{ action: 'show' }], match: 'all', rules: [rule('a')] };
  const state = build([[P1, [field('text', { name: 'a' }), field('text', { name: 'b', condition: cond })]]], 'f_bxxxxx');
  const value = mount(state).q('cond-0-value');
  assert.equal(value.tagName, 'INPUT');
  assert.equal(value.value, 'x');
});
