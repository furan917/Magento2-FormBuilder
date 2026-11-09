'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/actions-form');

const when = (field, value) => ({ field, operator: 'equals', value });
const cond = (actions, ...rules) => ({ actions, match: 'all', rules });
const OPTIONS = [{ value: 'x', label: 'X' }, { value: 'y', label: 'Y' }, { value: 'z', label: 'Z' }];

test('it marks a field required when a require action matches', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', condition: cond([{ action: 'require' }], when('a', '1')) },
    { name: 'c', required: true },
  ] }] });
  await flush();
  const wrapper = t.q('[data-ffb-name="b"]');
  assert.equal(wrapper.querySelector('.ffb-req'), null);
  t.type('a', '1');
  assert.ok(wrapper.querySelector('.ffb-label .ffb-req'));
  assert.equal(wrapper.querySelector('input').getAttribute('aria-required'), 'true');
  t.q('[name="fields[b]"]').focus();
  t.fire(t.q('[name="fields[b]"]'), 'focusout');
  assert.equal(t.q('[data-ffb-name="b"] [data-ffb-error]').hidden, false);
  t.type('a', '2');
  assert.equal(wrapper.querySelector('.ffb-req'), null);
  assert.equal(wrapper.querySelector('input').hasAttribute('aria-required'), false);
  assert.ok(t.q('[data-ffb-name="c"] .ffb-req'), 'a statically required marker stays');
});

test('it fills an empty editable field with a set value', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', condition: cond([{ action: 'set_value', param: 'hello' }], when('a', '1')) },
  ] }] });
  await flush();
  t.type('a', '1');
  assert.equal(t.q('[name="fields[b]"]').value, 'hello');
  t.type('a', '2');
  assert.equal(t.q('[name="fields[b]"]').value, 'hello', 'an unmatched action leaves the value');
});

test('it never overwrites a value the visitor typed', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', condition: cond([{ action: 'set_value', param: 'hello' }], when('a', '1')) },
    { name: 'c', condition: cond([{ action: 'set_value', param: 'other' }], when('a', '2')) },
  ] }] });
  await flush();
  t.type('b', 'mine');
  t.type('a', '1');
  assert.equal(t.q('[name="fields[b]"]').value, 'mine');
  t.type('a', '2');
  t.type('c', '');
  assert.equal(t.q('[name="fields[c]"]').value, '', 'a field the visitor emptied stays empty');
});

test('it overwrites a locked field with a set value even when the visitor value differs', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'b', overwritable: true, locked: true, condition: cond([{ action: 'set_value', param: 'forced' }], when('a', '1')) },
  ] }] });
  await flush();
  t.type('b', 'mine');
  t.type('a', '1');
  assert.equal(t.q('[name="fields[b]"]').value, 'forced');
});

test('it skips to the target page on next', async () => {
  const t = boot({ pages: [
    { id: 'p1', skip: { match: 'all', rules: [when('a', 'skip')], target: 'p3' }, fields: [{ name: 'a' }] },
    { id: 'p2', fields: [{ name: 'b' }] },
    { id: 'p3', fields: [{ name: 'c' }] },
  ] });
  await flush();
  t.type('a', 'skip');
  t.click('[data-ffb-next]');
  assert.deepEqual(t.pageShown(), ['p3']);
  assert.match(t.q('[data-ffb-status]').textContent, /Step 2 of 2/);
});

test('it returns to the source page when going back from a skip target', async () => {
  const t = boot({ pages: [
    { id: 'p1', skip: { match: 'all', rules: [when('a', 'skip')], target: 'p3' }, fields: [{ name: 'a' }] },
    { id: 'p2', fields: [{ name: 'b' }] },
    { id: 'p3', fields: [{ name: 'c' }] },
  ] });
  await flush();
  t.type('a', 'skip');
  t.click('[data-ffb-next]');
  t.click('[data-ffb-prev]');
  assert.deepEqual(t.pageShown(), ['p1']);
});

test('it ignores a skip on a hidden page', async () => {
  const t = boot({ pages: [
    { id: 'p1', fields: [{ name: 'a' }] },
    { id: 'p2', condition: cond([{ action: 'show' }], when('a', 'open')), skip: { match: 'all', rules: [{ field: 'b', operator: 'is_empty' }], target: 'p4' }, fields: [{ name: 'b' }] },
    { id: 'p3', fields: [{ name: 'c' }] },
    { id: 'p4', fields: [{ name: 'd' }] },
  ] });
  await flush();
  t.click('[data-ffb-next]');
  assert.deepEqual(t.pageShown(), ['p3']);
});

