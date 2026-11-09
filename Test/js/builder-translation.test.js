'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const src = (name) => fs.readFileSync(path.join(jsDir, name), 'utf8');
const scripts = ['builder-i18n.js', 'builder-model.js', 'builder-preview.js', 'builder-presets.js', 'builder-inspector.js', 'builder.js'].map(src);

const field = (id, name, label, extra) => Object.assign({
  id, kind: 'field', type: 'text', name, label, placeholder: '', help: '', default: '', width: 'full', required: false,
  rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
}, extra || {});

const config = {
  form: {
    id: 7, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [
      field('f_aaaa11', 'first', 'First name', { help: 'As on your passport' }),
      field('f_bbbb22', 'topic', 'Topic', { type: 'select', options: [{ label: 'Sales', value: 'sales' }] }),
    ] }] },
    settings: { submit_label: 'Submit', success_message: 'Thanks' },
    translations: { 2: { items: { f_bbbb22: { label: { t: 'Sujet', s: 'Subject' } } } } },
  },
  fieldTypes: [
    { code: 'text', label: 'Text', category: 'input', allowedRules: [], settingsSpec: [], hasOptions: false, isFile: false, isMultiValue: false },
    { code: 'select', label: 'Select', category: 'choice', allowedRules: [], settingsSpec: { empty_label: { type: 'string', default: '', translatable: true } }, hasOptions: true, isFile: false, isMultiValue: false },
  ],
  contentTypes: [], rules: [], operators: [], widths: [{ value: 'full', label: 'Full' }], autocomplete: [],
  stores: [{ value: 0, label: 'All' }],
  translationStores: [
    { id: 1, code: 'default', name: 'English', locale: 'en_GB' },
    { id: 2, code: 'fr', name: 'French', locale: 'fr_FR' },
  ],
  phrases: {},
  uploads: { allowedExtensions: [], maxMb: 5 },
  urls: { save: '/save', back: '/back', preview: null, duplicate: '/duplicate/id/7' },
  permissions: { save: true, notifications: true },
  errors: [],
};

function boot(overrides) {
  const cfg = Object.assign(JSON.parse(JSON.stringify(config)), overrides || {});
  const html = '<form id="ffb-admin-form" method="post" action="/save"><input type="hidden" name="form_key" value="k">'
    + ['form_id', 'identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'translations', 'back']
      .map((n) => '<input type="hidden" name="' + n + '" value="">').join('')
    + '<div id="ffb-builder" data-ffb-builder></div></form><script type="application/json" id="ffb-builder-config"></script>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/edit' });
  const w = dom.window;
  w.document.getElementById('ffb-builder-config').textContent = JSON.stringify(cfg);
  w.structuredClone = structuredClone;
  scripts.forEach((s) => w.eval(s));
  const submitted = [];
  w.HTMLFormElement.prototype.submit = function () { submitted.push(this); };
  const api = w.FuranFormBuilder.builder.init(w.document.getElementById('ffb-builder'));
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const qa = (s) => Array.from(doc.querySelectorAll(s));
  const click = (node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  const change = (node, value) => {
    node.value = value;
    node.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  const pick = (store) => change(q('[data-ffb-store-view]'), store);
  const state = () => JSON.parse(JSON.stringify(api.getState()));
  return { w, doc, q, qa, click, change, pick, submitted, api, state };
}

test('it offers the store views the form is assigned to', () => {
  const t = boot();
  const options = t.qa('[data-ffb-store-view] option').map((o) => [o.value, o.textContent]);
  assert.deepEqual(options, [['', 'Default (all store views)'], ['1', 'English (en_GB)'], ['2', 'French (fr_FR)']]);
  t.w.close();
  const one = boot({ form: Object.assign({}, config.form, { store_ids: [2] }) });
  assert.deepEqual(one.qa('[data-ffb-store-view] option').map((o) => o.value), ['', '2']);
  one.w.close();
  const none = boot({ translationStores: [] });
  assert.equal(none.q('.ffb-admin-store-switch').hidden, true);
  none.w.close();
});

test('translating locks the layout and previews translated text with default fallback', () => {
  const t = boot();
  t.pick('2');
  assert.equal(t.q('[data-ffb-palette]').hidden, true);
  assert.ok(t.q('[data-ffb-translate-note]').textContent.includes('French (fr_FR)'));
  assert.equal(t.qa('.ffb-admin-card-actions').length, 0);
  assert.equal(t.qa('[data-ffb-add-field]').length, 0);
  assert.equal(t.q('[data-ffb-page-add]'), null);
  assert.equal(t.q('[data-ffb-card="f_aaaa11"]').getAttribute('draggable'), null);
  assert.ok(t.q('[data-ffb-card="f_bbbb22"]').textContent.includes('Sujet'));
  assert.ok(t.q('[data-ffb-card="f_aaaa11"]').textContent.includes('First name'));
  assert.ok(t.q('[data-ffb-card="f_aaaa11"] .ffb-admin-badge-missing'));
  assert.ok(t.q('[data-ffb-card="f_bbbb22"] .ffb-admin-badge-review'));
  assert.equal(t.q('[data-ffb-translation-progress]').textContent, '1 of 7 translated, 1 need review');
  t.pick('');
  assert.equal(t.q('[data-ffb-palette]').hidden, false);
  assert.ok(t.qa('.ffb-admin-card-actions').length > 0);
  t.w.close();
});

test('it edits form texts, undoes them and posts every translation on save', () => {
  const t = boot();
  t.pick('2');
  const labels = t.qa('[data-ffb-inspector] [data-ffb-translation] label > span').map((n) => n.textContent);
  assert.deepEqual(labels, ['Submit label', 'Success message']);
  const input = t.q('[data-ffb-inspector] [data-ffb-input="tr-0"]');
  assert.equal(input.placeholder, 'Submit');
  t.change(input, 'Envoyer');
  assert.equal(t.state().dirty, true);
  assert.equal(t.q('[data-ffb-translation-progress]').textContent, '2 of 7 translated, 1 need review');
  t.click(t.q('[data-ffb-undo]'));
  assert.equal(t.state().form.translations[2].form, undefined);
  t.click(t.q('[data-ffb-redo]'));
  t.click(t.q('[data-ffb-save]'));
  const posted = JSON.parse(t.q('input[name="translations"]').value);
  assert.deepEqual(posted[2].form.submit_label, { t: 'Envoyer', s: 'Submit' });
  assert.deepEqual(posted[2].items.f_bbbb22.label, { t: 'Sujet', s: 'Subject' });
  t.w.close();
});

test('it translates an item, flags changed defaults and marks them reviewed', () => {
  const t = boot();
  t.pick('2');
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-select]'));
  const labels = t.qa('[data-ffb-inspector] [data-ffb-translation] label > span').map((n) => n.textContent);
  assert.deepEqual(labels, ['Label', 'Option "Sales"']);
  assert.match(t.q('[data-ffb-review="0"]').textContent, /changed from "Subject"/);
  t.click(t.q('[data-ffb-input="tr-0-reviewed"]'));
  assert.deepEqual(t.state().form.translations[2].items.f_bbbb22.label, { t: 'Sujet', s: 'Topic' });
  assert.equal(t.q('[data-ffb-review="0"]'), null);
  t.change(t.q('[data-ffb-input="tr-1"]'), 'Ventes');
  assert.ok(t.q('[data-ffb-card="f_bbbb22"]').textContent.includes('Ventes') || t.state().form.translations[2].items.f_bbbb22.options.sales);
  t.change(t.q('[data-ffb-input="tr-0"]'), '  ');
  assert.equal(t.state().form.translations[2].items.f_bbbb22.label, undefined);
  t.w.close();
});

