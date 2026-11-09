'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot, flush, json } = require('./helpers/context-form');

const EAGER = { eager: true, productId: null, loginUrl: 'https://shop.test/furanform/account/login' };
const RC = { scriptUrl: 'https://shop.test/static/ffb-recaptcha.js', rendering: { sitekey: 'k', hl: 'en' }, invisible: false };

// Manual timers and clock: nothing waits in real time.
function env(opts = {}) {
  const timers = [];
  const clock = { now: 1000 };
  const rc = { tokens: 0, resets: 0 };
  const sent = [];
  const held = [];
  const answers = (opts.submits || []).slice();
  const t = boot({
    fields: [{ name: 'a' }],
    context: opts.eager ? EAGER : undefined,
    token: { token: 'tok.1', notBefore: opts.notBefore },
    messages: { sending: 'Sending…', waiting: 'Please wait a moment…', recaptchaRequired: 'Confirm.' },
    beforeInit(w) {
      w.setTimeout = (fn, ms) => { timers.push({ fn, ms, done: false }); return timers.length; };
      Object.defineProperty(w.performance, 'now', { value: () => clock.now, configurable: true });
      const inner = w.fetch;
      w.fetch = (url, init) => {
        if (!String(url).includes('/submit')) {
          return inner(url, init);
        }
        sent.push({ url: String(url), init });
        if (opts.hold) {
          return new Promise((resolve) => { held.push(() => resolve(json(200, { success: true, message: 'Thanks', errors: {}, redirect: null }))); });
        }
        const answer = answers.shift() || { status: 200, body: { success: true, message: 'Thanks', errors: {}, redirect: null } };
        return Promise.resolve(json(answer.status, answer.body));
      };
      if (opts.recaptcha) {
        const el = w.document.querySelector('[data-ffb-root]');
        const config = JSON.parse(el.getAttribute('data-ffb-config'));
        config.recaptcha = RC;
        el.setAttribute('data-ffb-config', JSON.stringify(config));
        w.FuranFormBuilder = {
          recaptcha: {
            loadApi: () => Promise.resolve({}),
            create: () => ({
              getToken: () => { rc.tokens += 1; return Promise.resolve('rc-' + rc.tokens); },
              reset: () => { rc.resets += 1; },
            }),
          },
        };
      }
    },
  });
  const submits = () => sent;
  const waits = () => timers.filter((x) => !x.done).map((x) => x.ms);
  const release = (ms) => {
    const hit = timers.find((x) => !x.done && x.ms === ms);
    assert.ok(hit, 'no timer of ' + ms + ' ms in ' + JSON.stringify(waits()));
    hit.done = true;
    hit.fn();
  };
  const submit = () => t.form.dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  return Object.assign(t, { held, submits, waits, release, submit, clock, rc, status: () => t.q('[data-ffb-status]').textContent });
}

test('it holds a submit made before the token is accepted and sends it afterwards', async () => {
  const t = env({ notBefore: 3 });
  t.submit();
  await flush();
  assert.equal(t.submits().length, 0);
  assert.deepEqual(t.waits(), [4000]);
  t.release(4000);
  await flush();
  assert.equal(t.submits().length, 1);
  assert.equal(t.submits()[0].init.body.get('ffb_token'), 'tok.1');
});

test('it keeps the submit button aria disabled and shows the sending status while a held submit waits', async () => {
  const t = env({ notBefore: 3 });
  t.submit();
  await flush();
  const button = t.q('[data-ffb-submit]');
  assert.equal(button.getAttribute('aria-disabled'), 'true');
  assert.equal(button.getAttribute('aria-busy'), 'true');
  assert.equal(t.status(), 'Sending…');
  t.submit();
  await flush();
  assert.deepEqual(t.waits(), [4000]);
  t.release(4000);
  await flush();
  assert.equal(t.submits().length, 1);
});

test('it sends immediately when the token is already old enough', async () => {
  const t = env({ notBefore: 3, eager: true });
  await flush();
  t.clock.now += 4000;
  t.submit();
  await flush();
  assert.deepEqual(t.waits(), []);
  assert.equal(t.submits().length, 1);
});

