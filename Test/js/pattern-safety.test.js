'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const safety = require('../../view/adminhtml/web/js/pattern-safety.js');
const model = require('../../view/adminhtml/web/js/builder-model.js');

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/pattern-safety.json'), 'utf8'));

test('it classifies the shared pattern safety fixture like the server', () => {
  fixture.safe.forEach((p) => assert.equal(safety.isSafe(p), true, 'safe: ' + p));
  fixture.unsafe.forEach((p) => assert.equal(safety.isSafe(p), false, 'unsafe: ' + p));
});

test('it rejects a pattern that does not compile', () => {
  assert.equal(safety.isSafe('(unclosed'), false);
});

test('it reports a risky pattern in builder validation', () => {
  const state = model.createState({
    id: null, identifier: 'x', title: 'X', is_active: true, store_ids: [0], settings: {},
    schema: { version: 1, pages: [{ id: 'p_aaaa', title: '', condition: null, items: [
      { id: 'f_aaaa', kind: 'field', type: 'text', name: 'code', label: 'Code', rules: [{ rule: 'pattern', value: '(\\d*)(\\d*)x' }] },
    ] }] },
  });
  const errors = model.validate(state, { fieldTypes: [{ code: 'text' }] });
  assert.ok(errors.some((e) => e.itemId === 'f_aaaa' && /pattern/.test(e.message)), JSON.stringify(errors));
});

test('it shows a too-complex message in the saved rule tester', () => {
  const editor = require('../../view/adminhtml/web/js/rule-editor.js');
  assert.match(editor.compile('\\d*\\d*').error, /too complex/);
  assert.ok(editor.compile('[a-z]+\\d+').re instanceof RegExp);
});
