'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const read = (f) => fs.readFileSync(path.join(jsDir, f), 'utf8');
const modelSrc = read('builder-model.js');
const previewSrc = read('builder-preview.js');
const presetsSrc = read('builder-presets.js');
const builderSrc = read('builder.js');

const field = (id, name, extra) => Object.assign({
  id, kind: 'field', type: 'text', name, label: name, placeholder: '', help: '', default: '', width: 'full', required: false,
  rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
}, extra || {});

const config = {
  form: {
    id: 7, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [
      field('f_aaaa11', 'first'),
      { id: 's_cccc33', kind: 'section', title: 'Address', description: '', condition: null, items: [
        field('f_street01', 'street'),
        field('f_city0001', 'city', { condition: { actions: [{ action: 'show' }], match: 'all', rules: [{ field: 'street', operator: 'equals', value: 'x' }, { field: 'first', operator: 'equals', value: 'y' }] } }),
      ] },
    ] }] },
    settings: { submit_label: 'Submit' },
    translations: {},
  },
  fieldTypes: [{ code: 'text', personalByDefault: false, conditionSource: true, label: 'Text', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false }],
  contentTypes: [], rules: [], operators: [], widths: [{ value: 'full', label: 'Full' }], autocomplete: [], stores: [{ value: 0, label: 'All' }],
  uploads: { allowedExtensions: ['pdf'], maxMb: 5 },
  urls: { save: '/save', back: '/back', preview: null },
  fieldGroups: { list: '/groups/palette', insert: '/groups/insert', create: '/groups/create' },
  permissions: { save: true, field_groups: true, field_groups_edit: true },
  errors: [],
};

async function boot(overrides, responses) {
  const cfg = Object.assign(JSON.parse(JSON.stringify(config)), overrides || {});
  const html = '<form id="ffb-admin-form" method="post" action="/save"><input type="hidden" name="form_key" value="KEY1">'
    + ['form_id', 'identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'translations', 'back'].map((n) => '<input type="hidden" name="' + n + '" value="">').join('')
    + '<div id="ffb-builder" data-ffb-builder></div></form><script type="application/json" id="ffb-builder-config"></script>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/edit' });
  const w = dom.window;
  if (w.document.readyState === 'loading') {
    await new Promise((resolve) => w.document.addEventListener('DOMContentLoaded', resolve));
  }
  w.document.getElementById('ffb-builder-config').textContent = JSON.stringify(cfg);
  w.structuredClone = structuredClone;
  const requests = [];
  w.fetch = (url, opts) => {
    const params = new URLSearchParams(String(opts.body));
    requests.push({ url, opts, params });
    const reply = (responses || {})[url];
    const [status, body] = typeof reply === 'function' ? reply(params) : (reply || [404, { success: false, errors: ['no'] }]);
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
  };
  [modelSrc, previewSrc, presetsSrc].forEach((src) => w.eval(src));
  w.FuranFormBuilder.builderInspector = { render(container) { container.textContent = 'stub'; } };
  w.eval(builderSrc);
  w.document.getElementById('ffb-admin-form').submit = () => {};
  w.FuranFormBuilder.builder.init(w.document.getElementById('ffb-builder'));
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const qa = (s) => Array.from(doc.querySelectorAll(s));
  const click = (node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  const settle = async () => { for (let i = 0; i < 5; i++) { await new Promise((r) => w.setTimeout(r, 0)); } };
  const names = () => qa('[data-ffb-canvas] [data-ffb-card]').map((n) => n.getAttribute('data-ffb-card'));
  return { w, doc, q, qa, click, settle, requests, names };
}

test('it hides group actions from admins without the group permissions', async () => {
  const t = await boot({ fieldGroups: [], permissions: { save: true, field_groups: false, field_groups_edit: false } });
  assert.equal(t.q('[data-ffb-act="savegroup"]'), null);
  assert.equal(t.q('[data-ffb-insert-group]'), null);
  t.w.close();

  const viewOnly = await boot({ fieldGroups: { list: '/groups/palette', insert: '/groups/insert' } });
  assert.equal(viewOnly.q('[data-ffb-act="savegroup"]'), null);
  assert.ok(viewOnly.q('[data-ffb-insert-group]'));
  viewOnly.w.close();

  const editOnly = await boot({ fieldGroups: { create: '/groups/create' } });
  assert.ok(editOnly.q('[data-ffb-card="s_cccc33"] > .ffb-admin-card-actions [data-ffb-act="savegroup"]'));
  assert.equal(editOnly.q('[data-ffb-insert-group]'), null);
  editOnly.w.close();
});

