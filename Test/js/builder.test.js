'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const modelSrc = fs.readFileSync(path.join(jsDir, 'builder-model.js'), 'utf8');
const previewSrc = fs.readFileSync(path.join(jsDir, 'builder-preview.js'), 'utf8');
const presetsSrc = fs.readFileSync(path.join(jsDir, 'builder-presets.js'), 'utf8');
const builderSrc = fs.readFileSync(path.join(jsDir, 'builder.js'), 'utf8');

const config = {
  form: {
    id: 7, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: {
      version: 1,
      pages: [{
        id: 'p_aaaa11', title: 'One', condition: null,
        items: [
          { id: 'f_aaaa11', kind: 'field', type: 'text', name: 'first', label: 'First', placeholder: '', help: '', default: '', width: 'half', required: true, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '' },
          { id: 'f_bbbb22', kind: 'field', type: 'email', name: 'mail', label: 'Mail', placeholder: '', help: '', default: '', width: 'full', required: false, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '' },
          { id: 's_cccc33', kind: 'section', title: 'Sec', description: '', condition: null, items: [] },
        ],
      }],
    },
    settings: { submit_label: 'Submit' },
  },
  fieldTypes: [
    { code: 'text', label: 'Text', category: 'input', allowedRules: [], settingsSpec: [], hasOptions: false, isFile: false, isMultiValue: false },
    { code: 'email', label: 'Email', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false },
    { code: 'select', label: 'Select', category: 'choice', allowedRules: [], settingsSpec: {}, hasOptions: true, isFile: false, isMultiValue: false },
    { code: 'file', label: 'File', category: 'advanced', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: true, isMultiValue: false },
  ],
  contentTypes: [{ code: 'heading', label: 'Heading' }, { code: 'paragraph', label: 'Paragraph' }, { code: 'divider', label: 'Divider' }, { code: 'spacer', label: 'Spacer' }],
  rules: [], operators: [], widths: [{ value: 'full', label: 'Full' }, { value: 'half', label: 'Half' }],
  autocomplete: [], stores: [{ value: 0, label: 'All' }],
  uploads: { allowedExtensions: ['pdf'], maxMb: 5 },
  urls: { save: '/save', back: '/back', preview: 'https://shop.test/forms/contact' },
  errors: [],
};

function boot(overrides) {
  const cfg = Object.assign(JSON.parse(JSON.stringify(config)), overrides || {});
  const html = '<form id="ffb-admin-form" class="ffb-admin" method="post" action="/save">'
    + '<input type="hidden" name="form_key" value="k"><input type="hidden" name="form_id" value="7">'
    + ['identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'back'].map((n) => '<input type="hidden" name="' + n + '" value="">').join('')
    + '<div id="ffb-builder" data-ffb-builder></div></form>'
    + '<script type="application/json" id="ffb-builder-config"></script>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/edit' });
  const w = dom.window;
  w.document.getElementById('ffb-builder-config').textContent = JSON.stringify(cfg);
  w.structuredClone = structuredClone;
  w.eval(modelSrc);
  w.eval(previewSrc);
  w.eval(presetsSrc);
  w.FuranFormBuilder.builderInspector = {
    calls: [],
    render(container, state, c, actions) {
      this.calls.push({ container, state, config: c, actions });
      container.textContent = 'stub-inspector';
    },
  };
  w.eval(builderSrc);
  const submitted = [];
  w.document.getElementById('ffb-admin-form').submit = () => submitted.push(true);
  w.FuranFormBuilder.builder.init(w.document.getElementById('ffb-builder'));
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const qa = (s) => Array.from(doc.querySelectorAll(s));
  const click = (node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  const ids = () => qa('[data-ffb-canvas] [data-ffb-card]').map((n) => n.getAttribute('data-ffb-card'));
  return { w, doc, q, qa, click, ids, submitted, inspector: w.FuranFormBuilder.builderInspector, cfg };
}

test('it renders the palette grouped by category', () => {
  const t = boot();
  const groups = t.qa('[data-ffb-palette] [data-ffb-group]').map((g) => g.getAttribute('data-ffb-group'));
  assert.deepEqual(groups, ['input', 'choice', 'advanced', 'layout', 'presets']);
  assert.ok(t.q('[data-ffb-add="field:text"]'));
  assert.ok(t.q('[data-ffb-add="field:select"]'));
  assert.ok(t.q('[data-ffb-add="content:heading"]'));
  assert.ok(t.q('[data-ffb-add="section"]'));
  assert.ok(t.q('[data-ffb-add="page"]'));
  t.w.close();
});

test('it adds a field to the current page when a palette item is clicked', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add="field:select"]'));
  assert.equal(t.ids().length, 4);
  assert.equal(t.q('[data-ffb-canvas]').querySelectorAll('[data-ffb-card]').length, 4);
  t.click(t.q('[data-ffb-card="s_cccc33"] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="field:text"]'));
  const inSection = t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card]');
  assert.equal(inSection.length, 1);
  t.w.close();
});

