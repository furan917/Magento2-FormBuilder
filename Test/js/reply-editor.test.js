'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const editor = require('../../view/adminhtml/web/js/reply-editor.js');

const root = path.resolve(__dirname, '../../view/adminhtml/web/js');
const markdownSrc = fs.readFileSync(path.join(root, 'reply-markdown.js'), 'utf8');
const editorSrc = fs.readFileSync(path.join(root, 'reply-editor.js'), 'utf8');
const submitOnceSrc = fs.readFileSync(path.join(root, 'submit-once.js'), 'utf8');

const apply = (value, start, end, action) => editor.applyFormat(value, start, end, action, { linkText: 'link text' });

test('it wraps the selection in bold and italic markers', () => {
  assert.deepEqual(apply('say hi now', 4, 6, 'bold'), { value: 'say **hi** now', start: 6, end: 8 });
  assert.deepEqual(apply('say hi now', 4, 6, 'italic'), { value: 'say *hi* now', start: 5, end: 7 });
});

test('it inserts empty markers with the caret inside when nothing is selected', () => {
  assert.deepEqual(apply('ab', 1, 1, 'bold'), { value: 'a****b', start: 3, end: 3 });
});

test('it removes bold markers that already surround the selection', () => {
  assert.deepEqual(apply('say **hi** now', 6, 8, 'bold'), { value: 'say hi now', start: 4, end: 6 });
});

test('it turns the selection into a link label and selects the url placeholder', () => {
  assert.deepEqual(apply('see docs', 4, 8, 'link'), { value: 'see [docs](https://)', start: 11, end: 19 });
  assert.deepEqual(apply('', 0, 0, 'link'), { value: '[link text](https://)', start: 1, end: 10 });
});

test('it uses a selected url as the link target and selects the label', () => {
  assert.deepEqual(apply('https://e.com', 0, 13, 'link'), { value: '[link text](https://e.com)', start: 1, end: 10 });
});

test('it prefixes every selected line for lists and numbers them', () => {
  assert.equal(apply('a\nb\nc', 0, 5, 'bullets').value, '- a\n- b\n- c');
  assert.equal(apply('a\nb\nc', 2, 3, 'bullets').value, 'a\n- b\nc');
  assert.equal(apply('a\nb', 0, 3, 'numbers').value, '1. a\n2. b');
});

test('it keeps a bare caret at the end of the new list item so typing continues it', () => {
  assert.deepEqual(apply('Hello\n\n', 7, 7, 'bullets'), { value: 'Hello\n\n- ', start: 9, end: 9 });
  assert.deepEqual(apply('one\ntwo', 6, 6, 'numbers'), { value: 'one\n1. two', start: 10, end: 10 });
  assert.deepEqual(apply('a\nb', 0, 3, 'bullets'), { value: '- a\n- b', start: 0, end: 7 });
});

test('it removes list prefixes when every selected line already has them', () => {
  assert.equal(apply('- a\n- b', 0, 7, 'bullets').value, 'a\nb');
  assert.equal(apply('1. a\n2. b', 0, 9, 'numbers').value, 'a\nb');
});

test('it leaves the text alone for an unknown action', () => {
  assert.deepEqual(apply('abc', 0, 1, 'evil'), { value: 'abc', start: 0, end: 1 });
});