test('it saves the selected section as a group', async () => {
  const t = await boot({}, { '/groups/create': [200, { success: true, id: 5, name: 'Address block' }] });
  t.click(t.q('[data-ffb-card="s_cccc33"] > .ffb-admin-card-actions [data-ffb-act="savegroup"]'));
  const dialog = t.q('[data-ffb-dialog]');
  assert.ok(dialog);
  dialog.querySelector('input[name="group_name"]').value = 'Address block';
  dialog.querySelector('textarea[name="group_description"]').value = 'Street and city';
  t.click(dialog.querySelector('[data-ffb-dialog-confirm]'));
  await t.settle();

  assert.equal(t.requests.length, 1);
  const req = t.requests[0];
  assert.equal(req.url, '/groups/create');
  assert.equal(req.opts.method, 'POST');
  assert.equal(req.params.get('form_key'), 'KEY1');
  const payload = JSON.parse(req.params.get('payload'));
  assert.equal(payload.name, 'Address block');
  assert.equal(payload.description, 'Street and city');
  assert.equal(payload.fields[0].id, 's_cccc33');
  assert.deepEqual(payload.fields[0].items[1].condition.rules.map((r) => r.field), ['street'], 'rules outside the selection are left out');
  assert.equal(t.q('[data-ffb-dialog]'), null, 'the dialog closes');
  assert.match(t.q('[data-ffb-notice]').textContent, /Address block/);
  assert.equal(t.q('[data-ffb-card="f_aaaa11"]') !== null, true, 'the form is unchanged');
  t.w.close();
});

test('it tells the admin how many references outside the selection were left out of a saved group', async () => {
  const t = await boot({}, { '/groups/create': [200, { success: true, id: 5, name: 'A' }] });
  t.click(t.q('[data-ffb-card="s_cccc33"] > .ffb-admin-card-actions [data-ffb-act="savegroup"]'));
  t.q('[data-ffb-dialog] input[name="group_name"]').value = 'A';
  t.click(t.q('[data-ffb-dialog-confirm]'));
  await t.settle();
  assert.match(t.q('[data-ffb-notice]').textContent, /1 reference/);
  t.w.close();
});

test('it keeps the dialog open and shows the problems when the group is refused', async () => {
  const t = await boot({}, { '/groups/create': [422, { success: false, errors: ['A field group named "A" already exists.'] }] });
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="savegroup"]'));
  t.q('[data-ffb-dialog] input[name="group_name"]').value = 'A';
  t.click(t.q('[data-ffb-dialog-confirm]'));
  await t.settle();
  assert.match(t.q('[data-ffb-dialog-error]').textContent, /already exists/);
  t.w.close();
});

test('it needs a name before a group is posted', async () => {
  const t = await boot();
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="savegroup"]'));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  await t.settle();
  assert.equal(t.requests.length, 0);
  assert.ok(t.q('[data-ffb-dialog-error]').textContent.length > 0);
  t.w.close();
});

const GROUP = {
  success: true, name: 'Contact block',
  fields: [field('f_g000001', 'first'), field('f_g000002', 'phone', { condition: { actions: [{ action: 'show' }], match: 'all', rules: [{ field: 'first', operator: 'equals', value: 'a' }] } })],
  translations: {},
};

