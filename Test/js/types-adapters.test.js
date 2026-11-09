'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront, source } = require('./helpers/load-storefront');

const STRUCTURED = ['matrix', 'list', 'date_range', 'address', 'map'];

const boot = () => {
  const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  loadStorefront(dom.window);
  return dom.window.FuranFormBuilder;
};

test('it reads and writes structured built-in types through registered adapters', () => {
  const ns = boot();
  for (const code of STRUCTURED) {
    const hooks = ns.core.fieldHooks(code);
    assert.equal(typeof hooks.read, 'function', code + ' read');
    assert.equal(typeof hooks.write, 'function', code + ' write');
    assert.equal(typeof hooks.summary, 'function', code + ' summary');
  }
  assert.equal(ns.core.fieldHooks('text'), null);
});

test('it keeps type checks by code out of ffb-form.js', () => {
  assert.doesNotMatch(source('ffb-form.js'), /\b(isMatrix|isList|isMap|isAddress|isDateRange)\b/);
});

test('it still refuses a third-party registration for a built-in code', () => {
  const ns = boot();
  const before = ns.core.fieldHooks('matrix');
  for (const code of STRUCTURED) {
    assert.throws(() => ns.registry.fieldType(code, { read: () => 'x' }), /built in/);
  }
  assert.throws(() => ns.core.registerBuiltinFieldType('matrix', { read: () => 'x' }), /already/);
  assert.throws(() => ns.core.registerBuiltinFieldType('satisfaction', {}), /not a built-in/);
  assert.equal(ns.core.fieldHooks('matrix'), before);
  assert.equal(Object.keys(ns.core).includes('registerBuiltinFieldType'), false);
});
