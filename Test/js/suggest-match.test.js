'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { core } = require('./helpers/load-storefront');
const { boot, flush } = require('./helpers/actions-form');

test('it maps a typed suggestion to its option value exactly like the server', () => {
  const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/suggest-match.json'), 'utf8'));
  const cases = fixture.cases || fixture;
  assert.ok(cases.length > 0);
  for (const c of cases) {
    assert.deepEqual(core.suggestionValue({ type: 'suggest', options: c.options, settings: {} }, c.raw), c.expected, c.name);
  }
});

const COLOURS = [{ label: 'Dark Blue', value: 'navy' }, { label: 'Red', value: 'red' }];
const suggestForm = () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'tone', options: COLOURS, suggestions: true },
    { name: 'why', condition: { actions: [{ action: 'show' }], match: 'all', rules: [{ field: 'tone', operator: 'equals', value: 'navy' }] } },
  ] }] });
  return t;
};

test('a condition on a suggestion field sees the option value the server stores, the box keeps the typed text', async () => {
  const t = suggestForm();
  await flush();
  t.type('tone', 'x');
  assert.equal(t.q('[data-ffb-name="why"]').hidden, true);
  t.type('tone', '  dark BLUE ');
  assert.equal(t.q('[data-ffb-name="why"]').hidden, false);
  assert.equal(t.q('[name="fields[tone]"]').value, '  dark BLUE ');
  t.type('tone', 'Dark Blues');
  assert.equal(t.q('[data-ffb-name="why"]').hidden, true);
});

test('a label match beats a value match and the first option wins', () => {
  const field = { type: 'suggest', settings: {}, options: [
    { label: 'Red', value: 'blue' }, { label: 'Blue', value: 'b2' }, { label: 'Other', value: 'red' }, { label: 'red ', value: 'r2' },
  ] };
  assert.equal(core.suggestionValue(field, ' BLUE '), 'b2');
  assert.equal(core.suggestionValue(field, 'red'), 'blue');
  assert.equal(core.suggestionValue(field, 'b2'), 'b2');
  assert.equal(core.suggestionValue(field, ' Teal\u0001 '), 'Teal');
  assert.equal(core.suggestionValue(field, ''), '');
});

test('a field without the suggestions flag is never mapped', () => {
  const field = { type: 'text', settings: {}, options: COLOURS };
  assert.equal(core.normalizeValue(field, 'Dark Blue'), 'Dark Blue');
  assert.equal(core.normalizeValue(Object.assign({}, field, { suggestions: true }), ' dark blue '), 'navy');
});

test('normalising a suggestions field applies the server match', () => {
  const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/suggest-match.json'), 'utf8'));
  for (const c of fixture.cases) {
    assert.deepEqual(core.normalizeValue({ type: 'suggest', suggestions: true, options: c.options, settings: {} }, c.raw), c.expected, c.name);
  }
});
