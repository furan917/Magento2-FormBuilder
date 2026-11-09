'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { boot } = require('./helpers/context-form');

test('it looks up field wrappers from a cached map', () => {
  const lookups = [];
  const t = boot({
    fields: [{ name: 'a' }, { name: 'b' }, { name: 'c' }],
    beforeInit: (w) => {
      const original = w.Element.prototype.querySelector;
      w.Element.prototype.querySelector = function (selector) {
        if (/^\[data-ffb-name="/.test(selector)) { lookups.push(selector); }
        return original.call(this, selector);
      };
    },
  });
  for (let i = 0; i < 3; i++) {
    t.input('a').value = 'x' + i;
    t.fire(t.input('a'), 'input');
  }
  assert.deepEqual(lookups, []);
});