test('it selects an item and renders the inspector with state and actions', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-select]'));
  const last = t.inspector.calls[t.inspector.calls.length - 1];
  assert.equal(last.state.selectedId, 'f_bbbb22');
  assert.equal(last.container, t.q('[data-ffb-inspector]'));
  assert.equal(last.config.fieldTypes.length, 4);
  for (const k of ['apply', 'select', 'notify']) {
    assert.equal(typeof last.actions[k], 'function');
  }
  t.w.close();
});

test('it applies allowlisted inspector actions through the builder model', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-select]'));
  const actions = t.inspector.calls[t.inspector.calls.length - 1].actions;
  actions.apply('updateItem', 'f_aaaa11', { label: 'Renamed' });
  assert.match(t.q('[data-ffb-card="f_aaaa11"]').textContent, /Renamed/);
  const after = t.inspector.calls[t.inspector.calls.length - 1];
  assert.equal(after.state.form.schema.pages[0].items[0].label, 'Renamed');
  assert.throws(() => actions.apply('importJson', '{}'));
  assert.throws(() => actions.apply('constructor'));
  actions.apply('removeItem', 'f_bbbb22');
  assert.equal(t.q('[data-ffb-card="f_bbbb22"]'), null);
  actions.select(null);
  assert.equal(t.inspector.calls[t.inspector.calls.length - 1].state.selectedId, null);
  actions.notify('Hello notice');
  assert.match(t.q('[data-ffb-notice]').textContent, /Hello notice/);
  t.w.close();
});

test('it shows a fallback when the inspector is missing', () => {
  const t = boot();
  delete t.w.FuranFormBuilder.builderInspector;
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-select]'));
  assert.match(t.q('[data-ffb-inspector]').textContent, /Inspector unavailable/);
  t.w.close();
});

test('it moves an item with the keyboard buttons', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-act="up"]'));
  assert.deepEqual(t.ids().slice(0, 2), ['f_bbbb22', 'f_aaaa11']);
  assert.equal(t.doc.activeElement, t.q('[data-ffb-card="f_bbbb22"] [data-ffb-act="down"]'));
  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-act="down"]'));
  assert.deepEqual(t.ids().slice(0, 2), ['f_aaaa11', 'f_bbbb22']);
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="dup"]'));
  assert.equal(t.ids().length, 4);
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="del"]'));
  assert.equal(t.ids().includes('f_aaaa11'), false);
  assert.equal(t.ids().length, 3);
  t.w.close();
});

test('it moves items by drag and drop', () => {
  const t = boot();
  const dt = { data: {}, setData(k, v) { this.data[k] = v; }, getData(k) { return this.data[k]; }, effectAllowed: '' };
  const fire = (node, type) => {
    const e = new t.w.Event(type, { bubbles: true, cancelable: true });
    e.dataTransfer = dt;
    e.clientY = 0;
    node.dispatchEvent(e);
  };
  fire(t.q('[data-ffb-add="field:file"]'), 'dragstart');
  const list = t.q('[data-ffb-list="s_cccc33"]');
  fire(list, 'dragover');
  fire(list, 'drop');
  assert.equal(t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card]').length, 1);
  fire(t.q('[data-ffb-card="f_aaaa11"]'), 'dragstart');
  fire(list, 'drop');
  assert.equal(t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card]').length, 2);
  t.w.close();
});

test('it adds renames and deletes pages', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add="page"]'));
  assert.equal(t.qa('[data-ffb-tab]').length, 2);
  const input = t.q('[data-ffb-page-title]');
  input.value = 'Second';
  input.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  assert.match(t.qa('[data-ffb-tab]')[1].textContent, /Second/);
  t.click(t.q('[data-ffb-page-delete]'));
  assert.ok(t.q('[data-ffb-dialog]'));
  t.click(t.q('[data-ffb-dialog-cancel]'));
  assert.equal(t.q('[data-ffb-dialog]'), null);
  assert.equal(t.qa('[data-ffb-tab]').length, 2);
  t.click(t.q('[data-ffb-page-delete]'));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(t.qa('[data-ffb-tab]').length, 1);
  assert.equal(t.q('[data-ffb-page-delete]').disabled, true);
  t.w.close();
});

test('it undoes and redoes with the keyboard', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add="field:text"]'));
  assert.equal(t.ids().length, 4);
  const key = (init) => t.doc.body.dispatchEvent(new t.w.KeyboardEvent('keydown', Object.assign({ bubbles: true, cancelable: true }, init)));
  key({ key: 'z', ctrlKey: true });
  assert.equal(t.ids().length, 3);
  key({ key: 'Z', ctrlKey: true, shiftKey: true });
  assert.equal(t.ids().length, 4);
  key({ key: 'z', metaKey: true });
  assert.equal(t.ids().length, 3);
  t.click(t.q('[data-ffb-redo]'));
  assert.equal(t.ids().length, 4);
  t.click(t.q('[data-ffb-undo]'));
  assert.equal(t.ids().length, 3);
  t.w.close();
});

