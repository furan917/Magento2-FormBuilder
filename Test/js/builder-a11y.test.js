'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const read = (f) => fs.readFileSync(path.join(jsDir, f), 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/css/builder.css'), 'utf8');
const sources = ['builder-model.js', 'builder-preview.js', 'builder-presets.js', 'builder-inspector.js'].map(read);
const builderSrc = read('builder.js');

const field = (id, name, extra) => Object.assign({
  id, kind: 'field', type: 'text', name, label: name, placeholder: '', help: '', default: '', width: 'full', required: false,
  rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '',
}, extra || {});

const rule = (f, v) => ({ field: f, operator: 'equals', value: v });

const config = {
  form: {
    id: 7, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [
      field('f_aaaa11', 'first'),
      field('f_bbbb22', 'second'),
      field('f_cccc33', 'third', { condition: { actions: [{ action: 'show' }], match: 'all', rules: [rule('first', 'a'), rule('second', 'b')] } }),
      field('f_dddd44', 'fourth', { condition: { actions: [{ action: 'show' }], match: 'all', rules: [
        { match: 'all', rules: [rule('first', 'a')] }, { match: 'any', rules: [rule('second', 'b')] },
      ] } }),
    ] }] },
    settings: { submit_label: 'Submit' },
    translations: {},
  },
  fieldTypes: [{ code: 'text', label: 'Text', category: 'input', allowedRules: [], settingsSpec: {}, hasOptions: false, isFile: false, isMultiValue: false, relativeDate: null }],
  contentTypes: [], rules: [],
  operators: [{ code: 'equals', label: 'equals', needsValue: true, types: ['text'] }],
  actions: [{ code: 'show', label: 'Show', targets: ['page', 'section', 'field', 'content'], param: null, types: ['text'] }],
  conditionLimits: { maxLevels: 3, maxEntries: 25, maxRelativeDays: 3650, maxActions: 10 },
  widths: [{ value: 'full', label: 'Full' }], autocomplete: [], stores: [{ value: 0, label: 'All' }],
  uploads: { allowedExtensions: ['pdf'], maxMb: 5 },
  urls: { save: '/save', back: '/back', preview: null },
  fieldGroups: { list: '/groups/palette', insert: '/groups/insert' },
  permissions: { save: true, field_groups: true },
  errors: [],
};

async function boot(responses, mutate) {
  const cfg = JSON.parse(JSON.stringify(config));
  if (mutate) {
    mutate(cfg);
  }
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
    requests.push({ url, opts });
    const reply = (responses || {})[url];
    const [status, body] = typeof reply === 'function' ? reply() : (reply || [404, { success: false, errors: ['no'] }]);
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
  };
  sources.forEach((src) => w.eval(src));
  w.eval(builderSrc);
  w.document.getElementById('ffb-admin-form').submit = () => {};
  w.FuranFormBuilder.builder.init(w.document.getElementById('ffb-builder'));
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const qa = (s) => Array.from(doc.querySelectorAll(s));
  const click = (node) => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  const key = (node, k, extra) => node.dispatchEvent(new w.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, extra || {})));
  const settle = async () => { for (let i = 0; i < 5; i++) { await new Promise((r) => w.setTimeout(r, 0)); } };
  const input = (k) => q('[data-ffb-inspector] [data-ffb-input="' + k + '"]');
  return { w, doc, q, qa, click, key, settle, requests, input };
}

test('it hides inspector controls marked hidden', () => {
  assert.match(css, /\.ffb-admin-builder \[hidden\]\s*\{\s*display:\s*none;?\s*\}/);
  assert.doesNotMatch(css.match(/\.ffb-admin-builder \[hidden\][^}]*\}/)[0], /!important/);
});

test('it keeps keyboard focus in the condition editor after removing a rule', async () => {
  const t = await boot();
  t.click(t.q('[data-ffb-card="f_cccc33"] [data-ffb-select]'));
  t.click(t.q('[data-tab="conditions"]'));
  const remove = t.input('cond-1-remove');
  assert.ok(remove);
  remove.focus();
  t.click(remove);
  assert.equal(t.input('cond-1-remove'), null, 'the rule is gone');
  assert.equal(t.doc.activeElement, t.input('cond-add-rule'), 'focus lands on the Add rule button');
  t.w.close();
});

