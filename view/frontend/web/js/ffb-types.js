(function (root) {
  'use strict';

  var ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  var core = ns.core;
  if (ns.types || !core || !core.registerBuiltinFieldType) {
    return;
  }

  var doc = root.document;

  var asObject = function (value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  };

  var controlsOf = function (wrapper) {
    return Array.prototype.slice.call(wrapper.querySelectorAll('input, select, textarea'));
  };

  // Matrix radios are named fields[x][<row index>]; the value is a { rowIndex: columnValue } map.
  var rowIndexOf = function (control) {
    var match = /\[(\d+)\]$/.exec(control.name);
    return match ? match[1] : null;
  };

  // Date range inputs are named fields[x][start] and fields[x][end]; the value is { start, end }.
  var rangeEnd = function (control) { return /\[(start|end)\]$/.exec(control.name); };

  // List fields are one text input per row, all named fields[x][]; the first row is the template for new ones.
  var listRows = function (wrapper) {
    return Array.prototype.slice.call(wrapper.querySelectorAll('[data-ffb-list-row]'));
  };
  var listMax = function (wrapper) {
    var box = wrapper.querySelector('[data-ffb-list]');
    return Math.max(1, parseInt(box && box.getAttribute('data-ffb-list-max'), 10) || 1);
  };
  var listSync = function (wrapper) {
    var box = wrapper.querySelector('[data-ffb-list]');
    var rows = listRows(wrapper);
    rows.forEach(function (row, i) {
      var input = row.querySelector('input');
      var remove = row.querySelector('[data-ffb-list-remove]');
      input.setAttribute('aria-label', String(box.getAttribute('data-ffb-list-item') || '').replace('%1', i + 1));
      remove.setAttribute('aria-label', String(box.getAttribute('data-ffb-list-remove-label') || '').replace('%1', i + 1));
      remove.hidden = rows.length === 1;
    });
    var add = wrapper.querySelector('[data-ffb-list-add]');
    if (add) { add.hidden = rows.length >= listMax(wrapper); }
  };
  var listSetCount = function (wrapper, count) {
    var rows = listRows(wrapper);
    var target = Math.min(listMax(wrapper), Math.max(1, count));
    while (rows.length < target) {
      var row = rows[0].cloneNode(true);
      var input = row.querySelector('input');
      input.removeAttribute('id');
      input.value = '';
      rows[rows.length - 1].parentNode.appendChild(row);
      rows.push(row);
    }
    while (rows.length > target) {
      rows.pop().remove();
    }
    listSync(wrapper);
  };

  // Map inputs are named fields[x][lat|lng|zoom|label]; ffb-map.js and Leaflet load only after "Load map" is clicked.
  var mapPart = function (wrapper, part) { return wrapper.querySelector('[name$="[' + part + ']"]'); };

  // Address inputs are named fields[x][country_id|street][]|city|region_id|region|postcode (see item/address.phtml).
  var addressParts = function (wrapper) {
    var one = function (part) { return wrapper.querySelector('[name$="[' + part + ']"]'); };
    return {
      country: one('country_id'),
      streets: Array.prototype.slice.call(wrapper.querySelectorAll('[name$="[street][]"]')),
      city: one('city'),
      list: wrapper.querySelector('[data-ffb-region-list]'),
      text: wrapper.querySelector('[data-ffb-region-text]'),
      postcode: one('postcode'),
      hint: wrapper.querySelector('[data-ffb-hint]')
    };
  };

  // Regions load once per country and page; a failed request is retried next time, a 404 counts as "no list".
  var regionStore = {};
  var regionEntry = function (url, country) { return regionStore[url + '|' + country]; };
  var loadRegions = function (url, country) {
    var key = url + '|' + country;
    if (!regionStore[key]) {
      var entry = { done: false, list: [] };
      entry.promise = root.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'country=' + encodeURIComponent(country), {
        method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }
      }).then(function (res) { return res.ok ? res.json() : []; })
        .catch(function () { delete regionStore[key]; return []; })
        .then(function (list) {
          entry.list = (Array.isArray(list) ? list : []).filter(function (r) { return r && r.id !== undefined; })
            .map(function (r) { return { id: String(r.id), name: String(r.name === undefined ? '' : r.name) }; });
          entry.done = true;
          return entry.list;
        });
      regionStore[key] = entry;
    }
    return regionStore[key].promise;
  };

  // Owns the region list/text switch of one address wrapper; the address write hook waits on its promise.
  var addressController = function (wrapper, regionsUrl, onChange) {
    var p = addressParts(wrapper);
    var latest = 0;
    var clearList = function () {
      while (p.list.options.length > 1) { p.list.remove(1); }
      p.list.value = '';
    };
    var showText = function () {
      clearList();
      p.list.hidden = true;
      p.text.hidden = false;
    };
    var mirror = function () {
      if (!p.list.hidden) {
        var option = p.list.options[p.list.selectedIndex];
        p.text.value = option && option.value !== '' ? option.textContent : '';
      }
    };
    var showList = function (list) {
      clearList();
      list.forEach(function (r) {
        var option = doc.createElement('option');
        option.value = r.id;
        option.textContent = r.name;
        p.list.appendChild(option);
      });
      p.list.hidden = false;
      p.text.hidden = true;
    };
    var load = function (country, preset) {
      var mine = ++latest;
      showText();
      if (!country || !regionsUrl) {
        if (preset) { p.text.value = preset.region; }
        return Promise.resolve();
      }
      return loadRegions(regionsUrl, country).then(function (list) {
        if (mine !== latest) { return; }
        if (list.length) {
          showList(list);
          if (preset) { p.list.value = preset.region_id; }
          mirror();
        } else if (preset) {
          p.text.value = preset.region;
        }
      });
    };
    wrapper.addEventListener('change', function (event) {
      if (event.target === p.country) {
        p.text.value = '';
        load(p.country.value, null).then(onChange);
      } else if (event.target === p.list) {
        mirror();
      }
      if (event.target === p.country || event.target === p.postcode) { onChange(); }
    });
    wrapper.addEventListener('focusout', function (event) {
      if (event.target === p.postcode) { onChange(); }
    });
    return {
      write: function (value) {
        var v = asObject(value);
        var lines = [].concat(v.street === undefined ? [] : v.street).map(String);
        p.streets.forEach(function (c, i) { c.value = lines[i] === undefined ? '' : lines[i]; });
        p.city.value = typeof v.city === 'string' ? v.city : '';
        p.postcode.value = typeof v.postcode === 'string' ? v.postcode : '';
        p.country.value = typeof v.country_id === 'string' ? v.country_id : '';
        if (p.country.value !== v.country_id) { p.country.value = ''; }
        return load(p.country.value, {
          region_id: typeof v.region_id === 'string' ? v.region_id : '',
          region: typeof v.region === 'string' ? v.region : ''
        }).then(onChange);
      },
      reset: function () {
        latest += 1;
        showText();
        p.hint.textContent = '';
      },
      sync: function () {
        if (p.country.value) { load(p.country.value, { region_id: '', region: p.text.value }).then(onChange); }
      }
    };
  };

  // summary(field, wrapper, value, optionLabel) returns the review-step text of one answer.
  core.registerBuiltinFieldType('matrix', {
    read: function (wrapper) {
      var map = {};
      controlsOf(wrapper).forEach(function (c) {
        var row = rowIndexOf(c);
        if (c.checked && row !== null) { map[row] = c.value; }
      });
      return map;
    },
    write: function (wrapper, value) {
      var map = asObject(value);
      controlsOf(wrapper).forEach(function (c) {
        var row = rowIndexOf(c);
        c.checked = row !== null && Object.prototype.hasOwnProperty.call(map, row) && String(map[row]) === c.value;
      });
    },
    summary: function (field, wrapper, value, optionLabel) {
      var rows = asObject(field.settings).rows || [];
      return Object.keys(value).map(function (r) {
        return String(rows[r] === undefined ? r : rows[r]) + ': ' + optionLabel(field, wrapper, value[r]);
      }).join('; ');
    }
  });

  core.registerBuiltinFieldType('date_range', {
    read: function (wrapper) {
      var range = { start: '', end: '' };
      controlsOf(wrapper).forEach(function (c) {
        var part = rangeEnd(c);
        if (part) { range[part[1]] = c.value; }
      });
      return range;
    },
    write: function (wrapper, value) {
      var range = asObject(value);
      controlsOf(wrapper).forEach(function (c) {
        var part = rangeEnd(c);
        c.value = part && typeof range[part[1]] === 'string' ? range[part[1]] : '';
      });
    },
    summary: function (field, wrapper, value, optionLabel, formatDay) {
      return value.start === '' && value.end === '' ? '' : formatDay(value.start) + ' – ' + formatDay(value.end);
    }
  });

  core.registerBuiltinFieldType('map', {
    read: function (wrapper) {
      var text = function (part) { var c = mapPart(wrapper, part); return c ? c.value : ''; };
      return { lat: text('lat'), lng: text('lng'), zoom: text('zoom'), label: text('label') };
    },
    write: function (wrapper, value) {
      var v = asObject(value);
      ['lat', 'lng', 'zoom', 'label'].forEach(function (part) {
        var c = mapPart(wrapper, part);
        if (c) { c.value = v[part] === undefined || v[part] === null ? '' : String(v[part]); }
      });
      if (wrapper.ffbMap) {
        mapPart(wrapper, 'lat').dispatchEvent(new root.Event('change', { bubbles: true }));
      }
    },
    summary: function (field, wrapper, value) {
      return value.lat === '' || value.lng === '' ? '' : (value.label.trim() || value.lat + ', ' + value.lng);
    }
  });

  core.registerBuiltinFieldType('address', {
    read: function (wrapper) {
      var p = addressParts(wrapper);
      return {
        street: p.streets.map(function (c) { return c.value; }),
        city: p.city.value,
        region_id: p.list.hidden ? '' : p.list.value,
        region: p.text.value,
        postcode: p.postcode.value,
        country_id: p.country.value
      };
    },
    write: function (wrapper, value) {
      return wrapper.ffbAddress ? wrapper.ffbAddress.write(value) : Promise.resolve();
    },
    summary: function (field, wrapper, value, optionLabel) {
      return value.street.concat([value.city, value.region, value.postcode, value.country_id ? optionLabel(field, wrapper, value.country_id) : ''])
        .map(function (part) { return part.trim(); }).filter(Boolean).join(', ');
    }
  });

  core.registerBuiltinFieldType('list', {
    read: function (wrapper) {
      return controlsOf(wrapper).map(function (c) { return c.value; });
    },
    write: function (wrapper, value) {
      var list = [].concat(value).map(String);
      listSetCount(wrapper, list.length);
      controlsOf(wrapper).forEach(function (c, i) { c.value = list[i] === undefined ? '' : list[i]; });
    },
    summary: function (field, wrapper, value) {
      return value.map(function (v) { return v.trim(); }).filter(Boolean).join(', ');
    }
  });

  // Colour: a hidden input posts the value; the native picker (which cannot be empty) and the hex box only edit it.
  var COLOUR = /^#[0-9a-f]{6}$/i;
  var partsOfBox = function (box) {
    return {
      box: box,
      value: box.querySelector('[data-ffb-color-value]'),
      picker: box.querySelector('[data-ffb-color-picker]'),
      hex: box.querySelector('[data-ffb-color-hex]'),
      clear: box.querySelector('[data-ffb-color-clear]')
    };
  };
  var colourParts = function (wrapper) {
    var box = wrapper.querySelector('[data-ffb-color]');
    var p = box ? partsOfBox(box) : null;
    return p && p.value && p.picker && p.hex ? p : null;
  };
  // An unfinished code is kept as typed, so the format check reports it instead of it vanishing.
  var showColour = function (p, value) {
    p.value.value = value;
    if (COLOUR.test(value)) { p.picker.value = value; }
    // The swatch only shows a colour the value really holds; an unfinished code looks unchosen.
    if (COLOUR.test(value)) { p.box.removeAttribute('data-ffb-empty'); } else { p.box.setAttribute('data-ffb-empty', ''); }
    if (p.clear) { p.clear.hidden = value === ''; }
  };
  var setColour = function (p, value) {
    var clean = typeof value === 'string' && COLOUR.test(value) ? value.toLowerCase() : '';
    p.hex.value = clean;
    showColour(p, clean);
  };
  var isLocked = function (box) {
    var wrapper = box.closest('[data-ffb-item]');
    return !!wrapper && wrapper.getAttribute('aria-readonly') === 'true';
  };
  // Called for every input and change event; returns nothing, it only keeps the parts in step.
  var colourInput = function (target) {
    var box = target && target.closest ? target.closest('[data-ffb-color]') : null;
    var p = box ? partsOfBox(box) : null;
    if (!p || !p.value || (target !== p.picker && target !== p.hex)) { return; }
    if (isLocked(box)) {
      setColour(p, p.value.value);
      return;
    }
    if (target === p.picker) {
      p.hex.value = p.picker.value.toLowerCase();
      showColour(p, p.hex.value);
      return;
    }
    var text = p.hex.value.replace(/^\s+|\s+$/g, '');
    var code = /^#?([0-9a-f]{6})$/i.exec(text);
    showColour(p, code ? '#' + code[1].toLowerCase() : text);
  };
  // Returns the field wrapper that changed, or null.
  var clearColour = function (button) {
    var box = button.closest('[data-ffb-color]');
    var p = box ? partsOfBox(box) : null;
    if (!p || !p.value || !p.hex || isLocked(box)) { return null; }
    setColour(p, '');
    p.hex.focus();
    return box.closest('[data-ffb-name]');
  };
  // A hidden input keeps no default of its own once written, so the box carries it.
  var resetColours = function (form) {
    Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-color]'), function (box) {
      var p = partsOfBox(box);
      if (p.value && p.picker && p.hex) { setColour(p, box.getAttribute('data-ffb-default') || ''); }
    });
  };

  core.registerBuiltinFieldType('color', {
    // A theme template with a single colour input keeps the plain behaviour.
    read: function (wrapper) {
      var p = colourParts(wrapper);
      var plain = p ? null : wrapper.querySelector('input');
      return p ? p.value.value : (plain ? plain.value : '');
    },
    write: function (wrapper, value) {
      var p = colourParts(wrapper);
      var plain = p ? null : wrapper.querySelector('input');
      if (p) {
        setColour(p, value);
      } else if (plain) {
        plain.value = typeof value === 'string' ? value : '';
      }
    }
  });

  // readonly is ignored by select/radio/checkbox, so unselected choices are disabled instead.
  var lockControls = function (wrapper) {
    wrapper.setAttribute('aria-readonly', 'true');
    controlsOf(wrapper).forEach(function (c) {
      if (c.tagName === 'SELECT') {
        Array.prototype.forEach.call(c.options, function (o) { o.disabled = !o.selected; });
      } else if (c.type === 'radio' || c.type === 'checkbox') {
        c.ffbLockOff = !c.checked;
        c.disabled = c.ffbLockOff;
      } else if (c.type !== 'file') {
        c.readOnly = true;
      }
    });
    if (!wrapper.ffbLocked) {
      wrapper.ffbLocked = true;
      wrapper.addEventListener('click', function (event) {
        if (event.target && event.target.type === 'checkbox') { event.preventDefault(); }
      });
    }
  };

  // ffb-recaptcha.js is loaded on demand, once per page, so sites without reCAPTCHA pay nothing.
  var helperPromise = null;
  var loadRecaptchaHelper = function (url) {
    if (ns.recaptcha) {
      return Promise.resolve(ns.recaptcha);
    }
    if (!core.safeAsset(url)) {
      return Promise.reject(new Error('reCAPTCHA helper URL refused'));
    }
    if (!helperPromise) {
      helperPromise = new Promise(function (resolve, reject) {
        var script = doc.createElement('script');
        script.async = true;
        script.src = core.safeAsset(url);
        script.addEventListener('load', function () { resolve(ns.recaptcha); });
        script.addEventListener('error', function () { helperPromise = null; reject(new Error('reCAPTCHA helper failed to load')); });
        doc.head.appendChild(script);
      });
    }
    return helperPromise;
  };

  var injectAsset = function (tag, attr, url, extra, hideAmd) {
    var safe = core.safeAsset(url);
    if (!safe) {
      return Promise.reject(new Error('Map asset URL refused'));
    }
    return new Promise(function (resolve, reject) {
      var node = doc.createElement(tag);
      node[attr] = safe;
      Object.keys(extra || {}).forEach(function (k) { node[k] = extra[k]; });
      // Luma's RequireJS would capture Leaflet's UMD build as an anonymous module instead of window.L.
      var define = hideAmd && typeof root.define === 'function' && root.define.amd ? root.define : null;
      var amd = define ? define.amd : null;
      if (define) { define.amd = undefined; }
      var restore = function () { if (define) { define.amd = amd; define = null; } };
      node.addEventListener('load', function () { restore(); resolve(); });
      node.addEventListener('error', function () { restore(); reject(new Error('Map asset failed to load')); });
      doc.head.appendChild(node);
    });
  };
  var mapAssetsPromise = null;
  var loadMapAssets = function (assets, provider) {
    if (!mapAssetsPromise) {
      var tasks = [];
      var leaflet = provider !== 'google';
      if (leaflet && assets.leafletCss) { tasks.push(injectAsset('link', 'href', assets.leafletCss, { rel: 'stylesheet' })); }
      if (leaflet && assets.leafletJs && !root.L) { tasks.push(injectAsset('script', 'src', assets.leafletJs, { async: false }, true)); }
      if (assets.mapJs) { tasks.push(injectAsset('script', 'src', assets.mapJs, { async: false })); }
      mapAssetsPromise = Promise.all(tasks).catch(function (error) { mapAssetsPromise = null; throw error; });
    }
    return mapAssetsPromise;
  };

  var customHook = function (wrapper, name) {
    var hooks = core.fieldHooks(wrapper.getAttribute('data-ffb-type'));
    return hooks && typeof hooks[name] === 'function' ? hooks[name] : null;
  };

  var readValue = function (wrapper) {
    var customRead = customHook(wrapper, 'read');
    if (customRead) {
      return customRead(wrapper);
    }
    var controls = controlsOf(wrapper);
    var first = controls[0];
    if (!first) {
      return '';
    }
    if (first.type === 'file') {
      return first.files && first.files.length ? String(first.files[0].name) : '';
    }
    if (first.type === 'radio') {
      var picked = controls.filter(function (c) { return c.checked; })[0];
      return picked ? picked.value : '';
    }
    if (first.type === 'checkbox') {
      var checked = controls.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
      return /\[\]$/.test(first.name) ? checked : (checked[0] || '');
    }
    // A range input always holds a number; without a default it stays unanswered until the visitor sets it.
    if (first.type === 'range' && first.hasAttribute('data-ffb-unset')) {
      return '';
    }
    if (first.tagName === 'SELECT' && first.multiple) {
      return Array.prototype.filter.call(first.options, function (o) { return o.selected; })
        .map(function (o) { return o.value; });
    }
    return first.value;
  };

  // Masked fields hold their schema item on the wrapper (set at init); a value written to one is formatted like the server does.
  var maskedText = function (wrapper, text) {
    return wrapper.ffbMasked ? String(core.normalizeValue(wrapper.ffbMasked, text)) : text;
  };

  // A type's write hook may return a promise (address: it settles once the country's regions are applied).
  var writeValue = function (wrapper, value) {
    var customWrite = customHook(wrapper, 'write');
    if (customWrite) {
      return customWrite(wrapper, value);
    }
    var list = [].concat(value).map(String);
    controlsOf(wrapper).forEach(function (c) {
      if (c.type === 'file') {
        return;
      }
      if (c.type === 'radio' || c.type === 'checkbox') {
        c.checked = list.indexOf(c.value) !== -1;
      } else if (c.tagName === 'SELECT' && c.multiple) {
        Array.prototype.forEach.call(c.options, function (o) { o.selected = list.indexOf(o.value) !== -1; });
      } else if (c.type === 'range') {
        var set = list[0] !== undefined && list[0] !== '';
        if (set) { c.value = list[0]; }
        if (set || !c.hasAttribute('data-ffb-nodefault')) { c.removeAttribute('data-ffb-unset'); } else { c.setAttribute('data-ffb-unset', ''); }
      } else {
        c.value = list[0] === undefined ? '' : maskedText(wrapper, list[0]);
      }
    });
  };

  // DOM helpers below hold no per-form state.
  var jsonOf = function (w) { return JSON.stringify(readValue(w)); };

  var optionLabel = function (f, wrapper, value) {
    var hit = (f.options || []).filter(function (o) { return String(o.value) === String(value); })[0];
    if (hit) {
      return String(hit.label);
    }
    var select = wrapper.querySelector('select');
    var opt = select && Array.prototype.filter.call(select.options, function (o) { return o.value === String(value); })[0];
    return opt ? opt.textContent : String(value);
  };

  // Dates are read and shown in UTC so the visitor's own timezone never shifts the day.
  var DATE_FORMATS = {
    date: [/^(\d{4})-(\d{2})-(\d{2})$/, { day: 'numeric', month: 'short', year: 'numeric' }],
    'datetime-local': [/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/,
      { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }],
    month: [/^(\d{4})-(\d{2})$/, { month: 'long', year: 'numeric' }],
    time: [/^(\d{2}):(\d{2})$/, { hour: '2-digit', minute: '2-digit' }]
  };
  var formatDate = function (kind, value, locale) {
    var spec = DATE_FORMATS[kind];
    var m = spec && typeof value === 'string' ? spec[0].exec(value) : null;
    if (!m) { return value; }
    var n = m.slice(1).map(Number);
    var stamp = kind === 'time' ? Date.UTC(1970, 0, 1, n[0], n[1]) : Date.UTC(n[0], n[1] - 1, n[2] || 1, n[3] || 0, n[4] || 0);
    try {
      return new Intl.DateTimeFormat(locale || undefined, Object.assign({ timeZone: 'UTC' }, spec[1])).format(stamp);
    } catch (e) {
      return value.replace('T', ' ');
    }
  };
  // The input's type attribute, not its property: browsers without a month picker report "text".
  var controlKind = function (wrapper) {
    var first = controlsOf(wrapper)[0];
    return first ? String(first.getAttribute('type') || '').toLowerCase() : '';
  };

  var genericSummary = function (f, wrapper, value, labels) {
    var first = controlsOf(wrapper)[0] || {};
    var template = wrapper.querySelector('[data-ffb-summary-text]');
    var kind = controlKind(wrapper);
    var text;
    if (f.type === 'file') {
      text = String(value);
    } else if (Array.isArray(value)) {
      text = value.map(function (v) { return optionLabel(f, wrapper, v); }).join(', ');
    } else if (first.type === 'checkbox' && !/\[\]$/.test(first.name || '')) {
      text = String(value) === '' ? labels.no : labels.yes;
    } else if (String(value) === '') {
      text = '';
    } else if (template) {
      text = String(template.getAttribute('data-ffb-summary-text')).replace('%1', function () { return String(value); });
    } else if (f.suggestions === true) {
      var matched = core.normalizeValue(f, String(value));
      var option = (f.options || []).filter(function (o) { return o && String(o.value) === matched; })[0];
      text = option ? String(option.label) : String(value);
    } else if (DATE_FORMATS[kind]) {
      text = formatDate(kind, value, labels.locale);
    } else {
      text = optionLabel(f, wrapper, value);
      var settings = f.settings || {};
      if (settings.prefix || settings.suffix) {
        text = String(settings.prefix || '') + text + (settings.suffix ? ' ' + settings.suffix : '');
      }
    }
    return text;
  };

  // A third-party hook that throws or returns a non string must not break the review step.
  // labels: {yes, no, locale}; built-in hooks also get a date formatter.
  var summaryText = function (f, wrapper, labels) {
    var value = readValue(wrapper);
    var text;
    var hook = customHook(wrapper, 'summary');
    if (hook) {
      try {
        text = hook(f, wrapper, value, optionLabel, function (v) { return formatDate('date', v, labels.locale); });
      } catch (e) {
        console.warn('Furan_FormBuilder: the summary hook of "' + wrapper.getAttribute('data-ffb-type') + '" failed: ' + e.message);
      }
    }
    if (typeof text !== 'string') {
      text = genericSummary(f, wrapper, value, labels);
    }
    return text === '' ? '—' : text;
  };

  var markRequired = function (w, required) {
    var label = Array.prototype.filter.call(w.children, function (c) { return c.classList.contains('ffb-label'); })[0];
    var marker = label && label.querySelector('.ffb-req');
    if (label && required && !marker) {
      marker = doc.createElement('span');
      marker.className = 'ffb-req';
      marker.setAttribute('aria-hidden', 'true');
      marker.textContent = '*';
      label.appendChild(marker);
    } else if (marker && !required) {
      marker.parentNode.removeChild(marker);
    }
    var controls = controlsOf(w);
    var parts = controls.filter(function (c) { return c.hasAttribute('data-ffb-required-part'); });
    // Radio groups carry aria-required on the group (a radio has no such property); structured types only on the parts the server requires.
    var group = parts.length === 0 && w.tagName === 'FIELDSET' && controls.length > 0 && controls.every(function (c) {
      return c.type === 'radio' || c.type === 'checkbox';
    });
    if (group) {
      if (required) { w.setAttribute('aria-required', 'true'); } else { w.removeAttribute('aria-required'); }
    }
    (parts.length ? parts : (group ? [] : controls)).forEach(function (c) {
      if (required && c.type !== 'hidden') {
        c.setAttribute('aria-required', 'true');
      } else if (!c.hasAttribute('required')) {
        c.removeAttribute('aria-required');
      }
    });
  };

  // Removes the hidden <option>s (iOS Safari ignores `hidden` on them) and puts them back in schema order.
  var hideSelectOptions = function (select, hidden) {
    if (!select.ffbAllOptions) { select.ffbAllOptions = Array.prototype.slice.call(select.options); }
    select.ffbAllOptions.forEach(function (option, i) {
      var attached = option.parentNode !== null;
      if (hidden.indexOf(option.value) !== -1 && option.value !== '') {
        if (attached) { option.parentNode.removeChild(option); }
      } else if (!attached) {
        var next = select.ffbAllOptions.slice(i + 1).filter(function (o) { return o.parentNode === select; })[0];
        select.insertBefore(option, next || null);
      }
    });
  };

  // Only radios and checkboxes in their own .ffb-option label; other hidden boxes in the field are not options.
  var hideChoiceOptions = function (w, hidden) {
    controlsOf(w).forEach(function (c) {
      var box = (c.type === 'radio' || c.type === 'checkbox') ? c.closest('.ffb-option') : null;
      if (!box || !w.contains(box)) { return; }
      var off = hidden.indexOf(c.value) !== -1;
      if (off) { c.checked = false; }
      c.ffbOptionHidden = off;
      box.hidden = off;
    });
  };

  // Returns true when a selected option had to be dropped.
  var applyHiddenOptions = function (w, hidden) {
    var before = jsonOf(w);
    var select = w.querySelector('select');
    var hook = customHook(w, 'hideOptions');
    if (select && !hook && !select.ffbAllOptions && hidden.length === 0) { return false; }
    var picked = [].concat(readValue(w)).filter(function (v) { return typeof v === 'string'; });
    var kept = picked.filter(function (v) { return hidden.indexOf(v) === -1; });
    if (kept.length !== picked.length) {
      writeValue(w, select && !select.multiple ? '' : kept);
    }
    if (hook) {
      hook(w, hidden.slice());
    } else if (select) {
      hideSelectOptions(select, hidden);
    } else {
      hideChoiceOptions(w, hidden);
    }
    return jsonOf(w) !== before;
  };

  var fileError = function (config, field, input) {
    var messages = asObject(config.messages);
    var files = input && input.files ? input.files : [];
    if (!files.length) {
      return null;
    }
    var limits = [parseFloat(asObject(config.uploads).maxMb) || 0, parseFloat(field.settings.max_size_mb) || 0]
      .filter(function (n) { return n > 0; });
    var maxMb = limits.length ? Math.min.apply(null, limits) : 0;
    var accept = String(field.settings.accept || (field.settings.extensions || []).map(function (e) { return '.' + e; }).join(','))
      .split(',').map(function (s) { return s.trim().toLowerCase(); })
      .filter(function (s) { return s.charAt(0) === '.'; }).map(function (s) { return s.slice(1); });
    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      if (maxMb > 0 && file.size > maxMb * 1024 * 1024) {
        return String(messages.fileTooLarge || 'The file is too large (maximum %1 MB).').replace('%1', String(maxMb));
      }
      var ext = String(file.name).split('.').length > 1 ? String(file.name).split('.').pop().toLowerCase() : '';
      if (accept.length && accept.indexOf(ext) === -1) {
        return String(messages.fileType || 'This file type is not allowed (%1).').replace('%1', accept.join(', '));
      }
    }
    return null;
  };

  // Mask: keyboard hint at init, formatting on blur (never while typing or pasting) and after values are written.
  var applyMasks = function (fields, wrapperOf) {
    fields.forEach(function (f) {
      var maskText = f.settings.mask;
      var w = typeof maskText === 'string' && maskText !== '' ? wrapperOf(f.name) : null;
      var control = w && w.querySelector('input[type="text"], input[type="tel"], input:not([type])');
      if (!control) { return; }
      w.ffbMasked = f;
      var mode = core.mask.inputMode(maskText);
      if (mode) { control.setAttribute('inputmode', mode); }
    });
  };

  var fillHidden = function (fields, wrapperOf) {
    var params = new URLSearchParams(root.location.search);
    // No type flag marks a field as a hidden source, so the code stays.
    fields.filter(function (f) { return f.type === 'hidden'; }).forEach(function (f) {
      var s = f.settings;
      var value = null;
      var custom = core.hiddenSource(String(s.source));
      if (custom) {
        value = custom(f);
      } else if (s.source === 'query') {
        value = params.get(String(s.query_param || ''));
      } else if (s.source === 'page_url') {
        value = root.location.href;
      } else if (s.source === 'referrer') {
        value = doc.referrer;
      }
      var w = wrapperOf(f.name);
      var input = w && w.querySelector('input');
      // A source that gives nothing keeps the field's default value.
      if (input && value !== null && value !== undefined && String(value).trim() !== '') {
        input.value = String(value).slice(0, 255);
      }
    });
  };

  var showRange = function (r) {
    var out = r.closest('[data-ffb-item]');
    out = out && out.querySelector('[data-ffb-output]');
    if (out) {
      out.textContent = r.hasAttribute('data-ffb-unset') ? String(out.getAttribute('data-ffb-unset-label') || '') : r.value;
    }
  };
  var setRange = function (r) {
    if (r.hasAttribute('data-ffb-unset')) {
      r.removeAttribute('data-ffb-unset');
      showRange(r);
    }
  };
  var syncOutputs = function (form) {
    Array.prototype.forEach.call(form.querySelectorAll('input[type="range"]'), showRange);
  };

  var handleListClick = function (event, form) {
    var add = event.target.closest && event.target.closest('[data-ffb-list-add]');
    var remove = event.target.closest && event.target.closest('[data-ffb-list-remove]');
    var wrapper = (add || remove) && (add || remove).closest('[data-ffb-item]');
    if (!wrapper || !form.contains(wrapper)) {
      return;
    }
    var rows = listRows(wrapper);
    var focusAt;
    if (add) {
      listSetCount(wrapper, rows.length + 1);
      focusAt = rows.length;
    } else {
      var index = rows.indexOf(remove.closest('[data-ffb-list-row]'));
      if (rows.length === 1) {
        rows[0].querySelector('input').value = '';
      } else {
        rows[index].remove();
        listSync(wrapper);
      }
      focusAt = Math.max(0, index - 1);
    }
    var input = listRows(wrapper)[focusAt].querySelector('input');
    input.focus();
    if (remove) {
      input.dispatchEvent(new root.Event('input', { bubbles: true }));
    }
  };

  ns.types = {
    asObject: asObject,
    controlsOf: controlsOf,
    listRows: listRows,
    listSetCount: listSetCount,
    listSync: listSync,
    addressController: addressController,
    regionEntry: regionEntry,
    readValue: readValue,
    writeValue: writeValue,
    lockControls: lockControls,
    loadRecaptchaHelper: loadRecaptchaHelper,
    loadMapAssets: loadMapAssets,
    summaryText: summaryText,
    colourInput: colourInput,
    clearColour: clearColour,
    resetColours: resetColours,
    markRequired: markRequired,
    applyHiddenOptions: applyHiddenOptions,
    jsonOf: jsonOf,
    fileError: fileError,
    applyMasks: applyMasks,
    fillHidden: fillHidden,
    maskedText: maskedText,
    showRange: showRange,
    setRange: setRange,
    syncOutputs: syncOutputs,
    handleListClick: handleListClick
  };
})(typeof globalThis !== 'undefined' ? globalThis : this);
