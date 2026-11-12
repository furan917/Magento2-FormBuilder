'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('./helpers/dom');
const { loadStorefront } = require('./helpers/load-storefront');

const field = (id, type, name, label, extra) => Object.assign({
  id, kind: 'field', type, name, label, required: false, rules: [], options: [], settings: {}, draft: true,
}, extra || {});

const FIELDS = [
  field('f_when01', 'date', 'when', 'When'),
  field('f_at0001', 'datetime', 'at', 'At'),
  field('f_mon001', 'month', 'mon', 'Month'),
  field('f_tm0001', 'time', 'tm', 'Time'),
  field('f_stay01', 'date_range', 'stay', 'Stay'),
  field('f_star01', 'rating', 'stars', 'Stars', { settings: { max: 5 } }),
  field('f_mood01', 'scale', 'mood', 'Mood', { settings: { min: 1, max: 5 } }),
  field('f_news01', 'toggle', 'news', 'News'),
  field('f_agre01', 'checkbox', 'agree', 'Agree'),
  field('f_pick01', 'checkboxes', 'picks', 'Picks', { options: [{ label: 'A', value: 'a' }] }),
  field('f_hue001', 'color', 'hue', 'Hue', { required: true, required_message: 'Pick a colour' }),
  field('f_tint01', 'color', 'tint', 'Tint', { default: '#00ff00' }),
  field('f_shoe01', 'suggest', 'shoe', 'Shoe', { suggestions: true, options: [{ label: 'Leather boots', value: 'boots' }, { label: 'Sandals', value: 'sandals' }] }),
];

const wrap = (id, name, type, inner) =>
  `<div class="ffb-item ffb-field" data-ffb-item="${id}" data-ffb-name="${name}" data-ffb-type="${type}">` +
  `<label class="ffb-label" for="c-${name}">${name}</label>${inner}<p class="ffb-error" data-ffb-error hidden></p></div>`;

const radios = (name, from, to, attr) => {
  let out = `<div class="ffb-${name}" role="radiogroup" ${attr}>`;
  for (let i = from; i <= to; i++) out += `<label class="ffb-option"><input type="radio" name="fields[${name}]" value="${i}"></label>`;
  return out + '</div>';
};

// Mirrors color.phtml: the hidden input posts the value; the picker and the hex box are unnamed.
const colour = (name, value) => `<div class="ffb-color" data-ffb-color${value ? ` data-ffb-default="${value}"` : ' data-ffb-empty'}>` +
  `<input type="hidden" name="fields[${name}]" value="${value}" data-ffb-color-value>` +
  `<input type="color" class="ffb-color-picker" tabindex="-1" aria-hidden="true" value="${value || '#000000'}" data-ffb-color-picker>` +
  `<input id="c-${name}" type="text" class="ffb-input ffb-color-hex" maxlength="7" placeholder="#rrggbb" autocomplete="off" ` +
  `spellcheck="false" aria-describedby="c-${name}-error" value="${value}" data-ffb-color-hex data-ffb-required-part>` +
  `<button type="button" class="ffb-color-clear" data-ffb-color-clear aria-label="Clear colour"${value ? '' : ' hidden'}>Clear</button></div>`;

// Mirrors the field templates: color.phtml (no default = unnamed), rating.phtml, scale.phtml, toggle.phtml.
const PAGE_ONE = [
  wrap('f_when01', 'when', 'date', '<input id="c-when" type="date" name="fields[when]">'),
  wrap('f_at0001', 'at', 'datetime', '<input id="c-at" type="datetime-local" name="fields[at]">'),
  wrap('f_mon001', 'mon', 'month', '<input id="c-mon" type="month" name="fields[mon]">'),
  wrap('f_tm0001', 'tm', 'time', '<input id="c-tm" type="time" name="fields[tm]">'),
  wrap('f_stay01', 'stay', 'date_range', '<input id="c-stay" type="date" name="fields[stay][start]"><input type="date" name="fields[stay][end]">'),
  wrap('f_star01', 'stars', 'rating', radios('stars', 1, 5, 'data-ffb-summary-text="%1 of 5"')),
  wrap('f_mood01', 'mood', 'scale', radios('mood', 1, 5, 'data-ffb-summary-text="%1 on a 1 to 5 scale"')),
  wrap('f_news01', 'news', 'toggle', '<input id="c-news" type="checkbox" role="switch" name="fields[news]" value="yes">'),
  wrap('f_agre01', 'agree', 'checkbox', '<input id="c-agree" type="checkbox" name="fields[agree]" value="yes">'),
  wrap('f_pick01', 'picks', 'checkboxes', '<label class="ffb-option"><input type="checkbox" name="fields[picks][]" value="a"></label>'),
  wrap('f_hue001', 'hue', 'color', colour('hue', '')),
  wrap('f_tint01', 'tint', 'color', colour('tint', '#00ff00')),
  wrap('f_shoe01', 'shoe', 'suggest', '<input id="c-shoe" type="text" name="fields[shoe]" list="c-shoe-list">'),
].join('');

