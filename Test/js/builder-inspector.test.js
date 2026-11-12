'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspectorPath = path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js');
const inspector = require(inspectorPath);

const config = {
  personalAutocomplete: ['given-name', 'bday'],
  fieldTypes: [
    { code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: ['min_length', 'pattern', 'preset', 'matches'], settingsSpec: {}, hasOptions: false },
    { code: 'email', emailAddress: true, personalByDefault: true, conditionSource: true, label: 'Email', category: 'input', allowedRules: ['max_length'], settingsSpec: {}, hasOptions: false },
    { code: 'address', personalByDefault: true, conditionSource: false, label: 'Address', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, routable: false },
    { code: 'select', personalByDefault: false, conditionSource: true, label: 'Select', category: 'choice', allowedRules: [], settingsSpec: { empty_label: { type: 'string', default: '' } }, hasOptions: true },
    {
      code: 'textarea', personalByDefault: false, conditionSource: true, label: 'Textarea', category: 'input', allowedRules: [], hasOptions: false,
      settingsSpec: {
        rows: { type: 'int', default: 5, min: 2, max: 20 },
        mode: { type: 'enum', default: 'a', values: ['a', 'b'] },
        flag: { type: 'bool', default: false },
        tags: { type: 'list', default: [] },
      },
    },
  ],
  rules: [
    { code: 'min_length', label: 'Minimum length', valueType: 'int' },
    { code: 'pattern', label: 'Pattern', valueType: 'regex' },
    { code: 'max_length', label: 'Maximum length', valueType: 'int' },
    { code: 'matches', label: 'Matches', valueType: 'field' },
    { code: 'preset', label: 'Format', valueType: 'preset' },
  ],
  ruleLibrary: [
    { ref: 'uk_postcode', label: 'UK postcode', group: 'Postcodes', example: 'SW1A 1AA', message: 'Enter a UK postcode.', saved: false },
    { ref: 'saved:ref_no', label: 'Reference', group: 'Saved rules', example: 'AB-1', message: 'Enter a reference.', saved: true },
  ],
  urls: { rules: '/admin/rules' },
  operators: [{ code: 'equals', label: 'equals' }, { code: 'is_empty', label: 'is empty' }],
  actions: [{ code: 'show', label: 'Show', targets: ['page', 'section', 'field', 'content'], param: null }, { code: 'hide', label: 'Hide', targets: ['page', 'section', 'field', 'content'], param: null }],
  widths: [{ value: 'full', label: 'Full' }, { value: 'half', label: 'Half' }],
  autocomplete: ['', 'name', 'email'],
  prefillSources: [
    { code: 'customer.firstname', label: 'Customer first name', types: ['text', 'hidden'] },
    { code: 'customer.email', label: 'Customer email', types: ['email', 'text', 'hidden'] },
    { code: 'product.price', label: 'Product price', types: ['number', 'text', 'hidden'] },
  ],
  stores: [{ value: 0, label: 'All' }, { value: 1, label: 'Main' }],
  customerGroups: [{ value: 0, label: 'NOT LOGGED IN' }, { value: 1, label: 'General' }, { value: 2, label: '<b>Wholesale</b>' }],
};

const PAGE = 'p_aaaa11';
function setup(fields, selectedFn) {
  let state = model.createState({
    id: null, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] },
    settings: { submit_label: 'Submit', notify_recipients: [], autoresponder_enabled: true, autoresponder_email_field: '' },
  });
  const made = fields.map(([type, patch]) => {
    const f = Object.assign(model.newField(type, config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  if (selectedFn) {
    state = model.select(state, selectedFn(made));
  }
  return { state, made };
}

function mount(state, cfg) {
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  const actions = {
    apply: (...args) => calls.push(args),
    select: (id) => calls.push(['SELECT', id]),
    notify: (m) => calls.push(['NOTIFY', m]),
  };
  inspector.render(container, state, cfg || config, actions);
  const q = (key) => container.querySelector('[data-ffb-input="' + key + '"]');
  const fire = (node, type) => node.dispatchEvent(new dom.window.Event(type, { bubbles: true }));
  return { container, calls, actions, q, fire, dom };
}

test('it renders the form settings when nothing is selected', () => {
  const { state } = setup([]);
  const ui = mount(state);
  assert.equal(ui.q('identifier').value, 'contact');
  assert.equal(ui.q('title').value, 'Contact');
  ui.q('title').value = 'New';
  ui.fire(ui.q('title'), 'change');
  assert.deepEqual(ui.calls[0], ['updateForm', { title: 'New' }]);
  ui.q('settings.notify_recipients').value = 'a@x.com, b@x.com';
  ui.fire(ui.q('settings.notify_recipients'), 'change');
  assert.deepEqual(ui.calls[1], ['updateSettings', { notify_recipients: ['a@x.com', 'b@x.com'] }]);
  const stores = ui.q('store_ids');
  stores.options[1].selected = true;
  ui.fire(stores, 'change');
  assert.deepEqual(ui.calls[2], ['updateForm', { store_ids: [0, 1] }]);
  ui.q('settings.show_progress').checked = true;
  ui.fire(ui.q('settings.show_progress'), 'change');
  assert.deepEqual(ui.calls[3], ['updateSettings', { show_progress: true }]);
});

test('it edits the label and name of a field through update item', () => {
  const { state, made } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state);
  ui.q('label').value = 'Full name';
  ui.fire(ui.q('label'), 'change');
  ui.q('name').value = 'full_name';
  ui.fire(ui.q('name'), 'change');
  ui.q('required').checked = true;
  ui.fire(ui.q('required'), 'change');
  ui.q('width').value = 'half';
  ui.fire(ui.q('width'), 'change');
  assert.deepEqual(ui.calls, [
    ['updateItem', made[0].id, { label: 'Full name' }],
    ['updateItem', made[0].id, { name: 'full_name' }],
    ['updateItem', made[0].id, { required: true }],
    ['updateItem', made[0].id, { width: 'half' }],
  ]);
});

test('it commits text inputs on change not on every keystroke', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state);
  ui.q('label').value = 'Typing';
  ui.fire(ui.q('label'), 'input');
  assert.equal(ui.calls.length, 0);
  ui.fire(ui.q('label'), 'change');
  assert.equal(ui.calls.length, 1);
});

