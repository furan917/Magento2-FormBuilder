'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const root = path.resolve(__dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const { loadStorefront, core } = require('./helpers/load-storefront');
const cases = JSON.parse(fs.readFileSync(path.join(root, 'Test/fixtures/address-values.json'), 'utf8'));

const MESSAGES = {
  country: 'Please choose a valid country.',
  street: 'Please enter a street address.',
  city: 'Please enter a city.',
  region: 'Please choose or enter a region.',
  postcode: 'Please enter a postcode.',
  lines: 'Please use fewer street address lines.',
  postcode_hint: 'This postcode does not look valid for the selected country. Please check it.',
};

test('it validates the shared address fixtures identically to PHP', () => {
  for (const c of cases) {
    const field = Object.assign({}, c.field, { messages: MESSAGES });
    const normalized = core.normalizeValue(field, c.raw);
    assert.deepEqual(normalized, c.normalized, c.name);
    const message = core.validateField(field, c.raw, {}, c.directory);
    assert.equal(message === null, c.valid, c.name + ' -> ' + message);
    assert.equal(normalized !== '' && core.postcodeHint(normalized, c.directory), c.warning === 'postcode', c.name);
  }
});

const REGIONS = { US: [{ id: '12', code: 'CA', name: 'California' }, { id: '43', code: 'NY', name: 'New York' }], GB: [] };
const DIRECTORY = {
  allowedCountries: ['GB', 'US'], regionsRequired: ['US'], optionalZip: [],
  postcodes: { GB: ['^[A-Z]{1,2}[0-9]'], US: ['^[0-9]{5}$'] }, streetLines: 2,
};
const REGIONS_URL = 'https://shop.test/furanform/directory/regions';
const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

// Mirrors view/frontend/templates/item/address.phtml.
const MARKUP = `
<div class="ffb" data-ffb-root data-ffb-config="">
  <form class="ffb-form" data-ffb-form method="post" action="/submit">
    <input type="hidden" name="form_id" value="1"><input type="hidden" name="form_key" value=""><input type="hidden" name="ffb_token" value="">
    <section class="ffb-page" data-ffb-page="p_aaaa" data-ffb-item="p_aaaa"><div class="ffb-grid">
      <fieldset class="ffb-item ffb-field ffb-type-address" data-ffb-item="f_addr01" data-ffb-name="addr" data-ffb-type="address">
        <legend class="ffb-label">Address</legend>
        <select id="ffb-1-addr" name="fields[addr][country_id]"><option value="">Please select</option><option value="GB">United Kingdom</option><option value="US">United States</option></select>
        <input type="text" name="fields[addr][street][]"><input type="text" name="fields[addr][street][]">
        <input type="text" name="fields[addr][city]">
        <select name="fields[addr][region_id]" hidden data-ffb-region-list><option value="">Please select</option></select>
        <input type="text" name="fields[addr][region]" data-ffb-region-text>
        <input type="text" name="fields[addr][postcode]" aria-describedby="ffb-1-addr-hint">
        <p class="ffb-hint" id="ffb-1-addr-hint" aria-live="polite" data-ffb-hint></p>
        <p class="ffb-error" data-ffb-error hidden></p>
      </fieldset>
      <div class="ffb-item ffb-content" data-ffb-item="c_sum001"><div class="ffb-summary" data-ffb-summary><dl class="ffb-summary-list"></dl></div></div>
    </div></section>
    <div class="ffb-nav"><button type="submit" data-ffb-submit>Submit</button></div>
    <div data-ffb-status></div>
  </form>
  <div data-ffb-success hidden tabindex="-1"></div>
</div>`;

const FIELD = {
  id: 'f_addr01', kind: 'field', type: 'address', name: 'addr', label: 'Address', required: false, rules: [], draft: true,
  options: [], settings: {}, required_message: 'Required!', messages: MESSAGES,
};

