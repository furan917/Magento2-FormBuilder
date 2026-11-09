'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const MAP_SRC = read('view/frontend/web/js/ffb-map.js');
const { loadCore } = require('./helpers/load-storefront');

const SEARCH_URL = 'https://shop.test/furanform/map/search/';
const CONFIG = {
  provider: 'osm',
  tileUrl: 'https://tile.example/{z}/{x}/{y}.png',
  maxZoom: 18,
  assets: {},
  messages: { pinSet: 'Pin set at %1, %2' },
  searchUrl: SEARCH_URL,
};

// Mirrors the search block of view/frontend/templates/item/map.phtml.
const HTML = `<form><input type="hidden" name="form_id" value="7">
<div data-ffb-map data-center-lat="51.5" data-center-lng="-0.1" data-zoom="6">
  <div data-ffb-map-canvas></div>
  <div data-ffb-map-search hidden data-busy="Busy now" data-error="Search failed" data-empty="Nothing found" data-short="Too short">
    <input type="search" data-ffb-map-search-input>
    <button type="button" data-ffb-map-search-button>Search</button>
    <ul data-ffb-map-search-results hidden></ul>
    <p data-ffb-map-search-status aria-live="polite"></p>
  </div>
  <input data-ffb-map-lat><input data-ffb-map-lng><input data-ffb-map-zoom><input type="hidden" data-ffb-map-label>
  <p data-ffb-map-status aria-live="polite"></p>
</div></form>`;

function setup(overrides = {}, fetchImpl) {
  const dom = new JSDOM(HTML, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const rec = { zoom: 6, center: { lat: 51.5, lng: -0.1 }, marker: null, calls: [] };
  const latlng = (lat, lng) => ({ lat, lng, wrap: () => ({ lat, lng }) });
  w.L = {
    Icon: { Default: {} },
    map: () => {
      const m = {
        on: () => m,
        setView(c, z) { rec.center = { lat: c[0], lng: c[1] }; rec.zoom = z; return m; },
        getCenter: () => latlng(rec.center.lat, rec.center.lng),
        getZoom: () => rec.zoom,
        invalidateSize() {},
      };
      return m;
    },
    tileLayer: () => ({ addTo() { return this; } }),
    marker: (ll) => {
      const mk = { pos: ll, addTo: () => mk, setLatLng(p) { mk.pos = p; return mk; }, getLatLng: () => latlng(mk.pos[0], mk.pos[1]), on: () => mk };
      rec.marker = mk;
      return mk;
    },
  };
  w.fetch = (url, opts) => {
    rec.calls.push({ url: String(url), opts });
    return fetchImpl(url, opts);
  };
  loadCore(w);
  w.eval(MAP_SRC);
  const wrapper = w.document.querySelector('[data-ffb-map]');
  w.FuranFormBuilder.map.init(wrapper, Object.assign({}, CONFIG, overrides));
  const q = (s) => wrapper.querySelector(s);
  return { w, rec, q, wrapper };
}

const respond = (status, body) => () => Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
const flush = () => new Promise((resolve) => setTimeout(resolve, 5));
const type = (t, value) => { t.q('[data-ffb-map-search-input]').value = value; };

test('it searches only when the visitor presses search or enter', async () => {
  const t = setup({}, respond(200, []));
  assert.equal(t.q('[data-ffb-map-search]').hidden, false);
  const input = t.q('[data-ffb-map-search-input]');

  type(t, 'Trafalgar');
  ['input', 'keyup', 'change'].forEach((n) => input.dispatchEvent(new t.w.Event(n, { bubbles: true })));
  input.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'a', bubbles: true }));
  await flush();
  assert.equal(t.rec.calls.length, 0);

  t.q('[data-ffb-map-search-button]').click();
  await flush();
  assert.equal(t.rec.calls.length, 1);
  const url = new URL(t.rec.calls[0].url);
  assert.equal(url.origin + url.pathname, SEARCH_URL);
  assert.deepEqual([...url.searchParams.keys()].sort(), ['form_id', 'q']);
  assert.equal(url.searchParams.get('form_id'), '7');
  assert.equal(url.searchParams.get('q'), 'Trafalgar');

  const enter = new t.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  input.dispatchEvent(enter);
  await flush();
  assert.equal(t.rec.calls.length, 2);
  assert.equal(enter.defaultPrevented, true);
});

test('it shows no search when the search url is empty', () => {
  const t = setup({ searchUrl: '' }, respond(200, []));
  assert.equal(t.q('[data-ffb-map-search]').hidden, true);
});

test('it moves the pin and fills the label from a chosen result', async () => {
  const results = [{ label: 'Trafalgar Square, London', lat: '51.507400', lon: '-0.127800' }];
  const t = setup({}, respond(200, results));
  type(t, 'Trafalgar');
  t.q('[data-ffb-map-search-button]').click();
  await flush();
  const items = t.wrapper.querySelectorAll('[data-ffb-map-search-results] button');
  assert.equal(items.length, 1);
  items[0].click();
  assert.equal(t.q('[data-ffb-map-lat]').value, '51.507400');
  assert.equal(t.q('[data-ffb-map-lng]').value, '-0.127800');
  assert.equal(t.q('[data-ffb-map-label]').value, 'Trafalgar Square, London');
  assert.deepEqual(Array.from(t.rec.marker.pos), [51.5074, -0.1278]);
  assert.deepEqual(t.rec.center, { lat: 51.5074, lng: -0.1278 });
});

test('it normalises a chosen result like typed coordinates', async () => {
  const results = [{ label: 'Far east', lat: '10', lon: '190' }, { label: 'Bad', lat: 'x', lon: '1' }];
  const t = setup({}, respond(200, results));
  type(t, 'somewhere');
  t.q('[data-ffb-map-search-button]').click();
  await flush();
  const items = t.wrapper.querySelectorAll('[data-ffb-map-search-results] button');
  items[0].click();
  assert.equal(t.q('[data-ffb-map-lng]').value, '-170.000000');
  items[1].click();
  assert.equal(t.q('[data-ffb-map-lat]').value, '10.000000');
  assert.equal(t.q('[data-ffb-map-search-status]').textContent, 'Search failed');
});

test('it shows a busy message when the server reports busy', async () => {
  const t = setup({}, respond(429, { busy: true }));
  type(t, 'Trafalgar');
  const button = t.q('[data-ffb-map-search-button]');
  button.click();
  assert.equal(button.disabled, true);
  button.click();
  await flush();
  assert.equal(t.rec.calls.length, 1);
  assert.equal(button.disabled, false);
  assert.equal(t.q('[data-ffb-map-search-status]').textContent, 'Busy now');
  assert.equal(t.q('[data-ffb-map-search-results]').hidden, true);
});

test('it shows an error message when the search fails', async () => {
  const t = setup({}, respond(502, {}));
  type(t, 'Trafalgar');
  t.q('[data-ffb-map-search-button]').click();
  await flush();
  assert.equal(t.q('[data-ffb-map-search-status]').textContent, 'Search failed');
});

test('it renders results as text only', async () => {
  const label = '<img src=x onerror="window.pwned=1"><b>Bold</b>';
  const t = setup({}, respond(200, [{ label, lat: '1.000000', lon: '2.000000' }]));
  type(t, 'xss test');
  t.q('[data-ffb-map-search-button]').click();
  await flush();
  const list = t.q('[data-ffb-map-search-results]');
  assert.equal(list.querySelector('img'), null);
  assert.equal(list.querySelector('b'), null);
  assert.equal(list.querySelector('button').textContent, label);
  assert.equal(t.w.pwned, undefined);
});
