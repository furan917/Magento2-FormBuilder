'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { core } = require('./helpers/load-storefront');

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/masks.json'), 'utf8'));
const mask = core.mask;

test('it formats a sort code', () => {
  assert.equal(mask.format('123456', 'uk_sort_code'), '12-34-56');
  assert.equal(mask.format('12 34 56', 'uk_sort_code'), '12-34-56');
});

test('it upper cases and groups an iban', () => {
  assert.equal(mask.format('gb82west12345698765432', 'iban_format'), 'GB82 WEST 1234 5698 7654 32');
});

test('it formats a uk postcode with its space', () => {
  assert.equal(mask.format('sw1a1aa', 'uk_postcode'), 'SW1A 1AA');
  assert.equal(mask.format(' m11ae ', 'uk_postcode'), 'M1 1AE');
});

test('it applies a custom mask of digits and letters', () => {
  assert.equal(mask.format('5551234567', '(999) 999-9999'), '(555) 123-4567');
  assert.equal(mask.format('abc123', 'aaa-999'), 'abc-123');
  assert.equal(mask.format('ab12', '**-**'), 'ab-12');
});

test('it upper cases letters for a capital letter mask token', () => {
  assert.equal(mask.format('ab1234', 'AA-9999'), 'AB-1234');
  assert.equal(mask.raw('ab1234', 'AA-9999'), 'AB1234');
  assert.equal(mask.format('éß', 'AA'), 'éß');
  assert.equal(mask.inputMode('AA-9999'), 'text');
});

test('it keeps the typed case for a lower letter mask token', () => {
  assert.equal(mask.format('Ab12', 'aa-99'), 'Ab-12');
  assert.equal(mask.format('ab12', 'Aa-99'), 'Ab-12');
});

test('it keeps extra characters beyond the mask', () => {
  assert.equal(mask.format('123456', '99-99'), '12-3456');
  assert.equal(mask.format('ab12', 'aaa-999'), 'ab12');
  assert.equal(mask.format('123456789', 'uk_sort_code'), '12-34-56789');
});

test('it returns the raw value without mask characters', () => {
  assert.equal(mask.raw('(555) 123-4567', '(999) 999-9999'), '5551234567');
  assert.equal(mask.raw('12-34-56', 'uk_sort_code'), '123456');
  assert.equal(mask.raw('GB82 WEST 1234 5698 7654 32', 'iban_format'), 'GB82WEST12345698765432');
});

test('it passes the shared mask fixtures in php and js', () => {
  assert.ok(fixture.cases.length > 0);
  fixture.cases.forEach((c) => {
    const label = c.mask + ' <- ' + c.value;
    const formatted = mask.format(c.value, c.mask);
    assert.equal(formatted, c.formatted, 'format ' + label);
    assert.equal(mask.raw(c.value, c.mask), c.raw, 'raw ' + label);
    assert.equal(mask.format(formatted, c.mask), formatted, 'idempotent ' + label);
  });
});

test('it handles hostile input in linear time', () => {
  const big = '9 -a'.repeat(50000);
  const start = Date.now();
  ['99-99', 'iban_format', 'uk_phone', 'uk_postcode', '**-**'].forEach((m) => {
    assert.equal(typeof mask.format(big, m), 'string');
    assert.equal(typeof mask.raw(big, m), 'string');
  });
  assert.ok(Date.now() - start < 5000);
});

test('it does not throw on lone surrogates or non-string input', () => {
  assert.equal(typeof mask.format('1\ud800', '99-99'), 'string');
  assert.equal(mask.format(null, '99'), '');
  assert.equal(mask.raw(undefined, '99'), '');
});
