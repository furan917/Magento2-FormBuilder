'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom.js');
const model = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-model.js'));
const inspector = require(path.resolve(__dirname, '../../view/adminhtml/web/js/builder-inspector.js'));

const SCHEDULE = {
  timezones: [
    { value: 'Europe/London', label: 'London' }, { value: 'Asia/Tokyo', label: 'Tokyo' },
    { value: 'America/Chicago', label: 'Chicago' },
  ],
  storeTimezones: { 1: 'Europe/London', 2: 'Asia/Tokyo', 3: 'Asia/Tokyo' },
  defaultTimezone: 'America/Chicago',
  local: { open_from: '', close_at: '' },
};

function mount(settings, storeIds, schedule) {
  const form = {
    id: 1, identifier: 'x', title: 'X', is_active: true, store_ids: storeIds,
    schema: { version: 1, pages: [{ id: 'p_aaaa11', title: 'One', condition: null, items: [] }] },
    settings,
  };
  const config = { fieldTypes: [], rules: [], operators: [], actions: [], schedule: schedule || SCHEDULE, form };
  const dom = new JSDOM('<div id="c"></div>');
  const container = dom.window.document.getElementById('c');
  const calls = [];
  const actions = { apply: (...args) => calls.push(args), select: () => {}, notify: () => {} };
  inspector.render(container, model.createState(form), config, actions);
  const q = (key) => container.querySelector('[data-ffb-input="' + key + '"]');
  const fire = (node, type) => node.dispatchEvent(new dom.window.Event(type, { bubbles: true }));
  return { calls, q, fire, container };
}

test('it shows the stored times in the builder in the timezone they were entered in', () => {
  const schedule = Object.assign({}, SCHEDULE, { local: { open_from: '2026-07-01T18:00', close_at: '' } });
  const ui = mount({ open_from: '2026-07-01T09:00:00Z', close_at: '', schedule_timezone: 'Asia/Tokyo' }, [1], schedule);

  assert.equal(ui.q('settings.open_from').value, '2026-07-01T18:00');
  assert.equal(ui.q('settings.close_at').value, '');
  assert.equal(ui.q('settings.schedule_timezone').value, 'Asia/Tokyo');
  assert.equal(ui.q('settings.open_from').type, 'datetime-local');
});

test('it keeps the instant when store views change and the time was not edited', () => {
  const schedule = Object.assign({}, SCHEDULE, { local: { open_from: '2026-07-01T10:00', close_at: '' } });
  const ui = mount({ open_from: '2026-07-01T09:00:00Z', schedule_timezone: 'Europe/London' }, [1], schedule);

  ui.fire(ui.q('store_ids'), 'change');
  assert.deepEqual(ui.calls.filter((c) => c[0] === 'updateSettings'), []);
  ui.fire(ui.q('settings.closed_message'), 'change');
  assert.deepEqual(ui.calls.at(-1), ['updateSettings', { closed_message: '' }]);
});

test('it converts the entered times using the timezone the admin picked', () => {
  const ui = mount({ open_from: '', close_at: '', schedule_timezone: 'Asia/Tokyo' }, [1]);

  ui.q('settings.open_from').value = '2026-07-01T10:00';
  ui.fire(ui.q('settings.open_from'), 'change');

  assert.deepEqual(ui.calls[0], ['updateSettings', { open_from: '2026-07-01T10:00', schedule_timezone: 'Asia/Tokyo' }]);
});

test("it defaults the timezone to the single store view's zone when none is stored", () => {
  assert.equal(mount({}, [1]).q('settings.schedule_timezone').value, 'Europe/London');
  assert.equal(mount({}, [2, 3]).q('settings.schedule_timezone').value, 'Asia/Tokyo');
  assert.equal(mount({}, [0]).q('settings.schedule_timezone').value, 'America/Chicago');
  assert.equal(mount({}, [1, 2]).q('settings.schedule_timezone').value, 'America/Chicago');
  const ui = mount({}, [1]);
  ui.q('settings.open_from').value = '2026-01-01T08:00';
  ui.fire(ui.q('settings.open_from'), 'change');
  assert.equal(ui.calls[0][1].schedule_timezone, 'Europe/London');
});

test('it never replaces a stored timezone when store views change', () => {
  const ui = mount({ open_from: '2026-07-01T09:00:00Z', schedule_timezone: 'Asia/Tokyo' }, [1]);

  assert.equal(ui.q('settings.schedule_timezone').value, 'Asia/Tokyo');
});

test('it re-posts the shown times in the new zone when the admin changes the timezone', () => {
  const schedule = Object.assign({}, SCHEDULE, { local: { open_from: '2026-07-01T10:00', close_at: '2026-08-01T10:00' } });
  const ui = mount(
    { open_from: '2026-07-01T09:00:00Z', close_at: '2026-08-01T09:00:00Z', schedule_timezone: 'Europe/London' }, [1], schedule
  );

  ui.q('settings.schedule_timezone').value = 'Asia/Tokyo';
  ui.fire(ui.q('settings.schedule_timezone'), 'change');

  assert.deepEqual(ui.calls[0], ['updateSettings', {
    schedule_timezone: 'Asia/Tokyo', open_from: '2026-07-01T10:00', close_at: '2026-08-01T10:00',
  }]);
});

test('it lists a stored legacy timezone that Magento does not offer', () => {
  const ui = mount({ open_from: '2026-07-01T09:00:00Z', schedule_timezone: 'Asia/Calcutta' }, [1]);
  const values = Array.from(ui.q('settings.schedule_timezone').options).map((o) => o.value);

  assert.ok(values.includes('Asia/Calcutta'));
  assert.equal(ui.q('settings.schedule_timezone').value, 'Asia/Calcutta');
});

test('it shows an instant that was not loaded with the form in the chosen zone', () => {
  const ui = mount({ open_from: '2026-07-01T09:00:00Z', schedule_timezone: 'Asia/Tokyo' }, [1]);

  assert.equal(ui.q('settings.open_from').value, '2026-07-01T18:00');
});
