'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const { corePath, core } = require('./helpers/load-storefront');
const fixture = (name) => JSON.parse(fs.readFileSync(path.join(root, 'Test/fixtures', name), 'utf8'));

const textField = (extra) => Object.assign({
  name: 'f', type: 'text', required: false, required_message: 'Required!', rules: [], options: [], settings: {},
}, extra);

test('it passes every case in the shared rules fixture', () => {
  const cases = fixture('rules.json');
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.equal(
      core.validateRule(c.rule, c.value, c.ruleValue, c.values),
      c.expected,
      `${c.rule} ${JSON.stringify(c.ruleValue)} ${JSON.stringify(c.value)}`
    );
  }
});

test('it passes all shared condition fixtures', () => {
  const cases = fixture('conditions.json');
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.equal(core.evaluateCondition(c.condition, c.values, c.fields || {}, c.today === undefined ? null : c.today), c.expected, c.name);
  }
});

test('it treats an object answer as empty in js', () => {
  const cases = fixture('conditions.json').filter((c) => c.name.includes('object answer'));
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.equal(core.evaluateCondition(c.condition, c.values, c.fields || {}, c.today === undefined ? null : c.today), c.expected, c.name);
  }
});

test('it treats an object compared field as empty on both sides', () => {
  const cases = fixture('conditions.json').filter((c) => c.name.includes('object compared field'));
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.equal(core.evaluateCondition(c.condition, c.values), c.expected, c.name);
  }
});

test('it passes all shared visibility fixtures', () => {
  const cases = fixture('visibility.json');
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.deepEqual(core.computeVisibility(c.schema, c.values, c.today === undefined ? null : c.today), c.expected, c.name);
  }
});

test('it evaluates nested groups like the server', () => {
  const rule = (field, value) => ({ field, operator: 'equals', value });
  const deep = (leaf, levels) => (levels === 0 ? leaf : { match: 'all', rules: [deep(leaf, levels - 1)] });
  const nested = { match: 'all', rules: [rule('a', '1'), { match: 'any', rules: [rule('b', '1'), rule('c', '1')] }] };
  assert.equal(core.evaluateCondition(nested, { a: '1', c: '1' }), true);
  assert.equal(core.evaluateCondition(nested, { a: '1' }), false);
  assert.equal(core.evaluateCondition({ actions: [{ action: 'hide' }], match: 'all', rules: [{ match: 'any', rules: [rule('b', '1')] }] }, { b: '1' }), false);
  // a group past level 3, an empty group and a non-array rules member never match
  assert.equal(core.evaluateCondition(deep(rule('a', '1'), 3), { a: '1' }), true);
  assert.equal(core.evaluateCondition(deep(rule('a', '1'), 4), { a: '1' }), false);
  assert.equal(core.evaluateCondition({ match: 'any', rules: [{ match: 'all', rules: [] }] }, {}), false);
  assert.equal(core.evaluateCondition({ match: 'any', rules: [{ match: 'all', rules: 'x' }] }, {}), false);
});

test("it compares a field with another field's answer", () => {
  const cond = (operator) => ({ match: 'all', rules: [{ field: 'a', operator, value_field: 'b' }] });
  assert.equal(core.evaluateCondition(cond('equals'), { a: ' X ', b: 'x' }), true);
  assert.equal(core.evaluateCondition(cond('equals'), { a: 'x', b: 'y' }), false);
  assert.equal(core.evaluateCondition(cond('greater_than'), { a: '10', b: '9' }), true);
  // a list answer is one entry per line for is_one_of; a missing or hostile source is empty
  assert.equal(core.evaluateCondition(cond('is_one_of'), { a: 'b', b: ['a', 'b'] }), true);
  assert.equal(core.evaluateCondition(cond('is_one_of'), { a: 'c', b: ['a', 'b'] }), false);
  assert.equal(core.evaluateCondition(cond('equals'), { a: '' }), true);
  assert.equal(core.evaluateCondition(cond('equals'), { a: 'x', b: { z: 1 } }), false);
  assert.equal(core.evaluateCondition(cond('equals'), { a: 'x' }, {}, null), false);
});

test('it hands relative dates only to fields that declare a format', () => {
  const rule = { field: 'd', operator: 'greater_than', value_date: { days: 3 } };
  const cond = { match: 'all', rules: [rule] };
  const fields = { d: { type: 'date', relative_date: 'Y-m-d' } };
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, fields, '2026-11-07'), true);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-10' }, fields, '2026-11-07'), false);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, fields, null), false);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, fields), false);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, { d: { type: 'text' } }, '2026-11-07'), false);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, {}, '2026-11-07'), false);
  assert.equal(core.evaluateCondition(cond, { d: '2026-11-11' }, fields, '2026-02-31'), false);
});

test('it returns the required message for empty required fields', () => {
  const f = textField({ required: true, rules: [{ rule: 'min_length', value: 3, message: '' }] });
  assert.equal(core.validateField(f, '', {}), 'Required!');
  assert.equal(core.validateField(f, '  ', {}), 'Required!');
  assert.equal(core.validateField(textField({ type: 'checkboxes', required: true }), [], {}), 'Required!');
});

test('it skips rules for empty optional fields', () => {
  const f = textField({ type: 'email', rules: [{ rule: 'min_length', value: 3, message: '' }] });
  assert.equal(core.validateField(f, '', {}), null);
});