test('it adds and edits a validation rule', () => {
  const { state, made } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  let ui = mount(state);
  const menu = ui.q('add-rule');
  assert.deepEqual(Array.from(menu.options).map((o) => o.value),
    ['', 'rule:min_length', 'rule:pattern', 'rule:matches', 'preset:uk_postcode', 'preset:saved:ref_no']);
  menu.value = 'rule:min_length';
  ui.fire(menu, 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[0].id, { rules: [{ rule: 'min_length', value: '', message: '' }] }]);

  const withRule = setup([['text', { label: 'A', name: 'a', rules: [{ rule: 'min_length', value: 2, message: '' }] }]], (m) => m[0].id);
  ui = mount(withRule.state);
  const value = ui.q('rule-0-value');
  assert.equal(value.type, 'number');
  value.value = '5';
  ui.fire(value, 'change');
  assert.deepEqual(ui.calls[0][2].rules[0], { rule: 'min_length', value: 5, message: '' });
  ui.q('rule-0-message').value = 'Too short';
  ui.fire(ui.q('rule-0-message'), 'change');
  assert.equal(ui.calls[1][2].rules[0].message, 'Too short');
  ui.q('rule-0-remove').click();
  assert.deepEqual(ui.calls[2][2], { rules: [] });
});

test('it shows an invalid pattern hint', () => {
  const { state } = setup([['text', { label: 'A', name: 'a', rules: [{ rule: 'pattern', value: '(', message: '' }] }]], (m) => m[0].id);
  const ui = mount(state);
  const hint = ui.container.querySelector('[data-ffb-hint="rule-0"]');
  assert.equal(hint.hidden, false);
  const input = ui.q('rule-0-value');
  input.value = '^[a-z]+$';
  ui.fire(input, 'input');
  assert.equal(hint.hidden, true);
  input.value = '[';
  ui.fire(input, 'input');
  assert.equal(hint.hidden, false);
  assert.equal(ui.calls.length, 0);
});

test('it adds a condition using only earlier fields', () => {
  const { state, made } = setup([
    ['text', { label: 'A', name: 'a' }],
    ['text', { label: 'B', name: 'b' }],
    ['text', { label: 'C', name: 'c' }],
  ], (m) => m[1].id);
  let ui = mount(state);
  ui.q('add-condition').click();
  assert.deepEqual(ui.calls[0], ['updateItem', made[1].id, { condition: { actions: [{ action: 'show' }], match: 'all', rules: [] } }]);

  const cond = { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'equals', value: 'x' }] };
  const second = setup([
    ['text', { label: 'A', name: 'a' }],
    ['text', { label: 'B', name: 'b', condition: cond }],
    ['text', { label: 'C', name: 'c' }],
  ], (m) => m[1].id);
  ui = mount(second.state);
  const fieldSel = ui.q('cond-0-field');
  assert.deepEqual(Array.from(fieldSel.options).map((o) => o.value), ['a']);
  ui.q('cond-0-operator').value = 'is_empty';
  ui.fire(ui.q('cond-0-operator'), 'change');
  assert.equal(ui.calls[0][2].condition.rules[0].operator, 'is_empty');
  ui.q('cond-add-rule').click();
  assert.deepEqual(ui.calls[1][2].condition.rules[1], { field: 'a', operator: 'equals', value: '' });
  ui.q('cond-action-0').value = 'hide';
  ui.fire(ui.q('cond-action-0'), 'change');
  assert.deepEqual(ui.calls[2][2].condition.actions, [{ action: 'hide' }]);

  const hiddenValue = setup([
    ['text', { label: 'A', name: 'a' }],
    ['text', { label: 'B', name: 'b', condition: { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'is_empty', value: '' }] } }],
  ], (m) => m[1].id);
  ui = mount(hiddenValue.state);
  assert.equal(ui.q('cond-0-value').hidden, true);
});