const markup = (locale) => `
<div class="ffb" data-ffb-root data-ffb-config=""${locale ? ` data-ffb-locale="${locale}"` : ''}>
  <form class="ffb-form" data-ffb-form method="post" action="/submit">
    <input type="hidden" name="form_id" value="1"><input type="hidden" name="form_key" value=""><input type="hidden" name="ffb_token" value="">
    <ol class="ffb-progress" data-ffb-progress data-ffb-done-label="(completed)">
      <li class="ffb-progress-step" data-ffb-step="p_aaaa01">One</li>
      <li class="ffb-progress-step" data-ffb-step="p_bbbb02">Two</li>
      <li class="ffb-progress-step" data-ffb-step="p_cccc03">Three</li>
    </ol>
    <section class="ffb-page" data-ffb-page="p_aaaa01" data-ffb-item="p_aaaa01"><h2 class="ffb-page-title">One</h2><div class="ffb-grid">${PAGE_ONE}</div></section>
    <section class="ffb-page" data-ffb-page="p_bbbb02" data-ffb-item="p_bbbb02"><h2 class="ffb-page-title">Two</h2><div class="ffb-grid">
      ${wrap('f_note01', 'note', 'text', '<input id="c-note" type="text" name="fields[note]">')}
    </div></section>
    <section class="ffb-page" data-ffb-page="p_cccc03" data-ffb-item="p_cccc03"><h2 class="ffb-page-title">Three</h2><div class="ffb-grid">
      <div class="ffb-item ffb-content" data-ffb-item="c_sum001"><div class="ffb-summary" data-ffb-summary data-ffb-edit-label="Edit"
        data-ffb-yes-label="Yes" data-ffb-no-label="No" data-ffb-back-label="Back to review">
        <h3 class="ffb-summary-title">Review</h3><dl class="ffb-summary-list"></dl></div></div>
    </div></section>
    <div class="ffb-nav"><button type="button" data-ffb-prev>Back</button><button type="button" data-ffb-next>Next</button><button type="submit" data-ffb-submit>Submit</button></div>
    <div class="ffb-status" role="status" data-ffb-status></div>
    <p class="ffb-sr-only" role="status" data-ffb-step-status></p>
  </form>
  <div data-ffb-success hidden tabindex="-1"></div>
</div>`;

