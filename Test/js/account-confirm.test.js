'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const src = fs.readFileSync(path.resolve(__dirname, '../../view/frontend/web/js/ffb-account.js'), 'utf8');

function boot(answer) {
  const dom = new JSDOM('<form data-ffb-confirm="Delete this submission?" method="post" action="/d"><button type="submit">Delete</button></form>',
    { runScripts: 'outside-only', url: 'https://shop.test/furanform/account/submission' });
  const asked = [];
  dom.window.confirm = (m) => { asked.push(m); return answer; };
  dom.window.eval(src);
  const form = dom.window.document.querySelector('form');
  const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(event);
  return { asked, event };
}

test('it asks before deleting a submission and cancels when the customer declines', () => {
  const t = boot(false);
  assert.deepEqual(t.asked, ['Delete this submission?']);
  assert.equal(t.event.defaultPrevented, true);
});

test('it lets the delete go ahead when the customer confirms', () => {
  const t = boot(true);
  assert.equal(t.event.defaultPrevented, false);
});
