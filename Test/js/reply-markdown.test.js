'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');
const markdown = require('../../view/adminhtml/web/js/reply-markdown.js');

const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/reply-markdown.json'), 'utf8'));
const ALLOWED = ['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'A'];

function container(text) {
  const doc = new JSDOM('<div id="out"></div>').window.document;
  const out = doc.getElementById('out');
  out.appendChild(markdown.render(text, doc));
  return { doc, out };
}

for (const fixture of fixtures) {
  test('it renders the same html as the server: ' + fixture.name, () => {
    assert.equal(markdown.toHtml(fixture.text), fixture.html);
  });

  if (!fixture.misnested) {
    test('it builds the same dom the browser parses from the server html: ' + fixture.name, () => {
      const { doc, out } = container(fixture.text);
      const expected = doc.createElement('div');
      expected.innerHTML = fixture.html;
      assert.equal(out.innerHTML, expected.innerHTML);
    });
  }
}

test('it only ever creates the fixed tag set with fixed link attributes', () => {
  const hostile = '<script>alert(1)</script> <img src=x onerror=alert(1)> [a](https://e.com/"onclick="x) '
    + '[b](javascript:alert(1)) **<i>x</i>** &lt;b&gt; [c](https://ok.test/x) [d](mailto:a@b.co) **a *b** c*';
  const { out } = container(hostile);
  for (const el of out.querySelectorAll('*')) {
    assert.ok(ALLOWED.includes(el.tagName), el.tagName);
    const names = Array.from(el.attributes).map((a) => a.name).sort();
    if (el.tagName === 'A') {
      assert.ok(/^(https:\/\/|mailto:)/.test(el.getAttribute('href')));
      assert.equal(el.getAttribute('rel'), 'noopener noreferrer nofollow');
      assert.ok(names.every((n) => ['href', 'rel', 'target'].includes(n)));
    } else {
      assert.deepEqual(names, []);
    }
  }
  assert.equal(out.querySelectorAll('a').length, 2);
  assert.match(out.textContent, /<script>alert\(1\)<\/script>/);
  assert.match(out.textContent, /&lt;b&gt;/);
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