test('it shows the skipped pages again when the skip no longer matches', async () => {
  const t = boot({ pages: [
    { id: 'p1', skip: { match: 'all', rules: [when('a', 'skip')], target: 'p3' }, fields: [{ name: 'a' }] },
    { id: 'p2', fields: [{ name: 'b' }] },
    { id: 'p3', fields: [{ name: 'c' }] },
  ] });
  await flush();
  t.type('a', 'skip');
  t.click('[data-ffb-next]');
  t.click('[data-ffb-prev]');
  t.type('a', 'stay');
  t.click('[data-ffb-next]');
  assert.deepEqual(t.pageShown(), ['p2']);
});

test('it counts only reachable pages in the progress indicator', async () => {
  const t = boot({ pages: [
    { id: 'p1', skip: { match: 'all', rules: [when('a', 'skip')], target: 'p3' }, fields: [{ name: 'a' }] },
    { id: 'p2', fields: [{ name: 'b' }] },
    { id: 'p3', fields: [{ name: 'c' }] },
  ] });
  await flush();
  const progress = t.q('[data-ffb-progress]');
  assert.equal(progress.getAttribute('aria-label'), 'Step 1 of 3');
  t.type('a', 'skip');
  assert.equal(progress.getAttribute('aria-label'), 'Step 1 of 2');
  assert.equal(t.q('[data-ffb-step="p2"]').hidden, true);
});

test('it hides and clears a hidden option', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 'r', type: 'radio', options: OPTIONS, condition: cond([{ action: 'hide_options', param: ['y'] }], when('a', '1')) },
  ] }] });
  await flush();
  t.choose('r', 'y');
  t.type('a', '1');
  const y = t.q('[name="fields[r]"][value="y"]');
  assert.equal(y.checked, false);
  assert.equal(y.disabled, true);
  assert.equal(y.closest('.ffb-option').hidden, true);
  assert.equal(t.q('[name="fields[r]"][value="x"]').closest('.ffb-option').hidden, false);
  t.type('a', '2');
  assert.equal(y.disabled, false);
  assert.equal(y.closest('.ffb-option').hidden, false);
});

test('it removes a hidden select option from the list', async () => {
  const t = boot({ pages: [{ id: 'p1', fields: [
    { name: 'a' },
    { name: 's', type: 'select', options: OPTIONS, condition: cond([{ action: 'hide_options', param: ['y'] }], when('a', '1')) },
  ] }] });
  await flush();
  const select = t.q('[name="fields[s]"]');
  const values = () => Array.from(select.options).map((o) => o.value);
  select.value = 'y';
  t.fire(select, 'change');
  t.type('a', '1');
  assert.deepEqual(values(), ['', 'x', 'z']);
  assert.equal(select.value, '');
  t.type('a', '2');
  assert.deepEqual(values(), ['', 'x', 'y', 'z']);
});

test('it applies a registered custom condition action', async () => {
  const t = boot({
    afterCore: (w) => w.FuranFormBuilder.registry.conditionAction('mark_hidden', (state, id, param, matched) => { if (matched) state.hide(); }),
    pages: [{ id: 'p1', fields: [
      { name: 'a' },
      { name: 'b', condition: cond([{ action: 'mark_hidden' }], when('a', '1')) },
    ] }],
  });
  await flush();
  assert.equal(t.q('[data-ffb-name="b"]').hidden, false);
  t.type('a', '1');
  assert.equal(t.q('[data-ffb-name="b"]').hidden, true);
});

test('it keeps a restored draft value when a set value action matches', async () => {
  const t = boot({
    draft: { mode: 'session', key: 'ffb:1:1:abc', ttlHours: 24 },
    beforeEval: (w) => w.sessionStorage.setItem('ffb:1:1:abc', JSON.stringify({
      v: 1, savedAt: Date.now(), page: 'p1', values: { a: '1', b: 'draft text' },
    })),
    pages: [{ id: 'p1', fields: [
      { name: 'a' },
      { name: 'b', condition: cond([{ action: 'set_value', param: 'hello' }], when('a', '1')) },
    ] }],
  });
  await flush();
  assert.equal(t.q('[name="fields[b]"]').value, 'draft text');
});

test('it treats a prefilled value as the visitor\'s own when a set value action matches', async () => {
  const t = boot({
    context: { eager: true },
    token: { token: 'tok.1', customer: true, prefill: { b: 'from account' }, locked: [] },
    pages: [{ id: 'p1', fields: [
      { name: 'a' },
      { name: 'b', condition: cond([{ action: 'set_value', param: 'hello' }], when('a', '1')) },
    ] }],
  });
  await flush(40);
  t.type('a', '1');
  assert.equal(t.q('[name="fields[b]"]').value, 'from account');
});