function boot(opts = {}) {
  const dom = new JSDOM(MARKUP, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const doc = w.document;
  const el = doc.querySelector('[data-ffb-root]');
  const config = {
    formId: 1, submitUrl: 'https://shop.test/furanform/form/submit', tokenUrl: 'https://shop.test/furanform/form/token?form_id=1',
    draft: opts.draft || { mode: 'off', key: 'ffb:1:1:abc', ttlHours: 24 },
    schema: { pages: [{ id: 'p_aaaa', title: 'P', items: [Object.assign({}, FIELD, opts.field || {}),
      { id: 'c_sum001', kind: 'content', type: 'summary', width: 'full' }] }] },
    directory: DIRECTORY, regionsUrl: REGIONS_URL, uploads: { maxMb: 5 },
    messages: { required: 'Required!', generic: 'Oops', fixErrors: 'Fix', network: 'Net' }, labels: {},
  };
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  const calls = [];
  w.fetch = (url, init) => {
    calls.push({ url: String(url), init });
    if (String(url).includes('/token')) return Promise.resolve(json(200, { token: 'tok.1' }));
    if (String(url).startsWith(REGIONS_URL)) {
      const country = new URL(url).searchParams.get('country');
      return Promise.resolve(country in REGIONS ? json(200, REGIONS[country]) : json(404, {}));
    }
    return Promise.resolve(json(200, { success: true, message: 'Thanks', errors: {}, redirect: null }));
  };
  if (opts.storage) {
    for (const [k, v] of Object.entries(opts.storage)) w.sessionStorage.setItem(k, v);
  }
  loadStorefront(w);
  w.FuranFormBuilder.init(el);
  const q = (s) => doc.querySelector(s);
  const named = (part) => q('[name="fields[addr][' + part + ']"]');
  const fire = (node, type) => node.dispatchEvent(new w.Event(type, { bubbles: true }));
  const pick = (node, value) => { node.value = value; fire(node, 'change'); };
  return {
    w, doc, calls, q, named, fire, pick, wrapper: q('[data-ffb-name="addr"]'),
    regionCalls: () => calls.filter((c) => c.url.startsWith(REGIONS_URL)),
    list: () => q('[data-ffb-region-list]'), text: () => q('[data-ffb-region-text]'),
  };
}

test('it switches the region input between a list and free text by country', async () => {
  const t = boot();
  assert.equal(t.list().hidden, true);
  assert.equal(t.text().hidden, false);

  t.pick(t.named('country_id'), 'US');
  await flush();
  assert.equal(t.list().hidden, false);
  assert.equal(t.text().hidden, true);
  assert.deepEqual(Array.from(t.list().options).map((o) => o.value + ':' + o.textContent), [':Please select', '12:California', '43:New York']);

  t.pick(t.named('country_id'), 'GB');
  await flush();
  assert.equal(t.list().hidden, true);
  assert.equal(t.list().options.length, 1);
  assert.equal(t.text().hidden, false);

  t.pick(t.named('country_id'), 'US');
  await flush();
  assert.equal(t.list().hidden, false);
  assert.equal(t.regionCalls().length, 2, 'one request per country');
  assert.ok(t.regionCalls()[0].url.endsWith('?country=US'));
});

const US_ADDRESS = { street: ['1 Main St', ''], city: 'Austin', region_id: '12', region: 'California', postcode: '90001', country_id: 'US' };

test('it reads and writes the address for drafts and the summary', async () => {
  const draftCfg = { mode: 'session', key: 'ffb:1:1:abc', ttlHours: 24 };
  const t = boot({ draft: draftCfg });
  await t.w.FuranFormBuilder.types.writeValue(t.wrapper, US_ADDRESS);
  assert.deepEqual(JSON.parse(JSON.stringify(t.w.FuranFormBuilder.types.readValue(t.wrapper))), US_ADDRESS);

  t.fire(t.named('city'), 'change');
  const saved = JSON.parse(t.w.sessionStorage.getItem('ffb:1:1:abc'));
  assert.deepEqual(saved.values.addr, US_ADDRESS);

  t.fire(t.named('city'), 'input');
  assert.equal(t.q('.ffb-summary-list dd').textContent, '1 Main St, Austin, California, 90001, United States');
});

test('it keeps an untouched address out of the draft', () => {
  const t = boot({ draft: { mode: 'session', key: 'ffb:1:1:abc', ttlHours: 24 } });
  t.pick(t.named('country_id'), 'GB');
  assert.equal(t.w.sessionStorage.getItem('ffb:1:1:abc'), null);
});

test('it restores a saved region after the country regions load', async () => {
  const key = 'ffb:1:1:abc';
  const draft = { v: 1, savedAt: Date.now(), page: 'p_aaaa', values: { addr: US_ADDRESS } };
  const t = boot({ draft: { mode: 'session', key, ttlHours: 24 }, storage: { [key]: JSON.stringify(draft) } });
  assert.equal(t.list().hidden, true, 'regions have not loaded yet');
  await flush();
  assert.equal(t.list().hidden, false);
  assert.equal(t.list().value, '12');
  assert.equal(t.text().value, 'California');
  assert.equal(t.named('country_id').value, 'US');
});

test('it copies the chosen region name into the region text input', async () => {
  const t = boot();
  t.pick(t.named('country_id'), 'US');
  await flush();
  t.pick(t.list(), '43');
  assert.equal(t.text().value, 'New York');
  assert.equal(t.w.FuranFormBuilder.types.readValue(t.wrapper).region, 'New York');
  t.pick(t.list(), '');
  assert.equal(t.text().value, '');
});

test('it shows a non blocking postcode hint when the pattern does not match', () => {
  const t = boot();
  const hint = t.q('[data-ffb-hint]');
  t.pick(t.named('country_id'), 'GB');
  t.named('postcode').value = '12345';
  t.fire(t.named('postcode'), 'change');
  assert.equal(hint.textContent, MESSAGES.postcode_hint);
  assert.equal(hint.hasAttribute('data-ffb-error'), false);
  assert.equal(t.named('postcode').hasAttribute('aria-invalid'), false);
  assert.equal(t.q('[data-ffb-error]').hidden, true);

  t.named('postcode').value = 'ls1 4ab';
  t.fire(t.named('postcode'), 'change');
  assert.equal(hint.textContent, '');

  t.named('postcode').value = '12345';
  t.fire(t.named('postcode'), 'change');
  t.named('postcode').value = '';
  t.fire(t.named('postcode'), 'change');
  assert.equal(hint.textContent, '');

  t.named('postcode').value = '12345';
  t.pick(t.named('country_id'), 'US');
  assert.equal(hint.textContent, '');
  t.pick(t.named('country_id'), 'GB');
  assert.equal(hint.textContent, MESSAGES.postcode_hint);
});

test('it submits an address whose postcode does not match the pattern', async () => {
  const t = boot();
  t.pick(t.named('country_id'), 'GB');
  t.q('[name="fields[addr][street][]"]').value = '1 Main St';
  t.named('city').value = 'Leeds';
  t.named('postcode').value = '12345';
  t.q('form').dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush(40);

  const post = t.calls.find((c) => c.url === 'https://shop.test/furanform/form/submit');
  assert.ok(post, 'the form was submitted');
  assert.equal(post.init.body.get('fields[addr][postcode]'), '12345');
  assert.equal(post.init.body.get('fields[addr][city]'), 'Leeds');
  assert.equal(post.init.body.get('fields[addr][country_id]'), 'GB');
  assert.deepEqual(post.init.body.getAll('fields[addr][street][]'), ['1 Main St', '']);
  assert.equal(t.q('[data-ffb-success]').hidden, false);
});

test('it blocks a submit while a required region is missing once the regions have loaded', async () => {
  const t = boot();
  t.pick(t.named('country_id'), 'US');
  await flush();
  t.q('[name="fields[addr][street][]"]').value = '1 Main St';
  t.named('city').value = 'Austin';
  t.named('postcode').value = '90001';
  t.q('form').dispatchEvent(new t.w.Event('submit', { bubbles: true, cancelable: true }));
  await flush(40);
  assert.equal(t.calls.some((c) => c.url.endsWith('/submit')), false);
  assert.equal(t.q('[data-ffb-error]').textContent, MESSAGES.region);
});
