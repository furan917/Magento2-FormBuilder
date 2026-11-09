'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('./helpers/dom');

const source = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/js/analytics-charts.js'), 'utf8');

function setup(data) {
  const dom = new JSDOM('<div id="root"></div>', { runScripts: 'outside-only', url: 'https://admin.test/' });
  dom.window.eval(source);
  const root = dom.window.document.getElementById('root');
  dom.window.FuranFormBuilder.analyticsCharts.render(root, data);
  return { dom, root };
}

const i18n = { dataTable: 'Show data', answers: 'Answers', share: 'Share', average: 'Average', submissions: 'Submissions', period: 'Period', value: 'Value', answered: 'answered', row: 'Row' };
const choice = { key: 'colour', label: 'Colour <b>', type: 'choice', options: [
  { label: 'Red', hits: 6, percent: 60 }, { label: 'Blue', hits: 4, percent: 40 },
] };

test('it draws a bar chart for choice questions', () => {
  const { root } = setup({ i18n, charts: [choice], time: [], granularity: 'day' });
  const svg = root.querySelector('figure svg');
  assert.equal(svg.namespaceURI, 'http://www.w3.org/2000/svg');
  const bars = svg.querySelectorAll('rect.ffb-admin-bar');
  assert.equal(bars.length, 2);
  assert.ok(Number(bars[0].getAttribute('width')) > Number(bars[1].getAttribute('width')));
  assert.equal(root.querySelector('figcaption').textContent, 'Colour <b>');
  assert.equal(root.querySelector('figcaption b'), null);
  assert.equal(svg.getAttribute('style'), null);
});

test('it draws an average and distribution for rating questions', () => {
  const { root } = setup({ i18n, time: [], granularity: 'day', charts: [{
    key: 'r', label: 'Rating', type: 'rating', average: 4.25, n: 8,
    options: [{ label: '4', hits: 6, percent: 75 }, { label: '5', hits: 2, percent: 25 }],
  }] });
  assert.match(root.querySelector('.ffb-admin-average').textContent, /Average.*4\.25/);
  assert.equal(root.querySelectorAll('figure svg rect.ffb-admin-bar').length, 2);
});

test('it draws a line chart of submissions over time', () => {
  const { root } = setup({ i18n, charts: [], granularity: 'day', time: [
    { period: '2026-11-01', submissions: 2 }, { period: '2026-11-02', submissions: 5 }, { period: '2026-11-03', submissions: 1 },
  ] });
  const line = root.querySelector('figure.ffb-admin-time svg polyline');
  assert.equal(line.getAttribute('points').trim().split(/\s+/).length, 3);
  assert.equal(root.querySelectorAll('figure.ffb-admin-time svg circle').length, 3);
});

test('it offers each chart\'s numbers as an accessible table', () => {
  const { root } = setup({ i18n, charts: [choice, {
    key: 'h', label: 'Age', type: 'histogram', average: 30, buckets: [{ label: '0-9', hits: 1 }, { label: '10-19', hits: 3 }],
  }], granularity: 'day', time: [{ period: '2026-11-01', submissions: 2 }] });
  const figures = root.querySelectorAll('figure');
  assert.equal(figures.length, 3);
  for (const figure of figures) {
    const table = figure.querySelector('details table');
    assert.ok(table, 'table behind chart');
    assert.ok(table.querySelector('caption'));
    assert.ok(table.querySelectorAll('tbody tr').length >= 1);
    assert.equal(figure.querySelector('svg').getAttribute('role'), 'img');
  }
  assert.equal(figures[1].querySelectorAll('tbody tr').length, 2);
  assert.equal(figures[2].querySelectorAll('svg rect.ffb-admin-bar').length, 2);
});

test('it labels the time chart y axis with zero and the peak and keeps text at its natural size', () => {
  const { root } = setup({ i18n, charts: [], granularity: 'month', time: [
    { period: '2026-10', submissions: 0 }, { period: '2026-11', submissions: 6 },
  ] });
  const svg = root.querySelector('.ffb-admin-time svg');
  const labels = Array.from(svg.querySelectorAll('text')).map((t) => t.textContent);
  assert.ok(labels.includes('0'));
  assert.ok(labels.includes('6'));
  assert.equal(svg.getAttribute('viewBox').split(' ')[2], '1200');
});

const wide = (width) => {
  const dom = new JSDOM('<div id="root"></div>', { runScripts: 'outside-only', url: 'https://admin.test/' });
  dom.window.eval(source);
  const root = dom.window.document.getElementById('root');
  Object.defineProperty(root, 'clientWidth', { value: width, configurable: true });
  return { dom, root, charts: dom.window.FuranFormBuilder.analyticsCharts };
};
const days = (n) => Array.from({ length: n }, (_, i) => ({ period: '2026-10-' + String(i + 1).padStart(2, '0'), submissions: i % 7 }));

