'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const type = (code, extra) => Object.assign({
  code, label: code, category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false,
  personalByDefault: false, conditionSource: true,
}, extra);

const config = {
  fieldTypes: [
    type('text', { label: 'Text field', allowedRules: ['min_length', 'preset'], controls: {}, presets: ['uk_postcode', 'uk_phone', 'saved:crm'] }),
    type('email', { label: 'Email', emailAddress: true, allowedRules: ['max_length', 'preset'], controls: {}, presets: ['saved:crm'] }),
    type('tel', { label: 'Phone', allowedRules: ['preset'], controls: {}, presets: ['uk_phone'] }),
    type('acme', { label: 'Acme', allowedRules: ['preset'] }),
    type('select', { label: 'Dropdown', hasOptions: true, controls: { placeholder: false, default: 'option', option_description: false } }),
    type('cards', { label: 'Cards', hasOptions: true, controls: { placeholder: false, default: 'option' } }),
    type('matrix', { label: 'Matrix', hasOptions: true, defaultAllowed: false, controls: { option_description: false, options_title: 'Columns', option_add_label: 'Add column' } }),
    type('country', { label: 'Country', controls: { placeholder: false, default: 'option' }, defaultChoices: [{ value: 'GB', label: 'Britain' }, { value: 'FR', label: 'France' }] }),
    type('date', { label: 'Date', controls: { placeholder: false, default: 'date' } }),
    type('number', { label: 'Number', controls: { default: 'number' } }),
    type('color', { label: 'Colour', controls: { default: 'color' } }),
    type('checkbox', { label: 'Checkbox', defaultAllowed: false, controls: { placeholder: false, autocomplete: false } }),
    type('file', { label: 'File upload', isFile: true, defaultAllowed: false, draftAllowed: false, controls: { placeholder: false },
      settingsSpec: { extensions: { type: 'list', default: [] }, max_size_mb: { type: 'int', default: 0, min: 0, max: 100 } } }),
    type('hidden', {
      label: 'Hidden field', serverValue: true,
      requiredAllowed: false, draftAllowed: false,
      controls: { placeholder: false, help: false, width: false, css_class: false, autocomplete: false },
      settingsSpec: {
        source: { type: 'enum', default: 'static', values: ['static', 'query'], visible_when: { '@prefill': [''] } },
        query_param: { type: 'string', default: '', visible_when: { source: ['query'], '@prefill': [''] } },
      },
    }),
    type('newsletter', { label: 'Newsletter', consent: true, requiredAllowed: false, draftAllowed: false, defaultAllowed: false, controls: {},
      settingsSpec: { email_field: { type: 'string', default: '', format: 'email_field', hint: "Empty = the form's customer email field." } } }),
    type('date_range', { label: 'Date range', defaultAllowed: false, controls: {},
      settingsSpec: { min_date: { type: 'string', default: '', format: 'date' } } }),
    type('scale', { label: 'Scale', settingsSpec: { min: { type: 'int', default: 1, min: 0, max: 1, values: ['0', '1'] } } }),
  ],
  rules: [
    { code: 'min_length', label: 'Minimum length', valueType: 'int' },
    { code: 'max_length', label: 'Maximum length', valueType: 'int' },
    { code: 'preset', label: 'Format', valueType: 'preset' },
  ],
  ruleLibrary: [
    { ref: 'uk_postcode', label: 'UK postcode', group: 'Postcodes', example: '', message: '', saved: false },
    { ref: 'uk_phone', label: 'UK phone', group: 'Phone', example: '', message: '', saved: false },
    { ref: 'saved:crm', label: 'CRM id', group: 'Saved rules', example: '', message: '', saved: true },
  ],
  contentTypes: [{ code: 'notice', label: 'Notice' }, { code: 'paragraph', label: 'Paragraph' }, { code: 'rich_text', label: 'Rich text' }],
  operators: [], actions: [], widths: [{ value: 'full', label: 'Full' }],
  autocomplete: ['', 'given-name', 'email'],
  autocompleteLabels: { 'given-name': 'First name', email: 'Email' },
  prefillSources: [
    { code: 'customer.email', label: 'Customer email', types: ['email', 'text', 'hidden'] },
    { code: 'product.sku', label: 'Product SKU', types: ['text', 'hidden'] },
  ],
  uploads: { allowedExtensions: ['pdf', 'jpg'], maxMb: 8 },
  stores: [],
};

const PAGE = 'p_aaaa11';

