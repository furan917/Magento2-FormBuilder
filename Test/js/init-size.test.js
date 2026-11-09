'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { source } = require('./helpers/load-storefront');

test('it keeps init under one hundred lines', () => {
  const lines = source('ffb-form.js').split('\n');
  const start = lines.findIndex((line) => line === '  var init = function (rootEl) {');
  const end = lines.findIndex((line, i) => i > start && line === '  };');
  assert.ok(start > 0 && end > start);
  assert.ok(end - start + 1 < 100, 'init is ' + (end - start + 1) + ' lines');
});