test('it writes the serialized form including form id into hidden inputs and submits on save', () => {
  const t = boot();
  const val = (n) => t.q('input[name="' + n + '"]').value;
  t.click(t.q('[data-ffb-save-continue]'));
  assert.equal(t.submitted.length, 1);
  assert.equal(val('form_id'), '7');
  assert.equal(val('identifier'), 'contact');
  assert.equal(val('title'), 'Contact');
  assert.equal(val('is_active'), '1');
  assert.deepEqual(JSON.parse(val('store_ids')), [0]);
  assert.equal(JSON.parse(val('schema')).pages[0].items.length, 3);
  assert.equal(JSON.parse(val('settings')).submit_label, 'Submit');
  assert.equal(val('back'), 'edit');
  t.click(t.q('[data-ffb-save]'));
  assert.equal(val('back'), '');
  const nullId = boot({ form: Object.assign({}, config.form, { id: null }) });
  nullId.click(nullId.q('[data-ffb-save]'));
  assert.equal(nullId.q('input[name="form_id"]').value, '');
  t.w.close();
  nullId.w.close();
});

test('it blocks saving and lists validation errors', () => {
  const t = boot();
  const bad = JSON.parse(JSON.stringify(config.form));
  bad.schema.pages[0].items[0].label = '';
  const t2 = boot({ form: bad });
  t2.click(t2.q('[data-ffb-save]'));
  assert.equal(t2.submitted.length, 0);
  const links = t2.qa('[data-ffb-summary] [data-ffb-error-link]');
  assert.ok(links.length >= 1);
  t2.click(links[0]);
  const last = t2.inspector.calls[t2.inspector.calls.length - 1];
  assert.equal(last.state.selectedId, 'f_aaaa11');
  t.w.close();
  t2.w.close();
});

test('it shows errors from a failed save on load', () => {
  const t = boot({ errors: ['Identifier already exists.'] });
  assert.match(t.q('[data-ffb-summary]').textContent, /Identifier already exists\./);
  t.w.close();
});

test('it warns before leaving when dirty and in-page dialog on back', () => {
  const t = boot();
  const ev = () => { const e = new t.w.Event('beforeunload', { cancelable: true }); t.w.dispatchEvent(e); return e.defaultPrevented; };
  assert.equal(ev(), false);
  t.click(t.q('[data-ffb-add="field:text"]'));
  assert.equal(ev(), true);
  const back = t.q('[data-ffb-back]');
  const e = new t.w.MouseEvent('click', { bubbles: true, cancelable: true });
  back.dispatchEvent(e);
  assert.equal(e.defaultPrevented, true);
  assert.ok(t.q('[data-ffb-dialog]'));
  t.w.close();
});

test('without save rights it shows a view-only note, no save or import, and no leave warning', () => {
  const t = boot({ permissions: { save: false } });
  assert.equal(t.q('[data-ffb-save]'), null);
  assert.equal(t.q('[data-ffb-save-continue]'), null);
  assert.equal(t.q('[data-ffb-import]'), null);
  assert.ok(t.q('[data-ffb-export]'));
  assert.match(t.q('[data-ffb-readonly]').textContent, /view this form but not change it/);
  const e = new t.w.Event('beforeunload', { cancelable: true });
  t.w.dispatchEvent(e);
  assert.equal(e.defaultPrevented, false);
  t.w.close();
});

test('it imports json through a dialog and reports errors', () => {
  const t = boot();
  t.click(t.q('[data-ffb-import]'));
  const area = t.q('[data-ffb-dialog] textarea');
  area.value = 'nope';
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.ok(t.q('[data-ffb-dialog]'));
  assert.match(t.q('[data-ffb-dialog-error]').textContent, /not valid JSON/);
  const imp = JSON.parse(JSON.stringify(config.form));
  imp.title = 'Imported';
  imp.schema.pages[0].items = [];
  area.value = JSON.stringify(imp);
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(t.q('[data-ffb-dialog]'), null);
  assert.equal(t.ids().length, 0);
  t.w.close();
});

test('it disables preview without a url and opens it with noopener otherwise', () => {
  const t = boot();
  const a = t.q('[data-ffb-preview]');
  assert.equal(a.getAttribute('target'), '_blank');
  assert.match(a.getAttribute('rel'), /noopener/);
  const t2 = boot({ urls: { save: '/s', back: '/b', preview: null } });
  assert.equal(t2.q('[data-ffb-preview]').disabled, true);
  t.w.close();
  t2.w.close();
});

