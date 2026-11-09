'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush, json } = require('./helpers/context-form');

const DRAFT_URL = 'https://shop.test/furanform/form/draft?form_id=1';
const KEY = 'ffb:1:1:abc';
const draftCfg = (extra) => Object.assign({ mode: 'local', key: KEY, ttlHours: 24, serverUrl: DRAFT_URL }, extra);
const customer = (extra) => Object.assign({ token: 'tok.1', customer: true, access: { allowed: true, reason: null, loginUrl: '' }, prefill: {}, locked: [], previous: {} }, extra);
const guest = () => ({ token: 'tok.1', customer: false, access: { allowed: true, reason: null, loginUrl: '' }, prefill: {}, locked: [], previous: {} });
const browserDraft = (savedAt, values) => (w) => {
  w.localStorage.setItem(KEY, JSON.stringify({ v: 1, savedAt, page: 'p_aaaa', values }));
};

// Fakes the draft endpoint and the timers so the 5 s debounce can be driven by hand.
function setup(opts = {}) {
  const t = boot(Object.assign({ fields: [{ name: 'note' }, { name: 'other' }], token: customer(), draft: draftCfg() }, opts));
  const draftCalls = [];
  const timers = [];
  const server = opts.server === undefined ? { values: null, updatedAt: null } : opts.server;
  t.w.setTimeout = (fn, ms) => { timers.push({ fn, ms, live: true }); return timers.length; };
  t.w.clearTimeout = (id) => { if (timers[id - 1]) { timers[id - 1].live = false; } };
  const inner = t.w.fetch;
  t.w.fetch = (url, init) => {
    if (!String(url).startsWith('https://shop.test/furanform/form/draft')) { return inner(url, init); }
    draftCalls.push({ url: String(url), init: init || {}, method: (init && init.method) || 'GET' });
    return opts.serverResponse ? opts.serverResponse() : Promise.resolve(json(200, (init && init.method === 'POST') ? { success: true } : server));
  };
  const runTimers = () => {
    for (let i = 0; i < 20; i++) {
      const due = timers.filter((x) => x.live);
      if (!due.length) { return; }
      due.forEach((x) => { x.live = false; x.fn(); });
    }
  };
  const type = (name, value) => { t.input(name).value = value; t.fire(t.input(name), 'input'); };
  const posts = () => draftCalls.filter((c) => c.method === 'POST');
  const body = (call) => Object.fromEntries(new URLSearchParams(String(call.init.body)));
  return Object.assign(t, { draftCalls, timers, runTimers, type, posts, body });
}
const openForm = async (t) => { t.fire(t.input('note'), 'focusin'); await flush(); };
const submit = (t) => t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));

