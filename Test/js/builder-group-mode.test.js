'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const read = (f) => fs.readFileSync(path.join(jsDir, f), 'utf8');
const sources = ['builder-model.js', 'builder-preview.js', 'builder-presets.js', 'builder-inspector.js', 'builder.js'].map(read);

const field = (id, name, extra) => Object.assign({
  id, kind: 'field', type: 'text', name, label: name, placeholder: '', help: '', default: '', width: 'full', required: false,
  rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
}, extra || {});

const config = {
  group: { usage: { rows: [{ title: '<b>Contact</b>', url: '/form/edit/3' }, { title: 'Returns', url: '' }], total: 4 } },
  form: {
    id: 3, identifier: '', title: 'Contact details', description: 'Name and email', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_group000', title: '', condition: null, items: [field('f_aaaa11', 'first'), field('f_bbbb22', 'email')] }] },
    settings: {},
    translations: { 1: { items: { f_aaaa11: { label: { t: 'Vorname', s: 'first' } } } } },
  },
  fieldTypes: [{ code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false }],
  contentTypes: [{ code: 'heading', label: 'Heading' }], rules: [], operators: [], actions: [], widths: [{ value: 'full', label: 'Full' }], autocomplete: [],
  stores: [{ value: 0, label: 'All' }], translationStores: [], settingsContributors: [],
  uploads: { allowedExtensions: ['pdf'], maxMb: 5 },
  urls: { save: '/group/save', back: '/group/index', delete: '/group/delete' },
  fieldGroups: { list: '/groups/palette', insert: '/groups/insert' },
  permissions: { save: true, notifications: false },
  errors: [],
};

async function boot(overrides) {
  const cfg = Object.assign(JSON.parse(JSON.stringify(config)), overrides || {});
  const html = '<form id="ffb-admin-form" method="post" action="/group/save"><input type="hidden" name="form_key" value="KEY1">'
    + ['id', 'name', 'description', 'fields', 'translations', 'back'].map((n) => '<input type="hidden" name="' + n + '" value="">').join('')
    + '<div id="ffb-builder" data-ffb-builder></div></form><script type="application/json" id="ffb-builder-config"></script>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/group/edit' });
  const w = dom.window;
  w.document.getElementById('ffb-builder-config').textContent = JSON.stringify(cfg);
  w.structuredClone = structuredClone;
  sources.forEach((src) => w.eval(src));
  let submitted = 0;
  w.document.getElementById('ffb-admin-form').submit = () => { submitted++; };
  w.FuranFormBuilder.builder.init(w.document.getElementById('ffb-builder'));
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const qa = (s) => Array.from(doc.querySelectorAll(s));
  const click = (node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  const change = (node, value) => {
    node.value = value;
    node.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  const input = (name) => q('#ffb-admin-form input[name="' + name + '"]').value;
  return { w, q, qa, click, change, input, submitted: () => submitted };
}

test('in group mode the canvas shows the group fields without pages', async () => {
  const t = await boot();
  assert.deepEqual(t.qa('[data-ffb-canvas] [data-ffb-card]').map((n) => n.getAttribute('data-ffb-card')), ['f_aaaa11', 'f_bbbb22']);
  assert.equal(t.q('[data-ffb-page-add]'), null);
  assert.equal(t.q('[data-ffb-tab]'), null);
  assert.equal(t.q('[data-ffb-page-settings]'), null);
  assert.equal(t.q('[data-ffb-palette] [data-ffb-add="page"]'), null);
  assert.ok(t.q('[data-ffb-palette] [data-ffb-add="section"]'));
  assert.match(t.q('[data-ffb-palette] h2').textContent, /Add to group/);
  t.w.close();
});

test('in group mode the toolbar saves the group and offers no form actions', async () => {
  const t = await boot();
  assert.equal(t.q('[data-ffb-save]').textContent, 'Save group');
  assert.ok(t.q('[data-ffb-save-continue]'));
  ['data-ffb-export', 'data-ffb-import', 'data-ffb-duplicate', 'data-ffb-preview'].forEach((attr) => assert.equal(t.q('[' + attr + ']'), null, attr));
  assert.equal(t.q('[data-ffb-act="savegroup"]'), null, 'a group is not saved as another group from here');
  assert.ok(t.q('[data-ffb-insert-group]'));
  t.w.close();
});

test('in group mode the inspector edits the group name and description', async () => {
  const t = await boot();
  const name = t.q('[data-ffb-inspector] [data-ffb-input="title"]');
  assert.equal(name.value, 'Contact details');
  assert.equal(name.maxLength, 120);
  assert.equal(t.q('[data-ffb-inspector] [data-ffb-input="identifier"]'), null);
  assert.ok(t.q('[data-ffb-hint="group-name"]'));
  t.change(name, 'Contact');
  t.change(t.q('[data-ffb-inspector] [data-ffb-input="description"]'), 'Who to reply to');

  t.click(t.q('[data-ffb-save-continue]'));

  assert.equal(t.submitted(), 1);
  assert.equal(t.input('name'), 'Contact');
  assert.equal(t.input('description'), 'Who to reply to');
  assert.equal(t.input('back'), 'edit');
  assert.deepEqual(JSON.parse(t.input('fields')).map((f) => f.name), ['first', 'email']);
  assert.equal(JSON.parse(t.input('translations'))[1].items.f_aaaa11.label.t, 'Vorname');
  t.w.close();
});

test('in group mode an empty name stops the save', async () => {
  const t = await boot();
  t.change(t.q('[data-ffb-inspector] [data-ffb-input="title"]'), ' ');
  t.click(t.q('[data-ffb-save]'));
  assert.equal(t.submitted(), 0);
  assert.match(t.q('[data-ffb-summary]').textContent, /Enter a name\./);
  t.w.close();
});

test('in group mode an admin without edit rights only views the group', async () => {
  const t = await boot({ permissions: { save: false, notifications: false }, fieldGroups: [] });
  assert.equal(t.q('[data-ffb-save]'), null);
  assert.match(t.q('[data-ffb-readonly]').textContent, /You can view this group but not change it\./);
  assert.equal(t.q('[data-ffb-insert-group]'), null);
  assert.equal(t.q('[data-ffb-undo]'), null);
  assert.equal(t.q('[data-ffb-redo]'), null);
  assert.ok(t.q('[data-ffb-delete]'), 'delete follows its own permission');
  assert.equal(t.q('[data-ffb-palette]').hidden, true);
  assert.equal(t.qa('.ffb-admin-card-actions').length, 0);
  assert.equal(t.q('[data-ffb-add-field]'), null);
  assert.equal(t.q('[data-ffb-inspector] [data-ffb-input="title"]').disabled, true);
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-select]'));
  const label = t.q('[data-ffb-inspector] [data-ffb-input="label"]');
  assert.equal(label.disabled, true);
  assert.equal(label.value, 'first', 'the selected field stays viewable');
  t.qa('[data-ffb-inspector] [role="tab"]').forEach((tab) => assert.equal(tab.disabled, false));
  assert.equal(t.q('[data-ffb-inspector] [data-ffb-input="delete"]').disabled, true);
  t.w.close();
});

