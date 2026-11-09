'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../view/frontend/web/js/ffb-map.js'), 'utf8');

const HTML = `<div data-ffb-map data-center-lat="51.5" data-center-lng="-0.1" data-zoom="6">
  <div data-ffb-map-canvas></div>
  <input data-ffb-map-lat><input data-ffb-map-lng><input data-ffb-map-zoom>
  <button type="button" data-ffb-map-centre>Pin at centre</button>
  <p data-ffb-map-status aria-live="polite"></p>
</div>`;

const CONFIG = {
  tileUrl: 'https://tile.example/{z}/{x}/{y}.png',
  attribution: '&copy; Example contributors',
  maxZoom: 18,
  assets: { imagePath: 'https://shop.test/static/leaflet/images/' },
  messages: { pinSet: 'Pin set at %1, %2' },
};

function setup() {
  const dom = new JSDOM(HTML, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const rec = { handlers: {}, markerHandlers: {}, tile: null, marker: null, mapOpts: null, center: { lat: 51.5, lng: -0.1 }, zoom: 6 };
  const latlng = (lat, lng) => ({ lat, lng, wrap() { return { lat, lng: ((lng + 180) % 360 + 360) % 360 - 180 }; } });
  w.L = {
    Icon: { Default: { imagePath: undefined } },
    map(el, opts) {
      rec.mapOpts = opts;
      const m = {
        on(ev, fn) { rec.handlers[ev] = fn; return m; },
        setView(c, z) { rec.center = { lat: c[0], lng: c[1] }; rec.zoom = z; return m; },
        getCenter: () => latlng(rec.center.lat, rec.center.lng),
        getZoom: () => rec.zoom,
        invalidateSize() { rec.invalidated = true; },
      };
      rec.map = m;
      return m;
    },
    tileLayer(url, opts) { rec.tile = { url, opts }; return { addTo() { return this; } }; },
    marker(ll, opts) {
      const mk = {
        pos: latlng(ll[0], ll[1]), opts,
        addTo() { return mk; },
        setLatLng(p) { mk.pos = latlng(p[0], p[1]); return mk; },
        getLatLng: () => mk.pos,
        on(ev, fn) { rec.markerHandlers[ev] = fn; return mk; },
      };
      rec.marker = mk;
      return mk;
    },
  };
  w.eval(SOURCE);
  const wrapper = w.document.querySelector('[data-ffb-map]');
  const api = w.FuranFormBuilder.map.init(wrapper, CONFIG);
  const q = (s) => wrapper.querySelector(s);
  return { w, rec, api, q, latlng };
}

test('it places the pin where the visitor clicks and fills the coordinate inputs', () => {
  const { rec, q, latlng } = setup();
  rec.handlers.click({ latlng: latlng(51.5074, -0.1278) });
  assert.equal(q('[data-ffb-map-lat]').value, '51.507400');
  assert.equal(q('[data-ffb-map-lng]').value, '-0.127800');
  assert.equal(q('[data-ffb-map-zoom]').value, '6');
  assert.equal(rec.marker.opts.draggable, true);
  assert.equal(q('[data-ffb-map-status]').textContent, 'Pin set at 51.507400, -0.127800');
  rec.markerHandlers.dragend({ target: { getLatLng: () => latlng(10, 190) } });
  assert.equal(q('[data-ffb-map-lng]').value, '-170.000000');
  q('[data-ffb-map-centre]').click();
  assert.equal(q('[data-ffb-map-lat]').value, '51.500000');
});

test('it moves the pin when the coordinate inputs change', () => {
  const { w, rec, q } = setup();
  q('[data-ffb-map-lat]').value = '48.8566';
  q('[data-ffb-map-lng]').value = '2.3522';
  q('[data-ffb-map-lng]').dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.deepEqual([rec.marker.pos.lat, rec.marker.pos.lng], [48.8566, 2.3522]);
  assert.deepEqual(rec.center, { lat: 48.8566, lng: 2.3522 });
});

test('it shows the configured attribution', () => {
  const { rec } = setup();
  assert.equal(rec.tile.url, CONFIG.tileUrl);
  assert.equal(rec.tile.opts.attribution, CONFIG.attribution);
  assert.equal(rec.tile.opts.maxZoom, 18);
  assert.equal(rec.tile.opts.referrerPolicy, 'strict-origin-when-cross-origin');
  assert.notEqual(rec.mapOpts.attributionControl, false);
});

test('it sets the explicit marker image path', () => {
  const { w } = setup();
  assert.equal(w.L.Icon.Default.imagePath, CONFIG.assets.imagePath);
});