test('read-only admins can view translations but not change them', () => {
  const t = boot({ permissions: { save: false } });
  t.pick('2');
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-select]'));
  assert.equal(t.q('[data-ffb-input="tr-0"]').readOnly, true);
  assert.equal(t.q('[data-ffb-input="tr-0-reviewed"]'), null);
  assert.equal(t.q('[data-ffb-duplicate]'), null);
  t.w.close();
});

test('it duplicates the form by POST and asks first when there are unsaved changes', () => {
  const t = boot();
  t.click(t.q('[data-ffb-duplicate]'));
  assert.equal(t.submitted.length, 1);
  assert.equal(t.submitted[0].getAttribute('action'), '/duplicate/id/7');
  assert.equal(t.submitted[0].getAttribute('method'), 'post');
  assert.equal(t.submitted[0].querySelector('input[name="form_key"]').value, 'k');
  t.w.close();

  const dirty = boot();
  dirty.pick('2');
  dirty.change(dirty.q('[data-ffb-input="tr-0"]'), 'Envoyer');
  dirty.click(dirty.q('[data-ffb-duplicate]'));
  assert.equal(dirty.submitted.length, 0);
  assert.ok(dirty.q('[data-ffb-dialog]'));
  dirty.click(dirty.q('[data-ffb-dialog-confirm]'));
  assert.equal(dirty.submitted.length, 1);
  dirty.w.close();

  const fresh = boot({ urls: Object.assign({}, config.urls, { duplicate: '' }) });
  assert.equal(fresh.q('[data-ffb-duplicate]'), null);
  fresh.w.close();
});

test('it uses the admin locale phrases from the config', () => {
  const t = boot({ phrases: { Save: 'Enregistrer', 'Default (all store views)': 'Par défaut' } });
  assert.equal(t.q('[data-ffb-save]').textContent, 'Enregistrer');
  assert.equal(t.q('[data-ffb-store-view] option').textContent, 'Par défaut');
  t.w.close();
});

test('it leaves translation mode when the store view is unassigned', () => {
  const t = boot({ stores: [{ value: 0, label: 'All' }, { value: 1, label: 'English' }, { value: 2, label: 'French' }] });
  const select = t.q('[data-ffb-inspector] select[data-ffb-input="store_ids"]');
  Array.from(select.options).forEach((o) => { o.selected = o.value === '1'; });
  select.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  assert.deepEqual(t.qa('[data-ffb-store-view] option').map((o) => o.value), ['', '1']);
  t.click(t.q('[data-ffb-undo]'));
  t.pick('2');
  assert.equal(t.q('[data-ffb-store-view]').value, '2');
  t.click(t.q('[data-ffb-redo]'));
  assert.equal(t.q('[data-ffb-store-view]').value, '');
  assert.equal(t.q('[data-ffb-palette]').hidden, false);
  t.w.close();
});
