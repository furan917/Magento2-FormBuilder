'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const file = path.resolve(__dirname, '../../view/frontend/web/js/ffb-reply.js');
const src = fs.readFileSync(file, 'utf8');
const reply = require(file);

function boot(value = '') {
  const dom = new JSDOM(`<form data-ffb-reply method="post" action="/r">
    <textarea name="body" rows="4" maxlength="10000" data-ffb-max="5000" required data-ffb-reply-body>${value}</textarea>
    <p data-ffb-reply-count data-ffb-left="%1 characters left" hidden></p>
    <p data-ffb-reply-live aria-live="polite"></p>
    <button type="submit">Send</button></form>`, { runScripts: 'outside-only', url: 'https://shop.test/furanform/account/submission' });
  dom.window.HTMLFormElement.prototype.submit = () => {};
  dom.window.eval(src);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  const d = dom.window.document;
  const ui = {
    w: dom.window,
    form: d.querySelector('form'),
    input: d.querySelector('textarea'),
    count: d.querySelector('[data-ffb-reply-count]'),
    live: d.querySelector('[data-ffb-reply-live]'),
    button: d.querySelector('button'),
    type(text) {
      ui.input.value = text;
      ui.input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    },
    submit() {
      const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
      ui.form.dispatchEvent(event);
      return event;
    },
  };
  return ui;
}

test('it counts characters like the server roughly: code points, trimmed, CRLF as one', () => {
  assert.equal(reply.length('  hi  '), 2);
  assert.equal(reply.length('a\r\nb'), 3);
  assert.equal(reply.length('\u{1F600}x'), 2);
  assert.equal(reply.length(''), 0);
});

test('it shows a live x / 5000 counter', () => {
  const ui = boot();
  assert.equal(ui.count.hidden, false);
  assert.equal(ui.count.textContent, '0 / 5000');
  ui.type('Hello');
  assert.equal(ui.count.textContent, '5 / 5000');
});

test('it announces only when crossing thresholds near the limit', () => {
  const ui = boot();
  ui.type('a'.repeat(100));
  assert.equal(ui.live.textContent, '');
  ui.type('a'.repeat(4600));
  assert.equal(ui.live.textContent, '400 characters left');
  ui.live.textContent = '';
  ui.type('a'.repeat(4650));
  assert.equal(ui.live.textContent, '', 'no announcement within the same band');
  ui.type('a'.repeat(4950));
  assert.equal(ui.live.textContent, '50 characters left');
  ui.type('a'.repeat(5000));
  assert.equal(ui.live.textContent, '0 characters left');
  assert.ok(ui.count.classList.contains('is-near'));
});

test('it disables submit while the reply is empty or blank', () => {
  const ui = boot();
  assert.equal(ui.button.disabled, true);
  ui.type('   \n ');
  assert.equal(ui.button.disabled, true);
  ui.type('Thanks');
  assert.equal(ui.button.disabled, false);
});

test('it keeps a prefilled reply submittable after a failed post', () => {
  const ui = boot('Draft');
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.count.textContent, '5 / 5000');
});

test('it disables submit once sending and blocks a second submit', () => {
  const ui = boot();
  ui.type('Thanks');
  assert.equal(ui.submit().defaultPrevented, false);
  assert.equal(ui.button.disabled, true);
  assert.equal(ui.submit().defaultPrevented, true);
  ui.type('Thanks again');
  assert.equal(ui.button.disabled, true, 'typing does not re-enable while submitting');
});

test('it does not lock the form when another handler cancelled the submit', () => {
  const ui = boot();
  ui.type('Thanks');
  ui.form.addEventListener('submit', (e) => e.preventDefault(), { capture: true });
  ui.submit();
  assert.equal(ui.button.disabled, false);
});

test('it re-enables the form when the page is restored from the back-forward cache', () => {
  const ui = boot();
  ui.type('Thanks');
  ui.submit();
  const event = new ui.w.Event('pageshow');
  event.persisted = true;
  ui.w.dispatchEvent(event);
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.submit().defaultPrevented, false);
});

test('it grows the textarea up to sixteen rows', () => {
  assert.equal(reply.rows(0, 20, 4, 16), 4);
  assert.equal(reply.rows(200, 20, 4, 16), 10);
  assert.equal(reply.rows(205, 20, 4, 16), 11);
  assert.equal(reply.rows(2000, 20, 4, 16), 16);
  assert.equal(reply.rows(200, 0, 4, 16), 4);
});

test('it builds no markup from strings and uses no inline handlers', () => {
  assert.doesNotMatch(src, /innerHTML|outerHTML|insertAdjacentHTML|eval\(|new Function|setAttribute\('on/);
});

test('it counts an emoji as one character and blocks sending over the limit', () => {
  const ui = boot();
  ui.type('😀'.repeat(3000));
  assert.equal(ui.count.textContent, '3000 / 5000');
  assert.equal(ui.button.disabled, false);
  ui.type('x'.repeat(5001));
  assert.equal(ui.button.disabled, true);
  assert.ok(ui.count.classList.contains('is-over'));
});