test('it edits options for choice fields', () => {
  const { state, made } = setup([['select', { label: 'S', name: 's', options: [{ label: 'One', value: 'one' }, { label: 'Two', value: 'two' }] }]], (m) => m[0].id);
  let ui = mount(state);
  ui.q('option-0-label').value = 'Uno';
  ui.fire(ui.q('option-0-label'), 'change');
  assert.deepEqual(ui.calls[0][2].options[0], { label: 'Uno', value: 'one' });
  ui.q('option-add').click();
  assert.equal(ui.calls[1][2].options.length, 3);
  ui.q('option-1-up').click();
  assert.deepEqual(ui.calls[2][2].options.map((o) => o.value), ['two', 'one']);
  ui.q('option-0-remove').click();
  assert.deepEqual(ui.calls[3][2].options.map((o) => o.value), ['two']);
  assert.equal(ui.q('option-0-label') !== null && made.length === 1, true);
  const plain = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  ui = mount(plain.state);
  assert.equal(ui.q('option-add'), null);
});

test('it renders type settings from the settings spec', () => {
  const { state, made } = setup([['textarea', { label: 'T', name: 't', settings: { rows: 5 } }]], (m) => m[0].id);
  const ui = mount(state);
  const rows = ui.q('setting-rows');
  assert.equal(rows.type, 'number');
  assert.equal(rows.min, '2');
  assert.equal(rows.max, '20');
  rows.value = '8';
  ui.fire(rows, 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[0].id, { settings: { rows: 8 } }]);
  ui.q('setting-mode').value = 'b';
  ui.fire(ui.q('setting-mode'), 'change');
  assert.equal(ui.calls[1][2].settings.mode, 'b');
  ui.q('setting-flag').checked = true;
  ui.fire(ui.q('setting-flag'), 'change');
  assert.equal(ui.calls[2][2].settings.flag, true);
  ui.q('setting-tags').value = 'x, y';
  ui.fire(ui.q('setting-tags'), 'change');
  assert.deepEqual(ui.calls[3][2].settings.tags, ['x', 'y']);
});

test('it offers only email fields for the autoresponder field', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }], ['email', { label: 'E', name: 'e' }]]);
  const ui = mount(state);
  const sel = ui.q('settings.autoresponder_email_field');
  assert.deepEqual(Array.from(sel.options).map((o) => o.value), ['', 'e']);
  sel.value = 'e';
  ui.fire(sel, 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { autoresponder_email_field: 'e' }]);
});

const eq = (field, value) => ({ field, operator: 'equals', value });

test('it edits routing rules in the form settings', () => {
  const depts = [{ label: 'Sales', value: 'sales' }, { label: 'Support', value: 'support' }];
  const { state } = setup([['text', { label: 'A', name: 'a' }], ['select', { label: 'Dept', name: 'dept', options: depts }]]);
  const group = (value) => ({ match: 'all', rules: [eq('dept', value)] });
  state.form.settings.notify_routes = [{ condition: group('sales'), emails: ['s@x.com'], mode: 'add' }];
  const ui = mount(state);
  const key = 'notify_routes.0.condition-0-';
  assert.deepEqual(Array.from(ui.q(key + 'field').options).map((o) => o.value), ['a', 'dept']);
  assert.equal(ui.q(key + 'value').value, 'sales');
  assert.equal(ui.q('notify_routes.0.emails').value, 's@x.com');
  ui.q(key + 'value').value = 'support';
  ui.fire(ui.q(key + 'value'), 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { notify_routes: [{ condition: group('support'), emails: ['s@x.com'], mode: 'add' }] }]);
  ui.q('notify_routes.0.emails').value = 'a@x.com, b@x.com';
  ui.fire(ui.q('notify_routes.0.emails'), 'change');
  assert.deepEqual(ui.calls[1][1].notify_routes[0].emails, ['a@x.com', 'b@x.com']);
  ui.q('notify_routes.0.mode').value = 'replace';
  ui.fire(ui.q('notify_routes.0.mode'), 'change');
  assert.equal(ui.calls[2][1].notify_routes[0].mode, 'replace');
  ui.q('notify_routes.add').click();
  assert.deepEqual(ui.calls[3][1].notify_routes[1], { condition: { match: 'all', rules: [eq('a', '')] }, emails: [], mode: 'add' });
  ui.q('notify_routes.0.remove').click();
  assert.deepEqual(ui.calls[4], ['updateSettings', { notify_routes: [] }]);
});

test('it edits a nested group inside a routing rule', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }], ['select', { label: 'Dept', name: 'dept' }]]);
  state.form.settings.notify_routes = [{ condition: { match: 'all', rules: [eq('a', 'x')] }, emails: [], mode: 'add' }];
  const ui = mount(state);
  ui.q('notify_routes.0.condition-add-group').click();
  assert.deepEqual(ui.calls[0][1].notify_routes[0].condition.rules[1], { match: 'all', rules: [eq('a', '')] });
});