function boot(opts = {}) {
  const dom = new JSDOM(markup(opts.locale === undefined ? 'en-GB' : opts.locale), { runScripts: 'outside-only', url: 'https://shop.test/forms/x' });
  const w = dom.window;
  const doc = w.document;
  const el = doc.querySelector('[data-ffb-root]');
  const config = {
    formId: 1, submitUrl: 'https://shop.test/furanform/form/submit', tokenUrl: 'https://shop.test/furanform/form/token?form_id=1',
    draft: opts.draft || { mode: 'off', key: 'ffb:1:1:abc', ttlHours: 24 },
    schema: { pages: [
      { id: 'p_aaaa01', title: 'One', items: FIELDS },
      { id: 'p_bbbb02', title: 'Two', items: [field('f_note01', 'text', 'note', 'Note', { required: true, required_message: 'Need a note' })] },
      { id: 'p_cccc03', title: 'Three', items: [{ id: 'c_sum001', kind: 'content', type: 'summary', width: 'full' }] },
    ] },
    uploads: { maxMb: 5 },
    messages: { required: 'Required!', generic: 'Oops', fixErrors: 'Fix', network: 'Net', stepOf: 'Step %1 of %2' }, labels: {},
  };
  el.setAttribute('data-ffb-config', JSON.stringify(config));
  w.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 't.1' }) });
  if (opts.mutate) opts.mutate(doc);
  loadStorefront(w);
  w.FuranFormBuilder.init(el);
  const q = (s) => doc.querySelector(s);
  const fire = (n, t) => n.dispatchEvent(new w.Event(t, { bubbles: true }));
  const set = (sel, value) => { const c = q(sel); c.value = value; fire(c, 'input'); fire(c, 'change'); };
  const tick = (sel) => { const c = q(sel); c.checked = true; fire(c, 'change'); };
  const rows = () => Array.from(doc.querySelectorAll('.ffb-summary-row')).map((r) => [r.querySelector('dt').textContent, r.querySelector('dd').textContent]);
  const row = (label) => (rows().find((r) => r[0] === label) || [])[1];
  const toReview = () => {
    set('#c-hue', '#112233');
    q('[data-ffb-next]').click();
    set('#c-note', 'hello');
    q('[data-ffb-next]').click();
  };
  return { w, doc, q, fire, set, tick, rows, row, toReview, form: q('[data-ffb-form]') };
}

test('it shows dates, times and months in the store locale on the review step', () => {
  const t = boot();
  t.set('#c-when', '2026-01-05');
  t.set('#c-at', '2026-01-05T14:30');
  t.set('#c-mon', '2026-03');
  t.set('#c-tm', '09:05');
  t.set('#c-stay', '2026-01-05');
  t.set('[name="fields[stay][end]"]', '2026-01-09');
  t.toReview();
  assert.equal(t.row('When'), '5 Jan 2026');
  assert.equal(t.row('At'), '5 Jan 2026, 14:30');
  assert.equal(t.row('Month'), 'March 2026');
  assert.equal(t.row('Time'), '09:05');
  assert.equal(t.row('Stay'), '5 Jan 2026 – 9 Jan 2026');
});

test('it falls back to the raw value when the locale is missing or unusable', () => {
  for (const locale of ['', 'not a locale!!']) {
    const t = boot({ locale });
    t.set('#c-when', '2026-01-05');
    t.set('#c-at', '2026-01-05T14:30');
    t.toReview();
    assert.match(t.row('When'), /2026/);
    assert.doesNotMatch(t.row('At'), /T/);
  }
});

test('it reads ratings and scales with their bounds and unticked switches and checkboxes as No', () => {
  const t = boot();
  t.tick('[name="fields[stars]"][value="4"]');
  t.tick('[name="fields[mood]"][value="2"]');
  t.toReview();
  assert.equal(t.row('Stars'), '4 of 5');
  assert.equal(t.row('Mood'), '2 on a 1 to 5 scale');
  assert.equal(t.row('News'), 'No');
  assert.equal(t.row('Agree'), 'No');
  assert.equal(t.row('Picks'), '—');
});

test('it shows a ticked switch as Yes', () => {
  const t = boot();
  t.tick('#c-news');
  t.toReview();
  assert.equal(t.row('News'), 'Yes');
});

test('it shows a chosen colour as its hex code with a swatch and an unchosen one as a dash', () => {
  const t = boot();
  t.toReview();
  assert.equal(t.row('Hue'), '#112233');
  const swatch = t.doc.querySelector('.ffb-summary-row .ffb-summary-swatch');
  assert.equal(swatch.getAttribute('aria-hidden'), 'true');
  assert.equal(swatch.style.backgroundColor, 'rgb(17, 34, 51)');
  assert.equal(t.row('Tint'), '#00ff00');
  t.q('[data-ffb-prev]').click();
  t.q('[data-ffb-prev]').click();
  t.q('[data-ffb-name="tint"] [data-ffb-color-clear]').click();
  t.q('[data-ffb-next]').click();
  t.q('[data-ffb-next]').click();
  assert.equal(t.row('Tint'), '—');
  assert.equal(t.doc.querySelectorAll('.ffb-summary-swatch').length, 1);
});

