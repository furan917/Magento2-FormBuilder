'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const m = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));

const config = {
  fieldTypes: [
    { code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', hasOptions: false, isFile: false, settingsSpec: {} },
    { code: 'email', emailAddress: true, personalByDefault: true, conditionSource: true, label: 'Email', hasOptions: false, isFile: false, settingsSpec: {} },
    { code: 'textarea', personalByDefault: false, conditionSource: true, label: 'Textarea', hasOptions: false, isFile: false, settingsSpec: { rows: { type: 'int', default: 5 } } },
    { code: 'select', personalByDefault: false, conditionSource: true, label: 'Select', hasOptions: true, isFile: false, settingsSpec: { empty_label: { type: 'string', default: '' } } },
    { code: 'file', personalByDefault: true, conditionSource: true, label: 'File', hasOptions: false, isFile: true, settingsSpec: {} },
    { code: 'hidden', personalByDefault: false, conditionSource: true, label: 'Hidden', hasOptions: false, isFile: false, settingsSpec: {} },
  ],
};

function baseForm() {
  return {
    id: 7, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [] }] },
    settings: { autoresponder_enabled: false, autoresponder_email_field: '' },
  };
}
const fresh = () => m.createState(baseForm(), config);
const PAGE = 'p_aaaa11';

function withField(state, type, patch, target) {
  const f = Object.assign(m.newField(type, config, state), patch || {});
  const next = m.addItem(state, f, target || { pageId: PAGE, sectionId: null, index: 99 });
  return { state: next, field: f };
}

test('it creates a state with an empty history', () => {
  const s = fresh();
  assert.equal(s.selectedId, null);
  assert.equal(s.dirty, false);
  assert.deepEqual(s.past, []);
  assert.deepEqual(s.future, []);
  assert.equal(s.form.identifier, 'contact');
});

test('it generates ids with the right prefix', () => {
  for (const p of ['p', 'f', 'c', 's']) {
    assert.match(m.uid(p), new RegExp('^' + p + '_[a-z0-9]{8}$'));
  }
  assert.notEqual(m.uid('f'), m.uid('f'));
});

test('it slugifies labels into valid unique field names', () => {
  assert.equal(m.slugify('First Name!'), 'first_name');
  assert.equal(m.slugify('Café  au lait'), 'cafe_au_lait');
  assert.equal(m.slugify('123 go'), 'field_123_go');
  assert.equal(m.slugify(''), 'field_');
  assert.match(m.slugify('x'.repeat(100)), /^[a-z][a-z0-9_]{0,63}$/);
  assert.equal(m.uniqueName('name', ['name', 'name_2']), 'name_3');
  assert.equal(m.uniqueName('name', []), 'name');
  let s = fresh();
  s = withField(s, 'text').state;
  s = withField(s, 'text').state;
  assert.deepEqual(m.allFields(s).map((f) => f.name), ['text', 'text_2']);
});

test('it adds moves and removes items without mutating the previous state', () => {
  const s0 = fresh();
  const snapshot = JSON.stringify(s0);
  const a = withField(s0, 'text');
  const b = withField(a.state, 'email');
  assert.equal(JSON.stringify(s0), snapshot);
  assert.equal(b.state.dirty, true);
  assert.equal(b.state.past.length, 2);
  const s3 = m.moveItem(b.state, b.field.id, { pageId: PAGE, sectionId: null, index: 0 });
  assert.deepEqual(s3.form.schema.pages[0].items.map((i) => i.id), [b.field.id, a.field.id]);
  assert.deepEqual(b.state.form.schema.pages[0].items.map((i) => i.id), [a.field.id, b.field.id]);
  const sec = m.newSection();
  let s4 = m.addItem(s3, sec, { pageId: PAGE, sectionId: null, index: 0 });
  s4 = m.moveItem(s4, a.field.id, { pageId: PAGE, sectionId: sec.id, index: 0 });
  assert.equal(s4.form.schema.pages[0].items[0].items[0].id, a.field.id);
  const s5 = m.removeItem(s4, a.field.id);
  assert.equal(s5.form.schema.pages[0].items[0].items.length, 0);
  assert.equal(s4.form.schema.pages[0].items[0].items.length, 1);
});

