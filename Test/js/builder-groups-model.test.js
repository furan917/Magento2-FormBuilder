'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const m = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));

const PAGE = 'p_aaaa11';
const rule = (field, value) => ({ field, operator: 'equals', value });
const cond = (...rules) => ({ actions: [{ action: 'show' }], match: 'all', rules });

function field(id, name, extra) {
  return Object.assign({
    id, kind: 'field', type: 'text', name, label: name, placeholder: '', help: '', default: '', width: 'full',
    required: false, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
  }, extra || {});
}

function stateWith(items, translations) {
  const form = {
    id: 1, identifier: 'x', title: 'X', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: PAGE, title: 'One', condition: null, items }] },
    settings: {},
    translations: translations || {},
  };
  return m.createState(form);
}

const section = (id, items) => ({ id, kind: 'section', title: 'Address', description: '', condition: null, items });
const names = (state) => m.allFields(state).map((f) => f.name);

test('it saves the selected section as a group', () => {
  const s = stateWith([
    field('f_before1', 'before'),
    section('s_addr001', [field('f_street01', 'street'), field('f_city0001', 'city')]),
  ]);
  const group = m.extractGroup(s, ['s_addr001']);
  assert.equal(group.fields.length, 1);
  assert.equal(group.fields[0].kind, 'section');
  assert.deepEqual(group.fields[0].items.map((i) => i.name), ['street', 'city']);
  assert.deepEqual(group.removed, []);
  assert.equal(s.form.schema.pages[0].items.length, 2, 'the form is untouched');
});

test('it drops references to fields outside the selection when saving a group', () => {
  const s = stateWith([
    field('f_before1', 'before'),
    field('f_other001', 'other'),
    section('s_addr001', [
      field('f_street01', 'street'),
      field('f_city0001', 'city', {
        condition: cond(rule('street', 'a'), rule('before', 'b'), { match: 'any', rules: [rule('other', 'c'), { field: 'street', operator: 'equals', value_field: 'before' }] }),
        rules: [{ rule: 'matches', value: 'before', message: '' }, { rule: 'matches', value: 'street', message: '' }],
        settings: { email_field: 'before' },
      }),
    ]),
  ]);
  const group = m.extractGroup(s, ['s_addr001']);
  const city = group.fields[0].items[1];
  assert.deepEqual(city.condition.rules, [rule('street', 'a')]);
  assert.deepEqual(city.rules.map((r) => r.value), ['street']);
  assert.equal(city.settings.email_field, '');
  assert.ok(group.removed.length >= 4);
  assert.equal(s.form.schema.pages[0].items[2].items[1].condition.rules.length, 3, 'the form keeps its rules');
});

test('it inserts a group as a copy of its fields', () => {
  const group = { fields: [section('s_addr001', [field('f_street01', 'street')]), field('f_note0001', 'note')], translations: {} };
  const s = stateWith([field('f_before1', 'before')]);
  const next = m.insertGroup(s, group, { pageId: PAGE, sectionId: null, index: 1 });
  const items = next.form.schema.pages[0].items;
  assert.equal(items.length, 3);
  assert.equal(items[1].kind, 'section');
  assert.notEqual(items[1].id, 's_addr001');
  assert.notEqual(items[1].items[0].id, 'f_street01');
  assert.notEqual(items[2].id, 'f_note0001');
  assert.match(items[1].items[0].id, /^f_[a-z0-9]{8}$/);
  assert.equal(items[1].items[0].name, 'street');
  assert.equal(next.selectedId, items[2].id);
  assert.equal(next.dirty, true);
  assert.equal(group.fields[0].id, 's_addr001', 'the stored group is not changed');
  assert.equal(s.form.schema.pages[0].items.length, 1);
});

test('it refuses to insert a group holding a section inside a section', () => {
  const group = { fields: [section('s_addr001', [])], translations: {} };
  const s = stateWith([section('s_host001', [])]);
  assert.equal(m.insertGroup(s, group, { pageId: PAGE, sectionId: 's_host001', index: 0 }), s);
});

test('it renames fields whose names already exist in the form', () => {
  const group = { fields: [field('f_first001', 'email'), field('f_second01', 'email_2'), field('f_third001', 'phone')], translations: {} };
  const s = stateWith([field('f_before1', 'email'), field('f_before2', 'phone_2')]);
  const next = m.insertGroup(s, group, { pageId: PAGE, sectionId: null, index: 2 });
  assert.deepEqual(names(next), ['email', 'phone_2', 'email_2', 'email_2_2', 'phone']);
});

test('it remaps conditions inside an inserted group to the new names', () => {
  const group = {
    fields: [
      field('f_first001', 'email'),
      field('f_second01', 'email_2'),
      section('s_sec00001', [
        field('f_third001', 'phone', {
          condition: cond(rule('email', 'a'), { match: 'any', rules: [rule('email_2', 'b'), { field: 'email', operator: 'equals', value_field: 'email_2' }] }),
          rules: [{ rule: 'matches', value: 'email_2', message: '' }],
        }),
        field('f_news0001', 'news', { type: 'newsletter', settings: { email_field: 'email' } }),
      ]),
    ],
    translations: {},
  };
  const s = stateWith([field('f_before1', 'email')]);
  const next = m.insertGroup(s, group, { pageId: PAGE, sectionId: null, index: 1 });
  assert.deepEqual(names(next), ['email', 'email_2', 'email_2_2', 'phone', 'news']);
  const phone = next.form.schema.pages[0].items[3].items[0];
  assert.equal(phone.condition.rules[0].field, 'email_2');
  assert.equal(phone.condition.rules[1].rules[0].field, 'email_2_2');
  assert.equal(phone.condition.rules[1].rules[1].field, 'email_2');
  assert.equal(phone.condition.rules[1].rules[1].value_field, 'email_2_2');
  assert.equal(phone.rules[0].value, 'email_2_2');
  assert.equal(next.form.schema.pages[0].items[3].items[1].settings.email_field, 'email_2');
  assert.equal(next.form.schema.pages[0].items[0].name, 'email', 'existing fields keep their names and rules');
});