test('it sizes the time chart to the full card width', () => {
  const css = fs.readFileSync(path.resolve(__dirname, '../../view/adminhtml/web/css/analytics.css'), 'utf8');
  assert.doesNotMatch(css, /\.ffb-admin-time\s+\.ffb-admin-svg[^{]*\{[^}]*max-width/);
  assert.match(css, /\.ffb-admin-chart:not\(\.ffb-admin-time\) \.ffb-admin-svg \{ max-width: 600px; \}/);

  for (const width of [734, 1534, 2034]) {
    const { root, charts } = wide(width);
    charts.render(root, { i18n, charts: [], granularity: 'day', time: days(20) });
    const svg = root.querySelector('.ffb-admin-time svg');
    assert.equal(svg.getAttribute('viewBox').split(' ')[2], String(width - 34));
    assert.equal(svg.getAttribute('width'), '100%');
    assert.equal(svg.getAttribute('style'), null);
  }
});

test('it reduces time axis labels on narrow charts', () => {
  const { charts } = wide(0);
  const ticks = (width, points) => Array.from(charts.timeTicks(width, points));

  assert.deepEqual(ticks(300, 30), [0, 29]);
  assert.ok(ticks(1200, 30).length > ticks(600, 30).length);
  assert.ok(ticks(600, 30).length > ticks(300, 30).length);
  for (const [width, points] of [[1200, 30], [600, 12], [200, 3], [1200, 4], [90, 50]]) {
    const result = ticks(width, points);
    assert.equal(result[0], 0);
    assert.equal(result[result.length - 1], points - 1);
    assert.deepEqual(result, Array.from(new Set(result)).sort((a, b) => a - b));
    assert.ok(result.length <= points);
  }
  assert.deepEqual(ticks(1200, 1), [0]);
  assert.deepEqual(ticks(1200, 0), []);

  const narrow = wide(334);
  narrow.charts.render(narrow.root, { i18n, charts: [], granularity: 'day', time: days(30) });
  const axis = narrow.root.querySelectorAll('.ffb-admin-time svg text.ffb-admin-tick');
  assert.equal(axis.length, 2);
  const broad = wide(1534);
  broad.charts.render(broad.root, { i18n, charts: [], granularity: 'day', time: days(30) });
  assert.ok(broad.root.querySelectorAll('.ffb-admin-time svg text.ffb-admin-tick').length > 2);
});

test('it redraws only the time chart when the card width changes', () => {
  const { dom, root, charts } = wide(734);
  const data = { i18n, charts: [choice], granularity: 'day', time: days(10) };
  charts.render(root, data);
  const details = root.querySelector('figure.ffb-admin-choice details');
  details.open = true;
  Object.defineProperty(root, 'clientWidth', { value: 1034, configurable: true });

  charts.redrawTime(root, data);

  assert.equal(root.querySelector('.ffb-admin-time svg').getAttribute('viewBox').split(' ')[2], '1000');
  assert.equal(root.querySelectorAll('figure.ffb-admin-time').length, 1);
  assert.equal(root.querySelector('figure.ffb-admin-choice details'), details);
  assert.equal(details.open, true);
  assert.ok(dom);
});

const tableOf = (figure) => ({
  head: Array.from(figure.querySelectorAll('thead th')).map((th) => th.textContent),
  rows: Array.from(figure.querySelectorAll('tbody tr')).map((tr) => Array.from(tr.querySelectorAll('td')).map((td) => td.textContent)),
});

test('it heads the histogram table with the bucket first and its count second, like its rows', () => {
  const { root } = setup({ i18n, time: [], granularity: 'day', charts: [{
    key: 'h', label: 'Age', type: 'histogram', average: 30, buckets: [{ label: '20-29', hits: 1 }, { label: '30-39', hits: 3 }],
  }] });
  const table = tableOf(root.querySelector('figure.ffb-admin-histogram'));
  assert.deepEqual(table.head, ['Answers', 'Value']);
  assert.deepEqual(table.rows, [['20-29', '1'], ['30-39', '3']]);
});

test('it heads the matrix table with row, option, count and share, like its rows', () => {
  const { root } = setup({ i18n, time: [], granularity: 'day', charts: [{
    key: 'm', label: 'Service', type: 'matrix', rows: [{ label: 'Speed', options: [{ label: 'Good', hits: 3, percent: 75 }, { label: 'Poor', hits: 1, percent: 25 }] }],
  }] });
  const table = tableOf(root.querySelector('figure.ffb-admin-matrix'));
  assert.deepEqual(table.head, ['Row', 'Answers', 'Value', 'Share']);
  assert.deepEqual(table.rows, [['Speed', 'Good', '3', '75%'], ['Speed', 'Poor', '1', '25%']]);
});

test('it draws matrix rows and answers in the order the server sends them', () => {
  const { root } = setup({ i18n, time: [], granularity: 'day', charts: [{
    key: 'm', label: 'Rate us', type: 'matrix', rows: [
      { label: 'Performance', options: [{ label: 'Very', hits: 4, percent: 50 }] },
      { label: '2', options: [{ label: 'Very', hits: 1, percent: 12.5 }, { label: 'A little', hits: 2, percent: 25 }] },
      { label: '1', options: [{ label: 'Not at all', hits: 1, percent: 12.5 }] },
    ],
  }] });
  const figure = root.querySelector('figure.ffb-admin-matrix');
  assert.deepEqual(Array.from(figure.querySelectorAll('h4')).map((h) => h.textContent), ['Performance', '2', '1']);
  assert.deepEqual(tableOf(figure).rows.map((r) => r.slice(0, 2)), [['Performance', 'Very'], ['2', 'Very'], ['2', 'A little'], ['1', 'Not at all']]);
});

test('it keeps a histogram with few buckets to narrow bars with one label each', () => {
  const { root } = setup({ i18n, time: [], granularity: 'day', charts: [{
    key: 'h', label: 'Invoice month', type: 'histogram', average: null, buckets: [{ label: '2027-01', hits: 1 }],
  }] });
  const svg = root.querySelector('figure.ffb-admin-histogram svg');
  assert.ok(Number(svg.querySelector('rect.ffb-admin-bar').getAttribute('width')) <= 600 / 8);
  assert.deepEqual(Array.from(svg.querySelectorAll('text')).map((t) => t.textContent), ['2027-01']);
});