test('it refuses to place a section inside a section', () => {
  let s = fresh();
  const s1 = m.newSection();
  const s2 = m.newSection();
  s = m.addItem(s, s1, { pageId: PAGE, sectionId: null, index: 0 });
  const added = m.addItem(s, s2, { pageId: PAGE, sectionId: s1.id, index: 0 });
  assert.equal(added, s);
  s = m.addItem(s, s2, { pageId: PAGE, sectionId: null, index: 1 });
  const moved = m.moveItem(s, s2.id, { pageId: PAGE, sectionId: s1.id, index: 0 });
  assert.equal(moved, s);
});

test('it lists only fields before an item', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const sec = m.newSection();
  s = m.addItem(s, sec, { pageId: PAGE, sectionId: null, index: 99 });
  const b = withField(s, 'email', {}, { pageId: PAGE, sectionId: sec.id, index: 0 }); s = b.state;
  const c = withField(s, 'text'); s = c.state;
  assert.deepEqual(m.allFields(s).map((f) => f.id), [a.field.id, b.field.id, c.field.id]);
  assert.deepEqual(m.fieldsBefore(s, a.field.id), []);
  assert.deepEqual(m.fieldsBefore(s, b.field.id).map((f) => f.id), [a.field.id]);
  assert.deepEqual(m.fieldsBefore(s, c.field.id).map((f) => f.id), [a.field.id, b.field.id]);
  assert.deepEqual(m.fieldsBefore(s, sec.id).map((f) => f.id), [a.field.id]);
});

test('it removes condition references to a deleted field', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const cond = { action: 'show', match: 'all', rules: [{ field: a.field.name, operator: 'equals', value: 'x' }] };
  const b = withField(s, 'text', { condition: cond, rules: [{ rule: 'matches', value: a.field.name, message: '' }] }); s = b.state;
  s = m.removeItem(s, a.field.id);
  const kept = m.allFields(s)[0];
  assert.equal(kept.condition, null);
  assert.deepEqual(kept.rules, []);
  assert.ok(s.lastRemovedRefs.length >= 2);
  assert.equal(s.lastRemovedRefs[0].field, a.field.name);
});

test('it updates references when a field is renamed', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const cond = { action: 'show', match: 'all', rules: [{ field: a.field.name, operator: 'equals', value: 'x' }] };
  const b = withField(s, 'text', { condition: cond, rules: [{ rule: 'matches', value: a.field.name, message: '' }] }); s = b.state;
  s = m.updateItem(s, a.field.id, { name: 'renamed' });
  const f = m.allFields(s)[1];
  assert.equal(f.condition.rules[0].field, 'renamed');
  assert.equal(f.rules[0].value, 'renamed');
  assert.equal(m.allFields(s)[0].name, 'renamed');
});

test('it duplicates an item with fresh ids and names', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const z = withField(s, 'email'); s = z.state;
  s = m.duplicateItem(s, a.field.id);
  const items = s.form.schema.pages[0].items;
  assert.equal(items.length, 3);
  assert.equal(items[1].name, 'text_2');
  assert.notEqual(items[1].id, a.field.id);
  assert.match(items[1].id, /^f_[a-z0-9]{8}$/);
  const sec = m.newSection();
  s = m.addItem(s, sec, { pageId: PAGE, sectionId: null, index: 99 });
  s = withField(s, 'text', {}, { pageId: PAGE, sectionId: sec.id, index: 0 }).state;
  s = m.duplicateItem(s, sec.id);
  const names = m.allFields(s).map((f) => f.name);
  assert.equal(new Set(names).size, names.length);
  const secs = s.form.schema.pages[0].items.filter((i) => i.kind === 'section');
  assert.equal(secs.length, 2);
  assert.notEqual(secs[0].items[0].id, secs[1].items[0].id);
});

