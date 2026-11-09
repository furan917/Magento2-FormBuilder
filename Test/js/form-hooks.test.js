'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush } = require('./helpers/context-form');

// Registers hooks on the namespace queue before core loads (the way extension scripts do).
const withQueue = (queue, after) => (w) => {
  w.FuranFormBuilder = { registry: { queue } };
  if (after) { after(w); }
};
const typed = (name, type) => (w) => {
  w.document.querySelector('[data-ffb-name="' + name + '"]').setAttribute('data-ffb-type', type);
};

test('it reads and writes a custom field type through registered hooks', () => {
  const read = (el) => el.querySelector('input').value.split('|');
  const write = (el, value) => { el.querySelector('input').value = [].concat(value).join('|'); };
  const t = boot({
    fields: [{ name: 'pair', type: 'duo' }],
    beforeInit: withQueue([['fieldType', 'duo', { read, write }]], typed('pair', 'duo')),
  });
  t.input('pair').value = 'a|b';
  assert.deepEqual(Array.from(t.w.FuranFormBuilder.types.readValue(t.q('[data-ffb-name="pair"]'))), ['a', 'b']);
  t.w.FuranFormBuilder.types.writeValue(t.q('[data-ffb-name="pair"]'), ['x', 'y']);
  assert.equal(t.input('pair').value, 'x|y');
});

test('it fills a hidden field from a registered hidden source', () => {
  const t = boot({
    fields: [{ name: 'campaign', type: 'hidden', settings: { source: 'cookie_campaign' } }, { name: 'other', type: 'hidden', settings: { source: 'unknown_src' } }],
    beforeInit: withQueue([['hiddenSource', 'cookie_campaign', (field) => 'spring-' + field.name]]),
  });
  assert.equal(t.input('campaign').value, 'spring-campaign');
  assert.equal(t.input('other').value, '');
});

const listen = (w, type, seen) => w.document.addEventListener(type, (e) => seen.push({ target: e.target, detail: e.detail, event: e }));

test('it dispatches ffb ready after the form initialises', () => {
  const seen = [];
  const t = boot({ fields: [{ name: 'a' }], beforeInit: (w) => listen(w, 'ffb:ready', seen) });
  assert.equal(seen.length, 1);
  assert.equal(seen[0].target, t.el);
  assert.deepEqual({ ...seen[0].detail }, { formId: 1 });
});

const twoPages = (extra) => (w) => {
  const sec = w.document.querySelector('[data-ffb-page]');
  const second = w.document.createElement('section');
  second.setAttribute('data-ffb-page', 'p_bbbb');
  second.setAttribute('data-ffb-item', 'p_bbbb');
  second.hidden = true;
  sec.after(second);
  const el = w.document.querySelector('[data-ffb-root]');
  const config = JSON.parse(el.getAttribute('data-ffb-config'));
  config.schema.pages.push({ id: 'p_bbbb', title: 'Q', items: [] });
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  if (extra) { extra(w); }
};

test('it dispatches ffb pagechange with the new page number', () => {
  const seen = [];
  const t = boot({
    fields: [{ name: 'a' }],
    beforeInit: (w) => twoPages((win) => listen(win, 'ffb:pagechange', seen))(w),
  });
  assert.equal(seen.length, 0);
  t.form.dispatchEvent(new t.w.Event('submit', { cancelable: true }));
  assert.equal(seen.length, 1);
  assert.deepEqual({ ...seen[0].detail }, { formId: 1, page: 2, pages: 2 });
});

test('it dispatches ffb invalid with the names of failing fields', () => {
  const seen = [];
  const t = boot({
    fields: [{ name: 'a', required: true }, { name: 'b', required: true }, { name: 'c' }],
    beforeInit: (w) => listen(w, 'ffb:invalid', seen),
  });
  t.form.dispatchEvent(new t.w.Event('submit', { cancelable: true }));
  assert.equal(seen.length, 1);
  assert.deepEqual(Array.from(seen[0].detail.fields), ['a', 'b']);
  assert.equal(seen[0].detail.formId, 1);
});

test('it cancels the submit when an ffb submit listener calls preventDefault', async () => {
  const t = boot({
    fields: [{ name: 'a' }],
    beforeInit: (w) => w.document.addEventListener('ffb:submit', (e) => e.preventDefault()),
  });
  t.form.dispatchEvent(new t.w.Event('submit', { cancelable: true }));
  await flush();
  assert.equal(t.calls.filter((c) => c.url.includes('/submit')).length, 0);
  assert.equal(t.tokenCalls().length, 0);
});

test('it lets the submit through when no ffb submit listener cancels it', async () => {
  const seen = [];
  const t = boot({ fields: [{ name: 'a' }], beforeInit: (w) => listen(w, 'ffb:submit', seen) });
  t.form.dispatchEvent(new t.w.Event('submit', { cancelable: true }));
  await flush();
  assert.equal(seen.length, 1);
  assert.equal(seen[0].event.cancelable, true);
  assert.equal(t.calls.filter((c) => c.url.includes('/submit')).length, 1);
});

test('it dispatches ffb success without any answer values', async () => {
  const seen = [];
  const t = boot({
    fields: [{ name: 'a', default: 'secret-answer' }],
    beforeInit: (w) => listen(w, 'ffb:success', seen),
  });
  t.form.dispatchEvent(new t.w.Event('submit', { cancelable: true }));
  await flush();
  assert.equal(seen.length, 1);
  assert.deepEqual({ ...seen[0].detail }, { formId: 1 });
  assert.equal(JSON.stringify(seen[0].detail).includes('secret-answer'), false);
});