test('it never offers structured fields as routing sources', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }], ['address', { label: 'Addr', name: 'addr' }], ['select', { label: 'Dept', name: 'dept' }]]);
  state.form.settings.notify_routes = [{ condition: { match: 'all', rules: [eq('dept', 'x')] }, emails: [], mode: 'add' }];
  const ui = mount(state);
  assert.deepEqual(Array.from(ui.q('notify_routes.0.condition-0-field').options).map((o) => o.value), ['a', 'dept']);
});

test('it offers routing fields from the routable flag instead of a type list', () => {
  const custom = Object.assign({}, config, {
    fieldTypes: config.fieldTypes.concat([
      { code: 'date_range', personalByDefault: false, conditionSource: false, label: 'Range', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, routable: true },
      { code: 'geo', personalByDefault: false, conditionSource: true, label: 'Geo', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, routable: false },
    ]),
  });
  const { state } = setup([
    ['text', { label: 'A', name: 'a' }], ['text', { label: 'R', name: 'r', type: 'date_range' }], ['text', { label: 'G', name: 'g', type: 'geo' }],
    ['text', { label: 'U', name: 'u', type: 'unregistered' }],
    ['select', { label: 'Dept', name: 'dept' }],
  ]);
  state.form.settings.notify_routes = [{ condition: { match: 'all', rules: [eq('dept', 'x')] }, emails: [], mode: 'add' }];
  const ui = mount(state, custom);
  assert.deepEqual(Array.from(ui.q('notify_routes.0.condition-0-field').options).map((o) => o.value), ['a', 'r', 'u', 'dept']);
});

test('it edits the send when conditions of the notification and the autoresponder', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }], ['email', { label: 'E', name: 'e' }]]);
  const ui = mount(state);
  ui.q('notify_when.add').click();
  assert.deepEqual(ui.calls[0], ['updateSettings', { notify_when: { match: 'all', rules: [eq('a', '')] } }]);
  ui.q('autoresponder_when.add').click();
  assert.deepEqual(ui.calls[1], ['updateSettings', { autoresponder_when: { match: 'all', rules: [eq('a', '')] } }]);

  state.form.settings.notify_when = { match: 'all', rules: [eq('a', 'x')] };
  const again = mount(state);
  assert.equal(again.q('notify_when-0-value').value, 'x');
  again.q('notify_when-0-value').value = 'y';
  again.fire(again.q('notify_when-0-value'), 'change');
  assert.deepEqual(again.calls[0], ['updateSettings', { notify_when: { match: 'all', rules: [eq('a', 'y')] } }]);
  again.q('notify_when.remove').click();
  assert.deepEqual(again.calls[1], ['updateSettings', { notify_when: null }]);
});

test('it hides the notification conditions from admins without the submissions permission', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }]]);
  state.form.settings.notify_when = { match: 'all', rules: [eq('a', 'x')] };
  const ui = mount(state, Object.assign({}, config, { permissions: { notifications: false } }));
  assert.equal(ui.q('notify_when-0-value'), null);
  assert.equal(ui.q('notify_when.add'), null);
});

test('it edits the login required and allowed groups settings', () => {
  const { state } = setup([]);
  state.form.settings.access_groups = [1];
  const ui = mount(state);
  ui.q('settings.access_login_required').checked = true;
  ui.fire(ui.q('settings.access_login_required'), 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { access_login_required: true }]);
  assert.equal(ui.q('settings.access_groups.1').checked, true);
  ui.q('settings.access_groups.2').checked = true;
  ui.fire(ui.q('settings.access_groups.2'), 'change');
  assert.deepEqual(ui.calls[1], ['updateSettings', { access_groups: [1, 2] }]);
  ui.q('settings.access_groups.1').checked = false;
  ui.fire(ui.q('settings.access_groups.1'), 'change');
  assert.deepEqual(ui.calls[2], ['updateSettings', { access_groups: [] }]);
  assert.equal(ui.q('settings.access_groups.0').parentNode.textContent.includes('NOT LOGGED IN'), true);
  assert.equal(ui.container.querySelector('[data-ffb-input="settings.access_groups.2"]').parentNode.querySelector('b'), null);
});

test('it hides the not logged in group choice when login is required', () => {
  const { state } = setup([]);
  state.form.settings.access_login_required = true;
  const ui = mount(state);
  assert.equal(ui.q('settings.access_groups.0'), null);
  assert.ok(ui.q('settings.access_groups.1'));
});

test('it leaves the all groups entry out of the builder groups', () => {
  const { state } = setup([]);
  const cfg = Object.assign({}, config, { customerGroups: [{ value: 32000, label: 'ALL GROUPS' }, { value: 1, label: 'General' }] });
  const ui = mount(state, cfg);
  assert.equal(ui.q('settings.access_groups.32000'), null);
  assert.ok(ui.q('settings.access_groups.1'));
});