test('it never removes the last page', () => {
  let s = fresh();
  assert.equal(m.removePage(s, PAGE), s);
  s = m.addPage(s, 'Two');
  assert.equal(s.form.schema.pages.length, 2);
  const second = s.form.schema.pages[1];
  assert.match(second.id, /^p_/);
  s = m.updatePage(s, second.id, { title: 'Renamed' });
  assert.equal(s.form.schema.pages[1].title, 'Renamed');
  s = m.movePage(s, second.id, 0);
  assert.equal(s.form.schema.pages[0].id, second.id);
  s = m.removePage(s, second.id);
  assert.equal(s.form.schema.pages.length, 1);
  assert.equal(m.removePage(s, PAGE), s);
});

test('it undoes and redoes changes', () => {
  let s = fresh();
  s = withField(s, 'text').state;
  s = m.updateForm(s, { title: 'New' });
  s = m.updateSettings(s, { submit_label: 'Go' });
  assert.equal(m.select(s, 'x').past.length, s.past.length);
  const u = m.undo(s);
  assert.equal(u.form.settings.submit_label, undefined);
  assert.equal(u.future.length, 1);
  assert.equal(m.redo(u).form.settings.submit_label, 'Go');
  assert.equal(m.undo(m.undo(m.undo(m.undo(s)))).form.schema.pages[0].items.length, 0);
  assert.equal(m.undo(m.undo(m.undo(m.undo(s)))).past.length, 0);
  const again = m.undo(m.createState(baseForm()));
  assert.deepEqual(again.past, []);
  let h = fresh();
  for (let i = 0; i < 60; i++) h = m.updateForm(h, { title: 't' + i });
  assert.equal(h.past.length, 50);
  assert.equal(m.redo(h), h);
  assert.equal(m.redo(m.undo(h)).form.title, 't59');
});

test('it validates names labels options regex and condition order', () => {
  let s = fresh();
  const a = withField(s, 'text', { label: '' }); s = a.state;
  const sel = withField(s, 'select', { options: [] }); s = sel.state;
  const rx = withField(s, 'text', { rules: [{ rule: 'pattern', value: '(', message: '' }] }); s = rx.state;
  const cond = { action: 'show', match: 'all', rules: [{ field: 'later', operator: 'equals', value: 'x' }] };
  const early = withField(s, 'text', { name: 'early', condition: cond }); s = early.state;
  const later = withField(s, 'text', { name: 'later' }); s = later.state;
  const bad = withField(s, 'text', { name: 'Bad Name' }); s = bad.state;
  const dup = withField(s, 'text', { name: 'later' }); s = dup.state;
  const res = withField(s, 'text', { name: 'form_id' }); s = res.state;
  s = m.updateForm(s, { identifier: 'Bad Id', title: '' });
  const msgs = m.validate(s, config);
  const has = (itemId, re) => msgs.some((x) => x.itemId === itemId && re.test(x.message));
  assert.ok(has(a.field.id, /label/i));
  assert.ok(has(sel.field.id, /option/i));
  assert.ok(has(rx.field.id, /pattern|regular/i));
  assert.ok(has(early.field.id, /condition/i));
  assert.ok(has(bad.field.id, /name/i));
  assert.ok(has(dup.field.id, /duplicate|unique/i));
  assert.ok(has(res.field.id, /reserved/i));
  assert.ok(has(null, /identifier/i));
  assert.ok(has(null, /title/i));
  assert.deepEqual(m.validate(fresh(), config), []);
});

