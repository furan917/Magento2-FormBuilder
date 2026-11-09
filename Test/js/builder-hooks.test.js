'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');

const jsDir = path.resolve(__dirname, '../../view/adminhtml/web/js');
const src = (name) => fs.readFileSync(path.join(jsDir, name), 'utf8');
const model = require(path.join(jsDir, 'builder-model.js'));
const inspector = require(path.join(jsDir, 'builder-inspector.js'));
const nodeRegistry = require(path.join(jsDir, 'builder-registry.js'));
const SCRIPTS = ['builder-registry.js', 'builder-i18n.js', 'builder-model.js', 'builder-preview.js', 'builder-presets.js', 'builder-inspector.js', 'builder.js'];

const baseConfig = {
  fieldTypes: [
    { code: 'text', label: 'Text', category: 'input', allowedRules: [], hasOptions: false, settingsSpec: {
      max_length: { type: 'int', default: 5 },
      nice: { type: 'string', default: '', label: 'Nice label' },
      tone: { type: 'acme_tone', default: 'warm' },
    } },
    { code: 'acme_stars', label: 'Stars', category: 'advanced', allowedRules: [], hasOptions: false, settingsSpec: {} },
  ],
  rules: [], operators: [], widths: [{ value: 'full', label: 'Full' }], autocomplete: [], stores: [{ value: 0, label: 'All' }],
  uploads: { allowedExtensions: [], maxMb: 5 }, urls: { save: '/s', back: '/b', preview: null }, errors: [],
};

function mountField(cfg) {
  let state = model.createState({
    id: null, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [] }] },
    settings: {},
  });
  const f = Object.assign(model.newField('text', cfg, state), { label: 'A', name: 'a' });
  state = model.select(model.addItem(state, f, { pageId: 'p_aaaa11', sectionId: null, index: 0 }), f.id);
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  inspector.render(container, state, cfg, { apply: (...a) => calls.push(a), select() {}, notify() {} });
  return { container, calls, dom };
}

const labelOf = (container, key) => container.querySelector('[data-ffb-input="' + key + '"]').closest('label').firstChild.textContent;

test('it shows the translated label from a setting spec in the inspector', () => {
  const { container } = mountField(baseConfig);
  assert.equal(labelOf(container, 'setting-nice'), 'Nice label');
});

test('it falls back to a humanised key when a spec has no label', () => {
  const { container } = mountField(baseConfig);
  assert.equal(labelOf(container, 'setting-max_length'), 'Max length');
});

test('it renders a custom inspector control for a registered setting type', () => {
  const seen = [];
  nodeRegistry.inspectorControl('acme_tone', (spec, value, onChange) => {
    seen.push([spec.type, value]);
    const doc = new JSDOM('').window.document;
    const b = doc.createElement('button');
    b.setAttribute('data-custom', 'tone');
    b.addEventListener('click', () => onChange('cold'));
    return b;
  });
  const { container, calls, dom } = mountField(baseConfig);
  const custom = container.querySelector('[data-custom="tone"]');
  assert.ok(custom);
  assert.deepEqual(seen[0], ['acme_tone', 'warm']);
  custom.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  assert.equal(calls[0][0], 'updateItem');
  assert.equal(calls[0][2].settings.tone, 'cold');
  assert.throws(() => nodeRegistry.inspectorControl('int', () => null));
  assert.throws(() => nodeRegistry.preview('text', () => null));
  assert.throws(() => nodeRegistry.preview('Bad Code', () => null));
});