test('it never uses innerHTML for user content', () => {
  assert.doesNotMatch(builderSrc, /innerHTML|outerHTML|insertAdjacentHTML|eval\(|new Function/);
  const bad = JSON.parse(JSON.stringify(config.form));
  bad.schema.pages[0].items[0].label = '<img src=x onerror=alert(1)>';
  bad.schema.pages[0].title = '<b>x</b>';
  const t = boot({ form: bad });
  assert.equal(t.doc.querySelector('[data-ffb-canvas] img'), null);
  assert.equal(t.doc.querySelector('[data-ffb-canvas] b'), null);
  assert.match(t.q('[data-ffb-card="f_aaaa11"]').textContent, /<img src=x/);
  t.w.close();
});

test('it lists field types with unknown categories under other', () => {
  const t = boot({ fieldTypes: config.fieldTypes.concat([{ code: 'rating', label: 'Rating', category: 'vendor', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false }]) });
  const groups = t.qa('[data-ffb-palette] [data-ffb-group]').map((g) => g.getAttribute('data-ffb-group'));
  assert.deepEqual(groups, ['input', 'choice', 'advanced', 'other', 'layout', 'presets']);
  assert.ok(t.q('[data-ffb-group="other"] [data-ffb-add="field:rating"]'));
  t.w.close();
});

const full = (types) => config.fieldTypes.concat(types.map((code) => ({ code, label: code, category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false })));

test('it renders the page as a six column grid with width classes', () => {
  const t = boot();
  assert.ok(t.q('[data-ffb-list="p_aaaa11"].ffb-admin-list'));
  assert.ok(t.q('[data-ffb-card="f_aaaa11"].ffb-admin-w-half'));
  assert.ok(t.q('[data-ffb-card="f_bbbb22"].ffb-admin-w-full'));
  assert.ok(t.q('[data-ffb-card="s_cccc33"].ffb-admin-w-full'));
  t.w.close();
});

test('it renders an inert preview of each field control', () => {
  const bad = JSON.parse(JSON.stringify(config.form));
  const items = bad.schema.pages[0].items;
  items[0].placeholder = 'Your name';
  items[0].help = 'Help text here';
  items.splice(1, 1, { id: 'f_sel111', kind: 'field', type: 'select', name: 's', label: 'Pick', width: 'full', required: false, rules: [], options: [{ label: 'Alpha', value: 'a' }], settings: {}, condition: { action: 'show', match: 'all', rules: [] } });
  items.push({ id: 'c_head11', kind: 'content', type: 'heading', text: 'Big title', level: 1, width: 'full', condition: null });
  const t = boot({ form: bad });
  const first = t.q('[data-ffb-card="f_aaaa11"]');
  assert.match(first.textContent, /First/);
  assert.match(first.textContent, /Your name/);
  assert.match(first.textContent, /Help text here/);
  assert.ok(first.querySelector('.ffb-admin-required'));
  assert.equal(first.querySelector('.ffb-admin-mock').getAttribute('aria-hidden'), 'true');
  assert.equal(first.querySelectorAll('.ffb-admin-preview input, .ffb-admin-preview select, .ffb-admin-preview textarea').length, 0);
  const sel = t.q('[data-ffb-card="f_sel111"]');
  assert.match(sel.querySelector('.ffb-admin-mock').textContent, /Please select/);
  assert.match(sel.textContent, /Conditional/);
  assert.ok(t.q('[data-ffb-card="c_head11"] .ffb-admin-mock-h1'));
  t.w.close();
});

test('it replaces the mock of an unknown type with an unavailable notice', () => {
  const bad = JSON.parse(JSON.stringify(config.form));
  bad.schema.pages[0].items[0].type = 'mystery';
  const t = boot({ form: bad });
  assert.equal(t.q('[data-ffb-card="f_aaaa11"] .ffb-admin-mock-input'), null);
  assert.ok(t.q('[data-ffb-card="f_aaaa11"] .ffb-admin-preview-unavailable'));
  t.w.close();
});

test('it shows a select, radios, stars and a scale mock', () => {
  const mk = (id, type, extra) => Object.assign({ id, kind: 'field', type, name: id, label: id, width: 'full', required: false, rules: [], options: ['a', 'b', 'c', 'd', 'e', 'f'].map((v) => ({ label: v, value: v })), settings: {}, condition: null }, extra);
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages[0].items = [mk('f_rad111', 'radio'), mk('f_rat111', 'rating', { settings: { max: 4 } }), mk('f_sca111', 'scale', { settings: { min: 0, max: 10, low_label: 'Low', high_label: 'High' } })];
  const t = boot({ form, fieldTypes: full(['radio', 'rating', 'scale']) });
  const rad = t.q('[data-ffb-card="f_rad111"]');
  assert.equal(rad.querySelectorAll('.ffb-admin-mock-choice').length, 4);
  assert.match(rad.textContent, /\+2 more/);
  assert.equal(t.qa('[data-ffb-card="f_rat111"] .ffb-admin-mock-star').length, 4);
  assert.equal(t.qa('[data-ffb-card="f_sca111"] .ffb-admin-mock-scale-btn').length, 11);
  assert.match(t.q('[data-ffb-card="f_sca111"]').textContent, /Low/);
  t.w.close();
});

test('it shows a quick width cycle in the card toolbar', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="width"]'));
  assert.ok(t.q('[data-ffb-card="f_aaaa11"].ffb-admin-w-full'));
  assert.equal(t.doc.activeElement, t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="width"]'));
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="width"]'));
  assert.ok(t.q('[data-ffb-card="f_aaaa11"].ffb-admin-w-half'));
  t.w.close();
});