test('it saves drafts to the server only for logged in customers', async () => {
  const visitor = setup({ token: guest() });
  await openForm(visitor);
  visitor.type('note', 'hello');
  visitor.runTimers();
  await flush();
  assert.equal(visitor.draftCalls.length, 0);

  const t = setup();
  await openForm(t);
  t.type('note', 'hello');
  t.runTimers();
  await flush();
  assert.equal(t.posts().length, 1);
  const sent = t.body(t.posts()[0]);
  assert.equal(sent.form_id, '1');
  assert.deepEqual(JSON.parse(sent.values), { note: 'hello', other: '' });
  assert.ok('form_key' in sent);
  assert.equal(t.posts()[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.ok(t.timers.some((x) => x.ms >= 5000), 'the server save is debounced');
});

test('it restores the newest of the browser and server draft', async () => {
  const saved = Date.now() - 60000;
  const newer = setup({ server: { values: { note: 'from server' }, updatedAt: saved + 5000 }, beforeInit: browserDraft(saved, { note: 'from browser' }) });
  assert.equal(newer.input('note').value, 'from browser');
  await openForm(newer);
  assert.equal(newer.input('note').value, 'from server');

  const older = setup({ server: { values: { note: 'from server' }, updatedAt: saved }, beforeInit: browserDraft(saved, { note: 'from browser' }) });
  await openForm(older);
  assert.equal(older.input('note').value, 'from browser', 'ties go to the browser draft');
});

test('it deletes the server draft after a successful submit', async () => {
  const t = setup();
  await openForm(t);
  submit(t);
  await flush(30);
  const clear = t.posts().filter((c) => t.body(c).action === 'clear');
  assert.equal(clear.length, 1);
  assert.equal(clear[0].init.keepalive, true);
  assert.equal(t.body(clear[0]).form_id, '1');
});

test('it never overwrites answers typed before the server draft arrived', async () => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const t = setup({ serverResponse: () => gate });
  t.fire(t.input('note'), 'focusin');
  await flush();
  t.type('note', 'mine');
  release(json(200, { values: { note: 'server', other: 'kept' }, updatedAt: 9e12 }));
  await flush();
  assert.equal(t.input('note').value, 'mine');
  assert.equal(t.input('other').value, 'kept');
});

test('it neither loads nor saves a server draft while editing a previous submission', async () => {
  const edit = '1800000000.55.' + 'a'.repeat(64);
  const t = setup({ url: 'https://shop.test/forms/x#ffb-edit=' + edit, token: customer({ edit, previous: { note: 'old' } }) });
  await flush();
  t.type('note', 'changed');
  t.runTimers();
  submit(t);
  await flush(30);
  assert.equal(t.draftCalls.length, 0);
});

test('it clears the server draft before following a success redirect', async () => {
  const t = setup();
  await openForm(t);
  const order = [];
  const inner = t.w.fetch;
  t.w.fetch = (url, init) => {
    if (String(url).endsWith('/furanform/form/submit')) {
      return Promise.resolve(json(200, { success: true, message: 'ok', redirect: '/thanks' }));
    }
    if (String(url).startsWith('https://shop.test/furanform/form/draft')) { order.push('clear'); }
    return inner(url, init);
  };
  t.w.FuranFormBuilder.navigate = () => order.push('navigate');
  submit(t);
  await flush(30);
  assert.deepEqual(order, ['clear', 'navigate']);
});

test('it never syncs server drafts when the form\'s draft mode is session or off', async () => {
  for (const mode of ['session', 'off']) {
    const t = setup({ draft: draftCfg({ mode }) });
    await openForm(t);
    t.type('note', 'x');
    t.runTimers();
    submit(t);
    await flush(30);
    assert.equal(t.draftCalls.length, 0, mode);
  }
});

const syncedDraft = (savedAt, values) => (w) => {
  w.localStorage.setItem(KEY, JSON.stringify({ v: 1, savedAt, page: 'p_aaaa', values, synced: true }));
};

test('it drops a browser draft the server had when the server draft was cleared elsewhere', async () => {
  const t = setup({ beforeInit: syncedDraft(Date.now() - 60000, { note: 'stale' }) });
  assert.equal(t.input('note').value, 'stale');
  await openForm(t);
  assert.equal(t.input('note').value, '');
  assert.equal(t.w.localStorage.getItem(KEY), null);
  assert.equal(t.q('[data-ffb-status]').textContent, '');
});

test('it keeps a browser draft that never reached the server', async () => {
  const t = setup({ beforeInit: browserDraft(Date.now() - 60000, { note: 'unsent' }) });
  await openForm(t);
  assert.equal(t.input('note').value, 'unsent');
});

test('it marks the browser draft as on the server once the server saved it', async () => {
  const t = setup();
  await openForm(t);
  t.type('note', 'hello');
  t.runTimers();
  await flush();
  assert.equal(t.posts().length, 1);
  assert.equal(JSON.parse(t.w.localStorage.getItem(KEY)).synced, true);
});

test('it never restores a browser draft over a newer submitted answer', async () => {
  const saved = Date.now() - 60000;
  const stale = setup({
    token: customer({ previous: { note: 'submitted' }, previousAt: saved + 1000 }),
    beforeInit: browserDraft(saved, { note: 'old draft', other: 'x' }),
  });
  await openForm(stale);
  assert.equal(stale.input('note').value, 'submitted');
  assert.equal(stale.input('other').value, '');

  const fresh = setup({
    token: customer({ previous: { note: 'submitted' }, previousAt: saved - 1000 }),
    beforeInit: browserDraft(saved, { note: 'newer draft' }),
  });
  await openForm(fresh);
  assert.equal(fresh.input('note').value, 'newer draft');
});

test('it ignores a server draft older than the submitted answer', async () => {
  const at = Date.now() - 60000;
  const t = setup({
    token: customer({ previous: { note: 'submitted' }, previousAt: at + 1000 }),
    server: { values: { note: 'old server draft' }, updatedAt: at },
  });
  await openForm(t);
  assert.equal(t.input('note').value, 'submitted');
});

test('it keeps what the visitor typed when a stale browser draft is dropped', async () => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const t = setup({ serverResponse: () => gate, beforeInit: syncedDraft(Date.now() - 60000, { note: 'stale', other: 'stale too' }) });
  t.fire(t.input('note'), 'focusin');
  await flush();
  t.type('note', 'mine');
  release(json(200, { values: null, updatedAt: null }));
  await flush();
  assert.equal(t.input('note').value, 'mine');
  assert.equal(t.input('other').value, '');
});

test('it keeps a synced browser draft when the server draft request fails', async () => {
  const saved = Date.now() - 60000;
  const t = setup({ serverResponse: () => Promise.resolve(json(500, {})), beforeInit: syncedDraft(saved, { note: 'kept' }) });
  await openForm(t);
  assert.equal(t.input('note').value, 'kept');
  assert.equal(JSON.parse(t.w.localStorage.getItem(KEY)).savedAt, saved);
});

test('it keeps the draft time when it marks the browser draft as on the server', async () => {
  const t = setup();
  await openForm(t);
  t.type('note', 'hello');
  t.runTimers();
  const before = JSON.parse(t.w.localStorage.getItem(KEY)).savedAt;
  await new Promise((r) => setTimeout(r, 5));
  await flush();
  assert.equal(JSON.parse(t.w.localStorage.getItem(KEY)).savedAt, before);
});
