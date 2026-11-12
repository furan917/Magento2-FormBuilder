(function (root) {
  'use strict';

  var ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  if (ns.core) {
    if (typeof module === 'object' && module.exports) {
      module.exports = ns.core;
    }
    return;
  }

  // Sources mirror Model/Validation/Patterns.php JS_* constants.
  var REGEX = {
    email: /^[^\t\n\x0B\f\r @-][^\t\n\x0B\f\r @]*@[^\t\n\x0B\f\r @]+\.[^\t\n\x0B\f\r @]+$/u,
    url: /^https?:\/\/[^\t\n\x0B\f\r \/$.?#][^\t\n\x0B\f\r ]*$/ui,
    phone: /^\+?[0-9\t\n\x0B\f\r ().-]{6,20}$/u,
    alpha: /^[\p{L}\t\n\x0B\f\r '-]+$/u,
    alphanumeric: /^[\p{L}\p{N}\t\n\x0B\f\r ]+$/u,
    numeric: /^-?\d+(\.\d+)?$/,
    integer: /^-?\d+$/,
    date: /^\d{4}-\d{2}-\d{2}$/,
    datetime: /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/,
    color: /^#[0-9a-f]{6}$/i,
    time: /^([01]\d|2[0-3]):[0-5]\d$/,
    month: /^\d{4}-(0[1-9]|1[0-2])$/
  };

  var TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;
  var phpTrim = function (s) { return s.replace(TRIM, ''); };
  var isString = function (v) { return typeof v === 'string'; };
  var length = function (s) { return Array.from(s).length; };
  var has = function (object, key) { return Object.prototype.hasOwnProperty.call(object || {}, key); };

  var BUILTIN_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty',
    'not_empty', 'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal', 'is_one_of', 'is_not_one_of'];
  var BUILTIN_SOURCES = ['static', 'query', 'page_url', 'referrer'];
  var BUILTIN_TYPES = ['text', 'textarea', 'email', 'tel', 'url', 'number', 'date', 'time', 'datetime', 'color', 'hidden',
    'select', 'multiselect', 'radio', 'checkboxes', 'checkbox', 'country', 'rating', 'range', 'file', 'address', 'cards',
    'consent', 'date_range', 'list', 'map', 'matrix', 'month', 'scale', 'suggest', 'toggle'];
  var BUILTIN_ACTIONS = ['show', 'hide', 'require', 'set_value', 'hide_options'];
  var CODE = /^[a-z][a-z0-9_.-]{0,63}$/;
  var customRules = Object.create(null);
  var customOperators = Object.create(null);
  var customTypes = Object.create(null);
  var builtinTypes = Object.create(null);
  var customSources = Object.create(null);
  var customActions = Object.create(null);
  var warnedOperators = Object.create(null);

  var checkCode = function (kind, code, builtIn) {
    if (typeof code !== 'string' || !CODE.test(code)) {
      throw new Error('Invalid ' + kind + ' code: ' + String(code));
    }
    if (builtIn.indexOf(code) !== -1) {
      throw new Error('The ' + kind + ' "' + code + '" is built in and cannot be replaced.');
    }
  };
  var checkFn = function (fn) {
    if (typeof fn !== 'function') {
      throw new Error('A function is required.');
    }
  };

  // Mirrors AbstractFieldType::normalizeValue (max-length truncation stays server-side).
  var CONTROLS = /[\x00-\x08\x0B-\x1F\x7F]/g;
  var normalizeString = function (field, value) {
    var out = String(value).replace(CONTROLS, '');
    if (field.type !== 'textarea') {
      out = out.replace(/\n/g, ' ');
    }
    out = phpTrim(out);
    var maskText = field.settings && field.settings.mask;
    if (isString(maskText) && maskText !== '') {
      out = phpTrim(mask.format(out, maskText));
    }
    return field.type === 'color' ? out.toLowerCase() : out;
  };

  // Mirrors Matrix::normalizeValue; an entry naming no valid row or column becomes MATRIX_INVALID.
  var MATRIX_INVALID = '\0';
  var normalizeMatrix = function (field, value) {
    if (Array.isArray(value)) {
      return value.map(function (v) { return normalizeString(field, v); });
    }
    if (value === null || typeof value !== 'object') {
      return [];
    }
    var rows = (field.settings || {}).rows || [];
    var columns = new Map();
    (field.options || []).forEach(function (o) { columns.set(String(o.value), String(o.label)); });
    var answers = [];
    var invalid = false;
    Object.keys(value).forEach(function (key) {
      var column = isString(value[key]) ? normalizeString(field, value[key]) : null;
      if (column === '') {
        return;
      }
      if (!/^(0|[1-9]\d*)$/.test(key) || +key >= rows.length || column === null || !columns.has(column)) {
        invalid = true;
        return;
      }
      answers[+key] = String(rows[+key]) + ': ' + columns.get(column);
    });
    return invalid ? [MATRIX_INVALID] : answers.filter(function () { return true; });
  };

  // Mirrors DateRange::normalizeValue; both parts blank (or a non-object) becomes '' like an empty PHP value.
  var rangePart = function (value) {
    var scalar = isString(value) || typeof value === 'number' || typeof value === 'boolean';
    return scalar ? Array.from(phpTrim(String(value).replace(/[\x00-\x1F\x7F]/g, ''))).slice(0, 32).join('') : '';
  };
  var normalizeDateRange = function (value) {
    if (value === null || typeof value !== 'object') {
      return '';
    }
    var pair = { start: rangePart(value.start), end: rangePart(value.end) };
    return pair.start === '' && pair.end === '' ? '' : pair;
  };

  // Mirrors Address::normalizeValue; a blank address (country aside) becomes '' like an empty PHP value.
  var addressPart = function (value) {
    var scalar = isString(value) || typeof value === 'number' || typeof value === 'boolean';
    var text = scalar ? String(value).replace(CONTROLS, '').replace(/[\n\t]/g, ' ') : '';
    return Array.from(phpTrim(text)).slice(0, 255).join('');
  };
  var normalizeAddress = function (value) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
      return '';
    }
    var rawStreet = value.street === undefined || value.street === null ? [] : [].concat(value.street);
    var regionId = addressPart(value.region_id);
    var result = {
      street: rawStreet.map(addressPart).filter(function (line) { return line !== ''; }).slice(0, 4),
      city: addressPart(value.city),
      region_id: /^\d{1,10}$/.test(regionId) ? regionId : '',
      region: addressPart(value.region),
      postcode: addressPart(value.postcode).replace(/ {2,}/g, ' ').replace(/[a-z]+/g, function (m) { return m.toUpperCase(); }),
      country_id: addressPart(value.country_id)
    };
    var blank = result.street.length === 0 && !result.city && !result.region_id && !result.region && !result.postcode;
    return blank ? '' : result;
  };

  // Mirrors Map::normalizeValue; both coordinates blank (or a non-object) becomes '' like an empty PHP value.
  var COORDINATE = /^-?\d+(\.\d+)?$/;
  var mapScalar = function (value) {
    var scalar = isString(value) || typeof value === 'number' || typeof value === 'boolean';
    return scalar ? phpTrim(String(value)) : '';
  };
  var mapFormat = function (number) {
    var text = number.toFixed(6);
    return text === '-0.000000' ? '0.000000' : text;
  };
  var normalizeMap = function (field, value) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
      return '';
    }
    var lat = mapScalar(value.lat);
    var lng = mapScalar(value.lng);
    if (lat === '' && lng === '') {
      return '';
    }
    if (lat === '' || lng === '') {
      return { error: 'incomplete' };
    }
    if (!COORDINATE.test(lat) || !COORDINATE.test(lng) || !isFinite(+lat) || !isFinite(+lng)) {
      return { error: 'invalid' };
    }
    var east = +lng;
    if (east < -180 || east > 180) {
      east = ((east + 180) % 360 + 360) % 360 - 180;
    }
    var zoom = mapScalar(value.zoom);
    var label = isString(value.label) || typeof value.label === 'number' || typeof value.label === 'boolean'
      ? Array.from(normalizeString({ type: 'map' }, value.label)).slice(0, 255).join('') : '';
    return {
      lat: mapFormat(+lat),
      lng: mapFormat(east),
      zoom: /^\d{1,2}$/.test(zoom) && +zoom <= 22 ? +zoom : null,
      label: label
    };
  };

  var normalizeValue = function (field, value) {
    var custom = customTypes[field.type];
    if (custom && custom.normalize) {
      return custom.normalize(value, field);
    }
    if (field.type === 'map') {
      return normalizeMap(field, value);
    }
    if (field.type === 'address') {
      return normalizeAddress(value);
    }
    if (field.type === 'date_range') {
      return normalizeDateRange(value);
    }
    if (field.type === 'matrix') {
      return normalizeMatrix(field, value);
    }
    // Mirrors Suggest::normalizeValue: the public schema flags fields whose options are suggestions.
    if (field.suggestions === true && isString(value)) {
      return suggestionValue(field, value);
    }
    // Mirrors ItemList::normalizeValue: any non-string item makes the whole answer invalid.
    if (field.type === 'list') {
      var items = [].concat(value);
      if (!items.every(isString)) {
        return [MATRIX_INVALID];
      }
      return items.map(function (v) { return normalizeString(field, v); }).filter(function (v) { return v !== ''; });
    }
    if (Array.isArray(value)) {
      return value.map(function (v) { return normalizeString(field, v); });
    }
    return isString(value) ? normalizeString(field, value) : value;
  };

  // Mirrors the server: typed text equal (any case) to an option label becomes its value; failing that, to a value.
  var suggestionValue = function (field, text) {
    if (!isString(text)) {
      return text;
    }
    var clean = normalizeString(field, text);
    var key = clean.toLowerCase();
    var options = (field.options || []).filter(function (o) { return o && typeof o === 'object'; });
    var same = function (part) {
      return function (o) { return phpTrim(String(o[part])).toLowerCase() === key; };
    };
    var hit = key === '' ? null : (options.filter(same('label'))[0] || options.filter(same('value'))[0]);
    return hit ? String(hit.value) : clean;
  };

  var normalizeValues = function (fields, values) {
    var out = Object.assign({}, values);
    (fields || []).forEach(function (field) {
      if (Object.prototype.hasOwnProperty.call(out, field.name)) {
        out[field.name] = normalizeValue(field, out[field.name]);
      }
    });
    return out;
  };

  var isEmpty = function (value) {
    if (Array.isArray(value)) {
      return value.length === 0;
    }
    if (value !== null && typeof value === 'object') {
      return Object.keys(value).length === 0;
    }
    return value === null || value === undefined || phpTrim(String(value)) === '';
  };

  var normalize = function (v) {
    var scalar = typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
    return phpTrim(scalar ? String(v) : '').toLowerCase();
  };

  var compare = function (a, b, op) {
    var order;
    if (REGEX.numeric.test(a) && REGEX.numeric.test(b)) {
      order = parseFloat(a) - parseFloat(b);
    } else if (REGEX.date.test(a) && REGEX.date.test(b)) {
      order = a < b ? -1 : (a > b ? 1 : 0);
    } else {
      return false;
    }
    switch (op) {
      case '>': return order > 0;
      case '<': return order < 0;
      case '>=': return order >= 0;
      default: return order <= 0;
    }
  };

  var evaluateCustom = function (rule, raw, expected, fieldsByName) {
    var fn = customOperators[rule.operator];
    if (!fn) {
      if (!warnedOperators[rule.operator] && typeof console !== 'undefined') {
        warnedOperators[rule.operator] = true;
        console.warn('FuranFormBuilder: unknown condition operator "' + String(rule.operator) + '" evaluates to false.');
      }
      return false;
    }
    var source = fieldsByName && has(fieldsByName, rule.field) ? fieldsByName[rule.field] : null;
    return !!fn(raw, expected, source);
  };

  // Mirrors Model\Condition\ConditionNormalizer limits.
  var MAX_LEVELS = 3;
  var MAX_RELATIVE_DAYS = 3650;
  var DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
  var MONTH = /^\d{4}-\d{2}$/;

  var isGroup = function (rule) {
    return !has(rule, 'field') && Array.isArray(rule.rules);
  };

  var scalarText = function (v) {
    return typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' ? String(v) : '';
  };

  var pad = function (n, width) {
    var text = String(Math.abs(n));
    while (text.length < width) { text = '0' + text; }
    return (n < 0 ? '-' : '') + text;
  };

  // UTC day arithmetic on the given Y-m-d (same as Evaluator::relativeDate); null = never matches.
  var relativeDate = function (spec, today, format) {
    var days = spec !== null && typeof spec === 'object' && !Array.isArray(spec) ? spec.days : null;
    var parts = isString(today) ? DAY.exec(today) : null;
    if (!Number.isInteger(days) || Math.abs(days) > MAX_RELATIVE_DAYS || !parts) {
      return null;
    }
    var date = new Date(0);
    date.setUTCFullYear(+parts[1], +parts[2] - 1, +parts[3]);
    if (+parts[1] < 1 || date.getUTCMonth() !== +parts[2] - 1 || date.getUTCDate() !== +parts[3]) {
      return null;
    }
    date.setUTCDate(date.getUTCDate() + days);
    var month = pad(date.getUTCFullYear(), 4) + '-' + pad(date.getUTCMonth() + 1, 2);
    return format === 'Y-m' ? month : month + '-' + pad(date.getUTCDate(), 2);
  };

  // Months compare as their first day, datetime answers by their date part (Evaluator::asComparableDay).
  var comparableDay = function (value, format) {
    if (format === 'Y-m') {
      return MONTH.test(value) ? value + '-01' : value;
    }
    return REGEX.datetime.test(value) ? value.slice(0, 10) : value;
  };

  // A compared field's list answer becomes one element per line, like an is_one_of list.
  var otherAnswer = function (name, values) {
    var other = isString(name) && has(values, name) ? values[name] : '';
    return Array.isArray(other) ? other.map(scalarText).join('\n') : scalarText(other);
  };

  var inList = function (items, list) {
    var entries = list.split('\n').map(phpTrim).filter(function (e) { return e !== ''; });
    return items.some(function (v) { return entries.indexOf(v) !== -1; });
  };

  var evaluateRule = function (rule, ctx) {
    var values = ctx.values;
    var field = ctx.fieldsByName && has(ctx.fieldsByName, rule.field) ? ctx.fieldsByName[rule.field] : null;
    var raw = has(values, rule.field) ? values[rule.field] : '';
    var plain = rule.value === undefined ? '' : rule.value;
    if (rule.value_date !== undefined && rule.value_date !== null) {
      var format = field && (field.relative_date === 'Y-m-d' || field.relative_date === 'Y-m') ? field.relative_date : null;
      plain = format === null ? null : relativeDate(rule.value_date, ctx.today, format);
      if (plain === null) {
        return false;
      }
      plain = comparableDay(plain, format);
      raw = Array.isArray(raw) ? raw.map(function (v) { return comparableDay(scalarText(v), format); })
        : comparableDay(scalarText(raw), format);
    } else if (rule.value_field !== undefined && rule.value_field !== null) {
      plain = otherAnswer(rule.value_field, values);
    }
    var isArray = Array.isArray(raw);
    var items = (isArray ? raw : [raw]).map(normalize);
    var expected = normalize(plain);
    var some = function (fn) { return items.some(fn); };
    var contains = function () {
      return some(function (v) { return isArray ? v === expected : v.indexOf(expected) !== -1; });
    };
    switch (rule.operator) {
      case 'equals': return some(function (v) { return v === expected; });
      case 'not_equals': return !some(function (v) { return v === expected; });
      case 'contains': return contains();
      case 'not_contains': return !contains();
      case 'starts_with': return some(function (v) { return v.startsWith(expected); });
      case 'ends_with': return some(function (v) { return v.endsWith(expected); });
      case 'is_empty': return !some(function (v) { return v !== ''; });
      case 'not_empty': return some(function (v) { return v !== ''; });
      case 'greater_than': return some(function (v) { return compare(v, expected, '>'); });
      case 'less_than': return some(function (v) { return compare(v, expected, '<'); });
      case 'greater_or_equal': return some(function (v) { return compare(v, expected, '>='); });
      case 'less_or_equal': return some(function (v) { return compare(v, expected, '<='); });
      case 'is_one_of': return inList(items, expected);
      case 'is_not_one_of': return !inList(items, expected);
      default: return evaluateCustom(rule, raw, plain, ctx.fieldsByName);
    }
  };

  // A group beyond the level limit or without rules never matches.
  var matchesGroup = function (group, ctx, level) {
    var rules = Array.isArray(group.rules) ? group.rules : [];
    if (rules.length === 0 || level > MAX_LEVELS) {
      return false;
    }
    var any = group.match === 'any';
    for (var i = 0; i < rules.length; i++) {
      var rule = rules[i];
      var matched = !!rule && typeof rule === 'object' && !Array.isArray(rule)
        && (isGroup(rule) ? matchesGroup(rule, ctx, level + 1) : evaluateRule(rule, ctx));
      if (matched === any) {
        return any;
      }
    }
    return !any;
  };

  // Condition actions and form state mirror Model/Condition/{Evaluator,FormState}.php.
  var actionPart = function (state, id) {
    return {
      hide: function () { state.visible[id] = false; },
      require: function () { state.required[id] = true; },
      setValue: function (value) { state.requested[id] = String(value); },
      hideOptions: function (options) {
        var list = state.hiddenOptions[id] || [];
        options.forEach(function (o) { if (list.indexOf(o) === -1) { list.push(o); } });
        state.hiddenOptions[id] = list;
      }
    };
  };

  var BUILTIN_ACTION_FNS = {
    show: function (s, id, param, matched) { if (!matched) { s.hide(); } },
    hide: function (s, id, param, matched) { if (matched) { s.hide(); } },
    require: function (s, id, param, matched) { if (matched) { s.require(); } },
    set_value: function (s, id, param, matched) {
      if (matched && (isString(param) || typeof param === 'number')) { s.setValue(param); }
    },
    hide_options: function (s, id, param, matched) {
      if (matched && Array.isArray(param)) { s.hideOptions(param.filter(isString)); }
    }
  };

  // An empty `actions` list means no actions; without the list the item is shown when the rules match.
  var actionsOf = function (condition) {
    if (Array.isArray(condition.actions)) {
      return condition.actions.filter(function (e) { return e && typeof e === 'object' && isString(e.action); });
    }
    return [{ action: 'show' }];
  };

  var newState = function (values) {
    return { visible: {}, required: {}, values: Object.assign({}, values), defaults: {}, hiddenOptions: {}, requested: {} };
  };

  var applyActions = function (condition, id, state, fieldsByName, today) {
    var rules = condition && Array.isArray(condition.rules) ? condition.rules : [];
    if (rules.length === 0) {
      return;
    }
    var matched = matchesGroup(condition, { values: state.values, fieldsByName: fieldsByName, today: today }, 1);
    var part = actionPart(state, id);
    actionsOf(condition).forEach(function (entry) {
      var fn = has(BUILTIN_ACTION_FNS, entry.action) ? BUILTIN_ACTION_FNS[entry.action] : customActions[entry.action];
      if (typeof fn !== 'function') {
        return;
      }
      try {
        fn(part, id, entry.param, matched);
      } catch (e) {
        if (typeof console !== 'undefined') { console.warn('FuranFormBuilder: condition action "' + entry.action + '" failed: ' + e.message); }
      }
    });
  };

  // today: Y-m-d in the store timezone; null/invalid makes relative-date rules false.
  var evaluateCondition = function (condition, values, fieldsByName, today) {
    var state = newState(values);
    state.visible[''] = true;
    applyActions(condition || null, '', state, fieldsByName, today);
    return state.visible[''];
  };

  var fieldMap = function (schema) {
    var map = Object.create(null);
    var collect = function (item) {
      if (item.kind === 'field' && item.name !== undefined) {
        map[String(item.name)] = item;
      }
      (item.items || []).forEach(collect);
    };
    (schema.pages || []).forEach(collect);
    return map;
  };

  // A server-overwritten field (overwritable) takes the set value; any other only gets it as a default.
  var settleField = function (field, state) {
    var id = String(field.id);
    var value = state.requested[id];
    if (value === undefined) {
      return;
    }
    if (field.overwritable) {
      state.values[String(field.name)] = field.multi ? [value] : value;
    } else {
      state.defaults[id] = value;
    }
  };

  var walkState = function (item, parentVisible, state, pass) {
    var id = String(item.id);
    var isField = item.kind === 'field';
    state.visible[id] = parentVisible;
    state.required[id] = isField && !!item.required;
    if (parentVisible) {
      applyActions(item.condition || null, id, state, pass.fieldsByName, pass.today);
    }
    if (!state.visible[id]) {
      delete state.requested[id];
      delete state.hiddenOptions[id];
      state.required[id] = false;
      if (isField) {
        delete state.values[String(item.name || '')];
      }
    } else if (isField) {
      settleField(item, state);
      if (pass.exempt.indexOf(String(item.name)) !== -1) {
        state.required[id] = false;
      }
    }
    (item.items || []).forEach(function (child) { walkState(child, state.visible[id], state, pass); });
  };

  // A matched skip hides the pages strictly between its page and the target (a later page).
  var skipTarget = function (page, laterIds, state, pass) {
    var skip = page.skip;
    var target = skip && typeof skip === 'object' ? skip.target : null;
    if (!isString(target) || laterIds.indexOf(target) === -1) {
      return null;
    }
    var matched = matchesGroup(skip, { values: state.values, fieldsByName: pass.fieldsByName, today: pass.today }, 1);
    return matched ? target : null;
  };

  // One ordered pass: visible/required by item id, values by name, defaults and hiddenOptions by field id.
  var computeState = function (schema, values, today, exempt) {
    var state = newState(values);
    var pages = schema.pages || [];
    var pass = { fieldsByName: fieldMap(schema), today: today === undefined ? null : today, exempt: (exempt || []).map(String) };
    var skipUntil = null;
    pages.forEach(function (page, index) {
      var id = String(page.id);
      var skipped = skipUntil !== null && id !== skipUntil;
      if (id === skipUntil) {
        skipUntil = null;
      }
      walkState(page, !skipped, state, pass);
      if (page.skip && state.visible[id]) {
        var later = pages.slice(index + 1).map(function (p) { return String(p.id); });
        skipUntil = skipTarget(page, later, state, pass);
      }
    });
    delete state.requested;
    return state;
  };

  var computeVisibility = function (schema, values, today) {
    return computeState(schema, values, today).visible;
  };

  var datePart = function (value) {
    if (!isString(value)) {
      return null;
    }
    var part = value.slice(0, 10);
    return REGEX.date.test(part) ? part : null;
  };
  var selectedCount = function (value) {
    return Array.isArray(value) ? value.length : (value === '' ? 0 : 1);
  };
  var toInt = function (v) { return parseInt(v, 10) || 0; };
  var isNumeric = function (v) { return isString(v) && REGEX.numeric.test(v); };

  var PATTERN_MAX_INPUT = 1000;
  var compilePattern = function (pattern) {
    try {
      return new RegExp('^(?:' + pattern + ')$', 'u');
    } catch (e) {
      return null;
    }
  };

  var RULES = {
    min_length: function (v, rv) { return isString(v) && length(v) >= toInt(rv); },
    max_length: function (v, rv) { return isString(v) && length(v) <= toInt(rv); },
    pattern: function (v, rv) {
      if (!isString(v) || !isString(rv)) {
        return false;
      }
      // Browsers have no backtrack limit; long values are left to the server's bounded check.
      if (v.length > PATTERN_MAX_INPUT) {
        return true;
      }
      var re = compilePattern(rv);
      return re === null ? true : re.test(v);
    },
    alpha: function (v) { return isString(v) && REGEX.alpha.test(v); },
    alphanumeric: function (v) { return isString(v) && REGEX.alphanumeric.test(v); },
    numeric: function (v) { return isString(v) && REGEX.numeric.test(v); },
    integer: function (v) { return isString(v) && REGEX.integer.test(v); },
    email: function (v) { return isString(v) && REGEX.email.test(v); },
    url: function (v) { return isString(v) && REGEX.url.test(v); },
    phone: function (v) { return isString(v) && REGEX.phone.test(v); },
    min: function (v, rv) { return isNumeric(v) && parseFloat(v) >= parseFloat(rv); },
    max: function (v, rv) { return isNumeric(v) && parseFloat(v) <= parseFloat(rv); },
    min_date: function (v, rv) { var d = datePart(v); return d !== null && d >= String(rv); },
    max_date: function (v, rv) { var d = datePart(v); return d !== null && d <= String(rv); },
    matches: function (v, rv, values) {
      var other = values ? values[String(rv)] : undefined;
      return isString(v) && other !== undefined && other !== null && v === String(other);
    },
    min_selected: function (v, rv) { return selectedCount(v) >= toInt(rv); },
    max_selected: function (v, rv) { return selectedCount(v) <= toInt(rv); }
  };

  var DEFAULT_MESSAGES = {
    min_length: function (rv) { return 'Please enter at least ' + toInt(rv) + ' characters.'; },
    max_length: function (rv) { return 'Please enter no more than ' + toInt(rv) + ' characters.'; },
    pattern: function () { return 'Please match the requested format.'; },
    alpha: function () { return 'Please use letters only.'; },
    alphanumeric: function () { return 'Please use letters and numbers only.'; },
    numeric: function () { return 'Please enter a valid number.'; },
    integer: function () { return 'Please enter a whole number.'; },
    email: function () { return 'Please enter a valid email address.'; },
    url: function () { return 'Please enter a valid URL starting with http:// or https://.'; },
    phone: function () { return 'Please enter a valid phone number.'; },
    min: function (rv) { return 'Please enter a value of at least ' + parseFloat(rv) + '.'; },
    max: function (rv) { return 'Please enter a value of no more than ' + parseFloat(rv) + '.'; },
    min_date: function (rv) { return 'Please choose a date on or after ' + rv + '.'; },
    max_date: function (rv) { return 'Please choose a date on or before ' + rv + '.'; },
    matches: function (rv) { return 'This must match the ' + rv + ' field.'; },
    min_selected: function (rv) { return 'Please choose at least ' + toInt(rv) + ' options.'; },
    max_selected: function (rv) { return 'Please choose no more than ' + toInt(rv) + ' options.'; }
  };

  // Unknown rules pass here: the server stays authoritative.
  var validateRule = function (code, value, ruleValue, values, field) {
    if (has(RULES, code)) {
      return RULES[code](value, ruleValue, values || {});
    }
    var custom = customRules[code];
    return custom ? !!custom.test(value, ruleValue, field || null, values || {}) : true;
  };
  var ruleMessage = function (code, ruleValue) {
    var custom = customRules[code];
    var fallback = has(DEFAULT_MESSAGES, code) ? DEFAULT_MESSAGES[code] : (custom && custom.message);
    return fallback ? fallback(ruleValue) : 'This value is invalid.';
  };

  var INVALID_OPTION = 'Please choose a valid option.';

  // Equivalent of PHP checkdate() for a YYYY-MM-DD prefix.
  var isRealDate = function (value) {
    var y = +value.slice(0, 4);
    var m = +value.slice(5, 7);
    var d = +value.slice(8, 10);
    var leap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
    var days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1];
    return y >= 1 && m >= 1 && m <= 12 && d >= 1 && d <= days;
  };

  // Days since 1970-01-01 (days_from_civil, same integer arithmetic as DateRange::toDays); no Date objects.
  var toDays = function (date) {
    var y = +date.slice(0, 4);
    var m = +date.slice(5, 7);
    var d = +date.slice(8, 10);
    y -= m <= 2 ? 1 : 0;
    var era = Math.floor(y / 400);
    var yoe = y - era * 400;
    var doy = Math.floor((153 * (m > 2 ? m - 3 : m + 9) + 2) / 5) + d - 1;
    var doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy;
    return era * 146097 + doe - 719468;
  };

  var DATE_RANGE_DEFAULTS = {
    order: 'The end date must be on or after the start date.',
    incomplete: 'Please enter both a start date and an end date.',
    min_days: function (n) { return 'Please choose a range of at least ' + n + ' days.'; },
    max_days: function (n) { return 'Please choose a range of no more than ' + n + ' days.'; },
    min_date: function (d) { return 'Please choose dates on or after ' + d + '.'; },
    max_date: function (d) { return 'Please choose dates on or before ' + d + '.'; }
  };

  var cleanBound = function (v) {
    return isString(v) && REGEX.date.test(v) && isRealDate(v) ? v : '';
  };

  // Mirrors DateRange::validateValue; uses the server-translated message for the failure when the field carries them.
  var validateDateRange = function (field, value) {
    var settings = field.settings || {};
    var minDays = Math.max(0, Math.min(3660, toInt(settings.min_days)));
    var maxDays = Math.max(0, Math.min(3660, toInt(settings.max_days)));
    var minDate = cleanBound(settings.min_date);
    var maxDate = cleanBound(settings.max_date);
    var say = function (key, arg) {
      var served = (field.messages || {})[key];
      if (served) {
        return served;
      }
      var fallback = DATE_RANGE_DEFAULTS[key];
      return typeof fallback === 'function' ? fallback(arg) : fallback;
    };
    if (!value.start || !value.end) {
      return say('incomplete');
    }
    if (!REGEX.date.test(value.start) || !isRealDate(value.start) || !REGEX.date.test(value.end) || !isRealDate(value.end)) {
      return field.type_message || 'Please enter a valid date.';
    }
    var length = toDays(value.end) - toDays(value.start) + 1;
    if (length < 1) { return say('order'); }
    if (minDays > 0 && length < minDays) { return say('min_days', minDays); }
    if (maxDays > 0 && length > maxDays) { return say('max_days', maxDays); }
    if (minDate !== '' && (value.start < value.end ? value.start : value.end) < minDate) { return say('min_date', minDate); }
    if (maxDate !== '' && (value.start > value.end ? value.start : value.end) > maxDate) { return say('max_date', maxDate); }
    return null;
  };

  var ADDRESS_DEFAULTS = {
    country: 'Please choose a valid country.',
    street: 'Please enter a street address.',
    city: 'Please enter a city.',
    region: 'Please choose or enter a region.',
    postcode: 'Please enter a postcode.',
    lines: 'Please use fewer street address lines.'
  };

  var listed = function (list, item) { return Array.isArray(list) && list.indexOf(item) !== -1; };

  // Mirrors AddressRules::isRegionValid; a country missing from directory.regions behaves as one without a list.
  var isRegionValid = function (value, directory) {
    var country = value.country_id;
    var required = listed(directory.regionsRequired, country);
    var list = (directory.regions || {})[country] || {};
    if (Object.keys(list).length > 0) {
      return value.region_id === '' ? !required : has(list, value.region_id);
    }
    return !required || value.region !== '';
  };

  // Mirrors AddressRules::validate (hard errors only); without directory data the server stays authoritative.
  var validateAddress = function (field, value, directory) {
    if (!directory) {
      return null;
    }
    var say = function (key) { return (field.messages || {})[key] || ADDRESS_DEFAULTS[key]; };
    if (value.country_id === '' || !listed(directory.allowedCountries, value.country_id)) { return say('country'); }
    if (value.street.length === 0) { return say('street'); }
    if (value.street.length > (directory.streetLines === undefined ? 1 : +directory.streetLines)) { return say('lines'); }
    if (value.city === '') { return say('city'); }
    if (!isRegionValid(value, directory)) { return say('region'); }
    if (value.postcode === '' && !listed(directory.optionalZip, value.country_id)) { return say('postcode'); }
    return null;
  };

  // Mirrors AddressRules::postcodeWarning; the patterns run unflagged like core's, and never block a submit.
  var postcodeHint = function (value, directory) {
    var patterns = ((directory || {}).postcodes || {})[value.country_id] || [];
    if (!value.postcode || patterns.length === 0) {
      return false;
    }
    return !patterns.some(function (pattern) {
      try {
        return new RegExp(pattern).test(value.postcode);
      } catch (e) {
        return false;
      }
    });
  };

  // Mirrors Map::validateValue.
  var validateMap = function (field, value) {
    var say = function (key, fallback) { return (field.messages || {})[key] || fallback; };
    if (value.error === 'incomplete') { return say('incomplete', 'Please enter both a latitude and a longitude.'); }
    if (value.error === 'invalid' || Math.abs(+value.lat) > 90) { return say('invalid', 'Please choose a valid location.'); }
    return null;
  };

  var REGEX_TYPES = {
    email: ['email', 'Please enter a valid email address.'],
    tel: ['phone', 'Please enter a valid phone number.'],
    url: ['url', 'Please enter a valid URL starting with http:// or https://.'],
    number: ['numeric', 'Please enter a valid number.'],
    date: ['date', 'Please enter a valid date.'],
    time: ['time', 'Please enter a valid time.'],
    datetime: ['datetime', 'Please enter a valid date and time.'],
    color: ['color', 'Please choose a valid colour.'],
    month: ['month', 'Please enter a valid month.']
  };

  var optionValues = function (field) {
    return (field.options || []).map(function (o) { return String(o.value); });
  };

  // Returns the default message when the value fails the field type's own check, else null.
  var intrinsicDefault = function (field, value) {
    var type = field.type;
    if (customTypes[type]) {
      return customTypes[type].intrinsicDefault ? customTypes[type].intrinsicDefault(value, field) : null;
    }
    var settings = field.settings || {};
    var spec = has(REGEX_TYPES, type) ? REGEX_TYPES[type] : null;
    if (spec) {
      var calendar = type === 'date' || type === 'datetime';
      return isString(value) && REGEX[spec[0]].test(value) && (!calendar || isRealDate(value)) ? null : spec[1];
    }
    var allowed;
    switch (type) {
      case 'select':
      case 'radio':
      case 'cards':
        return isString(value) && optionValues(field).indexOf(value) !== -1 ? null : INVALID_OPTION;
      case 'multiselect':
      case 'checkboxes':
        allowed = optionValues(field);
        return Array.isArray(value) && value.every(function (v) { return allowed.indexOf(v) !== -1; }) ? null : INVALID_OPTION;
      case 'checkbox':
        return value === String(settings.checked_value || 'yes') ? null : INVALID_OPTION;
      case 'consent':
        return value === 'yes' ? null : INVALID_OPTION;
      case 'toggle':
        return value === String(settings.on_value || 'yes') ? null : INVALID_OPTION;
      case 'matrix':
        return Array.isArray(value) && value.indexOf(MATRIX_INVALID) === -1 ? null : INVALID_OPTION;
      case 'list':
        var most = Math.max(2, Math.min(20, settings.max_items === undefined ? 5 : toInt(settings.max_items)));
        return Array.isArray(value) && value.length <= most && value.indexOf(MATRIX_INVALID) === -1
          ? null : 'You can add up to ' + most + ' items.';
      case 'scale':
        var smin = settings.min === undefined ? 1 : toInt(settings.min);
        var smax = settings.max === undefined ? 5 : toInt(settings.max);
        return isString(value) && /^[0-9]{1,2}$/.test(value) && +value >= smin && +value <= smax
          ? null : 'Please choose a value between ' + smin + ' and ' + smax + '.';
      case 'rating':
        var max = settings.max === undefined ? 5 : toInt(settings.max);
        return isString(value) && REGEX.integer.test(value) && +value >= 1 && +value <= max ? null : 'Please choose a valid rating.';
      case 'range':
        var lo = settings.min === undefined ? 0 : parseFloat(settings.min);
        var hi = settings.max === undefined ? 100 : parseFloat(settings.max);
        return isNumeric(value) && +value >= lo && +value <= hi ? null : 'Please enter a valid number.';
      default:
        return null;
    }
  };

  // Mirrors Number::validateValue: steps count from the min rule; step "any", empty or malformed is no check.
  var STEP_TOLERANCE = 1e-9;
  var stepError = function (field, value) {
    var step = (field.settings || {}).step;
    step = typeof step === 'number' ? String(step) : step;
    if (field.type !== 'number' || !isString(step) || !/^\d+(\.\d+)?$/.test(step) || !(parseFloat(step) > 0)) {
      return null;
    }
    var base = 0;
    (field.rules || []).some(function (r) {
      var min = typeof r.value === 'number' ? String(r.value) : r.value;
      if (r.rule === 'min' && isNumeric(min)) {
        base = parseFloat(min);
        return true;
      }
      return false;
    });
    var steps = (parseFloat(value) - base) / parseFloat(step);
    if (Math.abs(steps - Math.round(steps)) <= STEP_TOLERANCE * Math.max(1, Math.abs(steps))) {
      return null;
    }
    var message = (field.messages || {}).step;
    if (message) {
      return message;
    }
    return parseFloat(step) === 1 && Number.isInteger(base) ? 'Please enter a whole number.' : 'Please enter a number in steps of ' + step + '.';
  };

  var validateField = function (field, rawValue, values, directory) {
    var value = normalizeValue(field, rawValue);
    if (isEmpty(value)) {
      return field.required ? (field.required_message || 'This field is required.') : null;
    }
    if (field.type === 'date_range') {
      return validateDateRange(field, value);
    }
    if (field.type === 'address') {
      return validateAddress(field, value, directory);
    }
    if (field.type === 'map') {
      return validateMap(field, value);
    }
    var failed = intrinsicDefault(field, value);
    var offStep = failed === null ? stepError(field, value) : null;
    if (failed !== null || offStep !== null) {
      // Mirrors Validator::formatMessage: a library format's message wins only when the answer breaks it too.
      var format = field.format;
      var re = format && isString(value) && value.length <= PATTERN_MAX_INPUT ? compilePattern(format.pattern) : null;
      if (re && !re.test(value)) {
        return format.message;
      }
      return offStep || field.type_message || failed;
    }
    var custom = customTypes[field.type];
    if (custom && custom.validate) {
      var customFailure = custom.validate(value, field, values);
      if (customFailure) {
        return customFailure;
      }
    }
    if (field.type === 'matrix' && field.required && (field.settings || {}).require_all !== false &&
        value.length < ((field.settings || {}).rows || []).length) {
      return field.rows_message || 'Please answer every row.';
    }
    var rules = field.rules || [];
    for (var i = 0; i < rules.length; i++) {
      var r = rules[i];
      if (!validateRule(r.rule, value, r.value, values, field)) {
        if (r.message) {
          return r.message;
        }
        return ruleMessage(r.rule, r.value);
      }
    }
    return null;
  };

  var validateFields = function (fields, values, directory) {
    var errors = {};
    (fields || []).forEach(function (field) {
      var v = Object.prototype.hasOwnProperty.call(values, field.name) ? values[field.name] : '';
      var message = validateField(field, v, values, directory);
      if (message !== null) {
        errors[field.name] = message;
      }
    });
    return errors;
  };

  var registry = ns.registry && typeof ns.registry === 'object' ? ns.registry : {};
  registry.rule = function (code, fn, messageFn) {
    checkCode('rule', code, Object.keys(RULES));
    checkFn(fn);
    customRules[code] = { test: fn, message: typeof messageFn === 'function' ? messageFn : null };
  };
  registry.operator = function (code, fn) {
    checkCode('operator', code, BUILTIN_OPERATORS);
    checkFn(fn);
    customOperators[code] = fn;
  };
  // Hooks merge by key so later scripts can add to an earlier registration.
  registry.fieldType = function (code, hooks) {
    checkCode('field type', code, BUILTIN_TYPES);
    customTypes[code] = Object.assign(customTypes[code] || {}, hooks || {});
  };
  registry.hiddenSource = function (code, fn) {
    checkCode('hidden source', code, BUILTIN_SOURCES);
    checkFn(fn);
    customSources[code] = fn;
  };
  // fn(state, targetId, param, matched); state offers hide(), require(), setValue(v), hideOptions(list).
  registry.conditionAction = function (code, fn) {
    checkCode('condition action', code, BUILTIN_ACTIONS);
    checkFn(fn);
    customActions[code] = fn;
  };
  var queued = Array.isArray(registry.queue) ? registry.queue : [];
  registry.queue = [];
  queued.forEach(function (entry) {
    try {
      registry[entry[0]].apply(registry, entry.slice(1));
    } catch (e) {
      if (typeof console !== 'undefined') {
        console.warn('FuranFormBuilder: queued registration skipped: ' + e.message);
      }
    }
  });
  ns.registry = registry;

  // Input masks: mirrors Model/Validation/Mask.php (fixture Test/fixtures/masks.json).
  var mask = (function () {
    var SPACE = ' \t\n\r\f\x0B';
    var PHONE_STRIP = SPACE + '().-';
    var PRESETS = {
      uk_sort_code: { mask: '99-99-99' },
      iban_format: { strip: '', when: /^[A-Z0-9]+$/, group: 4, sep: ' ' },
      uk_postcode: { strip: '', when: /^[A-Z0-9]{5,7}$/, at: -3, sep: ' ' },
      us_zip: { strip: '-', when: /^[0-9]{9}$/, at: 5, sep: '-' },
      ca_postal_code: { strip: '', when: /^[A-Z0-9]{6}$/, at: 3, sep: ' ' },
      ie_eircode: { strip: '', when: /^[A-Z0-9]{7}$/, at: 3, sep: ' ' },
      nl_postcode: { strip: '', when: /^[0-9]{4}[A-Z]{2}$/, at: 4, sep: ' ' },
      au_postcode: { strip: '', when: /^[0-9]{4}$/ },
      uk_vat: { strip: '', when: /^[A-Z0-9]+$/ },
      uk_phone: { phone: 'uk' },
      international_phone: { phone: 'intl' }
    };
    var PLACEHOLDERS = ['9', 'a', 'A', '*'];
    var LETTER = /^\p{L}$/u;
    var ALNUM = /^[\p{L}\p{N}]$/u;

    var strip = function (value, chars) {
      var set = new Set(chars.split(''));
      return value.split('').filter(function (c) { return !set.has(c); }).join('');
    };
    var upper = function (value) { return value.replace(/[a-z]/g, function (c) { return c.toUpperCase(); }); };
    var has = function (map, key) { return Object.prototype.hasOwnProperty.call(map, key); };

    var accepts = function (token, ch) {
      if (token === '9') { return ch.length === 1 && ch >= '0' && ch <= '9'; }
      return token === '*' || LETTER.test(ch);
    };

    // Returns [formatted, raw].
    var walk = function (value, maskText) {
      var chars = Array.from(value);
      var tokens = Array.from(maskText);
      var separators = new Set(SPACE.split(''));
      tokens.forEach(function (t) {
        if (PLACEHOLDERS.indexOf(t) === -1 && !ALNUM.test(t)) { separators.add(t); }
      });
      var i = 0;
      var out = '';
      var placed = '';
      for (var k = 0; k < tokens.length; k++) {
        var token = tokens[k];
        if (i >= chars.length) { break; }
        if (PLACEHOLDERS.indexOf(token) === -1) {
          if (chars[i] === token) { i++; }
          out += token;
          continue;
        }
        while (i < chars.length && separators.has(chars[i])) { i++; }
        if (i >= chars.length || !accepts(token, chars[i])) { break; }
        var ch = token === 'A' ? upper(chars[i]) : chars[i];
        out += ch;
        placed += ch;
        i++;
      }
      var rest = chars.slice(i).join('');
      return [out + rest, placed + rest];
    };

    var compact = function (value, spec) {
      var text = upper(strip(value, SPACE + spec.strip));
      if (!spec.when.test(text)) { return upper(phpTrim(value)); }
      if (spec.group) {
        var groups = [];
        for (var p = 0; p < text.length; p += spec.group) { groups.push(text.slice(p, p + spec.group)); }
        return groups.join(spec.sep);
      }
      if (spec.at) {
        var cut = spec.at < 0 ? text.length + spec.at : spec.at;
        return text.slice(0, cut) + spec.sep + text.slice(cut);
      }
      return text;
    };

    var ukPhone = function (value) {
      var text = phpTrim(value);
      if (!/^\+?[0-9 ().-]*$/.test(text)) { return text; }
      var digits = text.replace(/[^0-9]/g, '');
      if (text.charAt(0) === '+') {
        if (digits.indexOf('44') !== 0) { return text; }
        var national = digits.slice(2);
        if (national.length === 11 && national.charAt(0) === '0') { national = national.slice(1); }
        if (national.length !== 10 || national.charAt(0) === '0') { return text; }
        return '+44 ' + (national.charAt(0) === '2'
          ? national.slice(0, 2) + ' ' + national.slice(2, 6) + ' ' + national.slice(6)
          : national.slice(0, 4) + ' ' + national.slice(4));
      }
      if (digits.length !== 11 || digits.charAt(0) !== '0' || digits.charAt(1) === '0') { return text; }
      return digits.indexOf('02') === 0
        ? digits.slice(0, 3) + ' ' + digits.slice(3, 7) + ' ' + digits.slice(7)
        : digits.slice(0, 5) + ' ' + digits.slice(5);
    };

    var run = function (value, maskText, rawMode) {
      if (!isString(value)) { return ''; }
      if (value === '' || !isString(maskText) || maskText === '') { return value; }
      var spec = has(PRESETS, maskText) ? PRESETS[maskText] : null;
      if (spec === null) { return walk(value, maskText)[rawMode ? 1 : 0]; }
      if (spec.mask) { return walk(value, spec.mask)[rawMode ? 1 : 0]; }
      if (rawMode) { return strip(value, spec.phone ? PHONE_STRIP : SPACE + spec.strip); }
      if (spec.phone) { return spec.phone === 'uk' ? ukPhone(value) : strip(phpTrim(value), PHONE_STRIP); }
      return compact(value, spec);
    };

    return {
      format: function (value, maskText) { return run(value, maskText, false); },
      raw: function (value, maskText) { return run(value, maskText, true); },
      // Mobile keyboard for the mask: '' when there is none.
      inputMode: function (maskText) {
        if (!isString(maskText) || maskText === '') { return ''; }
        if (has(PRESETS, maskText)) {
          var spec = PRESETS[maskText];
          if (spec.phone) { return 'tel'; }
          return maskText === 'uk_sort_code' || maskText === 'us_zip' || maskText === 'au_postcode' ? 'numeric' : 'text';
        }
        return maskText.indexOf('9') !== -1 && !/[aA*]/.test(maskText) ? 'numeric' : 'text';
      }
    };
  })();

  // URL policy: endpoints must be same-origin; scripts same-origin, static or a known Google host.
  var doc = root.document;
  // Read while this file runs: document.currentScript is null afterwards. Absent when loaded by node.
  var SCRIPT_SRC = doc && doc.currentScript && doc.currentScript.src ? String(doc.currentScript.src) : '';
  var SCRIPT_HOSTS = ['www.google.com', 'www.gstatic.com', 'www.recaptcha.net', 'maps.googleapis.com'];


  var parseUrl = function (value) {
    if (typeof value !== 'string' || value === '' || !root.location) {
      return null;
    }
    try {
      var url = new root.URL(value, root.location.href);
      return url.protocol === 'https:' || url.protocol === 'http:' ? url : null;
    } catch (e) {
      return null;
    }
  };
  var sameOrigin = function (value) {
    var url = parseUrl(value);
    return url && url.origin === root.location.origin ? url.href : null;
  };
  // The theme/locale static directory these scripts came from, which may be on a CDN.
  var STATIC_BASE = (function () {
    var url = parseUrl(SCRIPT_SRC);
    var match = url && /^(.*\/)Furan_FormBuilder\/js\/[^\/]*$/.exec(url.origin + url.pathname);
    return match ? match[1] : '';
  })();
  var safeAsset = function (value) {
    var url = parseUrl(value);
    if (!url) {
      return null;
    }
    var ok = url.origin === root.location.origin ||
      (STATIC_BASE !== '' && (url.origin + url.pathname).indexOf(STATIC_BASE) === 0) ||
      (url.protocol === 'https:' && SCRIPT_HOSTS.indexOf(url.hostname) !== -1);
    return ok ? url.href : null;
  };

  var api = {
    REGEX: REGEX,
    isEmpty: isEmpty,
    normalizeValue: normalizeValue,
    normalizeValues: normalizeValues,
    suggestionValue: suggestionValue,
    evaluateCondition: evaluateCondition,
    computeVisibility: computeVisibility,
    computeState: computeState,
    validateRule: validateRule,
    validateField: validateField,
    validateFields: validateFields,
    fieldHooks: function (code) { return customTypes[code] || builtinTypes[code] || null; },
    hiddenSource: function (code) { return customSources[code] || null; },
    postcodeHint: postcodeHint,
    mask: mask,
    sameOrigin: sameOrigin,
    safeAsset: safeAsset
  };

  // Internal: ffb-types.js registers the module's own structured types here; third parties use registry.fieldType.
  Object.defineProperty(api, 'registerBuiltinFieldType', {
    value: function (code, hooks) {
      if (BUILTIN_TYPES.indexOf(code) === -1) {
        throw new Error('The field type "' + String(code) + '" is not a built-in type.');
      }
      if (builtinTypes[code]) {
        throw new Error('The built-in field type "' + code + '" is already registered.');
      }
      builtinTypes[code] = hooks;
    }
  });

  ns.core = api;
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