test('it keeps focus inside the insert group dialog after loading more', async () => {
  const groups = (ids, next) => [200, { success: true, groups: ids.map((id) => ({ id, name: 'G' + id, description: '' })), next }];
  const t = await boot({ '/groups/palette': () => (t.requests.length > 1 ? groups([3, 4], 0) : groups([1, 2], 2)) });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  const more = t.q('[data-ffb-groups-more]');
  more.focus();
  t.click(more);
  await t.settle();
  assert.equal(t.q('[data-ffb-groups-more]'), null);
  const box = t.q('[data-ffb-dialog]');
  assert.ok(box.contains(t.doc.activeElement), 'focus stays inside the dialog');
  assert.equal(t.doc.activeElement.getAttribute('data-ffb-group-id'), '3', 'the first new item is focused');

  t.q('[data-ffb-card="f_aaaa11"] [data-ffb-select]').focus();
  t.key(t.doc, 'Tab');
  assert.ok(box.contains(t.doc.activeElement), 'Tab pulls focus back inside');
  t.w.close();
});

test('it inserts a group only once on a double click', async () => {
  const t = await boot({ '/groups/palette': [200, { success: true, groups: [{ id: 1, name: 'G1', description: '' }], next: 0 }],
    '/groups/insert': [200, { success: true, name: 'G1', fields: [field('f_zzzz99', 'zed')], translations: {} }] });
  t.click(t.q('[data-ffb-insert-group]'));
  await t.settle();
  const button = t.q('[data-ffb-group-id="1"]');
  t.click(button);
  t.click(button);
  await t.settle();
  assert.equal(t.requests.filter((r) => r.url === '/groups/insert').length, 1);
  assert.equal(t.qa('[data-ffb-card]').filter((n) => /zed/.test(n.textContent)).length, 1);
  t.w.close();
});

test('it labels each nested group remove button', async () => {
  const t = await boot();
  t.click(t.q('[data-ffb-card="f_dddd44"] [data-ffb-select]'));
  t.click(t.q('[data-tab="conditions"]'));
  assert.equal(t.input('cond-g0-remove').getAttribute('aria-label'), 'Remove group 1');
  assert.equal(t.input('cond-g1-remove').getAttribute('aria-label'), 'Remove group 2');
  t.w.close();
});

test('it styles the schedule datetime inputs like other inspector inputs', () => {
  assert.match(css, /\.ffb-admin-inspector input\[type="datetime-local"\]/);
  assert.match(css, /\.ffb-admin-inspector input\[type="datetime-local"\]:focus/);
});

const withActions = (cfg) => {
  cfg.actions.push({ code: 'require', label: 'Require', targets: ['field'], param: null, types: ['text'] });
  const items = cfg.form.schema.pages[0].items;
  items.push(field('f_solo001', 'solo'));
  items.push(field('f_eeee55', 'fifth', { condition: { actions: [{ action: 'show' }, { action: 'require' }], match: 'all', rules: [rule('solo', 'x')] } }));
  items.push(field('f_ffff66', 'sixth', { condition: { actions: [{ action: 'frobnicate' }], match: 'all', rules: [rule('solo', 'x')] } }));
};

const maskable = (cfg) => {
  cfg.fieldTypes[0].settingsSpec = { mask: { type: 'string', format: 'mask', default: '', max_length: 40 } };
  cfg.masks = [{ code: 'uk_postcode', label: 'UK postcode' }];
  cfg.ruleLibrary = [];
};

test('it shows the insert a field group button as a full width cell with an icon', async () => {
  const t = await boot();
  const button = t.q('[data-ffb-insert-group]');
  assert.ok(button.querySelector('svg.ffb-admin-icon'));
  assert.match(css, /\.ffb-admin-builder \.ffb-admin-palette-list > \[data-ffb-insert-group\]\s*\{[^}]*grid-column:\s*1 \/ -1/);
  t.w.close();
});

test('it scopes the insert group button styles under the builder', () => {
  assert.doesNotMatch(css, /(^|[,\n]\s*)\[data-ffb-insert-group\]/);
  assert.match(css, /\.ffb-admin-builder \[data-ffb-insert-group\]/);
});

test('it labels later action selects and names an unknown action readably', async () => {
  const t = await boot({}, withActions);
  t.click(t.q('[data-ffb-card="f_eeee55"] [data-ffb-select]'));
  t.click(t.q('[data-tab="conditions"]'));
  assert.equal(t.input('cond-action-0').getAttribute('aria-label'), null);
  assert.equal(t.input('cond-action-1').getAttribute('aria-label'), 'Action 2');
  t.click(t.q('[data-ffb-card="f_ffff66"] [data-ffb-select]'));
  const unknown = t.input('cond-action-0');
  assert.equal(unknown.options[0].textContent, 'Unknown action (frobnicate)');
  t.w.close();
});

