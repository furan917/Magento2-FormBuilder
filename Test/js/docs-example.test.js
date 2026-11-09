'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const readme = fs.readFileSync(path.join(root, 'docs/extending.md'), 'utf8');
const { loadCore } = require('./helpers/load-storefront');
const builderRegistry = fs.readFileSync(path.join(root, 'view/adminhtml/web/js/builder-registry.js'), 'utf8');

const jsBlock = (needle) => {
  const blocks = [...readme.matchAll(/^```js\n([\s\S]*?)^```/gm)].map((m) => m[1]);
  const block = blocks.find((b) => b.includes(needle));
  assert.ok(block, 'docs/extending.md has no js block containing ' + needle);
  return block;
};

const field = { name: 'rate', type: 'satisfaction', required: true, rules: [], options: [], settings: { max: 5 } };

test('it runs the storefront script of the documented field type example', () => {
  const dom = new JSDOM('<div data-ffb-type="satisfaction"><input type="radio" name="fields[rate][score]" value="1">'
    + '<input type="radio" name="fields[rate][score]" value="4"><input type="text" name="fields[rate][comment]"></div>',
  { runScripts: 'outside-only' });
  const { window } = dom;
  loadCore(window);
  window.eval(jsBlock("registry.fieldType('satisfaction'"));
  const ffb = window.FuranFormBuilder;

  assert.equal(ffb.core.validateField(field, { score: '', comment: '' }, {}), 'This field is required.');
  assert.equal(ffb.core.validateField(field, { score: '9', comment: '' }, {}), 'Please choose a score.');
  assert.equal(ffb.core.validateField(field, { score: '4', comment: 'ok' }, {}), null);
  assert.equal(ffb.core.validateField({ ...field, required: false }, { score: '', comment: '' }, {}), null);

  const wrapper = window.document.querySelector('[data-ffb-type]');
  const hooks = ffb.core.fieldHooks('satisfaction');
  hooks.write(wrapper, { score: '4', comment: 'Fine' });
  assert.deepEqual(JSON.parse(JSON.stringify(hooks.read(wrapper))), { score: '4', comment: 'Fine' });
});

test('it runs the builder script of the documented field type example', () => {
  const { window } = new JSDOM('<div></div>', { runScripts: 'outside-only' });
  // The layout script runs before builder-registry.js, so the example queues its hook.
  window.eval(jsBlock('ns.builderRegistry = ns.builderRegistry'));
  window.eval(builderRegistry);
  const preview = window.FuranFormBuilder.builderRegistry.getPreview('satisfaction');
  assert.equal(typeof preview, 'function');
  const node = preview({ settings: { max: 4 } });
  assert.equal(node.querySelectorAll('span').length, 4);
});