test('it edits the survey mode and guest dedupe settings', () => {
  const { state } = setup([['email', { label: 'E', name: 'e' }]]);
  const ui = mount(state);
  assert.deepEqual(Array.from(ui.q('settings.survey_mode').options).map((o) => o.value), ['off', 'once', 'edit']);
  ui.q('settings.survey_mode').value = 'edit';
  ui.fire(ui.q('settings.survey_mode'), 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { survey_mode: 'edit' }]);
  ui.q('settings.guest_dedupe').checked = true;
  ui.fire(ui.q('settings.guest_dedupe'), 'change');
  assert.deepEqual(ui.calls[1], ['updateSettings', { guest_dedupe: true }]);
  assert.ok(ui.container.querySelector('[data-ffb-hint="survey-mode"]'));
  assert.match(ui.container.querySelector('[data-ffb-hint="guest-dedupe"]').textContent, /not verified/);
});

test('it edits the designated email field from the form\'s email fields', () => {
  const { state } = setup([['text', { label: 'T', name: 't' }], ['email', { label: 'Mail', name: 'mail' }]]);
  const ui = mount(state);
  assert.deepEqual(Array.from(ui.q('settings.email_field').options).map((o) => o.value), ['', 'mail']);
  ui.q('settings.email_field').value = 'mail';
  ui.fire(ui.q('settings.email_field'), 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { email_field: 'mail' }]);
});

test('it offers a third party email address type in the builder email pickers', () => {
  const extended = Object.assign({}, config, {
    fieldTypes: config.fieldTypes.concat([
      { code: 'work_email', emailAddress: true, personalByDefault: true, conditionSource: true, label: 'Work email', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false },
    ]),
  });
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] },
    settings: { notify_recipients: [], autoresponder_enabled: true, autoresponder_email_field: '' },
  }, extended);
  [['text', 'plain'], ['work_email', 'work'], ['email', 'mail']].forEach(([type, name]) => {
    state = model.addItem(state, Object.assign(model.newField(type, extended, state), { name, label: name }),
      { pageId: PAGE, sectionId: null, index: 99 });
  });
  const ui = mount(state, extended);

  assert.deepEqual(Array.from(ui.q('settings.autoresponder_email_field').options).map((o) => o.value), ['', 'work', 'mail']);
  assert.deepEqual(Array.from(ui.q('settings.email_field').options).map((o) => o.value), ['', 'work', 'mail']);
});

test('it edits the account edit and delete settings', () => {
  const { state } = setup([]);
  state.form.settings.account_delete = true;
  const ui = mount(state);
  assert.equal(ui.q('settings.account_delete').checked, true);
  ui.q('settings.account_edit').checked = true;
  ui.fire(ui.q('settings.account_edit'), 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { account_edit: true }]);
  ui.q('settings.account_delete').checked = false;
  ui.fire(ui.q('settings.account_delete'), 'change');
  assert.deepEqual(ui.calls[1], ['updateSettings', { account_delete: false }]);
});

test('it edits the customer replies setting with a store default choice and a hint', () => {
  const { state } = setup([]);
  const ui = mount(state);
  const select = ui.q('settings.customer_replies');
  assert.deepEqual(Array.from(select.options).map((o) => o.value), ['default', 'on', 'off']);
  assert.equal(select.value, 'default');
  assert.match(ui.container.querySelector('[data-ffb-hint="customer-replies"]').textContent, /store configuration/);
  select.value = 'off';
  ui.fire(select, 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { customer_replies: 'off' }]);
});

