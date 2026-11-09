'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const src = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/js/submit-once.js'), 'utf8');

function boot() {
  const dom = new JSDOM(`<form data-ffb-submit-once action="/x" method="post"><button type="submit">Send</button></form>
    <form action="/y" method="post"><button type="submit">Other</button></form>`, { runScripts: 'outside-only', url: 'https://admin.test/' });
  dom.window.HTMLFormElement.prototype.submit = () => {};
  dom.window.eval(src);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  return dom.window;
}

test('it disables the submit button once a marked form is submitted', () => {
  const w = boot();
  const form = w.document.querySelector('[data-ffb-submit-once]');
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(form.querySelector('button').disabled, true);
});

test('it blocks a second submit of the same form', () => {
  const w = boot();
  const form = w.document.querySelector('[data-ffb-submit-once]');
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  const second = new w.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(second);
  assert.equal(second.defaultPrevented, true);
});

test('it leaves unmarked forms alone', () => {
  const w = boot();
  const form = w.document.querySelectorAll('form')[1];
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(form.querySelector('button').disabled, false);
});