test('it keeps the page scroll position when the canvas is rebuilt', () => {
  const t = boot();
  let y = 400;
  Object.defineProperty(t.w, 'scrollY', { configurable: true, get: () => y });
  Object.defineProperty(t.w, 'scrollX', { configurable: true, get: () => 0 });
  t.w.scrollTo = (nx, ny) => { y = ny; };
  const canvas = t.q('[data-ffb-canvas]');
  const append = canvas.appendChild.bind(canvas);
  canvas.appendChild = (node) => { y = 287; return append(node); };
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="width"]'));
  assert.equal(y, 400);
  t.w.close();
});

test('it opens a type picker from add field and inserts into that container', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add-field="s_cccc33"]'));
  assert.ok(t.q('[data-ffb-picker][role="dialog"]'));
  assert.equal(t.doc.activeElement, t.q('[data-ffb-picker-search]'));
  assert.equal(t.q('[data-ffb-picker] [data-ffb-pick="section"]'), null);
  const search = t.q('[data-ffb-picker-search]');
  search.value = 'sel';
  search.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  assert.equal(t.q('[data-ffb-pick="field:select"]').hidden, false);
  assert.equal(t.q('[data-ffb-pick="field:text"]').hidden, true);
  t.click(t.q('[data-ffb-pick="field:select"]'));
  assert.equal(t.q('[data-ffb-picker]'), null);
  assert.equal(t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card]').length, 1);
  t.w.close();
});

test('it closes the type picker with escape and offers sections on a page', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add-field="p_aaaa11"]'));
  assert.ok(t.q('[data-ffb-pick="section"]'));
  t.doc.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  assert.equal(t.q('[data-ffb-picker]'), null);
  t.w.close();
});

test('it focuses the label input after adding a field', () => {
  const t = boot();
  t.inspector.render = (container) => {
    container.textContent = '';
    const input = t.doc.createElement('input');
    input.setAttribute('data-ffb-input', 'label');
    container.appendChild(input);
  };
  t.click(t.q('[data-ffb-add="field:text"]'));
  assert.equal(t.doc.activeElement.getAttribute('data-ffb-input'), 'label');
  t.w.close();
});

test('it filters the palette with the search box', () => {
  const t = boot();
  const search = t.q('[data-ffb-palette-search]');
  search.value = 'mail';
  search.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  assert.equal(t.q('[data-ffb-palette] [data-ffb-add="field:email"]').hidden, false);
  assert.equal(t.q('[data-ffb-palette] [data-ffb-add="field:text"]').hidden, true);
  assert.equal(t.q('[data-ffb-palette] [data-ffb-group="choice"]').hidden, true);
  t.w.close();
});

test('it shows presets only when their field types exist', () => {
  const t = boot();
  const codes = t.qa('[data-ffb-group="presets"] [data-ffb-add]').map((n) => n.getAttribute('data-ffb-add'));
  assert.deepEqual(codes, ['preset:full_name', 'preset:email_confirm']);
  const t2 = boot({ fieldTypes: full(['tel', 'country', 'radio', 'date', 'scale', 'consent']) });
  assert.equal(t2.qa('[data-ffb-group="presets"] [data-ffb-add]').length, 8);
  const t3 = boot({ fieldTypes: [config.fieldTypes[2]] });
  assert.equal(t3.q('[data-ffb-group="presets"]'), null);
  t.w.close();
  t2.w.close();
  t3.w.close();
});

test('it inserts the full name preset with unique names as one undo step', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages[0].items[0].name = 'first_name';
  const t = boot({ form });
  t.click(t.q('[data-ffb-add="preset:full_name"]'));
  const state = t.q('[data-ffb-canvas]');
  assert.equal(t.ids().length, 5);
  const names = [];
  t.inspector.calls[t.inspector.calls.length - 1].state.form.schema.pages[0].items.forEach((i) => names.push(i.name));
  assert.equal(new Set(names.filter(Boolean)).size, names.filter(Boolean).length);
  assert.ok(names.includes('first_name_2'));
  assert.ok(names.includes('last_name'));
  assert.ok(state);
  t.click(t.q('[data-ffb-undo]'));
  assert.equal(t.ids().length, 3);
  t.w.close();
});