test('it serializes and imports json definitions', () => {
  let s = fresh();
  s = withField(s, 'text').state;
  const out = m.serialize(s);
  assert.deepEqual(Object.keys(out).sort(), ['form_id', 'identifier', 'is_active', 'schema', 'settings', 'store_ids', 'title', 'translations']);
  assert.equal(out.form_id, 7);
  out.schema.pages = [];
  assert.equal(s.form.schema.pages.length, 1);
  const text = m.exportJson(s);
  assert.ok(text.includes('\n'));
  const parsed = JSON.parse(text);
  assert.equal(parsed.identifier, 'contact');
  assert.equal('form_id' in parsed, false);
  const imp = m.importJson(fresh(), text);
  assert.equal(imp.form.schema.pages[0].items.length, 1);
  assert.equal(imp.dirty, true);
  assert.equal(imp.past.length, 1);
  assert.equal(m.undo(imp).form.schema.pages[0].items.length, 0);
});

test('it throws a readable error for invalid import json', () => {
  const s = fresh();
  assert.throws(() => m.importJson(s, '{nope'), /not valid JSON/i);
  assert.throws(() => m.importJson(s, '[]'), /object/i);
  assert.throws(() => m.importJson(s, JSON.stringify({ identifier: 'a', title: 'b' })), /schema/i);
  assert.throws(() => m.importJson(s, JSON.stringify({ identifier: 'a', title: 'b', schema: { pages: [] } })), /page/i);
  assert.throws(() => m.importJson(s, JSON.stringify({ title: 'b', schema: { pages: [{ items: [] }] } })), /identifier/i);
});

test('it never generates reserved field names', () => {
  let s = fresh();
  assert.equal(m.uniqueName('form_id', []), 'form_id_2');
  assert.equal(m.uniqueName('form_key', []), 'form_key_2');
  assert.equal(m.uniqueName('ffb_x', []), 'field_x');
  const cfg = { fieldTypes: [{ code: 'text', label: 'Form ID', settingsSpec: {} }] };
  const f = m.newField('text', cfg, s);
  assert.equal(f.name, 'form_id_2');
  s = m.addItem(s, f, { pageId: PAGE, sectionId: null, index: 0 });
  s = m.updateItem(s, f.id, { name: 'form_key' });
  assert.equal(m.allFields(s)[0].name, 'form_key_2');
  const dup = m.duplicateItem(s, f.id);
  assert.ok(m.allFields(dup).every((x) => !/^(form_key|form_id)$|^ffb_/.test(x.name)));
});

test('it keeps the autoresponder email field setting in sync with renames and removals', () => {
  let s = fresh();
  const e = withField(s, 'email'); s = e.state;
  s = m.updateSettings(s, { autoresponder_enabled: true, autoresponder_email_field: e.field.name });
  const renamed = m.updateItem(s, e.field.id, { name: 'contact_email' });
  assert.equal(renamed.form.settings.autoresponder_email_field, 'contact_email');
  assert.equal(renamed.form.settings.autoresponder_enabled, true);
  const typed = m.updateItem(s, e.field.id, { type: 'text' });
  assert.equal(typed.form.settings.autoresponder_email_field, '');
  assert.equal(typed.form.settings.autoresponder_enabled, false);
  assert.ok(typed.lastRemovedRefs.length > 0);
  const removed = m.removeItem(s, e.field.id);
  assert.equal(removed.form.settings.autoresponder_email_field, '');
  assert.equal(removed.form.settings.autoresponder_enabled, false);
  assert.equal(removed.lastRemovedRefs.some((r) => r.setting === 'autoresponder_email_field'), true);
});

