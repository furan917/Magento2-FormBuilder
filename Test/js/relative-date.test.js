'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

const CONTEXT = { eager: false, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const fields = [
  { name: 'when', type: 'date', relative_date: 'Y-m-d' },
  {
    name: 'later',
    condition: { action: 'show', match: 'all', rules: [{ field: 'when', operator: 'greater_than', value_date: { days: 0 } }] },
  },
];

const localDay = (offset) => {
  const d = new Date();
  d.setDate(d.getDate() + offset);
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
};

const type = (t, value) => {
  t.input('when').value = value;
  t.fire(t.input('when'), 'input');
  t.fire(t.input('when'), 'change');
};
const hidden = (t) => t.q('[data-ffb-name="later"]').hidden;

test('it uses today from the token response for relative dates', async () => {
  const t = boot({ context: { ...CONTEXT, eager: true }, fields, token: { token: 'tok.1', today: '2000-01-01' } });
  await flush(40);

  type(t, '2000-01-02');
  assert.equal(hidden(t), false);
  type(t, '2000-01-01');
  assert.equal(hidden(t), true);
  // the browser's today would hide this answer, so only the token date can show it
  type(t, localDay(0));
  assert.equal(hidden(t), false);
  type(t, '1999-12-31');
  assert.equal(hidden(t), true);
});

test('it falls back to the browser date before the token arrives', async () => {
  const t = boot({ context: CONTEXT, fields, token: { token: 'tok.1', today: '2000-01-01' } });

  type(t, localDay(1));
  assert.equal(hidden(t), false);
  type(t, localDay(0));
  assert.equal(hidden(t), true);
});

test('it ignores a malformed today in the token response', async () => {
  const t = boot({ context: { ...CONTEXT, eager: true }, fields, token: { token: 'tok.1', today: 'tomorrow' } });
  await flush(40);

  type(t, localDay(1));
  assert.equal(hidden(t), false);
  type(t, localDay(0));
  assert.equal(hidden(t), true);
});
