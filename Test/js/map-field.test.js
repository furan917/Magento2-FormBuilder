'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront, core } = require('./helpers/load-storefront');
const cases = JSON.parse(read('Test/fixtures/map-values.json'));

const MAPS = {
  provider: 'osm',
  tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; OSM',
  maxZoom: 19,
  assets: {
    leafletJs: 'https://shop.test/static/leaflet.js',
    leafletCss: 'https://shop.test/static/leaflet.css',
    imagePath: 'https://shop.test/static/images/',
    mapJs: 'https://shop.test/static/ffb-map.js',
    googleJs: 'https://shop.test/static/ffb-map-google.js',
  },
  messages: { pinSet: 'Pin set at %1, %2', privacy: 'Loading sends your IP to OSM.', loadError: 'Map failed' },
};

// Mirrors view/frontend/templates/item/map.phtml.
const markup = (pages) => `
<div class="ffb" data-ffb-root data-ffb-config="">
  <form class="ffb-form" data-ffb-form method="post" action="/submit">
    <input type="hidden" name="form_id" value="1"><input type="hidden" name="form_key" value=""><input type="hidden" name="ffb_token" value="">
    <section class="ffb-page" data-ffb-page="p_aaaa" data-ffb-item="p_aaaa"><div class="ffb-grid">
      <fieldset class="ffb-item ffb-field ffb-type-map" data-ffb-item="f_map001" data-ffb-name="spot" data-ffb-type="map">
        <legend class="ffb-label">Spot</legend>
        <div class="ffb-map" data-ffb-map data-center-lat="51.5" data-center-lng="0" data-zoom="5">
          <div class="ffb-map-placeholder" data-ffb-map-placeholder>
            <p class="ffb-map-notice" data-ffb-map-notice>Generic notice</p>
            <button type="button" class="ffb-btn" data-ffb-map-load>Load map</button>
          </div>
          <div class="ffb-map-canvas" data-ffb-map-canvas hidden></div>
          <button type="button" data-ffb-map-centre hidden>Place pin at map centre</button>
          <input type="text" id="ffb-1-spot" name="fields[spot][lat]" data-ffb-map-lat>
          <input type="text" name="fields[spot][lng]" data-ffb-map-lng>
          <input type="hidden" name="fields[spot][zoom]" data-ffb-map-zoom>
          <input type="hidden" name="fields[spot][label]" data-ffb-map-label>
          <p class="ffb-map-status" data-ffb-map-status aria-live="polite"></p>
        </div>
        <p class="ffb-error" data-ffb-error hidden></p>
      </fieldset>
      <div class="ffb-item ffb-content" data-ffb-item="c_sum001"><div class="ffb-summary" data-ffb-summary><dl class="ffb-summary-list"></dl></div></div>
    </div></section>
    ${pages || ''}
    <div class="ffb-nav"><button type="submit" data-ffb-submit>Submit</button></div>
    <div data-ffb-status></div>
  </form>
  <div data-ffb-success hidden tabindex="-1"></div>
</div>`;

const FIELD = {
  id: 'f_map001', kind: 'field', type: 'map', name: 'spot', label: 'Spot', required: false, rules: [], draft: true,
  options: [], settings: {}, required_message: 'Required!',
  messages: { invalid: 'Bad location', incomplete: 'Need both' },
};

const PAGE_TWO = `<section class="ffb-page" data-ffb-page="p_bbbb" data-ffb-item="p_bbbb"><div class="ffb-grid"></div></section>
    <div class="ffb-nav"><button type="button" data-ffb-prev>Back</button><button type="button" data-ffb-next>Next</button></div>`;

function boot(opts = {}) {
  const dom = new JSDOM(markup(opts.twoPages ? PAGE_TWO : ''), { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const doc = w.document;
  const el = doc.querySelector('[data-ffb-root]');
  const config = {
    formId: 1, submitUrl: 'https://shop.test/furanform/form/submit', tokenUrl: 'https://shop.test/furanform/form/token?form_id=1',
    draft: opts.draft || { mode: 'off', key: 'ffb:1:1:abc', ttlHours: 24 },
    schema: { pages: [{ id: 'p_aaaa', title: 'P', items: [FIELD, { id: 'c_sum001', kind: 'content', type: 'summary', width: 'full' }] }]
      .concat(opts.twoPages ? [{ id: 'p_bbbb', title: 'Q', items: [] }] : []) },
    maps: Object.assign({}, MAPS, opts.provider ? { provider: opts.provider } : {}, opts.maps || {}), uploads: { maxMb: 5 },
    messages: { required: 'Required!', generic: 'Oops', fixErrors: 'Fix', network: 'Net' }, labels: {},
  };
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  w.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't.1' }) });
  loadStorefront(w);
  w.FuranFormBuilder.init(el);
  const q = (s) => doc.querySelector(s);
  return { w, doc, q, wrapper: q('[data-ffb-name="spot"]'), fire: (n, t) => n.dispatchEvent(new w.Event(t, { bubbles: true })) };
}

