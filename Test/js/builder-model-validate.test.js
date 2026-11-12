'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const m = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));

// Setting specs as BuilderSharedConfig sends them: the type's spec plus SettingLabels captions.
const days = { type: 'int', default: 0, min: 0, max: 3660, no_limit: 0 };
const date = { type: 'string', format: 'date', max_length: 10, default: '' };
const config = {
  fieldTypes: [
    { code: 'text', conditionSource: true, label: 'Text', maxLength: 1000, settingsSpec: {} },
    { code: 'email', emailAddress: true, conditionSource: true, label: 'Email', settingsSpec: {} },
    { code: 'date', conditionSource: true, label: 'Date', settingsSpec: {} },
    { code: 'number', conditionSource: true, label: 'Number',
      settingsSpec: { step: { type: 'string', format: 'step', default: 'any', label: 'Step' } } },
    { code: 'range', conditionSource: true, label: 'Range', settingsSpec: {
      min: { type: 'number', default: 0, not_above: 'max', label: 'Minimum' },
      max: { type: 'number', default: 100, label: 'Maximum' },
      step: { type: 'number', default: 1, exclusive_min: 0, label: 'Step' },
    } },
    { code: 'date_range', conditionSource: false, label: 'Date range', settingsSpec: {
      min_days: Object.assign({ not_above: 'max_days', label: 'Minimum days' }, days),
      max_days: Object.assign({ label: 'Maximum days' }, days),
      min_date: Object.assign({ not_above: 'max_date', label: 'Earliest date' }, date),
      max_date: Object.assign({ label: 'Latest date' }, date),
    } },
    { code: 'gauge', conditionSource: true, label: 'Third-party gauge', settingsSpec: {
      min: { type: 'number', default: 0, label: 'Minimum' }, max: { type: 'number', default: 1, label: 'Maximum' },
      step: { type: 'string', default: '', label: 'Step' },
    } },
    { code: 'checkboxes', conditionSource: true, label: 'Checkboxes', hasOptions: true, isMultiValue: true, settingsSpec: {} },
    { code: 'hidden', conditionSource: true, label: 'Hidden', serverValue: true, requiredAllowed: false, settingsSpec: {} },
    { code: 'newsletter', conditionSource: true, label: 'Newsletter', consent: true, requiredAllowed: false,
      settingsSpec: { email_field: { type: 'string', format: 'email_field', default: '' } } },
    { code: 'file', conditionSource: true, label: 'File', isFile: true, settingsSpec: { extensions: { type: 'list', default: [] } } },
  ],
  rules: [
    { code: 'min', label: 'Minimum value' }, { code: 'max', label: 'Maximum value' },
    { code: 'min_length', label: 'Minimum length' }, { code: 'max_length', label: 'Maximum length' },
    { code: 'min_date', label: 'Earliest date' }, { code: 'max_date', label: 'Latest date' },
    { code: 'min_selected', label: 'Minimum selected' }, { code: 'max_selected', label: 'Maximum selected' },
  ],
  uploads: { allowedExtensions: ['pdf', 'jpg', 'png'] },
};

function form(items, settings) {
  return m.createState({
    id: 1, identifier: 'contact', title: 'Contact', is_active: true, store_ids: [0],
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items }] },
    settings: settings || {},
  }, config);
}

function field(type, patch) {
  return Object.assign({
    id: 'f_' + type.replace('_', '') + '01', kind: 'field', type, name: 'answer_' + type, label: 'Answer',
    required: false, default: '', rules: [], options: [], settings: {}, condition: null,
  }, patch || {});
}

const messages = (items, settings) => m.validate(form(items, settings), config).map((x) => x.message);

test('it reports every case of the shared save checks fixture', () => {
  const cases = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../fixtures/field-save-checks.json'), 'utf8'));
  cases.forEach((c) => assert.deepEqual(messages([field(c.field.type, c.field)]), c.errors, c.name));
});

test('it reports that a server value or consent field cannot be required', () => {
  assert.deepEqual(messages([field('hidden', { required: true })]),
    ['Field "Answer" cannot be required: its value is set by the server, not the visitor.']);
  assert.deepEqual(messages([field('newsletter', { required: true, settings: { email_field: 'mail' } })]),
    ['Field "Answer" cannot be required: consent must be freely given.']);
});

test('it checks only the bounds a setting spec declares', () => {
  assert.deepEqual(messages([field('gauge', { settings: { min: 5, max: 1, step: '1,5' } })]), []);
});

test('it reports a maximum length rule above what the type keeps', () => {
  assert.deepEqual(messages([field('text', { rules: [{ rule: 'max_length', value: 1001, message: '' }] })]),
    ['Field "Answer": "Maximum length" can be at most 1000.']);
  assert.deepEqual(messages([field('text', { rules: [{ rule: 'max_length', value: 1000, message: '' }] })]), []);
});

test('it adds no advisory entries and every entry blocks the save', () => {
  const entries = m.validate(form([field('newsletter')]), config);
  assert.deepEqual(entries, []);
  m.validate(form([field('hidden', { required: true })]), config)
    .forEach((entry) => assert.deepEqual(Object.keys(entry).sort(), ['itemId', 'message']));
});

test('it resolves the submitter email field as FormSettings does', () => {
  assert.equal(m.submitterEmailField({ email_field: 'a', autoresponder_email_field: 'b' }), 'a');
  assert.equal(m.submitterEmailField({ email_field: '', autoresponder_email_field: 'b' }), 'b');
  assert.equal(m.submitterEmailField({}), '');
  assert.equal(m.submitterEmailField(null), '');
});