test('it inserts email confirm with a matches rule and the address preset as a section', () => {
  const t = boot({ fieldTypes: full(['country']) });
  t.click(t.q('[data-ffb-add="preset:email_confirm"]'));
  const items = t.inspector.calls[t.inspector.calls.length - 1].state.form.schema.pages[0].items;
  const confirm = items[items.length - 1];
  assert.deepEqual(confirm.rules[0].rule, 'matches');
  assert.equal(confirm.rules[0].value, items[items.length - 2].name);
  t.click(t.q('[data-ffb-add="preset:address"]'));
  const last = t.inspector.calls[t.inspector.calls.length - 1].state.form.schema.pages[0].items.pop();
  assert.equal(last.kind, 'section');
  assert.equal(last.items.length, 4);
  t.w.close();
});

test('it hides section presets from the picker inside a section', () => {
  const t = boot({ fieldTypes: full(['country']) });
  t.click(t.q('[data-ffb-add-field="s_cccc33"]'));
  assert.equal(t.q('[data-ffb-pick="preset:address"]'), null);
  assert.ok(t.q('[data-ffb-pick="preset:full_name"]'));
  t.w.close();
});

test('it moves an item to another page with the move to dialog', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add="page"]'));
  const second = t.qa('[data-ffb-tab]')[1].getAttribute('data-ffb-tab');
  t.click(t.qa('[data-ffb-tab]')[0]);
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="moveto"]'));
  const select = t.q('[data-ffb-moveto-select]');
  assert.ok(select.options.length >= 3);
  select.value = String(Array.from(select.options).findIndex((o) => o.textContent === 'Page 2'));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(t.q('[data-ffb-tab="' + second + '"]').getAttribute('aria-selected'), 'true');
  assert.ok(t.q('[data-ffb-card="f_aaaa11"]'));
  t.w.close();
});

test('it moves an item into a section with the move to dialog', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="moveto"]'));
  const select = t.q('[data-ffb-moveto-select]');
  select.value = String(Array.from(select.options).findIndex((o) => /Sec$/.test(o.textContent)));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(t.qa('[data-ffb-card="s_cccc33"] [data-ffb-card="f_aaaa11"]').length, 1);
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-act="moveto"]'));
  const again = t.q('[data-ffb-moveto-select]');
  assert.match(again.options[again.selectedIndex].textContent, /Sec$/);
  t.w.close();
});

test('it moves an item to a page by dropping it on the page tab', () => {
  const t = boot();
  t.click(t.q('[data-ffb-add="page"]'));
  const tabs = t.qa('[data-ffb-tab]');
  t.click(tabs[0]);
  const dt = { data: {}, setData(k, v) { this.data[k] = v; }, effectAllowed: '' };
  const fire = (node, type) => {
    const e = new t.w.Event(type, { bubbles: true, cancelable: true });
    e.dataTransfer = dt;
    node.dispatchEvent(e);
  };
  fire(t.q('[data-ffb-card="f_aaaa11"]'), 'dragstart');
  const secondTab = t.qa('[data-ffb-tab]')[1];
  fire(secondTab, 'dragover');
  assert.ok(secondTab.classList.contains('ffb-admin-drop-active'));
  fire(secondTab, 'drop');
  assert.equal(t.q('[data-ffb-card="f_aaaa11"]').closest('[data-ffb-list]').getAttribute('data-ffb-list'), secondTab.getAttribute('data-ffb-tab'));
  assert.equal(t.q('[data-ffb-tab][aria-selected="true"]').getAttribute('data-ffb-tab'), secondTab.getAttribute('data-ffb-tab'));
  t.w.close();
});

test('it badges fields that have validation rules', () => {
  const t = boot();
  assert.equal(t.q('[data-ffb-card="f_aaaa11"] .ffb-admin-badge-rules'), null);
  const withRules = JSON.parse(JSON.stringify(config.form));
  withRules.schema.pages[0].items[0].rules = [{ rule: 'preset', value: 'uk_postcode', message: '' }, { rule: 'min_length', value: 2, message: '' }];
  const r = boot({ form: withRules });
  assert.equal(r.q('[data-ffb-card="f_aaaa11"] .ffb-admin-badge-rules').textContent, '2 rules');
  t.w.close();
  r.w.close();
});

test('it shows an unknown-type field in the builder as unavailable and read-only', () => {
  const t0 = boot();
  const schema = JSON.parse(JSON.stringify(t0.cfg.form.schema));
  t0.w.close();
  schema.pages[0].items.splice(1, 0, { id: 'f_gone01', kind: 'field', type: 'gone', name: 'extra', label: 'Extra', required: true, rules: [], options: [], settings: {}, condition: null });
  const t = boot({ form: Object.assign({}, config.form, { schema }) });
  const card = t.q('[data-ffb-card="f_gone01"]');
  assert.ok(card.classList.contains('ffb-admin-card-unavailable'));
  assert.match(card.textContent, /unavailable/i);
  assert.equal(card.querySelector('[data-ffb-select]').disabled, true);
  assert.equal(card.getAttribute('draggable'), 'true');
  assert.ok(card.querySelector('[data-ffb-act="up"]'));
  assert.ok(card.querySelector('[data-ffb-act="del"]'));
  assert.equal(card.querySelector('[data-ffb-act="dup"]'), null);
  assert.equal(card.querySelector('[data-ffb-act="width"]'), null);
  t.click(card.querySelector('[data-ffb-act="del"]'));
  assert.equal(t.q('[data-ffb-card="f_gone01"]'), null);
  t.w.close();
});