function mount(fields, pick, cfg) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] }, settings: {},
  });
  const made = fields.map(([code, patch]) => {
    const f = Object.assign(model.newField(code, cfg || config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  state = model.select(state, made[pick === undefined ? made.length - 1 : pick].id);
  return render(state, cfg);
}

function render(state, cfg) {
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  inspector.render(container, state, cfg || config, { apply: (...a) => calls.push(a), select: () => {}, notify: () => {} });
  const q = (key) => container.querySelector('[data-ffb-input="' + key + '"]');
  const fire = (node, ev) => node.dispatchEvent(new dom.window.Event(ev, { bubbles: true }));
  const text = () => container.textContent;
  return { container, calls, q, fire, text };
}

test('it shows every general control for a type that declares none', () => {
  const ui = mount([['acme', {}]]);
  for (const key of ['placeholder', 'help', 'default', 'width', 'required', 'draft', 'autocomplete', 'css_class']) {
    assert.ok(ui.q(key), key);
  }
});

test('it hides the general controls a type does not render', () => {
  const ui = mount([['checkbox', {}]]);
  assert.equal(ui.q('placeholder'), null);
  assert.equal(ui.q('autocomplete'), null);
  assert.equal(ui.q('default'), null);
  assert.ok(ui.q('help'));
  assert.ok(ui.q('required'));
});

test('it hides placeholder, help, width, css class, autocomplete, required and draft on a hidden field', () => {
  const ui = mount([['hidden', {}]]);
  for (const key of ['placeholder', 'help', 'width', 'css_class', 'autocomplete', 'required', 'draft']) {
    assert.equal(ui.q(key), null, key);
  }
  assert.ok(ui.q('default'));
});

test('it keeps a hidden control visible while it holds a value the server rejects', () => {
  const ui = mount([['hidden', { required: true }]]);
  assert.ok(ui.q('required'));
  assert.equal(ui.q('required').checked, true);
  assert.ok(mount([['checkbox', { default: 'yes' }]]).q('default'));
});

test('it hides save in draft for a type the server never drafts', () => {
  assert.equal(mount([['file', {}]]).q('draft'), null);
  assert.equal(mount([['newsletter', {}]]).q('draft'), null);
  assert.equal(mount([['newsletter', {}]]).q('required'), null);
});

test('it picks the default of an option type from its options', () => {
  const ui = mount([['select', { options: [{ label: 'Red', value: 'r' }, { label: 'Blue', value: 'b' }], default: 'b' }]]);
  const input = ui.q('default');
  assert.equal(input.tagName, 'SELECT');
  assert.deepEqual(Array.from(input.options).map((o) => o.value), ['', 'r', 'b']);
  assert.equal(input.value, 'b');
  input.value = 'r';
  ui.fire(input, 'change');
  assert.deepEqual(ui.calls[0][2], { default: 'r' });
});

test('it keeps a stored default that is no longer an option visible', () => {
  const ui = mount([['select', { options: [{ label: 'Red', value: 'r' }], default: 'gone' }]]);
  assert.deepEqual(Array.from(ui.q('default').options).map((o) => o.value), ['', 'r', 'gone']);
});

test('it uses date, number and colour inputs for typed defaults', () => {
  assert.equal(mount([['date', {}]]).q('default').type, 'date');
  assert.equal(mount([['number', {}]]).q('default').type, 'number');
  const colour = mount([['color', { default: '#336699' }]]);
  assert.equal(colour.q('default').type, 'color');
  colour.fire(colour.q('default-clear'), 'click');
  assert.deepEqual(colour.calls[0][2], { default: '' });
  const none = mount([['color', {}]]);
  assert.equal(none.q('default-clear'), null);
  assert.equal(none.container.querySelector('input[type="color"]'), null);
  assert.match(none.text(), /No default/);
  none.fire(none.q('default-choose'), 'click');
  const picker = none.container.querySelector('input[type="color"]');
  assert.ok(picker);
  picker.value = '#112233';
  none.fire(picker, 'change');
  assert.deepEqual(none.calls[0][2], { default: '#112233' });
});

test('it labels autocomplete tokens', () => {
  const ui = mount([['text', {}]]);
  const labels = Array.from(ui.q('autocomplete').options).map((o) => o.textContent);
  assert.deepEqual(labels, ['(none)', 'First name', 'Email']);
});

test('it hides prefill when no source suits the type and shows locked only once a source is set', () => {
  assert.equal(mount([['date', {}]]).q('prefill'), null);
  const plain = mount([['text', {}]]);
  assert.ok(plain.q('prefill'));
  assert.equal(plain.q('locked'), null);
  const set = mount([['text', { prefill: 'customer.email' }]]);
  assert.ok(set.q('locked'));
});

test('it explains locked for customers and guests', () => {
  const ui = mount([['text', { prefill: 'customer.email', locked: true }]]);
  assert.match(ui.text(), /Signed-in customers get their account value\. Guests type their own\./);
  const hidden = mount([['hidden', { prefill: 'customer.email' }]]);
  assert.equal(hidden.q('locked'), null);
  assert.match(hidden.text(), /Signed-in customers get their account value\. Guests get the Default value\./);
});

test('it hides the value source of a hidden field while prefill is set', () => {
  const tab = (ui) => ui.container.querySelector('[data-tab="settings"]');
  const open = (ui) => { tab(ui).click(); return ui; };
  assert.ok(open(mount([['hidden', {}]])).q('setting-source'));
  const filled = mount([['hidden', { prefill: 'customer.email', settings: { source: 'query' } }]]);
  assert.equal(filled.q('setting-source'), null);
  assert.equal(filled.q('setting-query_param'), null);
});

test('it titles the matrix options tab columns and hides option descriptions where they are not shown', () => {
  const matrix = mount([['matrix', { options: [{ label: 'Good', value: 'g' }] }]]);
  assert.equal(matrix.container.querySelector('[data-tab="options"]').textContent, 'Columns');
  assert.equal(matrix.q('option-0-description'), null);
  assert.ok(mount([['cards', { options: [{ label: 'A', value: 'a' }] }]]).q('option-0-description'));
});

test('it offers only the formats that fit the type', () => {
  const offered = (code) => Array.from(mount([[code, {}]]).q('add-rule').options).map((o) => o.value).filter((v) => v.startsWith('preset:'));
  assert.deepEqual(offered('text'), ['preset:uk_postcode', 'preset:uk_phone', 'preset:saved:crm']);
  assert.deepEqual(offered('email'), ['preset:saved:crm']);
  assert.deepEqual(offered('tel'), ['preset:uk_phone']);
  assert.deepEqual(offered('acme'), ['preset:uk_postcode', 'preset:uk_phone', 'preset:saved:crm']);
});

test('it does not suggest postcodes in the empty rules note', () => {
  const note = mount([['email', {}]]).container.querySelector('[data-ffb-hint="no-rules"]');
  assert.doesNotMatch(note.textContent, /postcode|phone/);
});

test('it names the selection in the inspector header', () => {
  const header = (ui) => ui.container.querySelector('[data-ffb-inspector-title]').textContent;
  assert.equal(header(mount([['text', { label: 'Full name' }]])), 'Text field: Full name');
  assert.equal(header(mount([['text', { label: '' }]])), 'Text field');
  const ui = mount([['text', { label: 'A' }]]);
  assert.equal(ui.container.firstElementChild.getAttribute('data-ffb-inspector-title'), '');
});

test('it names content, sections and pages in the header', () => {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [
      { id: 'c_aaaa11', kind: 'content', type: 'notice', title: '', text: 'Hi', style: 'info', width: 'full', condition: null },
      { id: 's_aaaa11', kind: 'section', title: 'S', description: '', condition: null, items: [] },
    ] }] }, settings: {},
  });
  const header = (id) => render(model.select(state, id)).container.querySelector('[data-ffb-inspector-title]').textContent;
  assert.equal(header('c_aaaa11'), 'Notice');
  assert.equal(header('s_aaaa11'), 'Section');
  assert.equal(header(PAGE), 'Page');
  const hints = render(model.select(state, 'c_aaaa11')).text();
  assert.match(hints, /Plain text\./);
});

