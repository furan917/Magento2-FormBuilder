'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/actions-form');

const OPTIONS = [{ value: 'x', label: 'X' }, { value: 'y', label: 'Y' }];
const requireWhen = { actions: [{ action: 'require' }], match: 'all', rules: [{ field: 'a', operator: 'equals', value: '1' }] };

test('it marks a required radio group on the group not on each radio', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', type: 'radio', options: OPTIONS, condition: requireWhen },
  ] }] });
  await flush();
  const group = t.q('[data-ffb-name="b"]');
  assert.equal(group.hasAttribute('aria-required'), false);
  t.type('a', '1');
  assert.equal(group.getAttribute('aria-required'), 'true');
  group.querySelectorAll('input').forEach((r) => assert.equal(r.hasAttribute('aria-required'), false));
  t.type('a', '2');
  assert.equal(group.hasAttribute('aria-required'), false);
});

test('it marks only the required address parts', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', type: 'address', condition: requireWhen },
  ] }] });
  await flush();
  t.type('a', '1');
  const mark = (k) => t.q('[name="fields[b][' + k + ']"]').getAttribute('aria-required');
  assert.equal(mark('city'), 'true');
  assert.equal(mark('postcode'), 'true');
  assert.equal(mark('region'), null);
  assert.equal(t.q('[data-ffb-name="b"]').hasAttribute('aria-required'), false);
  t.type('a', '2');
  assert.equal(mark('city'), null);
});
