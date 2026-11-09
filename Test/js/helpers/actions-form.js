'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./dom');

const root = path.resolve(__dirname, '../../..');
const { loadStorefront } = require('./load-storefront');

const flush = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

// Markup follows the server templates (item/_open, _select, _choices, text).
const itemMarkup = (f) => {
  const n = f.name;
  const req = f.required ? '<span class="ffb-req" aria-hidden="true">*</span>' : '';
  const attrs = 'class="ffb-item ffb-field ffb-type-' + f.type + '" data-ffb-item="' + f.id + '" data-ffb-name="' + n + '"';
  const tail = '<p class="ffb-error" data-ffb-error hidden></p>';
  if (f.type === 'radio') {
    return '<fieldset ' + attrs + '><legend class="ffb-label">' + f.label + req + '</legend>' +
      f.options.map((o, i) => '<label class="ffb-option"><input class="ffb-choice" type="radio" id="c-' + n + '-' + i +
        '" name="fields[' + n + ']" value="' + esc(o.value) + '"><span>' + o.label + '</span></label>').join('') + tail + '</fieldset>';
  }
  if (f.type === 'address') {
    const part = (k, extra) => '<input class="ffb-input" type="text" id="c-' + n + '-' + k + '" name="fields[' + n + '][' + k + ']"' + (extra || '') + '>';
    return '<fieldset ' + attrs + '><legend class="ffb-label">' + f.label + req + '</legend><div class="ffb-address">' +
      part('city', ' data-ffb-required-part') + part('postcode', ' data-ffb-required-part') + part('region') + '</div>' + tail + '</fieldset>';
  }
  const label = '<label class="ffb-label" for="c-' + n + '">' + f.label + req + '</label>';
  if (f.type === 'select') {
    return '<div ' + attrs + '>' + label + '<select class="ffb-input" id="c-' + n + '" name="fields[' + n + ']"><option value="">Please select</option>' +
      f.options.map((o) => '<option value="' + esc(o.value) + '">' + o.label + '</option>').join('') + '</select>' + tail + '</div>';
  }
  return '<div ' + attrs + '>' + label + '<input class="ffb-input" id="c-' + n + '" name="fields[' + n + ']" type="text">' + tail + '</div>';
};

// opts: pages [{id, condition?, skip?, fields: [{name, type?, required?, options?, condition?, locked?, ...}]}]
function boot(opts) {
  let counter = 0;
  const pages = opts.pages.map((p) => ({
    id: p.id, kind: 'page', title: p.id, condition: p.condition || null, skip: p.skip || null,
    items: p.fields.map((f) => Object.assign({
      id: 'f_' + f.name, kind: 'field', type: 'text', label: f.name, required: false, rules: [], options: [], settings: {},
      draft: true, condition: null, order: counter++,
    }, f)),
  }));
  const html = '<div class="ffb" data-ffb-root data-ffb-config=""><form data-ffb-form action="/s">' +
    '<input type="hidden" name="form_key" value=""><input type="hidden" name="ffb_token" value="">' +
    (pages.length > 1 ? '<ol data-ffb-progress>' + pages.map((p) => '<li data-ffb-step="' + p.id + '">' + p.id + '</li>').join('') + '</ol>' : '') +
    pages.map((p) => '<section data-ffb-page="' + p.id + '" data-ffb-item="' + p.id + '">' + p.items.map(itemMarkup).join('') + '</section>').join('') +
    '<div class="ffb-nav"><button type="button" data-ffb-prev>Back</button><button type="button" data-ffb-next>Next</button>' +
    '<button type="submit" data-ffb-submit>Go</button></div><div data-ffb-status role="status"></div></form><div data-ffb-success hidden></div></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const doc = w.document;
  const config = {
    formId: 1, submitUrl: 'https://shop.test/furanform/form/submit', tokenUrl: 'https://shop.test/furanform/form/token?form_id=1',
    draft: opts.draft || { mode: 'off', key: 'ffb:1:1:abc', ttlHours: 24 },
    schema: { pages }, uploads: { maxMb: 5 }, context: opts.context || { eager: false },
    messages: { required: 'Required', stepOf: 'Step %1 of %2', generic: 'Oops', fixErrors: 'Fix', network: 'Net' }, labels: {},
  };
  doc.querySelector('[data-ffb-root]').setAttribute('data-ffb-config', JSON.stringify(config));
  w.fetch = (url) => {
    if (String(url).includes('/token')) {
      const body = typeof opts.token === 'function' ? opts.token() : (opts.token || { token: 'tok.1' });
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
  };
  if (opts.beforeEval) opts.beforeEval(w);
  loadStorefront(w, opts.afterCore ? [opts.afterCore] : []);
  const q = (s) => doc.querySelector(s);
  const fire = (el, type) => el.dispatchEvent(new w.Event(type, { bubbles: true }));
  const type = (name, value) => {
    const el = q('[name="fields[' + name + ']"]');
    el.value = value;
    fire(el, 'input');
  };
  const choose = (name, value) => {
    const el = q('[name="fields[' + name + ']"][value="' + value + '"]');
    el.checked = true;
    fire(el, 'change');
  };
  const pageShown = () => Array.from(doc.querySelectorAll('[data-ffb-page]')).filter((p) => !p.hidden).map((p) => p.getAttribute('data-ffb-page'));
  const click = (selector) => { q(selector).click(); };
  return { w, doc, q, fire, type, choose, pageShown, click, flush };
}

module.exports = { boot, flush };
