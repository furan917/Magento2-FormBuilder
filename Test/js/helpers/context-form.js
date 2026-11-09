'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./dom');

const root = path.resolve(__dirname, '../../..');
const { loadStorefront } = require('./load-storefront');

const REGIONS_URL = 'https://shop.test/furanform/directory/regions';
const REGIONS = { US: [{ id: '12', name: 'California' }, { id: '43', name: 'New York' }], GB: [] };
const DIRECTORY = { allowedCountries: ['GB', 'US'], regionsRequired: ['US'], optionalZip: [], postcodes: {}, streetLines: 2 };
const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

const markupFor = (f) => {
  const n = f.name;
  let inner;
  if (f.type === 'select') {
    inner = '<select name="fields[' + n + ']"><option value="">-</option><option value="a">A</option><option value="b">B</option></select>';
  } else if (f.type === 'radio') {
    inner = '<input type="radio" name="fields[' + n + ']" value="a"><input type="radio" name="fields[' + n + ']" value="b">';
  } else if (f.type === 'newsletter') {
    inner = '<input type="checkbox" name="fields[' + n + ']" value="yes" data-ffb-impersonation-lock>' +
      '<p id="ffb-lock-' + n + '" data-ffb-lock-note hidden>Not while logged in as the customer.</p>';
  } else if (f.type === 'range') {
    inner = '<input type="range" name="fields[' + n + ']" min="0" max="10" value="' + (f.default || '0') + '"' +
      (f.default ? '' : ' data-ffb-unset data-ffb-nodefault') + '>' +
      '<output data-ffb-output data-ffb-unset-label="Not set">' + (f.default || 'Not set') + '</output>';
  } else if (f.type === 'address') {
    inner = '<select name="fields[' + n + '][country_id]"><option value="">-</option><option value="GB">UK</option><option value="US">US</option></select>' +
      '<input type="text" name="fields[' + n + '][street][]"><input type="text" name="fields[' + n + '][street][]">' +
      '<input type="text" name="fields[' + n + '][city]">' +
      '<select name="fields[' + n + '][region_id]" hidden data-ffb-region-list><option value="">-</option></select>' +
      '<input type="text" name="fields[' + n + '][region]" data-ffb-region-text>' +
      '<input type="text" name="fields[' + n + '][postcode]"><p data-ffb-hint></p>';
  } else {
    inner = '<input type="' + (f.type === 'hidden' ? 'hidden' : 'text') + '" name="fields[' + n + ']"' + (f.default ? ' value="' + f.default + '"' : '') + '>';
  }
  return '<div class="ffb-item ffb-field" data-ffb-item="' + f.id + '" data-ffb-name="' + n + '"' +
    (f.type === 'address' ? ' data-ffb-type="address"' : '') + '>' + inner + '<p data-ffb-error hidden></p></div>';
};

// opts: fields [{name,type,prefill,locked,default}], context, token (response body or function), draft, messages
function boot(opts = {}) {
  const fields = (opts.fields || []).map((f, i) => Object.assign({
    id: 'f_aaa' + (100 + i), kind: 'field', type: 'text', label: f.name, required: false, rules: [], draft: true,
    options: [], settings: {},
  }, f));
  const html = '<div class="ffb" data-ffb-root data-ffb-config=""><form data-ffb-form action="/s">' +
    '<input type="hidden" name="form_key" value=""><input type="hidden" name="ffb_token" value="">' +
    '<section data-ffb-page="p_aaaa" data-ffb-item="p_aaaa">' + fields.map(markupFor).join('') + '</section>' +
    '<button type="submit" data-ffb-submit>Go</button><div data-ffb-status></div></form><div data-ffb-success hidden tabindex="-1"></div></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: opts.url || 'https://shop.test/forms/x' });
  const w = dom.window;
  const doc = w.document;
  const el = doc.querySelector('[data-ffb-root]');
  const config = {
    formId: 1, submitUrl: 'https://shop.test/furanform/form/submit', tokenUrl: 'https://shop.test/furanform/form/token?form_id=1',
    draft: opts.draft || { mode: 'off', key: 'ffb:1:1:abc', ttlHours: 24 },
    schema: { pages: [{ id: 'p_aaaa', title: 'P', items: fields }] },
    directory: DIRECTORY, regionsUrl: REGIONS_URL, uploads: { maxMb: 5 },
    context: opts.context || { eager: false, productId: null, loginUrl: 'https://shop.test/furanform/account/login' },
    messages: Object.assign({
      generic: 'Oops', fixErrors: 'Fix', network: 'Net',
      gateLogin: 'Please log in to fill in this form.', gateGroup: 'Your account may not fill in this form.',
      gateAnswered: 'You have already answered.', editing: 'You are changing your earlier answer.', gateLoginLink: 'Log in', gateRegisterLink: 'Create an account',
    }, opts.messages || {}),
    labels: {},
  };
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  const calls = [];
  w.fetch = (url, init) => {
    calls.push({ url: String(url), init });
    if (String(url).includes('/token')) {
      const body = typeof opts.token === 'function' ? opts.token() : (opts.token || { token: 'tok.1' });
      return Promise.resolve(body && typeof body.then === 'function' ? body : json(200, body)).then((r) => (r.json ? r : json(200, r)));
    }
    if (String(url).startsWith(REGIONS_URL)) {
      const country = new URL(url).searchParams.get('country');
      return Promise.resolve(country in REGIONS ? json(200, REGIONS[country]) : json(404, {}));
    }
    return Promise.resolve(json(200, { success: true, message: 'Thanks', errors: {}, redirect: null }));
  };
  if (opts.beforeInit) { opts.beforeInit(w); }
  loadStorefront(w);
  w.FuranFormBuilder.init(el);
  const q = (s) => doc.querySelector(s);
  const input = (name) => q('[name="fields[' + name + ']"]');
  const fire = (node, type) => node.dispatchEvent(new w.Event(type, { bubbles: true }));
  return { w, doc, el, calls, q, input, fire, form: q('[data-ffb-form]'), tokenCalls: () => calls.filter((c) => c.url.includes('/token')) };
}

module.exports = { boot, flush, json };