const itemsOf = (t, sectionId) => {
  const page = t.inspector.calls[t.inspector.calls.length - 1].state.form.schema.pages[0];
  return (sectionId ? page.items.find((i) => i.id === sectionId).items : page.items).map((i) => i.type || i.kind);
};

test('it keeps the order of several items added inside a section', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="s_cccc33"] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="field:text"]'));
  t.click(t.q('[data-ffb-add="field:email"]'));
  t.click(t.q('[data-ffb-add="field:select"]'));
  assert.deepEqual(itemsOf(t, 's_cccc33'), ['text', 'email', 'select']);
  t.click(t.q('[data-ffb-card="s_cccc33"] [data-ffb-card] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="field:file"]'));
  assert.deepEqual(itemsOf(t, 's_cccc33'), ['text', 'file', 'email', 'select'], 'it inserts after the selected child');
  t.w.close();
});

test('it inserts after a selected top-level item', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="field:select"]'));
  t.click(t.q('[data-ffb-add="field:file"]'));
  assert.deepEqual(itemsOf(t, null), ['text', 'select', 'file', 'email', 'section']);
  t.w.close();
});

test('it inserts a preset after the selected item in a section', () => {
  const t = boot();
  t.click(t.q('[data-ffb-card="s_cccc33"] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="field:select"]'));
  t.click(t.q('[data-ffb-add="field:file"]'));
  t.click(t.q('[data-ffb-card="s_cccc33"] [data-ffb-card] [data-ffb-select]'));
  t.click(t.q('[data-ffb-add="preset:full_name"]'));
  assert.deepEqual(itemsOf(t, 's_cccc33'), ['select', 'text', 'text', 'file']);
  t.w.close();
});

test('it names the removed skip ahead when a skip target page is deleted', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages[0].skip = { target: 'p_bbbb22', condition: null };
  form.schema.pages.push({ id: 'p_bbbb22', title: 'Two', condition: null, items: [] });
  const t = boot({ form });
  t.click(t.q('[data-ffb-tab="p_bbbb22"]'));
  t.click(t.q('[data-ffb-page-delete]'));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.equal(t.q('[data-ffb-notice]').textContent, 'Removed the skip ahead to the deleted page.');
  t.w.close();
});

test('it names the removed field references when a page with referenced fields is deleted', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages.push({ id: 'p_bbbb22', title: 'Two', condition: null, items: [
    { id: 'f_cccc44', kind: 'field', type: 'text', name: 'later', label: 'Later', placeholder: '', help: '', default: '', width: 'full', required: false, rules: [], options: [], settings: {}, draft: true, autocomplete: '', css_class: '',
      condition: null },
  ] });
  form.schema.pages[0].items[0].condition = null;
  form.schema.pages.push({ id: 'p_cccc33', title: 'Three', condition: null, items: [
    { id: 'f_dddd55', kind: 'field', type: 'text', name: 'last', label: 'Last', placeholder: '', help: '', default: '', width: 'full', required: false, rules: [], options: [], settings: {}, draft: true, autocomplete: '', css_class: '',
      condition: { actions: [{ action: 'show' }], match: 'all', rules: [{ field: 'later', operator: 'equals', value: 'x' }] } },
  ] });
  const t = boot({ form });
  t.click(t.q('[data-ffb-tab="p_bbbb22"]'));
  t.click(t.q('[data-ffb-page-delete]'));
  t.click(t.q('[data-ffb-dialog-confirm]'));
  assert.match(t.q('[data-ffb-notice]').textContent, /^Removed conditions and rules that used fields on the deleted page\./);
  t.w.close();
});

const readOnlyInspector = {
  render(container) {
    container.textContent = '';
    ['input', 'select', 'textarea'].forEach((tag) => container.appendChild(container.ownerDocument.createElement(tag)));
    const tab = container.ownerDocument.createElement('button');
    tab.setAttribute('role', 'tab');
    const back = container.ownerDocument.createElement('button');
    back.setAttribute('data-ffb-input', 'form-settings');
    const del = container.ownerDocument.createElement('button');
    del.setAttribute('data-ffb-input', 'delete');
    [tab, back, del].forEach((n) => container.appendChild(n));
  },
};