const flush = (ms = 5) => new Promise((resolve) => setTimeout(resolve, ms));
const injected = (doc) => Array.from(doc.querySelectorAll('script[src], link[href]')).map((n) => n.src || n.href);

test('it shows a placeholder and loads nothing from the provider before the visitor opts in', async () => {
  const t = boot();
  assert.deepEqual(injected(t.doc), []);
  assert.equal(t.q('[data-ffb-map-placeholder]').hidden, false);
  assert.equal(t.q('[data-ffb-map-canvas]').hidden, true);
  assert.equal(t.q('[data-ffb-map-notice]').textContent, MAPS.messages.privacy);

  const inits = [];
  const stub = { init: (wrapper, cfg) => { inits.push([wrapper, cfg]); return { invalidateSize() {} }; } };
  t.q('[data-ffb-map-load]').click();
  await flush();
  assert.deepEqual(injected(t.doc).sort(), [MAPS.assets.leafletCss, MAPS.assets.leafletJs, MAPS.assets.mapJs].sort());
  assert.equal(inits.length, 0, 'waits for the assets');

  t.doc.querySelectorAll('script[src], link[href]').forEach((n) => {
    if (n.src === MAPS.assets.mapJs) t.w.FuranFormBuilder.map = stub;
    n.dispatchEvent(new t.w.Event('load'));
  });
  await flush();
  assert.equal(inits.length, 1);
  assert.equal(inits[0][0], t.q('[data-ffb-map]'));
  assert.equal(inits[0][1].tileUrl, MAPS.tileUrl);
  assert.equal(t.q('[data-ffb-map-placeholder]').hidden, true);
  assert.equal(t.q('[data-ffb-map-canvas]').hidden, false);
  assert.equal(t.q('[data-ffb-map-centre]').hidden, false);
});

test('it hides the RequireJS AMD flag while Leaflet runs so Luma pages get window.L', async () => {
  const t = boot();
  const define = function () {};
  define.amd = { jQuery: true };
  t.w.define = define;
  t.q('[data-ffb-map-load]').click();
  await flush();
  const leaflet = Array.from(t.doc.querySelectorAll('script[src]')).find((n) => n.src === MAPS.assets.leafletJs);
  assert.equal(t.w.define.amd, undefined, 'hidden while the Leaflet script is pending');
  leaflet.dispatchEvent(new t.w.Event('load'));
  assert.deepEqual(t.w.define.amd, { jQuery: true }, 'restored after load');
});

test('it loads only the map script, not Leaflet, for the Google provider', async () => {
  const t = boot({ provider: 'google' });
  t.q('[data-ffb-map-load]').click();
  await flush();
  assert.deepEqual(injected(t.doc), [MAPS.assets.mapJs]);
});

test('it validates the shared map fixtures identically to PHP', () => {
  for (const c of cases) {
    const field = Object.assign({}, c.field, { messages: FIELD.messages, required_message: 'Required!' });
    const normalized = core.normalizeValue(field, c.raw);
    assert.deepEqual(normalized === '' ? null : normalized, c.normalized, c.name);
    const message = core.validateField(field, c.raw, {});
    assert.equal(message === null, c.valid, c.name + ' -> ' + message);
  }
  const bad = Object.assign({}, FIELD);
  assert.equal(core.validateField(bad, { lat: 'abc', lng: '0' }, {}), 'Bad location');
  assert.equal(core.validateField(bad, { lat: '1', lng: '' }, {}), 'Need both');
  assert.equal(core.validateField(bad, { lat: '95', lng: '0' }, {}), 'Bad location');
  assert.equal(core.validateField(Object.assign({}, bad, { required: true }), { lat: '', lng: '' }, {}), 'Required!');
});

const PIN = { lat: '51.507400', lng: '-0.127800', zoom: '16', label: 'Trafalgar Square' };