test('it hints the syntax of rich text and paragraphs', () => {
  const state = model.select(model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [
      { id: 'c_bbbb11', kind: 'content', type: 'rich_text', text: '', width: 'full', condition: null },
      { id: 'c_cccc11', kind: 'content', type: 'paragraph', text: '', width: 'full', condition: null },
    ] }] }, settings: {},
  }), 'c_bbbb11');
  assert.match(render(state).text(), /\*\*bold\*\*/);
  assert.match(render(model.select(state, 'c_cccc11')).text(), /Line breaks are kept\./);
});

test('it explains the file limits with the store values', () => {
  const ui = mount([['file', {}]]);
  ui.container.querySelector('[data-tab="settings"]').click();
  assert.match(ui.text(), /Leave empty for the store's types \(pdf, jpg\)\. 0 = store limit \(8 MB\)\./);
});

test('it picks the newsletter email field from the form email fields', () => {
  const ui = mount([['email', { name: 'work', label: 'Work email' }], ['newsletter', { settings: { email_field: 'work' } }]]);
  ui.container.querySelector('[data-tab="settings"]').click();
  const select = ui.q('setting-email_field');
  assert.equal(select.tagName, 'SELECT');
  assert.deepEqual(Array.from(select.options).map((o) => o.value), ['', 'work']);
  assert.equal(select.options[0].textContent, 'None');
  assert.equal(select.value, 'work');
  assert.match(ui.text(), /Empty = the form's customer email field\./);
});

test('it uses a date input for date settings and a select for an int with fixed values', () => {
  const range = mount([['date_range', {}]]);
  range.container.querySelector('[data-tab="settings"]').click();
  assert.equal(range.q('setting-min_date').type, 'date');
  const scale = mount([['scale', {}]]);
  scale.container.querySelector('[data-tab="settings"]').click();
  const min = scale.q('setting-min');
  assert.equal(min.tagName, 'SELECT');
  assert.equal(min.value, '1');
  min.value = '0';
  scale.fire(min, 'change');
  assert.deepEqual(scale.calls[0][2], { settings: { min: 0 } });
});

test('it explains the personal data flag', () => {
  assert.match(mount([['text', {}]]).text(), /Blanked when submissions are anonymised\./);
});

function mountWithSettings(fields, settings) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items: [] }] }, settings,
  });
  const made = fields.map(([code, patch]) => {
    const f = Object.assign(model.newField(code, config, state), patch);
    state = model.addItem(state, f, { pageId: PAGE, sectionId: null, index: 99 });
    return f;
  });
  return render(model.select(state, made[made.length - 1].id));
}