test('it never uses innerHTML for user content', () => {
  const src = fs.readFileSync(inspectorPath, 'utf8');
  assert.doesNotMatch(src, /innerHTML|outerHTML|insertAdjacentHTML|eval\(|new Function/);
  const { state } = setup([['text', { label: '<img src=x onerror=alert(1)>', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state);
  assert.equal(ui.container.querySelector('img'), null);
  assert.equal(ui.q('label').value, '<img src=x onerror=alert(1)>');
});

test('it warns when browser drafts are kept in local storage', () => {
  const { state } = setup([]);
  const hint = (st) => mount(st).container.querySelector('[data-ffb-hint="draft-local"]');
  assert.equal(hint(state), null);
  const local = model.updateSettings(state, { draft_mode: 'local' });
  const node = hint(local);
  assert.ok(node);
  assert.equal(node.textContent, 'Answers stay in this browser until the draft expires. Avoid for forms with personal data on shared computers.');
});

test('it hides notification settings without permission and explains why', () => {
  const { state } = setup([]);
  const allowed = mount(state);
  assert.ok(allowed.q('settings.notify_enabled'));
  assert.ok(allowed.q('settings.store_ip'));
  assert.equal(allowed.container.querySelector('[data-ffb-hint="notifications-permission"]'), null);

  const denied = new JSDOM('<div id="c"></div>');
  const box = denied.window.document.getElementById('c');
  inspector.render(box, state, Object.assign({}, config, { permissions: { notifications: false } }), { apply() {}, select() {}, notify() {} });
  const input = (key) => box.querySelector('[data-ffb-input="' + key + '"]');
  assert.equal(input('settings.notify_enabled'), null);
  assert.equal(input('settings.notify_recipients'), null);
  assert.equal(input('settings.store_ip'), null);
  assert.ok(input('settings.store_submissions'));
  const hint = box.querySelector('[data-ffb-hint="notifications-permission"]');
  assert.equal(hint.textContent, 'Notification settings need permission to view submissions.');
});

test('it adds library formats grouped by source and shows their example and default message', () => {
  const { state, made } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  let ui = mount(state);
  assert.ok(ui.container.querySelector('[data-ffb-hint="no-rules"]'));
  const menu = ui.q('add-rule');
  assert.deepEqual(Array.from(menu.querySelectorAll('optgroup')).map((g) => g.label), ['Checks', 'Postcodes', 'Saved rules']);
  menu.value = 'preset:saved:ref_no';
  ui.fire(menu, 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[0].id, { rules: [{ rule: 'preset', value: 'saved:ref_no', message: '' }] }]);
  assert.equal(ui.container.querySelector('a.ffb-admin-link-small').getAttribute('href'), '/admin/rules');

  const withRule = setup([['text', { label: 'A', name: 'a', rules: [{ rule: 'preset', value: 'uk_postcode', message: '' }] }]], (m) => m[0].id);
  ui = mount(withRule.state);
  const card = ui.container.querySelector('.ffb-admin-rule');
  assert.equal(card.querySelector('.ffb-admin-rule-title').textContent, 'UK postcode');
  assert.match(card.textContent, /Example: SW1A 1AA/);
  assert.equal(ui.q('rule-0-message').placeholder, 'Enter a UK postcode.');
  assert.equal(ui.q('add-rule').querySelector('option[value="preset:uk_postcode"]').disabled, true);
});

test('it flags a library format that no longer exists', () => {
  const { state } = setup([['text', { label: 'A', name: 'a', rules: [{ rule: 'preset', value: 'saved:gone', message: '' }] }]], (m) => m[0].id);
  const ui = mount(state);
  assert.match(ui.container.querySelector('.ffb-admin-rule').textContent, /no longer exists/);
});

test('it shows a personal data toggle in the field inspector', () => {
  const { state, made } = setup([['email', { label: 'E', name: 'e' }], ['select', { label: 'S', name: 's' }], ['text', { label: 'T', name: 't', autocomplete: 'bday' }]], (m) => m[0].id);
  assert.equal(made[0].personal, undefined);
  let ui = mount(state);
  assert.equal(ui.q('personal').checked, true);
  ui.q('personal').checked = false;
  ui.fire(ui.q('personal'), 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[0].id, { personal: false }]);
  ui = mount(model.select(state, made[1].id));
  assert.equal(ui.q('personal').checked, false);
  ui = mount(model.select(state, made[2].id));
  assert.equal(ui.q('personal').checked, true);
  assert.equal(model.isPersonal({ type: 'select', personal: true }, config), true);
  assert.equal(model.newField('email', config, state).personal, undefined);
});

test('it offers prefill sources in the field inspector', () => {
  const { state, made } = setup([['email', { label: 'E', name: 'e' }], ['select', { label: 'S', name: 's' }], ['text', { label: 'T', name: 't', prefill: 'product.price', locked: true }], ['text', { label: 'U', name: 'u', prefill: 'customer.firstname' }]], (m) => m[0].id);
  let ui = mount(state);
  assert.deepEqual(Array.from(ui.q('prefill').options).map((o) => o.value), ['', 'customer.email']);
  assert.equal(ui.q('prefill').options[1].textContent, 'Customer email');
  assert.equal(ui.q('locked'), null);
  ui.q('prefill').value = 'customer.email';
  ui.fire(ui.q('prefill'), 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[0].id, { prefill: 'customer.email' }]);
  assert.equal(mount(model.select(state, made[1].id)).q('prefill'), null);
  ui = mount(model.select(state, made[2].id));
  assert.equal(ui.q('prefill').value, 'product.price');
  assert.equal(ui.q('locked').checked, true);
  assert.equal(ui.q('locked').disabled, true);
  ui = mount(model.select(state, made[3].id));
  assert.equal(ui.q('locked').checked, false);
  assert.equal(ui.q('locked').disabled, false);
  ui.q('locked').checked = true;
  ui.fire(ui.q('locked'), 'change');
  assert.deepEqual(ui.calls[0], ['updateItem', made[3].id, { locked: true }]);
});

test('it offers only the operators that apply to the referenced field type', () => {
  const cond = { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'equals', value: 'x' }] };
  const { state } = setup([
    ['text', { label: 'A', name: 'a' }],
    ['text', { label: 'B', name: 'b', condition: cond }],
  ], (m) => m[1].id);
  const cfg = Object.assign({}, config, {
    operators: [
      { code: 'equals', label: 'equals', needsValue: true, types: ['text'] },
      { code: 'is_empty', label: 'is empty', needsValue: false, types: ['text'] },
      { code: 'sounds_like', label: 'sounds like', needsValue: true, types: ['date'] },
    ],
  });
  const ui = mount(state, cfg);
  assert.deepEqual(Array.from(ui.q('cond-0-operator').options).map((o) => o.value), ['equals', 'is_empty']);

  const custom = Object.assign({}, cfg, {
    operators: cfg.operators.concat([{ code: 'shout', label: 'shout', needsValue: false, types: ['text'] }]),
  });
  const hidden = setup([
    ['text', { label: 'A', name: 'a' }],
    ['text', { label: 'B', name: 'b', condition: { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'shout', value: '' }] } }],
  ], (m) => m[1].id);
  const ui2 = mount(hidden.state, custom);
  assert.equal(ui2.q('cond-0-value').hidden, true);
});

