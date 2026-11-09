'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { scriptOrder } = require('./helpers/load-storefront');

const root = path.resolve(__dirname, '../..');

test('it loads the storefront scripts in the order form.phtml prints them', () => {
  const template = fs.readFileSync(path.join(root, 'view/frontend/templates/form.phtml'), 'utf8');
  const printed = [];
  for (const line of template.split('\n')) {
    const own = /Furan_FormBuilder::js\/(ffb-[a-z-]+\.js)/.exec(line);
    if (own) printed.push(own[1]);
    else if (line.includes('getExtensionScripts')) printed.push('<extensions>');
  }
  assert.deepEqual(scriptOrder(), printed);
});

test('it reads the storefront scripts only through the shared loader', () => {
  const dir = __dirname;
  const files = [...fs.readdirSync(dir), ...fs.readdirSync(path.join(dir, 'helpers')).map((f) => path.join('helpers', f))]
    .filter((f) => f.endsWith('.js') && f !== path.join('helpers', 'load-storefront.js') && f !== 'storefront-loader.test.js');
  const offenders = files.filter((f) => /frontend\/web\/js\/ffb-(core|form|types|drafts|submit)\.js/.test(fs.readFileSync(path.join(dir, f), 'utf8')));
  assert.deepEqual(offenders, []);
});

test('it warns once, naming what is missing, and leaves the form alone when a required script did not load', () => {
  const { JSDOM } = require('./helpers/dom');
  const { source } = require('./helpers/load-storefront');
  for (const missing of ['ffb-types.js', 'ffb-drafts.js', 'ffb-submit.js']) {
    const dom = new JSDOM('<div data-ffb-root></div>', { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
    const warnings = [];
    dom.window.console.warn = (message) => warnings.push(String(message));
    ['ffb-core.js', 'ffb-types.js', 'ffb-drafts.js', 'ffb-submit.js', 'ffb-form.js']
      .filter((name) => name !== missing)
      .forEach((name) => dom.window.eval(source(name)));
    assert.equal(dom.window.FuranFormBuilder.init, undefined, missing);
    assert.equal(warnings.length, 1, missing);
    assert.ok(warnings[0].includes(missing), warnings[0]);
  }
});
