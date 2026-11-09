(function (root) {
  'use strict';

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const WIDTH = 600;
  const TIME_WIDTH = 1200;
  const CARD_PADDING = 34;
  const TICK_SPACING = 110;
  const RESIZE_DELAY = 150;
  const AXIS = 40;
  const LABEL_WIDTH = 200;
  const BAR_AREA = 320;
  const ROW_HEIGHT = 24;
  const MAX_LABEL = 28;
  const MIN_SLOTS = 8;

  function svgEl(doc, tag, attrs, text) {
    const node = doc.createElementNS(SVG_NS, tag);
    Object.keys(attrs || {}).forEach((name) => node.setAttribute(name, String(attrs[name])));
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function htmlEl(doc, tag, className, text) {
    const node = doc.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function short(label) {
    const chars = Array.from(String(label));
    return chars.length > MAX_LABEL ? chars.slice(0, MAX_LABEL - 1).join('') + '…' : chars.join('');
  }

  function number(value) {
    return Math.round(value * 100) / 100;
  }

  function canvas(doc, height, title, width) {
    const svg = svgEl(doc, 'svg', { viewBox: '0 0 ' + (width || WIDTH) + ' ' + height, width: '100%', role: 'img', 'aria-label': title, class: 'ffb-admin-svg' });
    svg.appendChild(svgEl(doc, 'title', {}, title));
    return svg;
  }

  function dataTable(doc, i18n, caption, headers, rows) {
    const details = htmlEl(doc, 'details', 'ffb-admin-data');
    details.appendChild(htmlEl(doc, 'summary', '', i18n.dataTable));
    const table = htmlEl(doc, 'table', 'ffb-admin-table');
    table.appendChild(htmlEl(doc, 'caption', '', caption));
    const head = htmlEl(doc, 'tr');
    headers.forEach((header) => {
      const th = htmlEl(doc, 'th', '', header);
      th.setAttribute('scope', 'col');
      head.appendChild(th);
    });
    table.appendChild(htmlEl(doc, 'thead')).appendChild(head);
    const body = table.appendChild(htmlEl(doc, 'tbody'));
    rows.forEach((cells) => {
      const tr = body.appendChild(htmlEl(doc, 'tr'));
      cells.forEach((cell) => tr.appendChild(htmlEl(doc, 'td', '', String(cell))));
    });
    details.appendChild(table);
    return details;
  }

  function figure(doc, className, title) {
    const node = htmlEl(doc, 'figure', 'ffb-admin-chart ' + className);
    node.appendChild(htmlEl(doc, 'figcaption', '', title));
    return node;
  }

  function barSvg(doc, items, title) {
    const svg = canvas(doc, Math.max(items.length, 1) * ROW_HEIGHT, title);
    const max = Math.max(1, ...items.map((item) => item.hits));
    items.forEach((item, index) => {
      const y = index * ROW_HEIGHT;
      const label = svgEl(doc, 'text', { x: 0, y: y + 16, class: 'ffb-admin-label' }, short(item.label));
      label.appendChild(svgEl(doc, 'title', {}, item.label));
      svg.appendChild(label);
      svg.appendChild(svgEl(doc, 'rect', { class: 'ffb-admin-bar', x: LABEL_WIDTH, y: y + 4, height: 16, width: number(item.hits / max * BAR_AREA), fill: '#1979c3' }));
      const suffix = item.percent === undefined ? '' : ' (' + item.percent + '%)';
      svg.appendChild(svgEl(doc, 'text', { x: LABEL_WIDTH + number(item.hits / max * BAR_AREA) + 6, y: y + 16, class: 'ffb-admin-value' }, item.hits + suffix));
    });
    return svg;
  }

  function optionRows(i18n, items) {
    return items.map((item) => [item.label, item.hits, item.percent === undefined ? '' : item.percent + '%']);
  }

  function choiceFigure(doc, i18n, chart) {
    const node = figure(doc, 'ffb-admin-choice', chart.label);
    if (chart.answered !== undefined) {
      node.appendChild(htmlEl(doc, 'p', 'ffb-admin-answered', chart.answered + ' ' + i18n.answered));
    }
    node.appendChild(barSvg(doc, chart.options, chart.label));
    node.appendChild(dataTable(doc, i18n, chart.label, [i18n.answers, i18n.value, i18n.share], optionRows(i18n, chart.options)));
    return node;
  }

  function ratingFigure(doc, i18n, chart) {
    const node = figure(doc, 'ffb-admin-rating', chart.label);
    const average = chart.average === null || chart.average === undefined ? '-' : number(chart.average);
    node.appendChild(htmlEl(doc, 'p', 'ffb-admin-average', i18n.average + ': ' + average + ' (' + chart.n + ')'));
    node.appendChild(barSvg(doc, chart.options, chart.label));
    node.appendChild(dataTable(doc, i18n, chart.label, [i18n.answers, i18n.value, i18n.share], optionRows(i18n, chart.options)));
    return node;
  }

  function histogramFigure(doc, i18n, chart) {
    const node = figure(doc, 'ffb-admin-histogram', chart.label);
    if (chart.average !== null && chart.average !== undefined) {
      node.appendChild(htmlEl(doc, 'p', 'ffb-admin-average', i18n.average + ': ' + number(chart.average)));
    }
    const height = 160;
    const svg = canvas(doc, height + 20, chart.label);
    const buckets = chart.buckets;
    const max = Math.max(1, ...buckets.map((bucket) => bucket.hits));
    const slot = WIDTH / Math.max(buckets.length, MIN_SLOTS);
    buckets.forEach((bucket, index) => {
      const h = number(bucket.hits / max * height);
      const rect = svgEl(doc, 'rect', { class: 'ffb-admin-bar', x: number(index * slot + 1), y: height - h, width: number(Math.max(slot - 2, 1)), height: h, fill: '#1979c3' });
      rect.appendChild(svgEl(doc, 'title', {}, bucket.label + ': ' + bucket.hits));
      svg.appendChild(rect);
    });
    if (buckets.length) {
      svg.appendChild(svgEl(doc, 'text', { x: 0, y: height + 14, class: 'ffb-admin-label' }, buckets[0].label));
    }
    if (buckets.length > 1) {
      svg.appendChild(svgEl(doc, 'text', { x: number(buckets.length * slot), y: height + 14, 'text-anchor': 'end', class: 'ffb-admin-label' }, buckets[buckets.length - 1].label));
    }
    node.appendChild(svg);
    node.appendChild(dataTable(doc, i18n, chart.label, [i18n.answers, i18n.value], buckets.map((b) => [b.label, b.hits])));
    return node;
  }

  function matrixFigure(doc, i18n, chart) {
    const node = figure(doc, 'ffb-admin-matrix', chart.label);
    const rows = [];
    (chart.rows || []).forEach((row) => {
      node.appendChild(htmlEl(doc, 'h4', '', row.label));
      node.appendChild(barSvg(doc, row.options, row.label));
      row.options.forEach((item) => rows.push([row.label, item.label, item.hits, item.percent + '%']));
    });
    node.appendChild(dataTable(doc, i18n, chart.label, [i18n.row, i18n.answers, i18n.value, i18n.share], rows));
    return node;
  }

  function answeredFigure(doc, i18n, chart) {
    const node = figure(doc, 'ffb-admin-answered-only', chart.label);
    node.appendChild(htmlEl(doc, 'p', 'ffb-admin-answered', chart.answered + ' ' + i18n.answered));
    return node;
  }

  // Indexes of the points that get an axis label: always the first and last, the rest evenly spread by width.
  function timeTicks(width, points) {
    if (points < 1) {
      return [];
    }
    const wanted = Math.min(points, Math.max(2, Math.floor(width / TICK_SPACING)));
    const indexes = [];
    for (let i = 0; i < wanted; i += 1) {
      const index = wanted === 1 ? 0 : Math.round(i * (points - 1) / (wanted - 1));
      if (indexes[indexes.length - 1] !== index) {
        indexes.push(index);
      }
    }
    return indexes;
  }

  // The time chart fills its card: the SVG is drawn at the container's pixel width so text keeps its size.
  function timeWidth(container) {
    return container.clientWidth > CARD_PADDING ? container.clientWidth - CARD_PADDING : TIME_WIDTH;
  }

  function timeFigure(doc, i18n, time, title, width) {
    const node = figure(doc, 'ffb-admin-time', title);
    const height = 160;
    const svg = canvas(doc, height + 24, title, width);
    const max = Math.max(1, ...time.map((point) => point.submissions));
    const step = time.length > 1 ? (width - AXIS - 20) / (time.length - 1) : 0;
    const yOf = (value) => number(height - value / max * height + 4);
    [0, max].forEach((value) => {
      svg.appendChild(svgEl(doc, 'line', { x1: AXIS, x2: width, y1: yOf(value), y2: yOf(value), stroke: '#e3e3e3' }));
      svg.appendChild(svgEl(doc, 'text', { x: AXIS - 6, y: yOf(value) + 4, 'text-anchor': 'end', class: 'ffb-admin-label' }, String(value)));
    });
    const coords = time.map((point, index) => ({ x: number(AXIS + 10 + index * step), y: yOf(point.submissions), point }));
    svg.appendChild(svgEl(doc, 'polyline', { class: 'ffb-admin-line', fill: 'none', stroke: '#1979c3', points: coords.map((c) => c.x + ',' + c.y).join(' ') }));
    coords.forEach((c) => {
      const dot = svgEl(doc, 'circle', { cx: c.x, cy: c.y, r: 3, fill: '#1979c3' });
      dot.appendChild(svgEl(doc, 'title', {}, c.point.period + ': ' + c.point.submissions));
      svg.appendChild(dot);
    });
    const ticks = timeTicks(width, time.length);
    ticks.forEach((index, position) => {
      const first = position === 0;
      const last = position === ticks.length - 1 && ticks.length > 1;
      svg.appendChild(svgEl(doc, 'text', {
        x: first ? AXIS : (last ? width : coords[index].x),
        y: height + 20,
        'text-anchor': first ? 'start' : (last ? 'end' : 'middle'),
        class: 'ffb-admin-label ffb-admin-tick',
      }, time[index].period));
    });
    node.appendChild(svg);
    node.appendChild(dataTable(doc, i18n, title, [i18n.period, i18n.submissions], time.map((p) => [p.period, p.submissions])));
    return node;
  }

  const BUILDERS = { choice: choiceFigure, rating: ratingFigure, histogram: histogramFigure, matrix: matrixFigure, answered: answeredFigure };

  function render(container, data) {
    const doc = container.ownerDocument;
    const i18n = data.i18n || {};
    container.textContent = '';
    if ((data.time || []).length) {
      container.appendChild(timeFigure(doc, i18n, data.time, i18n.submissions, timeWidth(container)));
    }
    (data.charts || []).forEach((chart) => {
      const build = BUILDERS[chart.type];
      if (build) {
        container.appendChild(build(doc, i18n, chart));
      }
    });
  }

  // Swaps the time figure for one drawn at the container's current width; other charts and open tables stay.
  function redrawTime(container, data) {
    const old = container.querySelector('figure.ffb-admin-time');
    if (!old || !(data.time || []).length) {
      return;
    }
    const fresh = timeFigure(container.ownerDocument, data.i18n || {}, data.time, (data.i18n || {}).submissions, timeWidth(container));
    const table = old.querySelector('details');
    if (table && table.open) {
      fresh.querySelector('details').open = true;
    }
    container.replaceChild(fresh, old);
  }

  // Debounced; redraws only when the width really changed, so the redraw cannot feed the observer.
  function watchWidth(win, target, data) {
    if (typeof win.ResizeObserver !== 'function') {
      return;
    }
    let last = target.clientWidth;
    let timer = null;
    new win.ResizeObserver(() => {
      win.clearTimeout(timer);
      timer = win.setTimeout(() => {
        if (target.clientWidth !== last) {
          last = target.clientWidth;
          redrawTime(target, data);
        }
      }, RESIZE_DELAY);
    }).observe(target);
  }

  function init(doc) {
    const target = doc.querySelector('[data-ffb-analytics-charts]');
    const json = doc.getElementById('ffb-analytics-data');
    if (target && json) {
      const data = JSON.parse(json.textContent);
      render(target, data);
      watchWidth(doc.defaultView, target, data);
    }
  }

  const api = { render, redrawTime, timeTicks, init };
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).analyticsCharts = api;
    if (root.document.readyState === 'loading') {
      root.document.addEventListener('DOMContentLoaded', () => init(root.document));
    } else {
      init(root.document);
    }
  }
}(typeof window !== 'undefined' ? window : globalThis));
