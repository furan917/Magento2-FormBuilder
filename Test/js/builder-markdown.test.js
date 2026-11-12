'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const markdown = require('../../view/adminhtml/web/js/builder-markdown.js');

const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/rich-text.json'), 'utf8'));
const ALLOWED = ['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'A', 'H3', 'H4', 'SPAN'];

function container(text) {
  const doc = new JSDOM('<div id="out"></div>').window.document;
  const out = doc.getElementById('out');
  out.appendChild(markdown.render(doc, text));
  return { doc, out };
}

for (const fixture of fixtures) {
  test('it builds the same dom the browser parses from the server html: ' + fixture.name, () => {
    const { doc, out } = container(fixture.text);
    const expected = doc.createElement('div');
    expected.innerHTML = fixture.html;
    assert.equal(out.innerHTML, expected.innerHTML);
  });
}

test('it never assigns innerHTML in the target document', () => {
  const doc = new JSDOM('<div></div>').window.document;
  const proto = Object.getPrototypeOf(doc.createElement('div'));
  const owner = Object.getPrototypeOf(Object.getPrototypeOf(proto));
  const descriptor = Object.getOwnPropertyDescriptor(owner, 'innerHTML');
  Object.defineProperty(owner, 'innerHTML', { configurable: true, set() { throw new Error('innerHTML used'); }, get: descriptor.get });
  try {
    assert.equal(markdown.render(doc, '## a\n[b](https://e.com) **c**').querySelectorAll('h3, p, a, span, strong').length, 5);
  } finally {
    Object.defineProperty(owner, 'innerHTML', descriptor);
  }
});

test('it exposes no html string api', () => {
  assert.deepEqual(Object.keys(markdown), ['render']);
});

test('it only ever creates the fixed tag set with fixed attributes', () => {
  const hostile = '<script>alert(1)</script> <img src=x onerror=alert(1)> [a](https://e.com/"onclick="x) '
    + '[b](javascript:alert(1)) **<i>x</i>** &lt;b&gt; [c](https://ok.test/x) [d](mailto:a@b.co) [e](/rel)\n'
    + '## <span class="ffb-sr-only">x</span>\n1. <a href="javascript:x">y</a>';
  const { out } = container(hostile);
  for (const el of out.querySelectorAll('*')) {
    assert.ok(ALLOWED.includes(el.tagName), el.tagName);
    const names = Array.from(el.attributes).map((a) => a.name).sort();
    if (el.tagName === 'A') {
      assert.ok(/^(https:\/\/|mailto:|\/)/.test(el.getAttribute('href')));
      assert.ok(names.every((n) => ['href', 'rel', 'target'].includes(n)));
    } else if (el.tagName === 'SPAN') {
      assert.deepEqual(names, ['class']);
      assert.equal(el.className, 'ffb-sr-only');
      assert.equal(el.parentNode.tagName, 'A');
    } else {
      assert.deepEqual(names, []);
    }
  }
  assert.equal(out.querySelectorAll('a').length, 3);
  assert.equal(out.querySelectorAll('span').length, 1);
  assert.match(out.textContent, /<script>alert\(1\)<\/script>/);
});

test('it nests mis-nested emphasis without losing text', () => {
  const { out } = container('**a *b** c*');
  assert.equal(out.textContent, 'a b c');
  assert.ok(out.querySelector('strong'));
});

test('it renders nothing for empty text', () => {
  const { out } = container('   ');
  assert.equal(out.childNodes.length, 0);
});
