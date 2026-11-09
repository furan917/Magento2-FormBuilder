'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const i18n = require('../../view/adminhtml/web/js/builder-i18n.js');

const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'Test/fixtures/translatable-texts.json'), 'utf8'));

// The PHP specs of the fixture's field types, as Block\Adminhtml\Form\Edit passes them to the builder.
const typeSpecs = {
  date_range: {
    start_label: { type: 'string', default: '', max_length: 60, translatable: true },
    end_label: { type: 'string', default: '', max_length: 60, translatable: true },
    min_days: { type: 'int', default: 0, min: 0, max: 366 },
  },
  text: { prefix: { type: 'string', default: '', max_length: 20, translatable: true }, suffix: { type: 'string', default: '', max_length: 20, translatable: true } },
  select: { empty_label: { type: 'string', default: '', translatable: true } },
  matrix: { rows: { type: 'list', default: ['Row 1', 'Row 2'], min_items: 1, max_items: 20, multiline: true, translatable: true }, require_all: { type: 'bool', default: true } },
  consent: {
    details: { type: 'string', default: '', max_length: 1000, translatable: true },
    privacy_url: { type: 'string', default: '', format: 'url', translatable: true },
    privacy_label: { type: 'string', default: 'Privacy policy', max_length: 100, translatable: true },
  },
};

test('it lists the same translatable texts as the PHP catalog', () => {
  const actual = i18n.collect(fixture.schema, fixture.settings, typeSpecs)
    .map((e) => [e.path, e.text, e.max, e.multiline, e.url]);
  assert.deepEqual(actual, fixture.expected);
});

test('it writes, reads and removes overlay entries without leaving empty parents', () => {
  let overlay = i18n.write({}, ['items', 'f_aaaa05', 'options', 'two.2', 'label'], { t: 'Deux', s: 'Two' });
  assert.deepEqual(i18n.read(overlay, ['items', 'f_aaaa05', 'options', 'two.2', 'label']), { t: 'Deux', s: 'Two' });
  overlay = i18n.write(overlay, ['form', 'submit_label'], { t: 'Envoyer', s: 'Send' });
  overlay = i18n.write(overlay, ['items', 'f_aaaa05', 'options', 'two.2', 'label'], null);
  assert.deepEqual(overlay, { form: { submit_label: { t: 'Envoyer', s: 'Send' } } });
  assert.deepEqual(i18n.write(overlay, ['form', 'submit_label'], { t: '', s: 'Send' }), {});
});

test('it keeps entries PHP sent as JSON lists when writing', () => {
  const overlay = { items: { f_aaaa06: { settings: { rows: [{ t: 'Vitesse', s: 'Speed' }] } } } };
  const out = i18n.write(overlay, ['items', 'f_aaaa06', 'settings', 'rows', '1'], { t: 'Prix', s: 'Price' });
  assert.deepEqual(out.items.f_aaaa06.settings.rows, { 0: { t: 'Vitesse', s: 'Speed' }, 1: { t: 'Prix', s: 'Price' } });
});

test('it counts translated and outdated texts', () => {
  let overlay = i18n.write({}, ['form', 'submit_label'], { t: 'Envoyer', s: 'Send' });
  overlay = i18n.write(overlay, ['items', 'f_aaaa03', 'label'], { t: 'Nom', s: 'Full name' });
  assert.deepEqual(i18n.progress(fixture.schema, fixture.settings, overlay, typeSpecs), { total: 31, translated: 2, outdated: 1 });
  assert.equal(i18n.status(overlay, { path: ['items', 'f_aaaa03', 'label'], text: 'Name' }), 'outdated');
});

test('it previews translated texts and falls back to the default text', () => {
  let overlay = i18n.write({}, ['items', 'f_aaaa06', 'settings', 'rows', '1'], { t: 'Prix', s: 'Price' });
  overlay = i18n.write(overlay, ['items', 'f_aaaa03', 'rules', 'min_length', 'message'], { t: 'Trop court', s: 'Too short' });
  overlay = i18n.write(overlay, ['items', 'gone', 'label'], { t: 'X', s: 'X' });
  const out = i18n.apply(fixture.schema, fixture.settings, overlay, typeSpecs);
  const items = out.schema.pages[0].items;
  assert.deepEqual(items[3].settings.rows, ['Speed', 'Prix']);
  assert.equal(items[0].items[0].rules[0].message, 'Trop court');
  assert.equal(items[0].items[0].label, 'Name');
  assert.equal(fixture.schema.pages[0].items[3].settings.rows[1], 'Price');
});

test('it translates admin phrases with placeholders and falls back to English', () => {
  assert.equal(i18n.t('Moved to %1', 'Page 2'), 'Moved to Page 2');
  i18n.setPhrases({ 'Moved to %1': 'Déplacé vers %1' });
  assert.equal(i18n.t('Moved to %1', 'Page 2'), 'Déplacé vers Page 2');
  i18n.setPhrases(null);
  assert.equal(i18n.t('Save'), 'Save');
});
