'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const page = () => {
  const dom = new JSDOM('<!doctype html><head></head><body></body>', { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  return dom.window;
};
const scripts = (w) => Array.from(w.document.querySelectorAll('script[src]')).map((s) => s.src);

test('it offers value read and write to extension scripts before ffb-form.js runs', () => {
  const w = page();
  const seen = {};
  loadStorefront(w, [(win) => {
    const types = win.FuranFormBuilder.types;
    seen.read = typeof types.readValue;
    seen.write = typeof types.writeValue;
    seen.form = typeof win.FuranFormBuilder.init;
  }]);
  assert.deepEqual(seen, { read: 'function', write: 'function', form: 'undefined' });
  const wrapper = w.document.createElement('div');
  wrapper.innerHTML = '<input type="text" name="fields[a]">';
  w.FuranFormBuilder.types.writeValue(wrapper, 'x');
  assert.equal(w.FuranFormBuilder.types.readValue(wrapper), 'x');
});

test('it loads reCAPTCHA and map assets once for two forms on a page', () => {
  const w = page();
  loadStorefront(w);
  const types = w.FuranFormBuilder.types;
  const helper = 'https://shop.test/static/Furan_FormBuilder/js/ffb-recaptcha.js';
  types.loadRecaptchaHelper(helper).catch(() => {});
  types.loadRecaptchaHelper(helper).catch(() => {});
  const assets = { leafletJs: 'https://shop.test/static/leaflet.js', leafletCss: 'https://shop.test/static/leaflet.css', mapJs: 'https://shop.test/static/ffb-map.js' };
  types.loadMapAssets(assets, 'osm').catch(() => {});
  types.loadMapAssets(assets, 'osm').catch(() => {});
  assert.deepEqual(scripts(w).sort(), [assets.leafletJs, assets.mapJs, helper].sort());
  assert.equal(w.document.querySelectorAll('link[rel="stylesheet"]').length, 1);
});