test('it renames or drops routing rules when their field is renamed or deleted in the builder', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const b = withField(s, 'select'); s = b.state;
  const rule = (field, extra) => Object.assign({ field, operator: 'equals', value: 'x' }, extra);
  s = m.updateSettings(s, {
    notify_routes: [
      { condition: { match: 'all', rules: [rule(a.field.name)] }, emails: ['x@x.com'], mode: 'add' },
      { condition: { match: 'any', rules: [rule(b.field.name), { match: 'all', rules: [rule(b.field.name, { value: undefined, value_field: a.field.name })] }] },
        emails: ['y@x.com'], mode: 'replace' },
    ],
    notify_when: { match: 'all', rules: [rule(a.field.name)] },
    autoresponder_when: { match: 'all', rules: [rule(b.field.name)] },
  });
  const renamed = m.updateItem(s, a.field.id, { name: 'renamed' });
  const rs = renamed.form.settings;
  assert.equal(rs.notify_routes[0].condition.rules[0].field, 'renamed');
  assert.equal(rs.notify_routes[1].condition.rules[1].rules[0].value_field, 'renamed');
  assert.equal(rs.notify_when.rules[0].field, 'renamed');
  assert.equal(rs.autoresponder_when.rules[0].field, b.field.name);

  const removed = m.removeItem(s, a.field.id);
  const gone = removed.form.settings;
  assert.equal(gone.notify_routes.length, 1);
  assert.deepEqual(gone.notify_routes[0].condition.rules.map((r) => r.field), [b.field.name]);
  assert.equal(gone.notify_when, null);
  assert.equal(gone.autoresponder_when.rules.length, 1);
  const settingRefs = removed.lastRemovedRefs.filter((r) => r.kind === 'setting').map((r) => r.setting).sort();
  assert.deepEqual(settingRefs, ['notify_routes', 'notify_routes', 'notify_when']);
  assert.equal(s.form.settings.notify_routes.length, 2);
  assert.equal(s.form.settings.notify_when.rules.length, 1);
});

test('it keeps a third party email address type as the email setting and clears it when the type changes', () => {
  const extended = { fieldTypes: config.fieldTypes.concat([
    { code: 'work_email', emailAddress: true, personalByDefault: true, conditionSource: true, label: 'Work', hasOptions: false, isFile: false, settingsSpec: {} },
  ]) };
  let s = m.createState(baseForm(), extended);
  const w = Object.assign(m.newField('work_email', extended, s), { name: 'work' });
  s = m.addItem(s, w, { pageId: PAGE, sectionId: null, index: 99 });
  s = m.updateSettings(s, { email_field: 'work', autoresponder_email_field: 'work' });

  const renamed = m.updateItem(s, w.id, { label: 'Work address' });
  assert.equal(renamed.form.settings.email_field, 'work');
  assert.equal(renamed.form.settings.autoresponder_email_field, 'work');

  const typed = m.updateItem(s, w.id, { type: 'text' });
  assert.equal(typed.form.settings.email_field, '');
});

test('it renames and clears the designated email field with its field', () => {
  let s = fresh();
  const e = withField(s, 'email'); s = e.state;
  s = m.updateSettings(s, { email_field: e.field.name, guest_dedupe: true });
  const renamed = m.updateItem(s, e.field.id, { name: 'contact_email' });
  assert.equal(renamed.form.settings.email_field, 'contact_email');
  assert.equal(renamed.form.settings.guest_dedupe, true);
  const removed = m.removeItem(s, e.field.id);
  assert.equal(removed.form.settings.email_field, '');
  assert.equal(removed.form.settings.guest_dedupe, false);
  assert.equal(removed.lastRemovedRefs.some((r) => r.setting === 'email_field'), true);
  const typed = m.updateItem(s, e.field.id, { type: 'text' });
  assert.equal(typed.form.settings.email_field, '');
  assert.equal(typed.form.settings.guest_dedupe, false);
  assert.equal(s.form.settings.email_field, e.field.name);
});

test('it keeps guest dedupe on while the autoresponder email field remains', () => {
  let s = fresh();
  const a = withField(s, 'email'); s = a.state;
  const b = withField(s, 'email'); s = b.state;
  s = m.updateSettings(s, { email_field: a.field.name, autoresponder_email_field: b.field.name, guest_dedupe: true });
  const removed = m.removeItem(s, a.field.id);
  assert.equal(removed.form.settings.email_field, '');
  assert.equal(removed.form.settings.guest_dedupe, true);
});