test('an untouched colour without a default posts no colour, counts as empty and is required', () => {
  const t = boot();
  assert.equal(new t.w.FormData(t.form).get('fields[hue]'), '');
  assert.equal(new t.w.FormData(t.form).get('fields[tint]'), '#00ff00');
  assert.equal(t.q('[data-ffb-name="hue"] [data-ffb-color-picker]').hasAttribute('name'), false);
  assert.equal(t.q('#c-hue').hasAttribute('name'), false);
  t.q('[data-ffb-next]').click();
  assert.equal(t.q('[data-ffb-name="hue"] [data-ffb-error]').textContent, 'Pick a colour');
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false);
  assert.equal(t.doc.activeElement, t.q('#c-hue'), 'focus goes to the hex box, not the picker');
});

test('typing a hex code chooses it, black included, and keeps the picker in step', () => {
  const t = boot();
  const box = t.q('[data-ffb-name="hue"] [data-ffb-color]');
  const value = () => t.q('[name="fields[hue]"]').value;
  t.set('#c-hue', '#000000');
  assert.equal(value(), '#000000');
  assert.equal(box.hasAttribute('data-ffb-empty'), false);
  assert.equal(box.querySelector('[data-ffb-color-clear]').hidden, false);
  t.set('#c-hue', 'AABBCC');
  assert.equal(value(), '#aabbcc');
  assert.equal(box.querySelector('[data-ffb-color-picker]').value, '#aabbcc');
  t.set('#c-hue', '#abc');
  assert.equal(value(), '#abc', 'an unfinished code is kept so the format check can report it');
  assert.equal(box.querySelector('[data-ffb-color-picker]').value, '#aabbcc');
  assert.equal(box.hasAttribute('data-ffb-empty'), true, 'the swatch shows no colour while the code is not valid');
  assert.equal(t.q('#c-hue').value, '#abc');
  assert.equal(box.querySelector('[data-ffb-color-clear]').hidden, false, 'Clear still empties what was typed');
  t.set('#c-hue', '#12zz');
  assert.equal(box.hasAttribute('data-ffb-empty'), true);
  t.set('#c-hue', '#a1b2c3');
  assert.equal(box.hasAttribute('data-ffb-empty'), false);
  assert.equal(box.querySelector('[data-ffb-color-picker]').value, '#a1b2c3');
  t.set('#c-hue', '#abc');
  t.q('[data-ffb-next]').click();
  assert.equal(t.q('[data-ffb-name="hue"] [data-ffb-error]').hidden, false);
  t.set('#c-hue', '');
  assert.equal(value(), '');
  assert.equal(box.hasAttribute('data-ffb-empty'), true);
});

test('picking in the colour picker fills the hex box', () => {
  const t = boot();
  const picker = t.q('[data-ffb-name="hue"] [data-ffb-color-picker]');
  t.set('[data-ffb-name="hue"] [data-ffb-color-picker]', '#123abc');
  assert.equal(t.q('#c-hue').value, '#123abc');
  assert.equal(t.q('[name="fields[hue]"]').value, '#123abc');
  assert.equal(picker.value, '#123abc');
});

test('Clear empties the hex box and the value and returns focus to the hex box', () => {
  const t = boot();
  const box = t.q('[data-ffb-name="tint"] [data-ffb-color]');
  const clear = box.querySelector('[data-ffb-color-clear]');
  clear.click();
  assert.equal(t.q('[name="fields[tint]"]').value, '');
  assert.equal(t.q('#c-tint').value, '');
  assert.equal(box.hasAttribute('data-ffb-empty'), true);
  assert.equal(clear.hidden, true);
  assert.equal(t.doc.activeElement, t.q('#c-tint'));
});

test('a colour written by a draft, a set value or an earlier answer fills every part; an invalid one empties it', () => {
  const t = boot();
  const types = t.w.FuranFormBuilder.types;
  const w = t.q('[data-ffb-name="hue"]');
  types.writeValue(w, '#010203');
  assert.equal(types.readValue(w), '#010203');
  assert.equal(t.q('#c-hue').value, '#010203');
  assert.equal(w.querySelector('[data-ffb-color-picker]').value, '#010203');
  types.writeValue(w, '');
  assert.equal(types.readValue(w), '');
  assert.equal(t.q('#c-hue').value, '');
  types.writeValue(w, 'javascript:alert(1)');
  assert.equal(types.readValue(w), '');
  assert.equal(w.querySelector('[data-ffb-color]').hasAttribute('data-ffb-empty'), true);
});