test('it reads and writes the map value for drafts and the summary', () => {
  const key = 'ffb:1:1:abc';
  const t = boot({ draft: { mode: 'session', key, ttlHours: 24 } });
  const api = t.w.FuranFormBuilder.types;
  assert.deepEqual(JSON.parse(JSON.stringify(api.readValue(t.wrapper))), { lat: '', lng: '', zoom: '', label: '' });
  assert.equal(t.q('.ffb-summary-list dd').textContent, '—');

  api.writeValue(t.wrapper, PIN);
  assert.deepEqual(JSON.parse(JSON.stringify(api.readValue(t.wrapper))), PIN);

  t.fire(t.q('[data-ffb-map-lat]'), 'change');
  assert.deepEqual(JSON.parse(t.w.sessionStorage.getItem(key)).values.spot, PIN);
  assert.equal(t.q('.ffb-summary-list dd').textContent, 'Trafalgar Square');

  api.writeValue(t.wrapper, { lat: '1.000000', lng: '2.000000', zoom: '', label: '' });
  t.fire(t.q('[data-ffb-map-lat]'), 'change');
  assert.equal(t.q('.ffb-summary-list dd').textContent, '1.000000, 2.000000');
});

test('it resizes a loaded map when its page becomes visible again', async () => {
  const t = boot({ twoPages: true });
  let resized = 0;
  t.w.FuranFormBuilder.map = { init: () => ({ invalidateSize: () => { resized += 1; } }) };
  t.q('[data-ffb-map-load]').click();
  await flush();
  t.doc.querySelectorAll('script[src], link[href]').forEach((n) => n.dispatchEvent(new t.w.Event('load')));
  await flush();
  t.q('[data-ffb-next]').click();
  await flush();
  assert.equal(t.q('[data-ffb-page="p_aaaa"]').hidden, true);
  const before = resized;
  t.q('[data-ffb-prev]').click();
  await flush();
  assert.equal(t.q('[data-ffb-page="p_aaaa"]').hidden, false);
  assert.ok(resized > before, 'invalidateSize after the page is shown');
});

test('it submits the map coordinates unchanged', async () => {
  const t = boot();
  const bodies = [];
  t.w.fetch = (url, init) => {
    if (init && init.body) { bodies.push(init.body); }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't', success: true, message: 'ok' }) });
  };
  t.q('[data-ffb-map-lat]').value = '51.742762';
  t.q('[data-ffb-map-lng]').value = '-0.153809';
  t.fire(t.q('[data-ffb-map-lng]'), 'change');
  t.q('[data-ffb-submit]').click();
  await flush(30);
  const sent = bodies.find((b) => b && typeof b.get === 'function');
  assert.ok(sent, 'a FormData body was posted');
  assert.equal(sent.get('fields[spot][lat]'), '51.742762');
  assert.equal(sent.get('fields[spot][lng]'), '-0.153809');
});

test('it refuses map assets and a search URL from another origin', async () => {
  const inits = [];
  const t = boot({ maps: {
    searchUrl: 'https://evil.test/search',
    assets: Object.assign({}, MAPS.assets, { leafletJs: 'https://evil.test/leaflet.js', googleJs: 'https://evil.test/g.js' }),
  } });
  t.w.FuranFormBuilder.map = { init: (box, cfg) => { inits.push(cfg); return { invalidateSize() {} }; } };
  t.q('[data-ffb-map-load]').click();
  await flush();

  assert.equal(injected(t.doc).some((u) => u.startsWith('https://evil.test')), false);
  assert.equal(t.q('[data-ffb-map-status]').textContent, MAPS.messages.loadError);
  assert.deepEqual(inits, []);
});

test('it hands ffb-map.js only a same-origin search URL and adapter script', async () => {
  const inits = [];
  const t = boot({ maps: {
    searchUrl: 'https://evil.test/search',
    assets: Object.assign({}, MAPS.assets, { googleJs: 'https://evil.test/g.js' }),
  } });
  t.w.FuranFormBuilder.map = { init: (box, cfg) => { inits.push(cfg); return { invalidateSize() {} }; } };
  t.q('[data-ffb-map-load]').click();
  await flush();
  t.doc.querySelectorAll('script[src], link[href]').forEach((n) => n.dispatchEvent(new t.w.Event('load')));
  await flush();

  assert.equal(inits.length, 1);
  assert.equal(inits[0].searchUrl, '');
  assert.equal(inits[0].assets.googleJs, '');
  assert.equal(inits[0].assets.imagePath, MAPS.assets.imagePath);
});