test('it inserts a group as a copy of its fields', async () => {
  const t = await boot({}, {
    '/groups/palette': [200, { success: true, groups: [{ id: 3, name: 'Contact block', description: 'Name and phone' }], next: null }],
    '/groups/insert': (params) => (params.get('id') === '3' ? [200, GROUP] : [404, { success: false, errors: ['gone'] }]),
  });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  assert.equal(t.requests[0].params.get('form_key'), 'KEY1');
  const choice = t.q('[data-ffb-dialog] [data-ffb-group-id="3"]');
  assert.equal(choice.textContent.includes('Contact block'), true);
  t.click(choice);
  await t.settle();

  assert.equal(t.requests[1].url, '/groups/insert');
  assert.equal(t.requests[1].params.get('id'), '3');
  assert.equal(t.q('[data-ffb-dialog]'), null);
  assert.equal(t.q('[data-ffb-card="f_g000001"]'), null, 'ids are fresh');
  assert.equal(t.names().length, 6);
  assert.match(t.q('[data-ffb-notice]').textContent, /Contact block/);
  t.w.close();
});

test('it renames fields whose names already exist in the form', async () => {
  const t = await boot({}, {
    '/groups/palette': [200, { success: true, groups: [{ id: 3, name: 'G', description: '' }], next: null }],
    '/groups/insert': [200, GROUP],
  });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  t.click(t.q('[data-ffb-group-id="3"]'));
  await t.settle();
  t.click(t.q('[data-ffb-save]'));
  const schema = JSON.parse(t.q('input[name="schema"]').value);
  const items = schema.pages[0].items;
  assert.deepEqual(items.filter((i) => i.kind === 'field').map((i) => i.name), ['first', 'first_2', 'phone']);
  assert.equal(items[3].condition.rules[0].field, 'first_2', 'conditions follow the new names');
  assert.equal(items[0].name, 'first');
  t.w.close();
});

test('it lets the admin load further pages of groups', async () => {
  const t = await boot({}, {
    '/groups/palette': (params) => (params.get('after') === '3'
      ? [200, { success: true, groups: [{ id: 4, name: 'Second', description: '' }], next: null }]
      : [200, { success: true, groups: [{ id: 3, name: 'First', description: '' }], next: 3 }]),
  });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  t.click(t.q('[data-ffb-groups-more]'));
  await t.settle();
  assert.deepEqual(t.qa('[data-ffb-group-id]').map((n) => n.getAttribute('data-ffb-group-id')), ['3', '4']);
  assert.equal(t.q('[data-ffb-groups-more]'), null);
  t.w.close();
});

test('it shows the problem when a group can no longer be inserted', async () => {
  const t = await boot({}, {
    '/groups/palette': [200, { success: true, groups: [{ id: 3, name: 'G', description: '' }], next: null }],
    '/groups/insert': [404, { success: false, errors: ['This field group no longer exists.'] }],
  });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  t.click(t.q('[data-ffb-group-id="3"]'));
  await t.settle();
  assert.match(t.q('[data-ffb-dialog-error]').textContent, /no longer exists/);
  assert.equal(t.names().length, 4);
  t.w.close();
});

test('a saved form offers delete in the toolbar and posts after confirming', async () => {
  const t = await boot({ urls: { save: '/save', back: '/back', preview: null, delete: '/form/delete' } });
  let posted = null;
  t.w.HTMLFormElement.prototype.submit = function () { posted = this; };
  t.click(t.q('[data-ffb-delete]'));
  assert.match(t.q('[data-ffb-dialog]').textContent, /The form and all its submissions are deleted\. This cannot be undone\./);
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(posted.getAttribute('action'), '/form/delete');
  assert.equal(posted.querySelector('[name="id"]').value, '7');
  t.w.close();

  const none = await boot();
  assert.equal(none.q('[data-ffb-delete]'), null);
  none.w.close();
});

test('it inserts a group after the selected item in a section', async () => {
  const t = await boot({}, {
    '/groups/palette': [200, { success: true, groups: [{ id: 3, name: 'G', description: '' }], next: null }],
    '/groups/insert': [200, GROUP],
  });
  t.click(t.q('[data-ffb-card="f_street01"] [data-ffb-select]'));
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  t.click(t.q('[data-ffb-dialog] [data-ffb-group-id="3"]'));
  await t.settle();
  const inSection = t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card]').map((n) => n.getAttribute('data-ffb-card'));
  assert.equal(inSection.length, 4);
  assert.equal(inSection[0], 'f_street01');
  assert.equal(inSection[3], 'f_city0001');
  t.w.close();
});
