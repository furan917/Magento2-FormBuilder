'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront } = require('./helpers/load-storefront');
const SUMMARY = '<div class="ffb-item ffb-content ffb-content-summary ffb-w-full" data-ffb-item="c_sum001">' +
  '<div class="ffb-summary" data-ffb-summary data-ffb-edit-label="Edit" data-ffb-yes-label="Yes">' +
  '<h3 class="ffb-summary-title">Review your answers</h3><dl class="ffb-summary-list"></dl></div></div>';
const snapshot = read('Test/fixtures/rendered-form.html')
  .replace(/<script src[^>]*><\/script>/g, '')
  .replace(/<link[^>]*>/g, '')
  .replace(/(Your message<\/h2>\s*<div class="ffb-grid">)/, (m) => m + SUMMARY);

async function boot() {
  const dom = new JSDOM(snapshot, { runScripts: 'outside-only', url: 'https://shop.test/forms/contact' });
  const w = dom.window;
  w.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't' }) });
  loadStorefront(w);
  const doc = w.document;
  const q = (s) => doc.querySelector(s);
  const fire = (el, type) => el.dispatchEvent(new w.Event(type, { bubbles: true }));
  const type = (name, value) => {
    const el = q(`[name="fields[${name}]"]`);
    el.value = value;
    fire(el, 'input');
  };
  const choose = (name, value) => {
    const el = q(`[name="fields[${name}]"][value="${value}"]`);
    el.checked = true;
    fire(el, 'change');
  };
  const rows = () => Array.from(doc.querySelectorAll('.ffb-summary-row')).map((r) => [
    r.querySelector('dt').textContent, r.querySelector('dd').textContent,
  ]);
  if (doc.readyState === 'loading') {
    await new Promise((r) => doc.addEventListener('DOMContentLoaded', r));
  }
  await new Promise((r) => setTimeout(r, 15));
  return { w, doc, q, type, choose, rows };
}

test('it fills the summary with visible answers given before it, in document order, when its page is shown', async () => {
  const t = await boot();
  assert.equal(t.rows().length, 0);
  t.type('name', 'Jane <b>Doe</b>');
  t.choose('contact', 'phone');
  t.q('[data-ffb-next]').click();
  assert.deepEqual(t.rows(), [
    ['Name', 'Jane <b>Doe</b>'],
    ['Preferred contact', 'Phone'],
  ]);
  assert.equal(t.doc.querySelectorAll('.ffb-summary b').length, 0);
});

test('it lists conditionally shown fields only while they are visible and omits hidden inputs', async () => {
  const t = await boot();
  t.type('name', 'Jane');
  t.choose('contact', 'email');
  t.type('email', 'a@b.test');
  t.q('[data-ffb-next]').click();
  const labels = t.rows().map((r) => r[0]);
  assert.ok(labels.includes('Email address'));
  assert.ok(!labels.includes('Source'));
  assert.deepEqual(t.rows().find((r) => r[0] === 'Preferred contact'), ['Preferred contact', 'Email']);
});

test('it refreshes the summary when the visitor goes back and changes an answer', async () => {
  const t = await boot();
  t.type('name', 'Jane');
  t.q('[data-ffb-next]').click();
  t.q('[data-ffb-prev]').click();
  t.type('name', 'Janet');
  t.q('[data-ffb-next]').click();
  assert.deepEqual(t.rows().find((r) => r[0] === 'Name'), ['Name', 'Janet']);
});

test('it navigates to the field page and focuses the field from the edit button', async () => {
  const t = await boot();
  t.type('name', 'Jane');
  t.q('[data-ffb-next]').click();
  const edit = t.doc.querySelector('.ffb-summary-row .ffb-summary-edit');
  assert.equal(edit.type, 'button');
  assert.equal(edit.textContent, 'Edit');
  edit.click();
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
  assert.equal(t.q('[data-ffb-page="p_bbbb02"]').hidden, true);
  assert.equal(t.doc.activeElement, t.q('#ffb-7-name'));
});

test('it falls back to the generic summary when a hook throws or returns a non string', async () => {
  const warnings = [];
  for (const hook of [() => { throw new Error('boom'); }, () => undefined, () => 42, () => null]) {
    const t = await boot();
    t.w.console.warn = (message) => warnings.push(String(message));
    t.w.FuranFormBuilder.registry.fieldType('shout', { summary: hook });
    t.q('[data-ffb-name="name"]').setAttribute('data-ffb-type', 'shout');
    t.type('name', 'Jane');
    t.q('[data-ffb-next]').click();
    assert.deepEqual(t.rows().find((r) => r[0] === 'Name'), ['Name', 'Jane']);
    assert.deepEqual(t.rows().map((r) => r[1]).filter((text) => text === 'undefined' || text === 'null' || text === '42'), []);
  }
  assert.equal(warnings.length, 1);
});
