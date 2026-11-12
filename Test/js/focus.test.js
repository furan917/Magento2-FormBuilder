'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const snapshot = fs.readFileSync(path.resolve(__dirname, '../fixtures/rendered-form.html'), 'utf8')
  .replace(/<script src[^>]*><\/script>/g, '')
  .replace(/<link[^>]*>/g, '');

const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

function boot(submitResponse, mutate) {
  const dom = new JSDOM(snapshot, { runScripts: 'outside-only', url: 'https://shop.test/forms/contact' });
  const w = dom.window;
  const doc = w.document;
  if (mutate) mutate(doc);
  const submits = [];
  w.fetch = (url) => {
    if (String(url).includes('/token')) return Promise.resolve(json(200, { token: 't' }));
    submits.push(url);
    return submitResponse();
  };
  loadStorefront(w);
  const q = (s) => doc.querySelector(s);
  const type = (name, value) => {
    const el = q(`[name="fields[${name}]"]`);
    el.value = value;
    el.dispatchEvent(new w.Event('input', { bubbles: true }));
    el.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  return { w, doc, q, type, submits };
}

const toLastPage = (t) => {
  t.type('name', 'Jane Doe');
  t.q('[data-ffb-next]').focus();
  t.q('[data-ffb-next]').click();
  t.type('message', 'this is long enough');
};

test('it moves focus to the new page after next and back', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  t.type('name', 'Jane Doe');
  t.q('[data-ffb-next]').focus();
  t.q('[data-ffb-next]').click();
  const heading = t.q('[data-ffb-page="p_bbbb02"] .ffb-page-title');
  assert.equal(t.doc.activeElement, heading);
  assert.equal(heading.getAttribute('tabindex'), '-1');
  t.q('[data-ffb-prev]').focus();
  t.q('[data-ffb-prev]').click();
  assert.equal(t.doc.activeElement, t.q('[data-ffb-page="p_aaaa01"] .ffb-page-title'));
});

test('it keeps focus on the submit control while busy and after an error', async () => {
  let respond;
  const t = boot(() => new Promise((resolve) => { respond = resolve; }));
  await flush();
  toLastPage(t);
  const btn = t.q('[data-ffb-submit]');
  btn.focus();
  btn.click();
  await flush();
  assert.equal(btn.getAttribute('aria-disabled'), 'true');
  assert.equal(btn.disabled, false);
  assert.equal(t.doc.activeElement, btn);
  respond(json(429, { success: false, message: 'Slow down', errors: {} }));
  await flush();
  assert.equal(btn.hasAttribute('aria-disabled'), false);
  assert.equal(t.doc.activeElement, btn);
});

test('it ignores a second submit while the button is aria disabled', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  toLastPage(t);
  t.q('[data-ffb-submit]').click();
  await flush();
  const again = new t.w.Event('submit', { bubbles: true, cancelable: true });
  t.q('[data-ffb-form]').dispatchEvent(again);
  t.q('[data-ffb-submit]').click();
  await flush();
  assert.equal(again.defaultPrevented, true);
  assert.equal(t.submits.length, 1);
});

const addThirdPage = (doc, withHeading = true) => {
  const el = doc.querySelector('[data-ffb-root]');
  const cfg = JSON.parse(el.getAttribute('data-ffb-config'));
  cfg.schema.pages.push({ id: 'p_cccc03', kind: 'page', title: 'Last', items: [] });
  el.setAttribute('data-ffb-config', JSON.stringify(cfg));
  const page = doc.createElement('section');
  page.setAttribute('data-ffb-page', 'p_cccc03');
  page.setAttribute('data-ffb-item', 'p_cccc03');
  if (withHeading) {
    page.innerHTML = '<h2 class="ffb-page-title">Last</h2>';
  }
  doc.querySelector('[data-ffb-page="p_bbbb02"]').after(page);
};