test('a colour condition sees an untouched colour as empty', () => {
  const t = boot();
  const types = t.w.FuranFormBuilder.types;
  assert.equal(types.readValue(t.q('[data-ffb-name="hue"]')), '');
  assert.equal(types.readValue(t.q('[data-ffb-name="tint"]')), '#00ff00');
});

test('a browser draft restores a chosen colour and a cleared one', () => {
  const draft = { mode: 'session', key: 'ffb:1:1:abc', ttlHours: 24 };
  const first = boot({ draft });
  first.set('#c-hue', '#445566');
  first.q('[data-ffb-name="tint"] [data-ffb-color-clear]').click();
  const ss = first.w.sessionStorage;
  const entries = Array.from({ length: ss.length }, (_, i) => [ss.key(i), ss.getItem(ss.key(i))]);
  assert.ok(entries.length > 0, 'a draft was saved');
  const second = boot({ draft, mutate: (doc) => entries.forEach(([k, v]) => doc.defaultView.sessionStorage.setItem(k, v)) });
  assert.equal(second.q('[name="fields[hue]"]').value, '#445566');
  assert.equal(second.q('#c-hue').value, '#445566');
  assert.equal(second.q('[name="fields[tint]"]').value, '');
  assert.equal(second.q('[data-ffb-name="tint"] [data-ffb-color]').hasAttribute('data-ffb-empty'), true);
});

test('starting over puts colours back to their first state', () => {
  const t = boot();
  t.set('#c-hue', '#445566');
  t.q('[data-ffb-name="tint"] [data-ffb-color-clear]').click();
  t.form.reset();
  t.w.FuranFormBuilder.types.resetColours(t.form);
  assert.equal(t.q('[name="fields[hue]"]').value, '');
  assert.equal(t.q('[data-ffb-name="hue"] [data-ffb-color]').hasAttribute('data-ffb-empty'), true);
  assert.equal(t.q('[name="fields[tint]"]').value, '#00ff00');
  assert.equal(t.q('#c-tint').value, '#00ff00');
  assert.equal(t.q('[data-ffb-name="tint"] [data-ffb-color-clear]').hidden, false);
});

test('Back to review appears after a summary edit, checks the page, then returns focus to the edited row', () => {
  const t = boot();
  t.toReview();
  assert.equal(t.q('.ffb-summary-back'), null);
  const edit = Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: Hue');
  edit.click();
  const back = t.q('.ffb-summary-back');
  assert.equal(back.hidden, false);
  assert.equal(back.type, 'button');
  assert.equal(back.textContent, 'Back to review');
  assert.equal(back.previousElementSibling, t.q('[data-ffb-next]'));
  t.q('[data-ffb-name="hue"] [data-ffb-color-clear]').click();
  back.click();
  assert.equal(t.q('[data-ffb-page="p_aaaa01"]').hidden, false, 'stays while the page has errors');
  assert.equal(t.q('[data-ffb-name="hue"] [data-ffb-error]').textContent, 'Pick a colour');
  t.set('#c-hue', '#998877');
  back.click();
  assert.equal(t.q('[data-ffb-page="p_cccc03"]').hidden, false);
  assert.equal(t.row('Hue'), '#998877');
  assert.equal(t.doc.activeElement.getAttribute('aria-label'), 'Edit: Hue');
  assert.equal(back.hidden, true);
});

test('Back to review goes away once the visitor navigates with Next or Back', () => {
  const t = boot();
  t.toReview();
  Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: Note').click();
  const back = t.q('.ffb-summary-back');
  assert.equal(back.hidden, false);
  t.q('[data-ffb-prev]').click();
  assert.equal(back.hidden, true);
  t.q('[data-ffb-next]').click();
  assert.equal(back.hidden, true);
});