test('in group mode the inspector lists the forms that contain the group', async () => {
  const t = await boot();
  const usage = t.q('[data-ffb-group-usage]');
  assert.equal(usage.querySelector('a').getAttribute('href'), '/form/edit/3');
  assert.equal(usage.querySelector('a').textContent, '<b>Contact</b>', 'titles are text, not markup');
  assert.equal(usage.querySelectorAll('a').length, 1, 'no link without the right to open forms');
  assert.match(usage.textContent, /Returns/);
  assert.match(usage.textContent, /And 2 more\./);
  t.w.close();

  const none = await boot({ group: { usage: { rows: [], total: 0 } } });
  assert.match(none.q('[data-ffb-group-usage]').textContent, /No form contains this group\./);
  none.w.close();
});

test('in group mode delete sits in the toolbar and posts after confirming', async () => {
  const t = await boot();
  let posted = null;
  t.w.HTMLFormElement.prototype.submit = function () { posted = this; };
  t.click(t.q('[data-ffb-delete]'));
  assert.match(t.q('[data-ffb-dialog]').textContent, /Forms that already use this group keep their fields\./);
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(posted.getAttribute('action'), '/group/delete');
  assert.equal(posted.method, 'post');
  assert.equal(posted.querySelector('[name="form_key"]').value, 'KEY1');
  assert.equal(posted.querySelector('[name="id"]').value, '3');
  t.w.close();

  const kept = await boot({ urls: { save: '/group/save', back: '/group/index', delete: '' } });
  assert.equal(kept.q('[data-ffb-delete]'), null);
  kept.w.close();
});

test('in group mode the save buttons sit together inside the toolbar actions group', async () => {
  const t = await boot();
  const pair = t.q('.ffb-admin-toolbar-actions .ffb-admin-toolbar-save');
  assert.ok(pair);
  assert.ok(pair.contains(t.q('[data-ffb-save]')) && pair.contains(t.q('[data-ffb-save-continue]')));
  t.w.close();
});