test('it moves focus to the new page and announces the step after a plain next', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => addThirdPage(doc));
  await flush();
  t.type('name', 'Jane Doe');
  t.q('[data-ffb-next]').focus();
  t.q('[data-ffb-next]').click();
  assert.equal(t.q('[data-ffb-next]').hidden, false);
  assert.equal(t.doc.activeElement, t.q('[data-ffb-page="p_bbbb02"] .ffb-page-title'));
  assert.equal(t.q('[data-ffb-step-status]').textContent, 'Step 2 of 3');
  t.q('[data-ffb-prev]').focus();
  t.q('[data-ffb-prev]').click();
  assert.equal(t.doc.activeElement, t.q('[data-ffb-page="p_aaaa01"] .ffb-page-title'));
  assert.equal(t.q('[data-ffb-step-status]').textContent, 'Step 1 of 3');
});

test('it focuses the page itself when the new page has no heading or legend', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => addThirdPage(doc, false));
  await flush();
  t.type('name', 'Jane Doe');
  t.q('[data-ffb-next]').click();
  t.type('message', 'this is long enough');
  t.q('[data-ffb-next]').focus();
  t.q('[data-ffb-next]').click();
  const page = t.q('[data-ffb-page="p_cccc03"]');
  assert.equal(page.hidden, false);
  assert.equal(t.doc.activeElement, page);
  assert.equal(page.getAttribute('tabindex'), '-1');
});

const enter = (t, el) => {
  const ev = new t.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  el.dispatchEvent(ev);
  return ev;
};

test('it ignores enter on a link and inside a map widget', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => {
    const page = doc.querySelector('[data-ffb-page="p_aaaa01"]');
    page.insertAdjacentHTML('beforeend', '<p><a href="#terms" data-test-link>Terms</a></p>' +
      '<div data-ffb-type="map"><div role="application" tabindex="0" data-test-map></div>' +
      '<input type="text" data-test-map-input></div>');
  });
  await flush();
  t.type('name', 'Jane Doe');
  [t.q('[data-test-link]'), t.q('[data-test-map]')].forEach((el) => {
    assert.equal(enter(t, el).defaultPrevented, false);
  });
  assert.equal(enter(t, t.q('[data-test-map-input]')).defaultPrevented, true, 'no native implicit submission either');
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
});

test('it ignores enter on choices and on an event already handled', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  t.type('name', 'Jane Doe');
  assert.equal(enter(t, t.q('#ffb-7-contact-0')).defaultPrevented, true);
  const handled = (ev) => ev.preventDefault();
  const name = t.q('#ffb-7-name');
  name.addEventListener('keydown', handled);
  enter(t, name);
  name.removeEventListener('keydown', handled);
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
});

test('it still advances on enter in a text input', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  t.type('name', 'Jane Doe');
  assert.equal(enter(t, t.q('#ffb-7-email')).defaultPrevented, true);
  assert.equal(t.q('[data-ffb-page="p_bbbb02"]').hidden, false);
});

const blur = (t, el, to) => el.dispatchEvent(new t.w.FocusEvent('focusout', { bubbles: true, relatedTarget: to || null }));
const pointer = (t, el, type) => el.dispatchEvent(new t.w.Event(type, { bubbles: true }));

test('it shows a blur error only after the click that caused the blur', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  const name = t.q('#ffb-7-name');
  name.value = 'J';
  const other = t.q('#ffb-7-contact-0');
  pointer(t, other, 'pointerdown');
  blur(t, name, other);
  assert.equal(t.q('#ffb-7-name-error').hidden, true);
  pointer(t, other, 'pointerup');
  other.click();
  assert.equal(t.q('#ffb-7-name-error').hidden, true);
  await flush();
  assert.equal(t.q('#ffb-7-name-error').hidden, false);
});

test('it shows a blur error at once without a pointer', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  const name = t.q('#ffb-7-name');
  name.value = 'J';
  blur(t, name);
  assert.equal(t.q('#ffb-7-name-error').hidden, false);
});