test('it keeps the current form id when importing', () => {
  const s = fresh();
  const text = JSON.stringify({ identifier: 'other', title: 'Other', is_active: false, store_codes: ['default'], schema: { version: 1, pages: [{ id: 'p_bbbb22', title: '', items: [] }] }, settings: {}, form_id: 99 });
  const imp = m.importJson(s, text, { default: 1 });
  assert.equal(imp.form.id, 7);
  assert.equal(imp.form.identifier, 'other');
  assert.equal(imp.form.is_active, false);
  assert.deepEqual(imp.form.store_ids, [1]);
  assert.equal(m.serialize(imp).form_id, 7);
});

test('it is not dirty after undoing back to the loaded form', () => {
  const a = withField(fresh(), 'text');
  const back = m.undo(a.state);
  assert.equal(back.dirty, false);
  assert.equal(m.redo(back).dirty, true);
});

test('translation edits are undoable, mark the form dirty and travel by store code', () => {
  let s = m.createState(Object.assign(baseForm(), { translations: [] }));
  assert.deepEqual(s.form.translations, {});
  s = m.updateTranslation(s, '2', ['form', 'submit_label'], { t: 'Envoyer', s: 'Submit' });
  assert.equal(s.dirty, true);
  assert.deepEqual(m.serialize(s).translations, { 2: { form: { submit_label: { t: 'Envoyer', s: 'Submit' } } } });
  assert.deepEqual(m.undo(s).form.translations, {});
  assert.equal(m.undo(s).dirty, false);

  const text = m.exportJson(s, { 2: 'fr' });
  assert.deepEqual(JSON.parse(text).translations, { fr: { form: { submit_label: { t: 'Envoyer', s: 'Submit' } } } });
  const imported = m.importJson(m.createState(Object.assign(baseForm(), { translations: {} })), text, { fr: 5 });
  assert.deepEqual(Object.keys(imported.form.translations), ['5']);
  const unknown = m.importJson(m.createState(Object.assign(baseForm(), { translations: {} })), text, { de: 3 });
  assert.deepEqual(unknown.form.translations, {});
  assert.equal('translations' in JSON.parse(m.exportJson(s, {})), false);

  s = m.updateTranslation(s, '2', ['form', 'submit_label'], null);
  assert.deepEqual(s.form.translations, {});
});

test('it does not flag a field of an unavailable type as invalid', () => {
  let s = fresh();
  const gone = withField(s, 'text', { name: 'Bad Name', label: '' });
  s = m.updateItem(gone.state, gone.field.id, { type: 'gone' });
  const msgs = m.validate(s, config);
  assert.deepEqual(msgs.filter((x) => x.itemId === gone.field.id), []);
});

const newsletterConfig = {
  fieldTypes: config.fieldTypes.concat([{
    code: 'newsletter', label: 'Newsletter', hasOptions: false, isFile: false,
    settingsSpec: { email_field: { type: 'string', default: '' } },
    defaults: { label: 'Sign me up for the newsletter', draft: false },
  }]),
};

test('it starts a new newsletter field with the type default label and no draft saving', () => {
  const f = m.newField('newsletter', newsletterConfig, fresh());
  assert.equal(f.label, 'Sign me up for the newsletter');
  assert.equal(f.draft, false);
  assert.equal(f.required, false);
  assert.equal(f.settings.email_field, '');
  assert.equal(m.newField('text', newsletterConfig, fresh()).label, 'Text');
});

