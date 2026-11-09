'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const js = (name) => fs.readFileSync(path.resolve(__dirname, '../../view/frontend/web/js/' + name), 'utf8');
const MAP_SRC = js('ffb-map.js');
const GOOGLE_SRC = js('ffb-map-google.js');

const HTML = `<form><div data-ffb-map data-center-lat="51.5" data-center-lng="-0.1" data-zoom="6">
  <div data-ffb-map-canvas></div>
  <input data-ffb-map-lat><input data-ffb-map-lng><input data-ffb-map-zoom><input data-ffb-map-label>
  <button type="button" data-ffb-map-centre>Pin at centre</button>
  <p data-ffb-map-status aria-live="polite"></p>
</div></form>`;

const CONFIG = {
  provider: 'google',
  googleKey: 'KEY123',
  mapId: 'MAP42',
  locale: 'en_GB',
  assets: { googleJs: 'https://shop.test/static/ffb-map-google.js' },
  messages: { pinSet: 'Pin set at %1, %2', loadError: 'Map failed' },
};

function setup(config = CONFIG, { stubLibraries = true, preloadAdapter = true } = {}) {
  const dom = new JSDOM(HTML, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const rec = { imports: [], mapOpts: null, mapHandlers: {}, markers: [], autocomplete: null, fetchArgs: null };
  if (stubLibraries) {
    const libs = {
      maps: {
        Map: class {
          constructor(el, opts) {
            rec.mapOpts = opts;
            this.c = { lat: opts.center.lat, lng: opts.center.lng };
            this.z = opts.zoom;
            rec.map = this;
          }
          addListener(ev, fn) { rec.mapHandlers[ev] = fn; }
          getCenter() { return { lat: () => this.c.lat, lng: () => this.c.lng }; }
          setCenter(c) { this.c = c; }
          getZoom() { return this.z; }
          setZoom(z) { this.z = z; }
        },
      },
      marker: {
        AdvancedMarkerElement: class {
          constructor(opts) {
            Object.assign(this, opts);
            this.listeners = {};
            rec.markers.push(this);
          }
          addEventListener(ev, fn) { this.listeners[ev] = fn; }
        },
      },
      places: {
        PlaceAutocompleteElement: class {
          constructor(opts) {
            rec.autocompleteOpts = opts;
            rec.autocomplete = w.document.createElement('div');
            return rec.autocomplete;
          }
        },
      },
    };
    w.google = { maps: { importLibrary: async (name) => { rec.imports.push(name); return libs[name]; } } };
  }
  if (preloadAdapter) {
    w.eval(MAP_SRC);
    w.eval(GOOGLE_SRC);
  }
  const wrapper = w.document.querySelector('[data-ffb-map]');
  const q = (s) => wrapper.querySelector(s);
  const start = async () => {
    const handle = w.FuranFormBuilder.map.init(wrapper, config);
    await handle.ready;
    return handle;
  };
  return { w, rec, wrapper, q, start };
}

const livePin = (rec) => rec.markers.filter((m) => m.map);

test('it loads the Google library only after the visitor opts in', async () => {
  const { w, q, start } = setup(CONFIG, { stubLibraries: false });
  assert.equal(w.google, undefined);
  assert.equal(w.document.querySelectorAll('script').length, 0);
  start();
  await new Promise((r) => setTimeout(r, 0));
  const scripts = w.document.querySelectorAll('script[src*="maps.googleapis.com"]');
  assert.equal(scripts.length, 1);
  const url = new w.URL(scripts[0].src);
  assert.equal(url.searchParams.get('key'), 'KEY123');
  assert.equal(url.searchParams.get('language'), 'en');
  assert.equal(url.searchParams.get('region'), 'GB');
  assert.equal(url.searchParams.get('v'), 'weekly');
  assert.equal(url.searchParams.get('loading'), 'async');
  assert.equal(url.searchParams.get('libraries'), 'maps,marker,places');
  assert.ok(url.searchParams.get('callback'));
  assert.equal(q('[data-ffb-map-lat]').value, '');
});

test('it creates a draggable advanced marker with the configured map id', async () => {
  const { rec, q, start } = setup();
  await start();
  assert.deepEqual(rec.imports.sort(), ['maps', 'marker', 'places']);
  assert.equal(rec.mapOpts.mapId, 'MAP42');
  rec.mapHandlers.click({ latLng: { lat: () => 51.5074, lng: () => -0.1278 } });
  const [pin] = livePin(rec);
  assert.equal(pin.gmpDraggable, true);
  assert.equal(pin.map, rec.map);
  assert.deepEqual({ ...pin.position }, { lat: 51.5074, lng: -0.1278 });
  assert.equal(q('[data-ffb-map-lat]').value, '51.507400');
  assert.equal(q('[data-ffb-map-lng]').value, '-0.127800');
  assert.equal(q('[data-ffb-map-zoom]').value, '6');
  pin.position = { lat: () => 10, lng: () => 190 };
  pin.listeners['gmp-dragend']();
  assert.equal(q('[data-ffb-map-lng]').value, '-170.000000');
});

test('it fills coordinates and label from a selected place', async () => {
  const { w, rec, q, start } = setup();
  await start();
  const place = {
    fetchFields: async (args) => { rec.fetchArgs = args; },
    location: { lat: () => 48.8566, lng: () => 2.3522 },
    formattedAddress: '1 Rue Example, Paris',
  };
  const event = new w.Event('gmp-select');
  event.placePrediction = { toPlace: () => place };
  rec.autocomplete.dispatchEvent(event);
  await new Promise((r) => setTimeout(r, 0));
  assert.equal(q('[data-ffb-map-lat]').value, '48.856600');
  assert.equal(q('[data-ffb-map-lng]').value, '2.352200');
  assert.equal(q('[data-ffb-map-label]').value, '1 Rue Example, Paris');
  assert.deepEqual({ ...rec.map.c }, { lat: 48.8566, lng: 2.3522 });
  assert.equal(livePin(rec).length, 1);
});

test('it requests only location and formatted address fields from places', async () => {
  const { w, rec, start } = setup();
  await start();
  const place = { fetchFields: async (args) => { rec.fetchArgs = args; }, location: { lat: () => 1, lng: () => 2 }, formattedAddress: 'X' };
  const event = new w.Event('gmp-select');
  event.placePrediction = { toPlace: () => place };
  rec.autocomplete.dispatchEvent(event);
  await new Promise((r) => setTimeout(r, 0));
  assert.deepEqual(JSON.parse(JSON.stringify(rec.fetchArgs)), { fields: ['location', 'formattedAddress'] });
});

test('it shows the coordinate inputs only when no Google key is configured', async () => {
  const { w, q, start } = setup({ ...CONFIG, googleKey: '' });
  await start();
  assert.equal(q('[data-ffb-map-canvas]').hidden, true);
  assert.equal(w.document.querySelectorAll('script').length, 0);
});

test('it clears the pin when the form is reset', async () => {
  const { w, rec, q, wrapper, start } = setup();
  await start();
  rec.mapHandlers.click({ latLng: { lat: () => 1, lng: () => 2 } });
  assert.equal(livePin(rec).length, 1);
  wrapper.closest('form').dispatchEvent(new w.Event('reset'));
  assert.equal(livePin(rec).length, 0);
  assert.equal(q('[data-ffb-map-status]').textContent, '');
  rec.mapHandlers.click({ latLng: { lat: () => 3, lng: () => 4 } });
  assert.equal(livePin(rec).length, 1);
});

test('it clears the pin when the form is reset (Leaflet)', () => {
  const dom = new JSDOM(HTML, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const removed = [];
  const handlers = {};
  w.L = {
    Icon: { Default: {} },
    map: () => {
      const m = { on: (ev, fn) => { handlers[ev] = fn; return m; }, setView: () => m, getZoom: () => 6, getCenter: () => ({}) };
      return m;
    },
    tileLayer: () => ({ addTo() {} }),
    marker: (ll) => {
      const mk = { addTo: () => mk, setLatLng() {}, on() { return mk; }, remove: () => removed.push(ll) };
      return mk;
    },
  };
  w.eval(MAP_SRC);
  const wrapper = w.document.querySelector('[data-ffb-map]');
  w.FuranFormBuilder.map.init(wrapper, { provider: 'osm', tileUrl: 'x', messages: {} });
  handlers.click({ latlng: { lat: 1, lng: 2, wrap: () => ({ lat: 1, lng: 2 }) } });
  wrapper.closest('form').dispatchEvent(new w.Event('reset'));
  assert.equal(removed.length, 1);
  handlers.click({ latlng: { lat: 3, lng: 4, wrap: () => ({ lat: 3, lng: 4 }) } });
  wrapper.closest('form').dispatchEvent(new w.Event('reset'));
  assert.equal(removed.length, 2);
});