function boot(value = '') {
  const dom = new JSDOM(`<form data-ffb-submit-once data-ffb-reply-editor method="post" action="/reply">
    <div data-ffb-md-head hidden>
      <div role="tablist">
        <button type="button" role="tab" aria-selected="true" data-ffb-md-tab="write">Write</button>
        <button type="button" role="tab" aria-selected="false" data-ffb-md-tab="preview">Preview</button>
      </div>
      <div role="toolbar">
        <button type="button" data-ffb-md="bold" aria-label="Bold">B</button>
        <button type="button" data-ffb-md="link" aria-label="Link">L</button>
        <button type="button" data-ffb-md="numbers" aria-label="Numbered list">1.</button>
      </div>
    </div>
    <textarea name="body" required data-ffb-md-input data-ffb-link-text="link text">${value}</textarea>
    <div data-ffb-md-preview hidden data-ffb-empty="Nothing to preview."></div>
    <button type="submit">Send</button>
  </form>`, { runScripts: 'outside-only', url: 'https://admin.test/' });
  dom.window.HTMLFormElement.prototype.submit = () => {};
  dom.window.eval(markdownSrc);
  dom.window.eval(editorSrc);
  dom.window.eval(submitOnceSrc);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  const d = dom.window.document;
  return {
    w: dom.window,
    form: d.querySelector('form'),
    input: d.querySelector('textarea'),
    preview: d.querySelector('[data-ffb-md-preview]'),
    tab: (name) => d.querySelector(`[data-ffb-md-tab="${name}"]`),
    tool: (name) => d.querySelector(`[data-ffb-md="${name}"]`),
    head: d.querySelector('[data-ffb-md-head]'),
  };
}

test('it reveals the toolbar only once the script runs', () => {
  const ui = boot();
  assert.equal(ui.head.hidden, false);
});

test('it applies toolbar buttons to the textarea selection', () => {
  const ui = boot('hello world');
  ui.input.setSelectionRange(6, 11);
  ui.tool('bold').click();
  assert.equal(ui.input.value, 'hello **world**');
});

test('it supports Ctrl or Cmd + B, I and K shortcuts', () => {
  const ui = boot('one two');
  ui.input.setSelectionRange(0, 3);
  ui.input.dispatchEvent(new ui.w.KeyboardEvent('keydown', { key: 'b', ctrlKey: true, bubbles: true, cancelable: true }));
  assert.equal(ui.input.value, '**one** two');
  ui.input.setSelectionRange(8, 11);
  const k = new ui.w.KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true });
  ui.input.dispatchEvent(k);
  assert.equal(k.defaultPrevented, true);
  assert.equal(ui.input.value, '**one** [two](https://)');
  ui.input.setSelectionRange(2, 5);
  ui.input.dispatchEvent(new ui.w.KeyboardEvent('keydown', { key: 'I', ctrlKey: true, bubbles: true, cancelable: true }));
  assert.equal(ui.input.value, '***one*** [two](https://)');
});

test('it previews with the server markdown rules and never injects raw html', () => {
  const ui = boot('**hi** <img src=x onerror=alert(1)> [x](javascript:alert(1)) [ok](https://e.com)');
  ui.tab('preview').click();

  assert.equal(ui.preview.hidden, false);
  assert.equal(ui.input.hidden, true);
  assert.equal(ui.tab('preview').getAttribute('aria-selected'), 'true');
  assert.equal(ui.tool('bold').disabled, true);
  assert.equal(ui.preview.querySelector('strong').textContent, 'hi');
  assert.equal(ui.preview.querySelector('img'), null);
  assert.equal(ui.preview.querySelectorAll('a').length, 1);
  assert.equal(ui.preview.querySelector('a').getAttribute('rel'), 'noopener noreferrer nofollow');
  assert.match(ui.preview.textContent, /<img src=x onerror=alert\(1\)>/);
});

test('it shows a placeholder when there is nothing to preview', () => {
  const ui = boot('   ');
  ui.tab('preview').click();
  assert.equal(ui.preview.textContent, 'Nothing to preview.');
});

test('it returns to the write tab before submitting so the required textarea is focusable', () => {
  const ui = boot('');
  ui.tab('preview').click();
  ui.form.querySelector('button[type="submit"]').click();
  assert.equal(ui.input.hidden, false);
  assert.equal(ui.preview.hidden, true);
  assert.equal(ui.tab('write').getAttribute('aria-selected'), 'true');
});

test('it keeps the submit-once behaviour', () => {
  const ui = boot('hello');
  ui.form.dispatchEvent(new ui.w.Event('submit', { bubbles: true, cancelable: true }));
  const second = new ui.w.Event('submit', { bubbles: true, cancelable: true });
  ui.form.dispatchEvent(second);
  assert.equal(second.defaultPrevented, true);
  assert.equal(ui.form.querySelector('button[type="submit"]').disabled, true);
});
