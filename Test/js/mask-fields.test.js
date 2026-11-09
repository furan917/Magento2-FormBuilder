'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { core } = require('./helpers/load-storefront');

const cases = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/mask-fields.json'), 'utf8'));

test('it stores the masked value consistently on the server', () => {
  cases.forEach((c) => {
    const field = { name: 'code', type: c.type, settings: { mask: c.mask } };
    assert.equal(core.normalizeValue(field, c.posted), c.stored, c.mask + ' ' + c.posted);
  });
});

test('it picks the mobile keyboard from the mask', () => {
  const kb = core.mask.inputMode;
  assert.equal(kb('uk_sort_code'), 'numeric');
  assert.equal(kb('us_zip'), 'numeric');
  assert.equal(kb('(999) 999-9999'), 'numeric');
  assert.equal(kb('uk_phone'), 'tel');
  assert.equal(kb('international_phone'), 'tel');
  assert.equal(kb('uk_postcode'), 'text');
  assert.equal(kb('aa-99'), 'text');
  assert.equal(kb('99-**'), 'text');
  assert.equal(kb(''), '');
});
