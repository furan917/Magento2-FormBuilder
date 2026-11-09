(function (root) {
  'use strict';

  const SVG_NS = 'http://www.w3.org/2000/svg';

  function tr(text, ...args) {
    const api = typeof module === 'object' && module.exports
      ? require('./builder-i18n.js')
      : (root.FuranFormBuilder || {}).builderI18n;
    return api
      ? api.t(text, ...args)
      : String(text).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m));
  }
  const MAX_OPTIONS = 4;
  const MAX_STARS = 10;
  const MAX_SCALE_BUTTONS = 11;

  // 24x24 stroke paths per type code; unknown codes use the fallback.
  const ICON_PATHS = {
    text: 'M4 7h16M4 12h10M4 17h13',
    email: 'M3 6h18v12H3zM3 7l9 6 9-6',
    tel: 'M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2',
    url: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1-1',
    number: 'M5 9h14M5 15h14M10 4L8 20M16 4l-2 16',
    textarea: 'M4 5h16v14H4zM8 10h8M8 14h5',
    select: 'M4 6h16v12H4zM9 11l3 3 3-3',
    multiselect: 'M4 5h16v14H4zM8 9h8M8 13h8M8 16h5',
    radio: 'M12 4a8 8 0 1 0 0.01 0zM12 9a3 3 0 1 0 0.01 0z',
    checkbox: 'M4 4h16v16H4zM8 12l3 3 5-6',
    checkboxes: 'M3 5h6v6H3zM3 14h6v6H3zM13 7h8M13 17h8',
    date: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
    datetime: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M12 14v3l2 1',
    time: 'M12 4a8 8 0 1 0 0.01 0zM12 8v4l3 2',
    file: 'M6 3h8l4 4v14H6zM14 3v4h4M12 17v-6M9 13l3-3 3 3',
    hidden: 'M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM4 20L20 4',
    rating: 'M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1-5.4-3-5.4 3 1.1-6.1L3.2 9.4l6.1-.8z',
    range: 'M4 12h16M14 8v8',
    color: 'M12 3a9 9 0 1 0 0 18c1.5 0 2-1 1.5-2s0-2 1.5-2h2a3 3 0 0 0 3-3A9 9 0 0 0 12 3z',
    country: 'M12 3a9 9 0 1 0 0.01 0zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18',
    scale: 'M4 8h3v8H4zM10.5 8h3v8h-3zM17 8h3v8h-3z',
    consent: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5',
    cards: 'M3 5h8v6H3zM13 5h8v6h-8zM3 13h8v6H3zM13 13h8v6h-8z',
    month: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M8 14h8',
    suggest: 'M4 7h12M4 12h9M4 17h6M17 13l4 4M15 15a3 3 0 1 0 0.01 0z',
    matrix: 'M4 4h16v16H4zM4 9.3h16M4 14.6h16M9.3 4v16M14.6 4v16',
    date_range: 'M3 6h18v14H3zM3 10h18M7 3v4M17 3v4M7 14h4M13 14h4',
    address: 'M12 21s-6-5.5-6-10a6 6 0 0 1 12 0c0 4.5-6 10-6 10zM12 9a2 2 0 1 0 0.01 0z',
    map: 'M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2zM9 4v14M15 6v14',
    list: 'M4 5h12v4H4zM4 11h12v4H4zM19 18v4M17 20h4',
    toggle: 'M3 8h18v8H3zM15 12a2 2 0 1 0 0.01 0z',
    heading: 'M6 4v16M18 4v16M6 12h12',
    paragraph: 'M5 6h14M5 10h14M5 14h14M5 18h8',
    divider: 'M3 12h18',
    rich_text: 'M5 6h14M5 10h9M5 14h14M5 18h7M17 9l2 2-2 2',
    notice: 'M3 5h18v14H3zM12 9v4M12 15.5v.5',
    summary: 'M5 4h14v16H5zM8 9h8M8 13h8M8 17h5',
    spacer: 'M8 5h8M8 19h8M12 8v8M10 10l2-2 2 2M10 14l2 2 2-2',
    section: 'M3 5h18v14H3zM3 9h18',
    page: 'M7 3h8l4 4v14H7zM15 3v4h4',
    preset: 'M12 3l2 5 5 .5-4 3.5 1.3 5L12 14.3 7.7 17l1.3-5L5 8.5 10 8z',
    fallback: 'M5 5h14v14H5z',
  };

  const NATIVE_INPUT_TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'datetime', 'time', 'month', 'suggest', 'color', 'hidden', 'password'];

  function el(doc, tag, cls, text) {
    const node = doc.createElement(tag);
    if (cls) {
      node.className = cls;
    }
    if (text !== undefined && text !== null) {
      node.textContent = text;
    }
    return node;
  }

  function icon(doc, code) {
    const svg = doc.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'ffb-admin-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.7');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    const path = doc.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', ICON_PATHS[code] || ICON_PATHS.fallback);
    svg.appendChild(path);
    return svg;
  }

  function optionLabels(item) {
    return (Array.isArray(item.options) ? item.options : []).map((o) => String((o && (o.label || o.value)) || ''));
  }

  function number(value, fallback) {
    const n = Number(value);
    return value === '' || value === null || value === undefined || Number.isNaN(n) ? fallback : n;
  }

  function setting(item, keys, fallback) {
    const s = item.settings || {};
    for (let i = 0; i < keys.length; i++) {
      if (s[keys[i]] !== undefined && s[keys[i]] !== null && s[keys[i]] !== '') {
        return s[keys[i]];
      }
    }
    return fallback;
  }

  function box(doc, text, placeholder, chevron) {
    const b = el(doc, 'div', 'ffb-admin-mock-input' + (placeholder ? ' is-placeholder' : ''));
    b.appendChild(el(doc, 'span', 'ffb-admin-mock-text', text));
    if (chevron) {
      b.appendChild(el(doc, 'span', 'ffb-admin-mock-chevron'));
    }
    return b;
  }

  function choiceList(doc, item, kind) {
    const labels = optionLabels(item);
    const list = el(doc, 'div', 'ffb-admin-mock-choices' + (item.settings && item.settings.layout === 'inline' ? ' is-inline' : ''));
    labels.slice(0, MAX_OPTIONS).forEach((label) => {
      const row = el(doc, 'div', 'ffb-admin-mock-choice');
      row.appendChild(el(doc, 'span', 'ffb-admin-mock-' + kind));
      row.appendChild(el(doc, 'span', 'ffb-admin-mock-text', label));
      list.appendChild(row);
    });
    if (labels.length > MAX_OPTIONS) {
      list.appendChild(el(doc, 'div', 'ffb-admin-mock-more', tr('+%1 more', labels.length - MAX_OPTIONS)));
    }
    return list;
  }

  function stars(doc, item) {
    const max = Math.max(1, Math.min(MAX_STARS, number(setting(item, ['max'], 5), 5)));
    const wrap = el(doc, 'div', 'ffb-admin-mock-stars');
    for (let i = 0; i < max; i++) {
      wrap.appendChild(el(doc, 'span', 'ffb-admin-mock-star', '★'));
    }
    return wrap;
  }

  function slider(doc) {
    const wrap = el(doc, 'div', 'ffb-admin-mock-slider');
    wrap.appendChild(el(doc, 'span', 'ffb-admin-mock-knob'));
    return wrap;
  }

  function dropzone(doc) {
    return el(doc, 'div', 'ffb-admin-mock-dropzone', tr('Drop a file or click to browse'));
  }

  function scale(doc, item) {
    const min = number(setting(item, ['min'], 1), 1);
    const max = number(setting(item, ['max'], 5), 5);
    const count = Math.max(1, Math.min(MAX_SCALE_BUTTONS, max - min + 1));
    const wrap = el(doc, 'div', 'ffb-admin-mock-scale');
    const row = el(doc, 'div', 'ffb-admin-mock-scale-row');
    for (let i = 0; i < count; i++) {
      row.appendChild(el(doc, 'span', 'ffb-admin-mock-scale-btn', String(min + i)));
    }
    wrap.appendChild(row);
    const low = setting(item, ['low_label', 'low'], '');
    const high = setting(item, ['high_label', 'high'], '');
    if (low || high) {
      const ends = el(doc, 'div', 'ffb-admin-mock-scale-ends');
      ends.appendChild(el(doc, 'span', '', String(low)));
      ends.appendChild(el(doc, 'span', '', String(high)));
      wrap.appendChild(ends);
    }
    return wrap;
  }

  function cards(doc, item) {
    const labels = optionLabels(item);
    const wrap = el(doc, 'div', 'ffb-admin-mock-cards');
    (item.options || []).slice(0, MAX_OPTIONS).forEach((option, i) => {
      const card = el(doc, 'div', 'ffb-admin-mock-card');
      card.appendChild(el(doc, 'span', 'ffb-admin-mock-card-title', labels[i]));
      if (option && option.description) {
        card.appendChild(el(doc, 'span', 'ffb-admin-mock-card-desc', String(option.description)));
      }
      wrap.appendChild(card);
    });
    if (labels.length > MAX_OPTIONS) {
      wrap.appendChild(el(doc, 'div', 'ffb-admin-mock-card ffb-admin-mock-more', tr('+%1 more', labels.length - MAX_OPTIONS)));
    }
    return wrap;
  }

  function matrix(doc, item) {
    const rows = Array.isArray(item.settings && item.settings.rows) ? item.settings.rows : [];
    const cols = optionLabels(item);
    const label = (v) => String(v && typeof v === 'object' ? (v.label || v.value || '') : (v || ''));
    const shownCols = (cols.length ? cols : ['', '', '']).slice(0, 4).map(label);
    const shownRows = (rows.length ? rows : ['', '']).slice(0, 3).map(label);
    const grid = el(doc, 'div', 'ffb-admin-mock-matrix');
    grid.style.gridTemplateColumns = 'minmax(0, 1.4fr) repeat(' + shownCols.length + ', minmax(0, 1fr))';
    grid.appendChild(el(doc, 'span'));
    shownCols.forEach((c) => grid.appendChild(el(doc, 'span', 'ffb-admin-mock-matrix-head', c)));
    shownRows.forEach((r) => {
      grid.appendChild(el(doc, 'span', 'ffb-admin-mock-matrix-head', r));
      shownCols.forEach(() => grid.appendChild(el(doc, 'span', 'ffb-admin-mock-radio')));
    });
    return grid;
  }

  function dateRange(doc, item) {
    const wrap = el(doc, 'div', 'ffb-admin-mock-pair');
    wrap.appendChild(box(doc, String(setting(item, ['start_label'], '') || tr('Start date')), true, false));
    wrap.appendChild(box(doc, String(setting(item, ['end_label'], '') || tr('End date')), true, false));
    return wrap;
  }

  function address(doc) {
    const wrap = el(doc, 'div', 'ffb-admin-mock-address');
    [tr('Street address'), tr('City'), tr('Postcode'), tr('Country')].forEach((text) => {
      wrap.appendChild(box(doc, text, true, false));
    });
    return wrap;
  }

  function map(doc, item) {
    const wrap = el(doc, 'div', 'ffb-admin-mock-map');
    wrap.style.height = Math.max(60, Math.min(160, number(setting(item, ['height'], 320), 320) / 2)) + 'px';
    wrap.appendChild(el(doc, 'span', 'ffb-admin-mock-text', tr('Click the map to place a pin')));
    return wrap;
  }

  function list(doc, item) {
    const wrap = el(doc, 'div', 'ffb-admin-mock-list');
    wrap.appendChild(box(doc, String(item.placeholder || ''), true, false));
    wrap.appendChild(el(doc, 'span', 'ffb-admin-mock-list-add', '+ ' + String(setting(item, ['add_label'], '') || tr('Add another'))));
    return wrap;
  }

  function singleCheckbox(doc, item, kind) {
    const row = el(doc, 'div', 'ffb-admin-mock-choice');
    row.appendChild(el(doc, 'span', 'ffb-admin-mock-' + kind));
    row.appendChild(el(doc, 'span', 'ffb-admin-mock-text', String(item.label || item.name || '')));
    return row;
  }

  function toggle(doc, item) {
    const row = el(doc, 'div', 'ffb-admin-mock-choice');
    row.appendChild(el(doc, 'span', 'ffb-admin-mock-switch'));
    row.appendChild(el(doc, 'span', 'ffb-admin-mock-text', String(item.label || item.name || '')));
    return row;
  }

  // Inert mock of the storefront control. `meta` is the field type entry from the builder config.
  function control(doc, item, meta) {
    const type = item.type;
    const placeholder = String(item.placeholder || '');
    let node;
    if (type === 'hidden') {
      node = box(doc, tr('Hidden field'), true, false);
      node.classList.add('is-hidden-field');
    } else if (type === 'textarea') {
      node = box(doc, placeholder, true, false);
      node.classList.add('is-tall');
    } else if (type === 'select' || type === 'multiselect' || type === 'country') {
      const first = optionLabels(item)[0] || (type === 'country' ? tr('Select a country') : tr('Select...'));
      node = box(doc, placeholder || first, !!placeholder || type === 'country', true);
    } else if (type === 'radio') {
      node = choiceList(doc, item, 'radio');
    } else if (type === 'checkboxes') {
      node = choiceList(doc, item, 'check');
    } else if (type === 'checkbox') {
      node = singleCheckbox(doc, item, 'check');
    } else if (type === 'consent') {
      node = singleCheckbox(doc, item, 'check');
    } else if (type === 'rating') {
      node = stars(doc, item);
    } else if (type === 'range') {
      node = slider(doc);
    } else if (type === 'file' || (meta && meta.isFile)) {
      node = dropzone(doc);
    } else if (type === 'scale') {
      node = scale(doc, item);
    } else if (type === 'cards') {
      node = cards(doc, item);
    } else if (type === 'matrix') {
      node = matrix(doc, item);
    } else if (type === 'date_range') {
      node = dateRange(doc, item);
    } else if (type === 'address') {
      node = address(doc);
    } else if (type === 'map') {
      node = map(doc, item);
    } else if (type === 'toggle') {
      node = toggle(doc, item);
    } else if (type === 'list') {
      node = list(doc, item);
    } else if (NATIVE_INPUT_TYPES.indexOf(type) >= 0 || !meta) {
      node = box(doc, placeholder, true, false);
      const prefix = String(setting(item, ['prefix'], '') || '');
      const suffix = String(setting(item, ['suffix'], '') || '');
      if (prefix) {
        node.insertBefore(el(doc, 'span', 'ffb-admin-mock-affix', prefix), node.firstChild);
      }
      if (suffix) {
        node.appendChild(el(doc, 'span', 'ffb-admin-mock-affix', suffix));
      }
    } else if (meta.hasOptions) {
      node = meta.isMultiValue ? choiceList(doc, item, 'check') : box(doc, optionLabels(item)[0] || tr('Select...'), false, true);
    } else {
      node = box(doc, placeholder, true, false);
    }
    node.classList.add('ffb-admin-mock');
    node.setAttribute('aria-hidden', 'true');
    return node;
  }

  function hasOwnLabel(item) {
    return item.type === 'checkbox' || item.type === 'consent' || item.type === 'toggle';
  }

  // Rendered look of a content item.
  function content(doc, item) {
    if (item.type === 'heading') {
      const level = Math.max(1, Math.min(6, number(item.level, 2)));
      return el(doc, 'div', 'ffb-admin-mock-heading ffb-admin-mock-h' + level, String(item.text || tr('Heading')));
    }
    if (item.type === 'paragraph') {
      return el(doc, 'p', 'ffb-admin-mock-paragraph', String(item.text || ''));
    }
    if (item.type === 'rich_text') {
      return el(doc, 'p', 'ffb-admin-mock-paragraph', String(item.text || ''));
    }
    if (item.type === 'notice') {
      const box = el(doc, 'div', 'ffb-admin-mock-notice');
      if (item.title) {
        box.appendChild(el(doc, 'strong', '', String(item.title)));
      }
      box.appendChild(el(doc, 'p', 'ffb-admin-mock-paragraph', String(item.text || '')));
      return box;
    }
    if (item.type === 'summary') {
      const box = el(doc, 'div', 'ffb-admin-mock-summary');
      box.appendChild(el(doc, 'strong', '', String(item.title || '')));
      return box;
    }
    if (item.type === 'divider') {
      return el(doc, 'hr', 'ffb-admin-mock-divider');
    }
    const gap = el(doc, 'div', 'ffb-admin-mock-spacer');
    gap.setAttribute('aria-label', tr('Spacer'));
    return gap;
  }

  const api = { icon, control, content, hasOwnLabel, ICON_PATHS };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderPreview = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