test('it leaves a condition in the form alone when a group is inserted', () => {
  const s = stateWith([field('f_before1', 'email'), field('f_after001', 'x', { condition: cond(rule('email', 'a')) })]);
  const next = m.insertGroup(s, { fields: [field('f_first001', 'email')], translations: {} }, { pageId: PAGE, sectionId: null, index: 1 });
  assert.equal(next.form.schema.pages[0].items[2].condition.rules[0].field, 'email');
});

test('it copies the group\'s translations onto the inserted fields', () => {
  const entry = (t) => ({ t, s: 'x' });
  const group = {
    fields: [section('s_addr001', [field('f_street01', 'street')])],
    translations: { 1: { items: { s_addr001: { title: entry('Adresse') }, f_street01: { label: entry('Strasse') }, f_gone0001: { label: entry('x') } } } },
  };
  const s = stateWith([], { 2: { items: { f_keep0001: { label: entry('Keep') } } } });
  const next = m.insertGroup(s, group, { pageId: PAGE, sectionId: null, index: 0 });
  const section_ = next.form.schema.pages[0].items[0];
  const overlay = next.form.translations;
  assert.equal(overlay[1].items[section_.id].title.t, 'Adresse');
  assert.equal(overlay[1].items[section_.items[0].id].label.t, 'Strasse');
  assert.equal(Object.keys(overlay[1].items).length, 2, 'entries for ids outside the group are not copied');
  assert.equal(overlay[2].items.f_keep0001.label.t, 'Keep');
});

test('it carries only the selected items\' translations into a saved group', () => {
  const entry = (t) => ({ t, s: 'x' });
  const s = stateWith(
    [field('f_before1', 'before'), section('s_addr001', [field('f_street01', 'street')])],
    { 1: { items: { f_before1: { label: entry('A') }, f_street01: { label: entry('B') } }, form: { submit_label: entry('S') } } }
  );
  const group = m.extractGroup(s, ['s_addr001']);
  assert.deepEqual(group.translations, { 1: { items: { f_street01: { label: entry('B') } } } });
});

test('it marks every inserted item with the group it came from', () => {
  const group = { id: 4, fields: [section('s_addr001', [field('f_street01', 'street')]), field('f_note0001', 'note')], translations: {} };
  const next = m.insertGroup(stateWith([field('f_before1', 'before')]), group, { pageId: PAGE, sectionId: null, index: 1 });
  const items = next.form.schema.pages[0].items;
  assert.equal(items[0].source_group, undefined);
  assert.equal(items[1].source_group, 4);
  assert.equal(items[1].items[0].source_group, 4);
  assert.equal(items[2].source_group, 4);
  assert.equal(group.fields[0].source_group, undefined, 'the stored group is not changed');
});

test('it marks nothing when the group has no usable id', () => {
  for (const id of [undefined, 0, -1, '4', 1.5]) {
    const next = m.insertGroup(stateWith([]), { id, fields: [field('f_note0001', 'note')], translations: {} }, { pageId: PAGE, sectionId: null, index: 0 });
    assert.equal('source_group' in next.form.schema.pages[0].items[0], false, String(id));
  }
});

test('it leaves group markers out of a saved group', () => {
  const s = stateWith([section('s_addr001', [field('f_street01', 'street', { source_group: 4 })])]);
  s.form.schema.pages[0].items[0].source_group = 4;
  const group = m.extractGroup(s, ['s_addr001']);
  assert.equal('source_group' in group.fields[0], false);
  assert.equal('source_group' in group.fields[0].items[0], false);
  assert.equal(s.form.schema.pages[0].items[0].source_group, 4, 'the form keeps its markers');
});

test('it leaves group markers out of an exported definition', () => {
  const s = stateWith([section('s_addr001', [field('f_street01', 'street', { source_group: 4 })])]);
  s.form.schema.pages[0].items[0].source_group = 4;
  const out = JSON.parse(m.exportJson(s, {}));
  assert.equal(JSON.stringify(out).includes('source_group'), false);
  assert.equal(s.form.schema.pages[0].items[0].source_group, 4);
});

test('it lets a form update its description', () => {
  const next = m.updateForm(stateWith([]), { description: 'Who to reply to' });
  assert.equal(next.form.description, 'Who to reply to');
});

test('in group mode it checks the group name instead of the identifier and title', () => {
  const groupConfig = { group: true };
  const s = m.createState({
    id: 3, identifier: '', title: '', description: '', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_group000', title: '', condition: null, items: [field('f_a0000001', 'a')] }] },
    settings: {}, translations: {},
  }, groupConfig);
  const messages = (state) => m.validate(state, groupConfig).map((e) => e.message);
  assert.deepEqual(messages(s), ['Enter a name.']);
  assert.deepEqual(messages(m.updateForm(s, { title: 'x'.repeat(121) })), ['The name must be at most 120 characters.']);
  assert.deepEqual(messages(m.updateForm(s, { title: 'Contact' })), []);
  const empty = m.removeItem(m.updateForm(s, { title: 'Contact' }), 'f_a0000001');
  assert.deepEqual(messages(empty), ['A group needs at least one field.']);
});