test('without save rights the builder is read-only but selection and the inspector stay viewable', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages.push({ id: 'p_bbbb22', title: 'Two', condition: null, items: [] });
  const t = boot({ form, permissions: { save: false } });
  t.w.FuranFormBuilder.builderInspector = readOnlyInspector;
  assert.equal(t.q('[data-ffb-palette]').hidden, true);
  assert.ok(t.q('.ffb-admin-builder').classList.contains('is-readonly'));
  assert.equal(t.q('[data-ffb-undo]'), null);
  assert.equal(t.q('[data-ffb-redo]'), null);
  assert.equal(t.qa('.ffb-admin-card-actions').length, 0);
  assert.equal(t.q('[data-ffb-add-field]'), null);
  assert.equal(t.q('[data-ffb-page-add]'), null);
  assert.equal(t.q('[data-ffb-page-delete]'), null);
  assert.equal(t.q('[data-ffb-page-left]'), null);
  assert.equal(t.q('[data-ffb-page-right]'), null);
  assert.equal(t.q('[data-ffb-page-title]').disabled, true);
  assert.equal(t.qa('[data-ffb-card][draggable]').length, 0);

  t.click(t.q('[data-ffb-card="f_bbbb22"] [data-ffb-select]'));
  assert.ok(t.q('[data-ffb-card="f_bbbb22"]').classList.contains('is-selected'));
  const box = t.q('[data-ffb-inspector]');
  box.querySelectorAll('input, select, textarea, [data-ffb-input="delete"]').forEach((n) => assert.equal(n.disabled, true));
  assert.equal(box.querySelector('[role="tab"]').disabled, false);
  assert.equal(box.querySelector('[data-ffb-input="form-settings"]').disabled, false);

  const key = new t.w.KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true });
  t.doc.dispatchEvent(key);
  assert.equal(key.defaultPrevented, false);
  t.click(t.q('[data-ffb-tab="p_bbbb22"]'));
  assert.equal(t.q('[data-ffb-tab="p_bbbb22"]').getAttribute('aria-selected'), 'true');
  t.w.close();
});

test('without save rights a drop on the canvas changes nothing', () => {
  const t = boot({ permissions: { save: false } });
  t.q('[data-ffb-card="f_bbbb22"]').dispatchEvent(new t.w.Event('dragstart', { bubbles: true }));
  const list = t.q('[data-ffb-list="p_aaaa11"]');
  const drop = new t.w.Event('drop', { bubbles: true, cancelable: true });
  list.dispatchEvent(drop);
  assert.equal(drop.defaultPrevented, false);
  assert.deepEqual(t.ids(), ['f_aaaa11', 'f_bbbb22', 's_cccc33']);
  t.w.close();
});

test('a role that may delete but not edit still gets the delete button', () => {
  const t = boot({ permissions: { save: false }, urls: Object.assign({}, config.urls, { delete: '/delete' }) });
  assert.ok(t.q('[data-ffb-delete]'));
  assert.equal(t.q('[data-ffb-save]'), null);
  t.w.close();
});

test('it keeps the toolbar actions in one group so a narrow screen wraps them together with both saves side by side', () => {
  const t = boot();
  const actions = t.q('.ffb-admin-toolbar > .ffb-admin-toolbar-actions');
  assert.ok(actions);
  assert.ok(actions.contains(t.q('[data-ffb-undo]')));
  assert.equal(t.q('[data-ffb-save]').parentElement, t.q('[data-ffb-save-continue]').parentElement);
  assert.ok(t.q('[data-ffb-save]').parentElement.classList.contains('ffb-admin-toolbar-save'));
  t.w.close();
});

test('it previews the page title above the page content like the storefront', () => {
  const t = boot();
  const heading = t.q('.ffb-admin-tabpanel > .ffb-admin-page-heading');
  assert.equal(heading.textContent, 'One');
  assert.equal(heading.getAttribute('aria-hidden'), 'true');
  assert.equal(heading.nextElementSibling, t.q('[data-ffb-list="p_aaaa11"]'));
  assert.equal(t.qa('[data-ffb-page-title]').length, 1);
  t.w.close();
});

test('it shows no page title preview for an untitled page', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages[0].title = '';
  const t = boot({ form });
  assert.equal(t.q('.ffb-admin-page-heading'), null);
  t.w.close();
});

test('it saves a newsletter field without an email field on the first click', () => {
  const form = JSON.parse(JSON.stringify(config.form));
  form.schema.pages[0].items.push({ id: 'f_dddd44', kind: 'field', type: 'newsletter', name: 'news', label: 'News', placeholder: '', help: '', default: '', width: 'full', required: false, rules: [], options: [], settings: { email_field: '' }, condition: null, draft: false, autocomplete: '', css_class: '' });
  const newsletter = { code: 'newsletter', label: 'Newsletter', category: 'choice', allowedRules: [], hasOptions: false, isFile: false, isMultiValue: false,
    settingsSpec: { email_field: { type: 'string', default: '', format: 'email_field' } } };
  const t = boot({ form, fieldTypes: config.fieldTypes.concat([newsletter]) });
  t.click(t.q('[data-ffb-save]'));
  assert.equal(t.submitted.length, 1);
  assert.equal(t.q('[data-ffb-summary]').hidden, true);
  t.w.close();
});