const contributorConfig = (readOnly) => Object.assign({}, config, {
  settingsContributors: [{
    code: 'acme_crm', label: 'Acme CRM', readOnly: Boolean(readOnly),
    spec: {
      list_id: { type: 'string', default: 'main', label: 'List id' },
      sync: { type: 'bool', default: true, label: 'Sync' },
    },
  }, {
    code: 'other_ext', label: 'Other', readOnly: false,
    spec: { mode: { type: 'enum', default: 'a', values: ['a', 'b'], label: 'Mode' } },
  }],
});

test('it renders a builder panel for each contributor from its spec', () => {
  const { state } = setup([]);
  const view = mount(state, contributorConfig());

  const legends = Array.from(view.container.querySelectorAll('fieldset legend')).map((l) => l.textContent);
  assert.ok(legends.includes('Acme CRM'));
  assert.ok(legends.includes('Other'));
  const list = view.q('settings.ext.acme_crm.list_id');
  assert.equal(list.value, 'main');
  assert.equal(list.closest('label').textContent, 'List id');
  assert.equal(view.q('settings.ext.acme_crm.sync').checked, true);

  list.value = 'vip';
  view.fire(list, 'change');
  assert.deepEqual(view.calls.pop(), ['updateSettings', { ext: { acme_crm: { list_id: 'vip' } } }]);
});

test('it shows translated labels for custom hidden sources in the builder', () => {
  const labelled = Object.assign({}, config, {
    settingsContributors: [{
      code: 'acme_crm', label: 'Acme CRM', readOnly: false,
      spec: {
        source: { type: 'enum', default: 'a', values: ['a', 'crm.id', 'plain'], value_labels: { a: 'Fixed value', 'crm.id': 'CRM contact id' }, label: 'Source' },
      },
    }],
  });
  const { state } = setup([]);
  const view = mount(state, labelled);

  const options = Array.from(view.q('settings.ext.acme_crm.source').options).map((o) => [o.value, o.textContent]);
  assert.deepEqual(options, [['a', 'Fixed value'], ['crm.id', 'CRM contact id'], ['plain', 'plain']]);
});

test('it keeps other contributor values when one contributor setting changes', () => {
  const { state } = setup([]);
  const stateWithExt = Object.assign({}, state, {
    form: Object.assign({}, state.form, { settings: Object.assign({}, state.form.settings, {
      ext: { acme_crm: { list_id: 'vip', sync: false }, other_ext: { mode: 'b' } },
    }) }),
  });
  const view = mount(stateWithExt, contributorConfig());

  const sync = view.q('settings.ext.acme_crm.sync');
  assert.equal(sync.checked, false);
  sync.checked = true;
  view.fire(sync, 'change');

  assert.deepEqual(view.calls.pop(), ['updateSettings', {
    ext: { acme_crm: { list_id: 'vip', sync: true }, other_ext: { mode: 'b' } },
  }]);
});

test('it disables a contributor panel the admin may not change', () => {
  const { state } = setup([]);
  const view = mount(state, contributorConfig(true));

  assert.equal(view.q('settings.ext.acme_crm.list_id').disabled, true);
  assert.equal(view.q('settings.ext.other_ext.mode').disabled, false);
  assert.ok(view.container.querySelector('[data-ffb-hint="contributor-read-only-acme_crm"]'));
});

test('it shows the built-in setting label in the builder inspector', () => {
  const cfg = Object.assign({}, config, {
    fieldTypes: config.fieldTypes.map((t) => (t.code === 'select'
      ? Object.assign({}, t, { settingsSpec: { empty_label: { type: 'string', default: '', label: 'Empty option label' } } })
      : t)),
  });
  const { state } = setup([['select', { label: 'S', name: 's' }]], (m) => m[0].id);
  const ui = mount(state, cfg);
  assert.ok(ui.container.textContent.includes('Empty option label'));
  assert.ok(!ui.container.textContent.includes('Empty label'));
});

const maskConfig = () => Object.assign({}, config, {
  fieldTypes: config.fieldTypes.map((t) => (t.code === 'text'
    ? Object.assign({}, t, { settingsSpec: { mask: { type: 'string', format: 'mask', default: '', max_length: 40, label: 'Input mask' } } })
    : t)),
  masks: [{ code: 'uk_postcode', label: 'UK postcode' }, { code: 'uk_sort_code', label: 'UK sort code' }],
});