test('it drops a deferred blur error when the click starts over', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => {
    doc.defaultView.sessionStorage.setItem('ffb:1:7:3dd458cc8832',
      JSON.stringify({ v: 1, savedAt: Date.now(), page: 'p_aaaa01', values: { name: 'J' } }));
  });
  await flush();
  const name = t.q('#ffb-7-name');
  assert.equal(name.value, 'J');
  const startOver = t.q('[data-ffb-status] .ffb-btn-link');
  pointer(t, startOver, 'pointerdown');
  blur(t, name, startOver);
  pointer(t, startOver, 'pointerup');
  startOver.click();
  await flush();
  assert.equal(name.value, '');
  assert.equal(t.q('#ffb-7-name-error').hidden, true);
});

test('it shows an error from a changed value only after the click that blurred it', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  const name = t.q('#ffb-7-name');
  name.value = 'J';
  const radio = t.q('#ffb-7-contact-1');
  pointer(t, radio, 'pointerdown');
  name.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  blur(t, name, radio);
  assert.equal(t.q('#ffb-7-name-error').hidden, true);
  pointer(t, radio, 'pointerup');
  radio.click();
  assert.equal(radio.checked, true);
  assert.equal(t.q('#ffb-7-name-error').hidden, true);
  await flush();
  assert.equal(t.q('#ffb-7-name-error').hidden, false);
});

test('it applies a deferred change before next validates the page', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  const name = t.q('#ffb-7-name');
  name.value = 'J';
  const nextBtn = t.q('[data-ffb-next]');
  pointer(t, nextBtn, 'pointerdown');
  name.dispatchEvent(new t.w.Event('change', { bubbles: true }));
  blur(t, name, nextBtn);
  pointer(t, nextBtn, 'pointerup');
  nextBtn.click();
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
  assert.equal(t.q('#ffb-7-name-error').hidden, false);
});

test('it stops enter on a radio or checkbox from submitting', async () => {
  const t = boot(() => new Promise(() => {}));
  await flush();
  t.type('name', 'Jane Doe');
  assert.equal(enter(t, t.q('#ffb-7-contact-0')).defaultPrevented, true);
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
});

test('it announces the step after a summary edit jumps back', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => {
    addThirdPage(doc);
    doc.querySelector('[data-ffb-page="p_cccc03"]').insertAdjacentHTML('beforeend',
      '<div data-ffb-summary data-ffb-edit-label="Edit"><dl class="ffb-summary-list"></dl></div>');
  });
  await flush();
  t.type('name', 'Jane Doe');
  t.q('[data-ffb-next]').click();
  t.type('message', 'this is long enough');
  t.q('[data-ffb-next]').click();
  assert.equal(t.q('[data-ffb-step-status]').textContent, 'Step 3 of 3');
  t.q('.ffb-summary-edit').click();
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
  assert.equal(t.q('[data-ffb-step-status]').textContent, 'Step 1 of 3');
});

test('it waits with a blur error while focus stays inside the same field', async () => {
  const t = boot(() => new Promise(() => {}), (doc) => {
    const el = doc.querySelector('[data-ffb-root]');
    const cfg = JSON.parse(el.getAttribute('data-ffb-config'));
    cfg.schema.pages[0].items.push({ id: 'f_cons01', kind: 'field', type: 'consent', name: 'agree', label: 'Agree', required: true,
      rules: [], options: [], settings: {}, draft: false, required_message: 'This field is required.' });
    el.setAttribute('data-ffb-config', JSON.stringify(cfg));
    doc.querySelector('[data-ffb-page="p_aaaa01"] .ffb-grid').insertAdjacentHTML('beforeend',
      '<div data-ffb-item="f_cons01" data-ffb-name="agree"><input type="checkbox" id="agree" name="fields[agree]" value="yes" required>' +
      '<label class="ffb-label" for="agree">I agree to the <a href="#privacy" id="privacy">privacy notice</a></label>' +
      '<p data-ffb-error hidden></p></div>');
  });
  await flush();
  const box = t.q('#agree');
  const error = t.q('[data-ffb-name="agree"] [data-ffb-error]');
  blur(t, box, t.q('#privacy'));
  assert.equal(error.hidden, true);
  blur(t, t.q('#privacy'), t.q('[data-ffb-next]'));
  assert.equal(error.hidden, false);
});