test('it shows the condition limit hint once at the root', async () => {
  const t = await boot({}, (cfg) => { cfg.conditionLimits.maxEntries = 4; });
  t.click(t.q('[data-ffb-card="f_dddd44"] [data-ffb-select]'));
  t.click(t.q('[data-tab="conditions"]'));
  assert.equal(t.qa('[data-ffb-hint="cond-entry-limit"]').length, 1);
  assert.equal(t.q('[data-ffb-hint="cond-entry-limit"]').closest('[data-ffb-level]').getAttribute('data-ffb-level'), '1');
  t.w.close();
});

async function maskInspector(max) {
  const t = await boot({}, (cfg) => {
    maskable(cfg);
    if (max) {
      cfg.form.schema.pages[0].items[0].rules = [{ rule: 'max_length', value: max, message: '' }];
    }
  });
  t.click(t.q('[data-ffb-card="f_aaaa11"] [data-ffb-select]'));
  t.click(t.q('[data-tab="settings"]'));
  return t;
}

test('it focuses the pattern input after choosing a custom mask', async () => {
  const t = await maskInspector();
  const select = t.input('setting-mask');
  select.value = '__custom';
  select.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  assert.equal(t.doc.activeElement, t.input('setting-mask-custom'));
  t.w.close();
});

test('it keeps one persistent mask warning status node', async () => {
  const t = await maskInspector(3);
  const select = t.input('setting-mask');
  select.value = '__custom';
  select.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  const nodes = t.qa('[data-ffb-mask-warning]');
  assert.equal(nodes.length, 1);
  assert.equal(nodes[0].getAttribute('role'), 'status');
  assert.equal(nodes[0].textContent, '');
  const custom = t.input('setting-mask-custom');
  custom.value = '99-99-99';
  custom.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  assert.equal(t.qa('[data-ffb-mask-warning]')[0], nodes[0], 'same node');
  assert.match(nodes[0].textContent, /8 characters long.*3/);
  custom.value = '9';
  custom.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  assert.equal(nodes[0].textContent, '');
  t.w.close();
});

test('it words the mask hint without suggesting input is rewritten while typing', async () => {
  const t = await maskInspector();
  const text = t.q('[data-ffb-inspector]').textContent;
  assert.match(text, /Answers are formatted when the shopper leaves the field\. Typing and pasting are never blocked\./);
  assert.doesNotMatch(text, /Formats on blur/);
  t.w.close();
});

test('it names nested groups consistently', async () => {
  const t = await boot();
  t.click(t.q('[data-ffb-card="f_dddd44"] [data-ffb-select]'));
  t.click(t.q('[data-tab="conditions"]'));
  assert.ok(t.qa('legend').some((n) => n.textContent === 'Nested group (level 2 of 3)'));
  t.w.close();
});

test('it uses singular and plural wording for removed references', async () => {
  const t = await boot({}, withActions);
  t.click(t.q('[data-ffb-card="f_solo001"] [data-ffb-select]'));
  t.click(t.input('delete'));
  assert.equal(t.q('[data-ffb-notice]').textContent, '2 condition or rule references to removed fields were also removed.');
  const one = await boot({}, (cfg) => {
    cfg.form.schema.pages[0].items.push(field('f_solo001', 'solo'));
    cfg.form.schema.pages[0].items.push(field('f_eeee55', 'fifth', { condition: { actions: [{ action: 'show' }], match: 'all', rules: [rule('solo', 'x')] } }));
  });
  one.click(one.q('[data-ffb-card="f_solo001"] [data-ffb-select]'));
  one.click(one.input('delete'));
  assert.equal(one.q('[data-ffb-notice]').textContent, '1 condition or rule reference to a removed field was also removed.');
  t.w.close();
  one.w.close();
});

test('it words the insert group dialog without an ellipsis and closes with Close', async () => {
  const t = await boot({ '/groups/palette': [200, { success: true, groups: [], next: 0 }] });
  t.click(t.q('[data-ffb-insert-group]'));
  assert.equal(t.q('[data-ffb-dialog] h2').textContent, 'Insert a field group');
  assert.equal(t.q('[data-ffb-dialog-cancel]').textContent, 'Close');
  t.w.close();
});
