'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const { source: readSource } = require('./helpers/load-storefront');
const source = readSource('ffb-core.js');

// Fresh context per test so registrations never leak between tests.
const load = (seed) => {
  const warnings = [];
  const sandbox = { FuranFormBuilder: seed || {}, console: { warn: (m) => warnings.push(String(m)) } };
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  return { ns: sandbox.FuranFormBuilder, core: sandbox.FuranFormBuilder.core, registry: sandbox.FuranFormBuilder.registry, warnings };
};

const field = (extra) => Object.assign({ name: 'f', type: 'text', required: false, rules: [], options: [], settings: {} }, extra);

test('it validates a value with a registered custom rule', () => {
  const { core, registry } = load();
  registry.rule('even_length', (value) => typeof value === 'string' && value.length % 2 === 0, (ruleValue) => 'Need even length ' + ruleValue);
  const f = field({ rules: [{ rule: 'even_length', value: 'x', message: '' }] });
  assert.equal(core.validateField(f, 'ab', {}), null);
  assert.equal(core.validateField(f, 'abc', {}), 'Need even length x');
  f.rules[0].message = 'Admin text';
  assert.equal(core.validateField(f, 'abc', {}), 'Admin text');
  assert.equal(core.validateRule('even_length', 'ab', null, {}), true);
});

test('it evaluates a condition with a registered custom operator', () => {
  const { core, registry } = load();
  registry.operator('longer_than', (actual, expected) => String(actual).length > Number(expected));
  const condition = { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'longer_than', value: '3' }] };
  assert.equal(core.evaluateCondition(condition, { a: 'abcd' }), true);
  assert.equal(core.evaluateCondition(condition, { a: 'abc' }), false);
});

test('it normalizes and validates a registered custom field type', () => {
  const { core, registry } = load();
  registry.fieldType('pin', {
    normalize: (value) => String(value).replace(/\D/g, ''),
    intrinsicDefault: (value) => (/^\d{4}$/.test(value) ? null : 'Four digits please.'),
  });
  registry.fieldType('pin', { validate: (value) => (value === '0000' ? 'Not that one.' : null) });
  const f = field({ type: 'pin' });
  assert.equal(core.normalizeValue(f, '12-34'), '1234');
  assert.equal(core.validateField(f, '12-34', {}), null);
  assert.equal(core.validateField(f, '12', {}), 'Four digits please.');
  assert.equal(core.validateField(f, '0000', {}), 'Not that one.');
});

test('it refuses to register a code that is built in', () => {
  const { registry } = load();
  assert.throws(() => registry.rule('min_length', () => true));
  assert.throws(() => registry.operator('equals', () => true));
  assert.throws(() => registry.fieldType('text', {}));
  assert.throws(() => registry.fieldType('address', {}));
});

test('it accepts registrations made before ffb-core loaded', () => {
  const queue = [
    ['rule', 'always_no', () => false, () => 'Nope'],
    ['operator', 'always', () => true],
    ['fieldType', 'shout', { normalize: (value) => String(value).toUpperCase() }],
  ];
  const pending = { queue };
  const { core, registry, ns } = load({ registry: pending });
  assert.equal(ns.registry, pending);
  assert.equal(registry.rule, pending.rule);
  assert.equal(core.validateField(field({ rules: [{ rule: 'always_no', value: '' }] }), 'x', {}), 'Nope');
  assert.equal(core.evaluateCondition({ action: 'show', match: 'all', rules: [{ field: 'a', operator: 'always', value: '' }] }, {}), true);
  assert.equal(core.normalizeValue(field({ type: 'shout' }), 'hi'), 'HI');
});

test('it passes an unregistered unknown rule in the browser so the server decides', () => {
  const { core } = load();
  assert.equal(core.validateField(field({ rules: [{ rule: 'not_installed', value: '1' }] }), 'x', {}), null);
  assert.equal(core.validateRule('__proto__', 'x', null, {}), true);
  assert.equal(core.validateRule('constructor', 'x', null, {}), true);
});

test('it passes the raw values and the source field to a custom operator', () => {
  const { core, registry, warnings } = load();
  const calls = [];
  registry.operator('spy', (actual, expected, source) => { calls.push([actual, expected, source]); return true; });
  const src = field({ name: 'a', id: 'f_aaaa', kind: 'field', type: 'checkboxes' });
  const hidden = field({ name: 'b', id: 'f_bbbb', kind: 'field' });
  const schema = { pages: [{ id: 'p_aaaa', items: [
    src,
    { id: 's_aaaa', kind: 'section', items: [hidden] },
    Object.assign(field({ name: 'c', id: 'f_cccc', kind: 'field' }), { condition: { action: 'show', match: 'all', rules: [
      { field: 'a', operator: 'spy', value: ' Mixed ' },
      { field: 'b', operator: 'spy', value: 'x' },
      { field: 'nope', operator: 'spy' },
    ] } }),
  ] }] };
  core.computeVisibility(schema, { a: ['One', ' Two '], b: 'v' });
  assert.deepEqual(calls[0], [['One', ' Two '], ' Mixed ', src]);
  assert.equal(calls[1][2], hidden);
  assert.deepEqual(calls[2], ['', '', null]);
  const cond = { action: 'show', match: 'all', rules: [{ field: 'a', operator: 'nobody', value: '1' }] };
  assert.equal(core.evaluateCondition(cond, {}), false);
  core.evaluateCondition(cond, {});
  assert.equal(warnings.length, 1);
});

test('it rejects registry codes that are not plain identifiers such as __proto__', () => {
  const { registry } = load();
  for (const code of ['__proto__', 'Upper', '1abc', '', 'a b', 'x'.repeat(65)]) {
    assert.throws(() => registry.rule(code, () => true), undefined, code);
    assert.throws(() => registry.operator(code, () => true), undefined, code);
    assert.throws(() => registry.fieldType(code, {}), undefined, code);
  }
  assert.doesNotThrow(() => registry.rule('vendor.my-rule_1', () => true));
});
