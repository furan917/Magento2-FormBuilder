'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const typesIn = (html) => {
  const dom = new JSDOM('<div data-ffb-name="f">' + html + '</div>', { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  loadStorefront(dom.window);
  return { types: dom.window.FuranFormBuilder.types, w: dom.window.document.querySelector('[data-ffb-name]') };
};

test('it never un-hides a hidden box that is not a choice option', () => {
  const { types, w } = typesIn('<div data-ffb-map-search hidden><input type="search" value=""></div>' +
    '<input type="text" name="fields[f][lat]" value="1">');
  types.applyHiddenOptions(w, []);
  types.applyHiddenOptions(w, ['1']);
  assert.equal(w.querySelector('[data-ffb-map-search]').hidden, true);
  assert.equal(w.querySelector('[name="fields[f][lat]"]').ffbOptionHidden, undefined);
});

test('it still hides and shows choice options', () => {
  const { types, w } = typesIn('<label class="ffb-option"><input type="radio" name="fields[f]" value="a"></label>' +
    '<label class="ffb-option"><input type="radio" name="fields[f]" value="b"></label>');
  types.applyHiddenOptions(w, ['b']);
  const boxes = w.querySelectorAll('.ffb-option');
  assert.deepEqual([boxes[0].hidden, boxes[1].hidden], [false, true]);
  types.applyHiddenOptions(w, []);
  assert.equal(boxes[1].hidden, false);
});