function bootBuilder(cfg, before, opts) {
  const o = opts || {};
  const html = '<form id="ffb-admin-form" method="post" action="/s"><input type="hidden" name="form_key" value="k">'
    + ['form_id', 'identifier', 'title', 'is_active', 'store_ids', 'schema', 'settings', 'back'].map((n) => '<input type="hidden" name="' + n + '" value="">').join('')
    + '<div id="ffb-builder" data-ffb-builder></div></form><script type="application/json" id="ffb-builder-config"></script>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://admin.test/edit' });
  const w = dom.window;
  if (o.readyState) {
    Object.defineProperty(w.document, 'readyState', { value: o.readyState, configurable: true });
  }
  const full = Object.assign(JSON.parse(JSON.stringify(cfg)), {
    form: {
      id: 1, identifier: 'c', title: 'C', is_active: true, store_ids: [0],
      schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [
        { id: 'f_aaaa11', kind: 'field', type: 'acme_stars', name: 'rate', label: 'Rate', placeholder: '', help: '', default: '', width: 'full', required: false, rules: [], options: [], settings: {}, condition: null, draft: true, autocomplete: '', css_class: '' },
      ] }] },
      settings: {},
    },
  });
  w.document.getElementById('ffb-builder-config').textContent = JSON.stringify(full);
  w.structuredClone = structuredClone;
  const run = (names) => names.forEach((n) => w.eval(src(n)));
  if (before) {
    run(['builder-registry.js']);
    before(w);
    run(SCRIPTS.slice(1));
  } else {
    run(SCRIPTS);
  }
  if (!o.readyState) {
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  }
  return w;
}

const stars = (w) => {
  const s = w.document.createElement('div');
  s.className = 'acme-stars';
  s.textContent = '*****';
  return s;
};

test('it renders a field preview with a registered preview hook', () => {
  const w = bootBuilder(baseConfig, (win) => {
    win.FuranFormBuilder.builderRegistry.preview('acme_stars', () => stars(win));
  });
  assert.ok(w.document.querySelector('[data-ffb-card="f_aaaa11"] .acme-stars'));
  w.close();
});

test('it keeps registrations made before builder js loaded', () => {
  let reg;
  const w = bootBuilder(baseConfig, (win) => {
    reg = win.FuranFormBuilder.builderRegistry;
    reg.preview('acme_stars', () => stars(win));
  });
  assert.equal(w.FuranFormBuilder.builderRegistry, reg);
  assert.ok(w.document.querySelector('.acme-stars'));
  w.close();
});

test('it falls back to the generic control when a hook throws', () => {
  const w = bootBuilder(baseConfig, (win) => {
    win.FuranFormBuilder.builderRegistry.preview('acme_stars', () => { throw new Error('boom'); });
  });
  const card = w.document.querySelector('[data-ffb-card="f_aaaa11"]');
  assert.ok(card);
  assert.ok(card.querySelector('.ffb-admin-mock'));
  w.close();
  nodeRegistry.inspectorControl('acme_boom', () => { throw new Error('boom'); });
  const cfg = JSON.parse(JSON.stringify(baseConfig));
  cfg.fieldTypes[0].settingsSpec = { tone: { type: 'acme_boom', default: 'x' } };
  const { container } = mountField(cfg);
  assert.ok(container.querySelector('[data-ffb-input="setting-tone"]'));
});

test('it boots the builder after scripts added to the edit page layout have run', () => {
  const w = bootBuilder(baseConfig, null, { readyState: 'interactive' });
  assert.equal(w.document.querySelector('.ffb-admin-toolbar'), null);
  w.FuranFormBuilder.builderRegistry.preview('acme_stars', () => stars(w));
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  assert.ok(w.document.querySelector('[data-ffb-card="f_aaaa11"] .acme-stars'));
  w.close();
  const late = bootBuilder(baseConfig, null, { readyState: 'complete' });
  assert.ok(late.document.querySelector('.ffb-admin-toolbar'));
  late.close();
});

test('it keeps registrations queued by scripts that loaded before the registry', () => {
  const html = '<div></div>';
  const w = new JSDOM(html, { runScripts: 'outside-only' }).window;
  w.eval("window.FuranFormBuilder = {builderRegistry: {queue: [['preview', 'acme_q', function () {}], ['preview', 'text', function () {}]]}};");
  w.eval(src('builder-registry.js'));
  assert.equal(typeof w.FuranFormBuilder.builderRegistry.getPreview('acme_q'), 'function');
  assert.equal(w.FuranFormBuilder.builderRegistry.getPreview('text'), null);
  w.close();
});