test('it keeps the token and retries once after a too early response', async () => {
  const early = { status: 425, body: { success: false, message: 'Please wait a moment and try again.', errors: {} } };
  const t = env({ submits: [early] });
  t.submit();
  await flush();
  assert.equal(t.submits().length, 1);
  assert.deepEqual(t.waits(), [1000]);
  assert.equal(t.q('[name="ffb_token"]').value, 'tok.1');
  t.release(1000);
  await flush();
  assert.equal(t.submits().length, 2);
  assert.equal(t.submits()[1].init.body.get('ffb_token'), 'tok.1');
  assert.equal(t.tokenCalls().length, 1);
  assert.equal(t.form.hidden, true);
});

test('it shows the server message after a second too early response', async () => {
  const early = { status: 425, body: { success: false, message: 'Please wait a moment and try again.', errors: {} } };
  const t = env({ submits: [early, early] });
  t.submit();
  await flush();
  t.release(1000);
  await flush();
  assert.equal(t.submits().length, 2);
  assert.equal(t.status(), 'Please wait a moment and try again.');
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
  assert.deepEqual(t.waits(), []);
  assert.equal(t.q('[name="ffb_token"]').value, 'tok.1');
});

test('it reuses the recaptcha token when retrying after a too early response', async () => {
  const early = { status: 425, body: { success: false, message: 'Wait.', errors: {} } };
  const t = env({ recaptcha: true, submits: [early] });
  t.submit();
  await flush();
  t.release(1000);
  await flush();
  assert.equal(t.submits().length, 2);
  assert.equal(t.rc.tokens, 1);
  assert.equal(t.rc.resets, 0);
  assert.equal(t.submits()[0].init.body.get('g-recaptcha-response'), 'rc-1');
  assert.equal(t.submits()[1].init.body.get('g-recaptcha-response'), 'rc-1');
});

test('it requests the recaptcha token after a long wait', async () => {
  const t = env({ recaptcha: true, notBefore: 90, eager: true });
  await flush();
  t.submit();
  await flush();
  assert.equal(t.rc.tokens, 0);
  assert.deepEqual(t.waits(), [91000]);
  assert.equal(t.status(), 'Please wait a moment…');
  assert.equal(t.q('[data-ffb-submit]').getAttribute('aria-disabled'), 'true');
  t.submit();
  await flush();
  assert.deepEqual(t.waits(), [91000]);
  t.release(91000);
  await flush();
  assert.equal(t.rc.tokens, 1);
  assert.equal(t.submits().length, 1);
  assert.equal(t.submits()[0].init.body.get('g-recaptcha-response'), 'rc-1');
});

test('it takes the recaptcha token first when the wait is short', async () => {
  const t = env({ recaptcha: true, notBefore: 3 });
  t.submit();
  await flush();
  assert.equal(t.rc.tokens, 1);
  assert.deepEqual(t.waits(), [4000]);
  t.release(4000);
  await flush();
  assert.equal(t.submits().length, 1);
  assert.equal(t.submits()[0].init.body.get('g-recaptcha-response'), 'rc-1');
});

test('it asks for a fresh recaptcha token when the long wait is only learned after the first one', async () => {
  const t = env({ recaptcha: true, notBefore: 90 });
  t.submit();
  await flush();
  assert.equal(t.rc.tokens, 1);
  assert.equal(t.rc.resets, 1);
  assert.deepEqual(t.waits(), [91000]);
  assert.equal(t.q('[data-ffb-submit]').disabled, false);
  t.release(91000);
  await flush();
  assert.equal(t.rc.tokens, 2);
  assert.equal(t.submits().length, 1);
  assert.equal(t.submits()[0].init.body.get('g-recaptcha-response'), 'rc-2');
});

test('it keeps the sending status until the response arrives', async () => {
  const t = env({ notBefore: 3, hold: true });
  t.submit();
  await flush();
  t.release(4000);
  await flush();
  assert.equal(t.submits().length, 1);
  assert.equal(t.status(), 'Sending…');
  t.held[0]();
  await flush();
  assert.equal(t.status(), '');
});

test('it shows the sending status when no wait was needed', async () => {
  const t = env({ notBefore: 3, eager: true, hold: true });
  await flush();
  t.clock.now += 4000;
  t.submit();
  await flush();
  assert.equal(t.submits().length, 1);
  assert.equal(t.status(), 'Sending…');
});