test('it follows a renamed email field in the newsletter email field setting', () => {
  let { state } = withField(fresh(), 'email', { name: 'mail' });
  const news = Object.assign(m.newField('newsletter', newsletterConfig, state), { settings: { email_field: 'mail' } });
  state = m.addItem(state, news, { pageId: PAGE, sectionId: null, index: 99 });
  const mail = m.allFields(state).find((f) => f.name === 'mail');

  state = m.updateItem(state, mail.id, { name: 'contact_mail' });

  assert.equal(m.allFields(state).find((f) => f.id === news.id).settings.email_field, 'contact_mail');
});

test('it clears the newsletter email field setting when that field is removed and reports it', () => {
  let { state } = withField(fresh(), 'email', { name: 'mail' });
  const news = Object.assign(m.newField('newsletter', newsletterConfig, state), { settings: { email_field: 'mail' } });
  state = m.addItem(state, news, { pageId: PAGE, sectionId: null, index: 99 });
  const mail = m.allFields(state).find((f) => f.name === 'mail');

  state = m.removeItem(state, mail.id);

  assert.equal(m.allFields(state).find((f) => f.id === news.id).settings.email_field, '');
  assert.equal(state.lastRemovedRefs.length, 1);
});

test('it flags a routing rule without a condition', () => {
  let s = fresh();
  const a = withField(s, 'text'); s = a.state;
  const ok = { condition: { match: 'all', rules: [{ field: a.field.name, operator: 'equals', value: 'x' }] }, emails: [], mode: 'add' };
  s = m.updateSettings(s, { notify_routes: [ok, { condition: null, emails: [], mode: 'add' }] });
  const msgs = m.validate(s, config).filter((x) => /routing rule/i.test(x.message));
  assert.equal(msgs.length, 1);
});

test('it accepts and rejects the same identifiers in php and the builder', () => {
  const fixture = require(path.resolve(__dirname, '../fixtures/identifiers.json'));
  const errorsFor = (identifier, title) => {
    const state = m.createState(Object.assign(baseForm(), { identifier, title }));
    return m.validate(state, config).filter((e) => e.itemId === null);
  };
  fixture.validIdentifiers.forEach((id) => {
    assert.equal(errorsFor(id, 'Contact').some((e) => /identifier/.test(e.message)), false, id);
  });
  fixture.invalidIdentifiers.forEach((id) => {
    assert.equal(errorsFor(id, 'Contact').some((e) => /identifier/.test(e.message)), true, JSON.stringify(id));
  });
  fixture.validTitles.forEach((title) => {
    assert.equal(errorsFor('contact', title).some((e) => /title/.test(e.message)), false, title);
  });
  fixture.invalidTitles.forEach((title) => {
    assert.equal(errorsFor('contact', title).some((e) => /title/.test(e.message)), true, JSON.stringify(title));
  });
});

test('it reads the same definition file in the builder and on the cli', () => {
  const fixture = require(path.resolve(__dirname, '../fixtures/definition-export.json'));
  const idByCode = Object.assign({}, fixture.stores);
  delete idByCode.admin;
  const codeById = {};
  Object.keys(idByCode).forEach((code) => { codeById[idByCode[code]] = code; });
  const text = JSON.stringify(fixture.definition);

  const imported = m.importJson(m.createState(Object.assign(baseForm(), { translations: {} })), text, idByCode);
  assert.deepEqual(imported.form.store_ids, fixture.store_ids);
  assert.deepEqual(Object.keys(imported.form.translations), [String(fixture.stores.fr)]);

  const exported = JSON.parse(m.exportJson(imported, codeById));
  assert.deepEqual(exported.store_codes, fixture.definition.store_codes);
  assert.equal('store_ids' in exported, false);
  assert.deepEqual(exported.translations, fixture.definition.translations);

  const unknown = Object.assign({}, fixture.definition, { store_codes: fixture.unknown.store_codes });
  assert.throws(() => m.importJson(fresh(), JSON.stringify(unknown), idByCode), new RegExp(fixture.unknown.error.replace('.', '\\.')));
});