test('it returns the admin message before the default', () => {
  const rules = [{ rule: 'min_length', value: 3, message: 'Too short, friend.' }, { rule: 'alpha', value: null, message: '' }];
  assert.equal(core.validateField(textField({ rules }), 'a1', {}), 'Too short, friend.');
  const noMsg = [{ rule: 'min_length', value: 3, message: '' }];
  assert.equal(core.validateField(textField({ rules: noMsg }), 'ab', {}), 'Please enter at least 3 characters.');
  assert.equal(core.validateField(textField({ rules }), 'abc', {}), null);
});

test('it skips a pattern rule that cannot compile in the browser', () => {
  const f = textField({ rules: [{ rule: 'pattern', value: 'a\\-b', message: 'bad' }] });
  assert.equal(core.validateField(f, 'zzz', {}), null);
  const ok = textField({ rules: [{ rule: 'pattern', value: '[a-c]{3}', message: 'bad' }] });
  assert.equal(core.validateField(ok, 'zzz', {}), 'bad');
});

test('it uses the type message from the public schema for intrinsic failures', () => {
  const f = textField({ type: 'email', type_message: 'Translated email message' });
  assert.equal(core.validateField(f, 'nope', {}), 'Translated email message');
  assert.equal(core.validateField(textField({ type: 'email' }), 'nope', {}), 'Please enter a valid email address.');
  assert.equal(core.validateField(f, 'a@b.co', {}), null);
  const sel = textField({ type: 'select', options: [{ label: 'A', value: 'a' }] });
  assert.equal(core.validateField(sel, 'z', {}), 'Please choose a valid option.');
  assert.equal(core.validateField(sel, 'a', {}), null);
  const cb = textField({ type: 'checkbox', settings: { checked_value: 'yes' } });
  assert.equal(core.validateField(cb, 'no', {}), 'Please choose a valid option.');
  const num = textField({ type: 'number', rules: [{ rule: 'min', value: 5, message: '' }] });
  assert.equal(core.validateField(num, 'abc', {}), 'Please enter a valid number.');
  assert.equal(core.validateField(num, '4', {}), 'Please enter a value of at least 5.');
});

test('it exports the api for node and the browser global', () => {
  for (const k of ['evaluateCondition', 'computeVisibility', 'validateField', 'validateFields', 'validateRule', 'isEmpty', 'REGEX']) {
    assert.ok(k in core, k);
  }
  const src = fs.readFileSync(corePath, 'utf8');
  const sandbox = { FuranFormBuilder: { other: 1 } };
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox);
  assert.equal(sandbox.FuranFormBuilder.other, 1);
  assert.equal(typeof sandbox.FuranFormBuilder.core.validateField, 'function');
  const first = sandbox.FuranFormBuilder.core;
  vm.runInContext(src, sandbox);
  assert.equal(sandbox.FuranFormBuilder.core, first);
});

test('it uses consistent required and fix errors messages', () => {
  assert.equal(core.validateField({ name: 'f', type: 'text', required: true, rules: [] }, '', {}), 'This field is required.');
});

test('it normalises values like the server does', () => {
  const text = textField();
  assert.equal(core.normalizeValue(text, '  a\u0001b\tc\nd \r\n'), 'ab\tc d');
  assert.equal(core.normalizeValue(textField({ type: 'textarea' }), ' a \r\nb\n\n '), 'a \nb');
  assert.deepEqual(core.normalizeValue(textField({ type: 'multiselect' }), [' a ', 'b\u0000']), ['a', 'b']);
  assert.equal(core.normalizeValue(textField({ type: 'color' }), ' #AABBCC '), '#aabbcc');
  assert.equal(core.validateField(textField({ rules: [{ rule: 'min_length', value: 3 }] }), '  ab  ', {}) !== null, true);
});

test('it rejects impossible calendar dates in the browser', () => {
  const date = textField({ type: 'date' });
  const datetime = textField({ type: 'datetime' });
  assert.equal(core.validateField(date, '2024-02-29', {}), null);
  assert.equal(core.validateField(date, '2023-02-29', {}), 'Please enter a valid date.');
  assert.equal(core.validateField(date, '2024-02-31', {}), 'Please enter a valid date.');
  assert.equal(core.validateField(date, '2024-04-31', {}), 'Please enter a valid date.');
  assert.equal(core.validateField(date, '0000-01-01', {}), 'Please enter a valid date.');
  assert.equal(core.validateField(datetime, '2024-02-30T10:00', {}), 'Please enter a valid date and time.');
  assert.equal(core.validateField(datetime, '2024-02-28T10:00', {}), null);
});

test('it leaves pattern checks on values over 1000 characters to the server', () => {
  const field = { name: 'x', type: 'text', required: false, rules: [{ rule: 'pattern', value: '(\\d*)(\\d*)(\\d*)x', message: 'bad' }] };
  const started = Date.now();
  assert.equal(core.validateField(field, '1'.repeat(1001) + 'y', {}), null);
  assert.ok(Date.now() - started < 500);
  assert.equal(core.validateField(field, 'abc', {}), 'bad');
});

test('it ignores inherited property names used as field types', () => {
  for (const type of ['constructor', 'toString', '__proto__', 'hasOwnProperty']) {
    assert.doesNotThrow(() => core.validateField(textField({ type }), 'abc', {}), type);
  }
});