test('Back to review focuses the review heading when the edited row is gone', () => {
  const t = boot();
  t.toReview();
  Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: Note').click();
  t.q('[data-ffb-item="f_note01"]').setAttribute('data-ffb-name', 'gone');
  t.set('#c-note', 'still here');
  t.q('.ffb-summary-back').click();
  assert.equal(t.doc.activeElement, t.q('.ffb-summary-title'));
  assert.equal(t.q('.ffb-summary-title').getAttribute('tabindex'), '-1');
});

test('it marks earlier progress steps as completed for screen readers only', () => {
  const t = boot();
  const done = (id) => {
    const s = t.q(`[data-ffb-step="${id}"] [data-ffb-step-done]`);
    return s && !s.hidden ? s.textContent : null;
  };
  assert.equal(done('p_aaaa01'), null);
  t.set('#c-hue', '#112233');
  t.q('[data-ffb-next]').click();
  assert.equal(done('p_aaaa01'), ' (completed)');
  assert.equal(t.q('[data-ffb-step="p_aaaa01"] [data-ffb-step-done]').className, 'ffb-sr-only');
  assert.equal(done('p_bbbb02'), null);
  assert.equal(t.q('[data-ffb-step="p_aaaa01"]').hasAttribute('data-ffb-done'), true);
  assert.equal(t.q('[data-ffb-step="p_bbbb02"]').hasAttribute('data-ffb-done'), false);
  t.q('[data-ffb-prev]').click();
  assert.equal(done('p_aaaa01'), null);
  assert.equal(t.q('[data-ffb-step="p_aaaa01"]').hasAttribute('data-ffb-done'), false);
});

test('it announces the step in the screen reader region, never in the visible status box', () => {
  const t = boot();
  t.set('#c-hue', '#112233');
  t.q('[data-ffb-next]').click();
  assert.equal(t.q('[data-ffb-step-status]').textContent, 'Step 2 of 3');
  assert.equal(t.q('[data-ffb-status]').textContent, '');
});

test('a summary edit on a field whose first control is not rendered focuses the first one that is', () => {
  const t = boot({
    mutate: (doc) => doc.querySelector('#c-when').insertAdjacentHTML('beforebegin',
      '<div data-ffb-map-search hidden><input id="c-search" type="search"></div>'),
  });
  t.toReview();
  Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: When').click();
  assert.equal(t.doc.activeElement, t.q('#c-when'));
});

test('focus falls back to the field wrapper when none of its controls is rendered', () => {
  const t = boot({
    mutate: (doc) => {
      const mon = doc.querySelector('#c-mon');
      const box = doc.createElement('span');
      box.hidden = true;
      mon.replaceWith(box);
      box.appendChild(mon);
    },
  });
  t.toReview();
  Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: Month').click();
  const wrapper = t.q('[data-ffb-name="mon"]');
  assert.equal(t.doc.activeElement, wrapper);
  assert.equal(wrapper.getAttribute('tabindex'), '-1');
});

test('the review shows a matched suggestion as its option label and free text as typed', () => {
  const t = boot();
  t.set('#c-shoe', '  leather BOOTS ');
  t.toReview();
  assert.equal(t.row('Shoe'), 'Leather boots');
  t.q('[data-ffb-prev]').click();
  t.q('[data-ffb-prev]').click();
  t.set('#c-shoe', 'Leather bootees');
  t.q('[data-ffb-next]').click();
  t.q('[data-ffb-next]').click();
  assert.equal(t.row('Shoe'), 'Leather bootees');
});

test('while editing from the review, Back to review is the primary button after Next, then it all reverts', () => {
  const t = boot();
  const next = t.q('[data-ffb-next]');
  t.toReview();
  Array.from(t.doc.querySelectorAll('.ffb-summary-edit')).find((b) => b.getAttribute('aria-label') === 'Edit: Note').click();
  const back = t.q('.ffb-summary-back');
  assert.ok(back.classList.contains('ffb-btn-primary'));
  assert.ok(!back.classList.contains('ffb-btn-secondary'));
  assert.ok(next.classList.contains('ffb-btn-secondary'));
  const shown = Array.from(t.q('.ffb-nav').children).filter((b) => !b.hidden);
  assert.equal(shown[shown.length - 1], back);
  back.click();
  assert.equal(back.hidden, true);
  assert.ok(!next.classList.contains('ffb-btn-secondary'));
});