test('it saves a preset mask on a text field', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state, maskConfig());
  const select = ui.q('setting-mask');
  assert.deepEqual(Array.from(select.options).map((o) => o.value), ['', 'uk_postcode', 'uk_sort_code', '__custom']);
  assert.equal(ui.q('setting-mask-custom').closest('label').hidden, true);
  select.value = 'uk_postcode';
  ui.fire(select, 'change');
  const call = ui.calls.filter((c) => c[0] === 'updateItem').pop();
  assert.equal(call[2].settings.mask, 'uk_postcode');
});

test('it saves a custom mask typed into the pattern box', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state, maskConfig());
  ui.q('setting-mask').value = '__custom';
  ui.fire(ui.q('setting-mask'), 'change');
  const custom = ui.q('setting-mask-custom');
  assert.equal(custom.closest('label').hidden, false);
  assert.equal(custom.maxLength, 40);
  custom.value = '(999) 999-9999';
  ui.fire(custom, 'change');
  const call = ui.calls.filter((c) => c[0] === 'updateItem').pop();
  assert.equal(call[2].settings.mask, '(999) 999-9999');
});

test('it shows an existing custom mask in the pattern box', () => {
  const { state } = setup([['text', { label: 'A', name: 'a', settings: { mask: '99-99' } }]], (m) => m[0].id);
  const ui = mount(state, maskConfig());
  assert.equal(ui.q('setting-mask').value, '__custom');
  assert.equal(ui.q('setting-mask-custom').value, '99-99');
});

test('it warns when the maximum length is shorter than the mask', () => {
  const rules = [{ rule: 'max_length', value: 8, message: '' }];
  const withMask = (mask, limit) => setup([['text', { label: 'A', name: 'a', settings: { mask }, rules: limit ? rules : [] }]], (m) => m[0].id);
  // The status node always exists (a live region); only its text says whether there is a warning.
  const hint = (ui) => {
    const node = ui.container.querySelector('[data-ffb-mask-warning]');
    return node.textContent === '' ? null : node;
  };
  assert.ok(hint(mount(withMask('(999) 999-9999', true).state, maskConfig())));
  const cfg = Object.assign(maskConfig(), { ruleLibrary: [{ ref: 'uk_postcode', label: 'UK postcode', group: 'P', example: 'SW1A 1AA', message: '', saved: false }] });
  assert.equal(hint(mount(withMask('uk_postcode', true).state, cfg)), null, 'example fits in 8 characters');
  assert.equal(hint(mount(withMask('99-99-99-99-99', false).state, maskConfig())), null, 'no limit, no warning');
  assert.equal(hint(mount(withMask('99-99-99-99-99', true).state, maskConfig())).getAttribute('role'), 'status');
});

test('it switches the reference line on the success message off and says when it shows', () => {
  const { state } = setup([['email', { label: 'E', name: 'e' }]]);
  const ui = mount(state);
  const box = ui.q('settings.show_reference');
  assert.equal(box.type, 'checkbox');
  box.checked = false;
  ui.fire(box, 'change');
  assert.deepEqual(ui.calls[0], ['updateSettings', { show_reference: false }]);
  assert.match(ui.container.querySelector('[data-ffb-hint="show-reference"]').textContent, /\{\{reference\}\}/);
});

test('it opens the tab pressed while an edited input commits and re-renders the inspector', () => {
  const { state } = setup([['text', { label: 'A', name: 'a' }]], (m) => m[0].id);
  const ui = mount(state);
  ui.actions.apply = (...args) => {
    ui.calls.push(args);
    inspector.render(ui.container, state, config, ui.actions);
  };
  const tab = (name) => ui.container.querySelector('.ffb-admin-tab[data-tab="' + name + '"]');
  ui.q('label').value = 'Full name';
  ui.fire(tab('validation'), 'mousedown');
  ui.fire(ui.q('label'), 'change');

  assert.deepEqual(ui.calls[0], ['updateItem', state.selectedId, { label: 'Full name' }]);
  assert.equal(tab('validation').getAttribute('aria-selected'), 'true');
  assert.equal(tab('general').getAttribute('aria-selected'), 'false');
});

test('it groups each option with its own move and remove buttons and disables moves that do nothing', () => {
  const { state } = setup([['select', { label: 'S', name: 's', options: [{ label: 'One', value: 'one' }, { label: 'Two', value: 'two' }] }]], (m) => m[0].id);
  const ui = mount(state);
  const blocks = ui.container.querySelectorAll('.ffb-admin-option');
  assert.equal(blocks.length, 2);
  assert.ok(blocks[0].querySelector('[data-ffb-input="option-0-up"]'));
  assert.ok(blocks[1].querySelector('[data-ffb-input="option-1-remove"]'));
  assert.equal(ui.q('option-0-up').disabled, true);
  assert.equal(ui.q('option-0-down').disabled, false);
  assert.equal(ui.q('option-1-up').disabled, false);
  assert.equal(ui.q('option-1-down').disabled, true);
  assert.ok(ui.q('option-add').classList.contains('ffb-admin-add-field'));
});