test('it names the form email field in the empty newsletter email option', () => {
  const ui = mountWithSettings([['email', { name: 'mail', label: 'Your email' }], ['newsletter', {}]], { email_field: 'mail' });
  assert.equal(ui.q('setting-email_field').options[0].textContent, "Form's email field (Your email)");
});

test('it leaves out a settings tab with no visible settings', () => {
  const ui = mount([['hidden', { prefill: 'customer.email' }]]);
  assert.equal(ui.container.querySelector('[data-tab="settings"]'), null);
  assert.ok(mount([['hidden', {}]]).container.querySelector('[data-tab="settings"]'));
});

test('it does not point to Required when the type cannot be required', () => {
  const note = (code) => mount([[code, {}]]).container.querySelector('[data-ffb-hint="no-rules"]').textContent;
  assert.doesNotMatch(note('hidden'), /Required/);
  assert.match(note('checkbox'), /Required/);
});

test('it shows only the type in the header when the label repeats it', () => {
  const header = mount([['email', { label: 'Email' }]]).container.querySelector('[data-ffb-inspector-title]').textContent;
  assert.equal(header, 'Email');
});

test('it labels the add button after the options tab', () => {
  const add = mount([['matrix', { options: [] }]]).q('option-add');
  assert.equal(add.textContent, '+ Add column');
  assert.equal(mount([['select', { options: [] }]]).q('option-add').textContent, '+ Add option');
});

test('it picks a country default from the country list', () => {
  const ui = mount([['country', { default: 'GB' }]]);
  const input = ui.q('default');
  assert.equal(input.tagName, 'SELECT');
  assert.deepEqual(Array.from(input.options).map((o) => o.value), ['', 'GB', 'FR']);
  assert.equal(input.options[1].textContent, 'Britain');
  assert.equal(input.value, 'GB');
});

test('it warns under the email field setting when guests cannot be subscribed', () => {
  const note = (ui) => ui.container.querySelector('[data-ffb-hint="email-field-guests"]');
  const none = mountWithSettings([['email', { name: 'mail', label: 'Mail' }], ['newsletter', {}]], {});
  assert.equal(note(none).textContent, "Guests won't be subscribed: choose an email field.");
  const control = none.q('setting-email_field').closest('.ffb-admin-control');
  assert.ok(control.parentNode.contains(note(none)));
  assert.equal(note(mountWithSettings([['email', { name: 'mail' }], ['newsletter', {}]], { email_field: 'mail' })), null);
  assert.equal(note(mountWithSettings([['email', { name: 'mail' }], ['newsletter', { settings: { email_field: 'mail' } }]], {})), null);  const fallback = (ui) => Array.from(ui.container.querySelectorAll('.ffb-admin-hint'))
    .filter((h) => h.textContent === "Empty = the form's customer email field.").length;
  assert.equal(fallback(none), 0);
  assert.equal(fallback(mountWithSettings([['email', { name: 'mail' }], ['newsletter', {}]], { email_field: 'mail' })), 1);
});
