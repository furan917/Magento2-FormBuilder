'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadCore, source } = require('./helpers/load-storefront');

const URL_DRAFT = 'https://shop.test/furanform/form/draft?form_id=7';
const plain = (value) => JSON.parse(JSON.stringify(value));
const flush = () => new Promise((resolve) => setTimeout(resolve, 10));

// Only core and ffb-drafts.js are loaded: the factory gets everything it uses as inputs.
function build(overrides = {}) {
  const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  loadCore(w);
  w.eval(source('ffb-drafts.js'));
  const timers = [];
  w.setTimeout = (fn, ms) => { timers.push({ fn, ms, live: true }); return timers.length; };
  w.clearTimeout = (id) => { if (timers[id - 1]) { timers[id - 1].live = false; } };
  const requests = [];
  w.fetch = (url, init) => {
    requests.push({ url: String(url), init: init || {} });
    return Promise.resolve({ ok: true, json: () => Promise.resolve(overrides.server || { values: null }) });
  };
  const applied = [];
  const inputs = Object.assign({
    cfg: { mode: 'local', key: 'ffb:7', ttlHours: 24, serverUrl: URL_DRAFT },
    formId: 7,
    fields: [{ name: 'a', type: 'text', draft: true }, { name: 'b', type: 'text', draft: true }, { name: 'h', type: 'hidden', draft: false }, { name: 'form_key', type: 'text', draft: true }],
    readValues: () => ({ a: 'x', b: '', h: 'secret', form_key: 'k' }),
    applyValues: (values) => applied.push(values),
    getPage: () => 'p1',
    isCustomer: () => true,
    isEditing: () => false,
    isLocked: () => false,
    isDirty: () => false,
    anyDirty: () => false,
    prefilledJson: () => undefined,
    formKey: () => 'fk123',
  }, overrides.inputs || {});
  const sync = w.FuranFormBuilder.createDraftSync(inputs);
  const run = () => { timers.filter((t) => t.live).forEach((t) => { t.live = false; t.fn(); }); };
  return { w, sync, timers, requests, applied, run };
}

test('it syncs drafts through the draft factory with explicit inputs', async () => {
  const t = build();
  await t.sync.loadServer([]);
  t.sync.save();
  const stored = JSON.parse(t.w.localStorage.getItem('ffb:7'));
  assert.deepEqual(stored.values, { a: 'x', b: '' });
  assert.equal(stored.page, 'p1');

  t.run();
  await flush();
  const post = t.requests.find((r) => r.init.method === 'POST');
  const body = Object.fromEntries(new URLSearchParams(String(post.init.body)));
  assert.equal(post.url, URL_DRAFT);
  assert.equal(body.form_id, '7');
  assert.equal(body.form_key, 'fk123');
  assert.deepEqual(JSON.parse(body.values), { a: 'x', b: '' });

  assert.equal(t.sync.restore(), 'p1');
  assert.deepEqual(plain(t.applied), [{ a: 'x', b: '' }]);
});

test('it applies a newer server draft only to fields the visitor has not typed in', async () => {
  const t = build({
    server: { values: { a: 'server', b: 'also', h: 'no' }, updatedAt: Date.now() + 1000 },
    inputs: { isDirty: (name) => name === 'a' },
  });
  await t.sync.loadServer([]);
  assert.deepEqual(plain(t.applied), [{ b: 'also' }]);
});

test('it keeps a draft out of the server while the form edits a submission', async () => {
  const t = build({ inputs: { isEditing: () => true } });
  await t.sync.loadServer([]);
  t.sync.save();
  t.run();
  assert.equal(t.sync.enabled(), false);
  assert.equal(t.requests.filter((r) => r.init.method === 'POST').length, 0);
});

test('it never drafts file or hidden fields even when the flag is missing', async () => {
  const t = build({
    inputs: {
      fields: [{ name: 'a', type: 'text' }, { name: 'f', type: 'file' }, { name: 'h', type: 'hidden' }],
      readValues: () => ({ a: 'x', f: 'doc', h: 'secret' }),
    },
  });
  t.sync.save();
  assert.deepEqual(JSON.parse(t.w.localStorage.getItem('ffb:7')).values, { a: 'x' });
});
