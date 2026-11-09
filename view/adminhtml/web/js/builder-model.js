(function (root) {
  'use strict';

  const HISTORY_LIMIT = 50;
  const SETTING_EMAIL_FIELD = 'autoresponder_email_field';
  const SETTING_CUSTOMER_EMAIL = 'email_field';
  const FIELD_EMAIL_FIELD = 'email_field';
  const SETTING_ROUTES = 'notify_routes';
  const SETTINGS_SEND_WHEN = ['notify_when', 'autoresponder_when'];

  const typeOf = (typeCode, config) => ((config && config.fieldTypes) || []).find((t) => t.code === typeCode) || null;

  // Unknown types (their module is disabled) are neither personal nor sources, as on the server.
  function isConditionSource(typeCode, config) {
    const type = typeOf(typeCode, config);
    return type !== null && type.conditionSource === true;
  }

  function isEmailAddress(typeCode, config) {
    const type = typeOf(typeCode, config);
    return type !== null && type.emailAddress === true;
  }

  function isPersonal(field, config) {
    if (typeof field.personal === 'boolean') {
      return field.personal;
    }
    const type = typeOf(field.type, config);
    return (type !== null && type.personalByDefault === true)
      || ((config && config.personalAutocomplete) || []).indexOf(field.autocomplete) >= 0;
  }
  const NAME_RE = /^[a-z][a-z0-9_]{0,63}$/;
  const IDENTIFIER_RE = /^[a-z0-9][a-z0-9-]{0,63}$/;
  const ID_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

  const clone = (value) => structuredClone(value);

  function i18n() {
    return typeof module === 'object' && module.exports
      ? require('./builder-i18n.js')
      : (root.FuranFormBuilder || {}).builderI18n;
  }

  function patternSafety() {
    return typeof module === 'object' && module.exports
      ? require('./pattern-safety.js')
      : (root.FuranFormBuilder || {}).patternSafety;
  }

  function isPatternAllowed(pattern) {
    const safety = patternSafety();
    if (safety) {
      return safety.isSafe(pattern);
    }
    try {
      new RegExp(pattern, 'u');
      return true;
    } catch (e) {
      return false;
    }
  }

  function tr(text, ...args) {
    const api = i18n();
    return api
      ? api.t(text, ...args)
      : String(text).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m));
  }

  // PHP sends an empty map as [], and saved forms only carry overlays for some store views.
  function cleanTranslations(raw) {
    const out = {};
    if (raw && typeof raw === 'object') {
      Object.keys(raw).forEach((storeId) => {
        if (raw[storeId] && typeof raw[storeId] === 'object' && Object.keys(raw[storeId]).length) {
          out[storeId] = clone(raw[storeId]);
        }
      });
    }
    return out;
  }

  function randomIndex(max) {
    if (root.crypto && root.crypto.getRandomValues) {
      return root.crypto.getRandomValues(new Uint32Array(1))[0] % max;
    }
    return Math.floor(Math.random() * max);
  }

  function uid(prefix) {
    let out = '';
    for (let i = 0; i < 8; i++) {
      out += ID_CHARS[randomIndex(ID_CHARS.length)];
    }
    return prefix + '_' + out;
  }

  function slugify(label) {
    let s = String(label || '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (s === '' || /^[0-9]/.test(s)) {
      s = 'field_' + s;
    }
    return s.slice(0, 64);
  }

  function isReserved(name) {
    return name === 'form_key' || name === 'form_id' || String(name).indexOf('ffb_') === 0;
  }

  function uniqueName(base, takenNames) {
    const taken = new Set(takenNames || []);
    if (String(base).indexOf('ffb_') === 0) {
      base = 'field_' + base.slice(4);
    }
    let candidate = base;
    let n = 2;
    while (taken.has(candidate) || isReserved(candidate)) {
      candidate = base + '_' + n++;
    }
    return candidate;
  }

  function createState(form, config) {
    const f = clone(form);
    if ('translations' in f) {
      f.translations = cleanTranslations(f.translations);
    }
    const emailTypes = ((config && config.fieldTypes) || []).filter((t) => t.emailAddress === true).map((t) => t.code);
    return { form: f, selectedId: null, dirty: false, past: [], future: [], saved: JSON.stringify(f), emailTypes };
  }

  // Pre-order walk: page, then its items; a section is followed by its children.
  function walk(form, fn) {
    form.schema.pages.forEach((page) => {
      fn(page, 'page', page, null);
      (page.items || []).forEach((item) => {
        fn(item, item.kind, page, null);
        if (item.kind === 'section') {
          (item.items || []).forEach((child) => fn(child, child.kind, page, item));
        }
      });
    });
  }

  function fieldsOf(form) {
    const out = [];
    walk(form, (node, kind) => {
      if (kind === 'field') {
        out.push(node);
      }
    });
    return out;
  }

  function allFields(state) {
    return fieldsOf(state.form);
  }

  function fieldsBefore(state, itemId) {
    const out = [];
    let found = false;
    walk(state.form, (node, kind) => {
      if (found) {
        return;
      }
      if (node.id === itemId) {
        found = true;
        return;
      }
      if (kind === 'field') {
        out.push(node);
      }
    });
    return found ? out : [];
  }

  function locate(form, itemId) {
    for (const page of form.schema.pages) {
      const list = page.items || [];
      for (let i = 0; i < list.length; i++) {
        if (list[i].id === itemId) {
          return { item: list[i], list, index: i, page, section: null };
        }
        if (list[i].kind === 'section') {
          const inner = list[i].items || [];
          for (let j = 0; j < inner.length; j++) {
            if (inner[j].id === itemId) {
              return { item: inner[j], list: inner, index: j, page, section: list[i] };
            }
          }
        }
      }
    }
    return null;
  }

  function targetList(form, target) {
    const page = form.schema.pages.find((p) => p.id === target.pageId);
    if (!page) {
      return null;
    }
    if (target.sectionId) {
      const section = (page.items || []).find((i) => i.id === target.sectionId && i.kind === 'section');
      return section ? section.items : null;
    }
    return page.items;
  }

  function commit(state, form, extra) {
    const next = {
      form,
      selectedId: state.selectedId,
      dirty: true,
      past: state.past.concat([clone(state.form)]).slice(-HISTORY_LIMIT),
      future: [],
      saved: state.saved,
      emailTypes: state.emailTypes,
    };
    return Object.assign(next, extra || {});
  }

  function newField(typeCode, config, state) {
    const type = ((config && config.fieldTypes) || []).find((t) => t.code === typeCode);
    if (!type) {
      throw new Error(tr('Unknown field type: %1', typeCode));
    }
    const settings = {};
    Object.keys(type.settingsSpec || {}).forEach((key) => {
      if (type.settingsSpec[key].default !== undefined) {
        settings[key] = clone(type.settingsSpec[key].default);
      }
    });
    const defaults = type.defaults || {};
    const options = type.hasOptions
      ? [{ label: tr('Option %1', 1), value: 'option_1' }, { label: tr('Option %1', 2), value: 'option_2' }]
      : [];
    return {
      id: uid('f'),
      kind: 'field',
      type: typeCode,
      name: uniqueName(slugify(type.label), allFields(state).map((f) => f.name)),
      label: typeof defaults.label === 'string' && defaults.label !== '' ? defaults.label : type.label,
      placeholder: '',
      help: '',
      default: '',
      width: 'full',
      required: typeCode === 'consent',
      rules: [],
      options,
      settings,
      condition: null,
      draft: defaults.draft === false ? false : !(type.isFile || typeCode === 'hidden'),
      autocomplete: '',
      css_class: '',
    };
  }

  function newContent(typeCode) {
    const item = { id: uid('c'), kind: 'content', type: typeCode, width: 'full', condition: null };
    if (typeCode === 'heading') {
      item.text = tr('Heading');
      item.level = 2;
    } else if (typeCode === 'paragraph' || typeCode === 'rich_text') {
      item.text = '';
    } else if (typeCode === 'notice') {
      item.text = '';
      item.style = 'info';
      item.title = '';
    } else if (typeCode === 'summary') {
      item.title = tr('Review your answers');
    }
    return item;
  }

  function newSection() {
    return { id: uid('s'), kind: 'section', title: tr('Section'), description: '', condition: null, items: [] };
  }

  function newPage(title) {
    return { id: uid('p'), title: title || '', condition: null, items: [] };
  }

  function insertAt(list, index, item) {
    const i = Math.max(0, Math.min(index, list.length));
    list.splice(i, 0, item);
  }

  function addItem(state, item, target) {
    if (item.kind === 'section' && target.sectionId) {
      return state;
    }
    const form = clone(state.form);
    const list = targetList(form, target);
    if (!list) {
      return state;
    }
    insertAt(list, target.index, clone(item));
    return commit(state, form);
  }

  // target.index applies to the destination list after the item has been taken out.
  function moveItem(state, itemId, target) {
    const form = clone(state.form);
    const loc = locate(form, itemId);
    if (!loc || (loc.item.kind === 'section' && target.sectionId)) {
      return state;
    }
    loc.list.splice(loc.index, 1);
    const list = targetList(form, target);
    if (!list) {
      return state;
    }
    insertAt(list, target.index, loc.item);
    return commit(state, form);
  }

  function nodeFieldNames(node) {
    if (node.kind === 'field') {
      return [node.name];
    }
    if (node.kind === 'section') {
      return (node.items || []).filter((i) => i.kind === 'field').map((i) => i.name);
    }
    if (node.pages || node.items) {
      return (node.items || []).reduce((acc, i) => acc.concat(nodeFieldNames(i)), []);
    }
    return [];
  }

  function nodeIds(node) {
    return [node.id].concat((node.items || []).reduce((acc, i) => acc.concat(nodeIds(i)), []));
  }

  // A group has no `field`; mirrors ConditionNormalizer::isGroup.
  const isGroup = (entry) => Boolean(entry) && !('field' in entry) && Array.isArray(entry.rules);

  function eachLeaf(rules, fn) {
    (rules || []).forEach((entry) => {
      if (isGroup(entry)) {
        eachLeaf(entry.rules, fn);
      } else if (entry) {
        fn(entry);
      }
    });
  }

  // Every rule holder of the form: item conditions and page skip slots.
  function eachConditionHolder(form, fn) {
    walk(form, (node, kind) => {
      fn(node, 'condition', kind);
      if (kind === 'page') {
        fn(node, 'skip', kind);
      }
    });
  }

  // Keeps entries that do not reference a removed name; a group left without rules goes too.
  function pruneRules(rules, names, drop) {
    const out = [];
    (rules || []).forEach((entry) => {
      if (isGroup(entry)) {
        const inner = pruneRules(entry.rules, names, drop);
        if (inner.length) {
          out.push(Object.assign(entry, { rules: inner }));
        }
      } else if (names.has(entry.field) || names.has(entry.value_field)) {
        drop(names.has(entry.field) ? entry.field : entry.value_field);
      } else {
        out.push(entry);
      }
    });
    return out;
  }

  // Route conditions and send when groups: no earlier-field order, so only the names matter.
  function eachSettingsCondition(form, fn) {
    const settings = form.settings || {};
    (Array.isArray(settings[SETTING_ROUTES]) ? settings[SETTING_ROUTES] : []).forEach((route) => {
      fn(route, 'condition', SETTING_ROUTES);
    });
    SETTINGS_SEND_WHEN.forEach((key) => fn(settings, key, key));
  }

  function pruneSettingsConditions(form, names, refs) {
    const emptied = new Set();
    eachSettingsCondition(form, (holderParent, key, setting) => {
      const holder = holderParent[key];
      if (!holder || !Array.isArray(holder.rules)) {
        return;
      }
      let dropped = 0;
      const kept = pruneRules(holder.rules, names, (field) => {
        dropped++;
        refs.push({ itemId: null, kind: 'setting', setting, field });
      });
      if (dropped) {
        holderParent[key] = kept.length ? Object.assign(holder, { rules: kept }) : null;
        if (!kept.length && setting === SETTING_ROUTES) {
          emptied.add(holderParent);
        }
      }
    });
    if (emptied.size) {
      form.settings[SETTING_ROUTES] = form.settings[SETTING_ROUTES].filter((r) => !emptied.has(r));
    }
  }

  function pruneRefs(form, names) {
    const refs = [];
    eachConditionHolder(form, (node, key) => {
      const holder = node[key];
      if (!holder || !Array.isArray(holder.rules)) {
        return;
      }
      let dropped = 0;
      const kept = pruneRules(holder.rules, names, (field) => {
        dropped++;
        refs.push({ itemId: node.id, kind: key, field });
      });
      if (dropped) {
        node[key] = kept.length ? Object.assign(holder, { rules: kept }) : null;
      }
    });
    pruneSettingsConditions(form, names, refs);
    walk(form, (node, kind) => {
      if (kind === 'field' && Array.isArray(node.rules)) {
        node.rules = node.rules.filter((r) => {
          if (r.rule === 'matches' && names.has(r.value)) {
            refs.push({ itemId: node.id, kind: 'rule', field: r.value });
            return false;
          }
          return true;
        });
      }
    });
    return refs;
  }

  // `names` maps old field names to new ones; all swaps apply at once so chained renames cannot collide.
  function remapRefs(form, names) {
    const rename = (name) => (names.has(name) ? names.get(name) : name);
    const remapLeaves = (parent, key) => {
      eachLeaf(parent[key] && parent[key].rules, (r) => {
        r.field = rename(r.field);
        if (r.value_field !== undefined) {
          r.value_field = rename(r.value_field);
        }
      });
    };
    eachConditionHolder(form, remapLeaves);
    walk(form, (node, kind) => {
      if (kind !== 'field') {
        return;
      }
      (node.rules || []).forEach((r) => {
        if (r.rule === 'matches') {
          r.value = rename(r.value);
        }
      });
      if (node.settings && node.settings[FIELD_EMAIL_FIELD]) {
        node.settings[FIELD_EMAIL_FIELD] = rename(node.settings[FIELD_EMAIL_FIELD]);
      }
    });
    [SETTING_EMAIL_FIELD, SETTING_CUSTOMER_EMAIL].forEach((key) => {
      if (form.settings && form.settings[key]) {
        form.settings[key] = rename(form.settings[key]);
      }
    });
    eachSettingsCondition(form, remapLeaves);
  }

  function renameRefs(form, from, to) {
    remapRefs(form, new Map([[from, to]]));
  }

  function reconcileEmailSetting(form, refs, key, emailTypes) {
    const name = form.settings && form.settings[key];
    if (!name) {
      return;
    }
    if (!fieldsOf(form).some((f) => f.name === name && emailTypes.indexOf(f.type) >= 0)) {
      form.settings[key] = '';
      if (key === SETTING_EMAIL_FIELD) {
        form.settings.autoresponder_enabled = false;
      }
      refs.push({ itemId: null, kind: 'setting', setting: key, field: name });
    }
  }

  // A field's `email_field` setting (newsletter) must name a field that still exists.
  function reconcileFieldEmailSettings(form, refs) {
    const names = new Set(fieldsOf(form).map((f) => f.name));
    walk(form, (node, kind) => {
      const ref = kind === 'field' && node.settings ? node.settings[FIELD_EMAIL_FIELD] : '';
      if (ref && !names.has(ref)) {
        node.settings[FIELD_EMAIL_FIELD] = '';
        refs.push({ itemId: node.id, kind: 'setting', field: ref });
      }
    });
  }

  function reconcileSettings(form, refs, emailTypes) {
    reconcileFieldEmailSettings(form, refs);
    reconcileEmailSetting(form, refs, SETTING_EMAIL_FIELD, emailTypes);
    reconcileEmailSetting(form, refs, SETTING_CUSTOMER_EMAIL, emailTypes);
    const settings = form.settings;
    if (settings && settings.guest_dedupe && !settings[SETTING_CUSTOMER_EMAIL] && !settings[SETTING_EMAIL_FIELD]) {
      settings.guest_dedupe = false;
    }
  }

  // Action parameters name option values of their own field: follow a renamed value, drop a removed one.
  function reconcileActionOptions(item, oldOptions, refs) {
    const holder = item.condition;
    if (!holder || !Array.isArray(holder.actions)) {
      return;
    }
    const before = (oldOptions || []).map((o) => String(o.value));
    const now = (item.options || []).map((o) => String(o.value));
    const renamed = {};
    if (before.length === now.length) {
      before.forEach((value, i) => {
        if (value !== now[i] && now.indexOf(value) < 0) {
          renamed[value] = now[i];
        }
      });
    }
    const follow = (value) => (value in renamed ? renamed[value] : value);
    holder.actions = holder.actions.filter((entry) => {
      if (Array.isArray(entry.param)) {
        const kept = entry.param.map(follow).filter((v) => now.indexOf(v) >= 0);
        entry.param = kept;
        if (!kept.length) {
          refs.push({ itemId: item.id, kind: 'action', action: entry.action });
          return false;
        }
      } else if (entry.action === 'set_value' && now.length) {
        entry.param = follow(String(entry.param));
        if (now.indexOf(entry.param) < 0) {
          refs.push({ itemId: item.id, kind: 'action', action: entry.action });
          return false;
        }
      }
      return true;
    });
    if (!holder.actions.length) {
      holder.actions = [{ action: 'show' }];
    }
  }

  function removeFromForm(form, itemIds, names, emailTypes) {
    const refs = pruneRefs(form, names);
    reconcileSettings(form, refs, emailTypes);
    return refs;
  }

  function removeItem(state, itemId) {
    const form = clone(state.form);
    const loc = locate(form, itemId);
    if (!loc) {
      return state;
    }
    const names = new Set(nodeFieldNames(loc.item));
    const ids = nodeIds(loc.item);
    loc.list.splice(loc.index, 1);
    const refs = removeFromForm(form, ids, names, state.emailTypes);
    return commit(state, form, {
      lastRemovedRefs: refs,
      selectedId: ids.indexOf(state.selectedId) >= 0 ? null : state.selectedId,
    });
  }

  function duplicateNode(node, takenNames) {
    const copy = clone(node);
    const prefix = { field: 'f', content: 'c', section: 's' }[copy.kind];
    copy.id = uid(prefix);
    if (copy.kind === 'field') {
      copy.name = uniqueName(copy.name, takenNames);
      takenNames.push(copy.name);
    }
    if (copy.kind === 'section') {
      copy.items = (copy.items || []).map((child) => duplicateNode(child, takenNames));
    }
    return copy;
  }

  function duplicateItem(state, itemId) {
    const form = clone(state.form);
    const loc = locate(form, itemId);
    if (!loc) {
      return state;
    }
    const taken = fieldsOf(form).map((f) => f.name);
    loc.list.splice(loc.index + 1, 0, duplicateNode(loc.item, taken));
    return commit(state, form);
  }

  const GROUP_PAGE_ID = 'p_group000';
  const GROUP_NAME_MAX = 120;

  function withoutGroupMarks(node) {
    delete node.source_group;
    (node.kind === 'section' ? node.items || [] : []).forEach(withoutGroupMarks);
    return node;
  }

  // Translation overlays keep the texts of items under `items.<id>`.
  function pickItemTranslations(translations, ids) {
    const out = {};
    Object.keys(translations || {}).forEach((storeId) => {
      const items = translations[storeId] && translations[storeId].items;
      const picked = {};
      ids.forEach((id) => {
        if (items && Object.prototype.hasOwnProperty.call(items, id)) {
          picked[id] = clone(items[id]);
        }
      });
      if (Object.keys(picked).length) {
        out[storeId] = { items: picked };
      }
    });
    return out;
  }

  // The selected items (a selected section carries its children) as a stand-alone copy; references to
  // fields outside the selection are dropped and listed in `removed`.
  function extractGroup(state, itemIds) {
    const form = clone(state.form);
    const wanted = new Set(itemIds);
    const picked = [];
    walk(form, (node, kind, page, parent) => {
      if (kind !== 'page' && wanted.has(node.id) && !(parent && wanted.has(parent.id))) {
        picked.push(node);
      }
    });
    const inside = new Set(picked.reduce((acc, n) => acc.concat(nodeFieldNames(n)), []));
    const outside = new Set(fieldsOf(form).map((f) => f.name).filter((n) => !inside.has(n)));
    picked.forEach(withoutGroupMarks);
    const mini = { schema: { pages: [{ id: GROUP_PAGE_ID, items: picked }] }, settings: {} };
    const removed = pruneRefs(mini, outside);
    reconcileFieldEmailSettings(mini, removed);
    const ids = picked.reduce((acc, n) => acc.concat(nodeIds(n)), []);
    return { fields: picked, translations: pickItemTranslations(form.translations, ids), removed };
  }

  // Copies the group's items into the form with fresh ids and unique names; every reference between the
  // copied items follows the new names and nothing links back to the group.
  function insertGroup(state, group, target) {
    const nodes = clone(group.fields || []);
    if (!nodes.length || (target.sectionId && nodes.some((n) => n.kind === 'section'))) {
      return state;
    }
    const form = clone(state.form);
    const list = targetList(form, target);
    if (!list) {
      return state;
    }
    const taken = fieldsOf(form).map((f) => f.name);
    const nameMap = new Map();
    const idMap = new Map();
    const source = Number.isInteger(group.id) && group.id > 0 ? group.id : null;
    const copy = (node) => {
      idMap.set(node.id, uid({ field: 'f', content: 'c', section: 's' }[node.kind]));
      node.id = idMap.get(node.id);
      if (source) {
        node.source_group = source;
      }
      if (node.kind === 'field') {
        const fresh = uniqueName(node.name, taken);
        taken.push(fresh);
        nameMap.set(node.name, fresh);
        node.name = fresh;
      }
      (node.kind === 'section' ? node.items || [] : []).forEach(copy);
    };
    const originalIds = nodes.reduce((acc, n) => acc.concat(nodeIds(n)), []);
    nodes.forEach(copy);
    remapRefs({ schema: { pages: [{ id: GROUP_PAGE_ID, items: nodes }] }, settings: {} }, nameMap);
    nodes.forEach((n, i) => insertAt(list, target.index + i, n));
    mergeTranslations(form, group.translations, originalIds, idMap);
    // The last node is selected so the next add lands after the inserted block.
    return commit(state, form, { selectedId: nodes[nodes.length - 1].id });
  }

  function mergeTranslations(form, translations, originalIds, idMap) {
    const merged = cleanTranslations(form.translations);
    const incoming = pickItemTranslations(translations, originalIds);
    if (!Object.keys(incoming).length) {
      return;
    }
    Object.keys(incoming).forEach((storeId) => {
      const overlay = merged[storeId] || {};
      overlay.items = overlay.items || {};
      Object.keys(incoming[storeId].items).forEach((oldId) => {
        overlay.items[idMap.get(oldId)] = incoming[storeId].items[oldId];
      });
      merged[storeId] = overlay;
    });
    form.translations = merged;
  }

  function updateItem(state, itemId, patch) {
    const form = clone(state.form);
    const loc = locate(form, itemId);
    if (!loc) {
      return state;
    }
    const item = loc.item;
    const oldName = item.name;
    const changes = Object.assign({}, patch);
    delete changes.id;
    delete changes.kind;
    if (item.kind === 'field' && typeof changes.name === 'string' && changes.name !== oldName) {
      const others = fieldsOf(form).filter((f) => f.id !== itemId).map((f) => f.name);
      if (isReserved(changes.name) || others.indexOf(changes.name) >= 0) {
        changes.name = uniqueName(changes.name, others);
      }
    }
    const oldOptions = item.options;
    Object.assign(item, changes);
    if (item.kind === 'field' && item.name !== oldName) {
      renameRefs(form, oldName, item.name);
    }
    const refs = [];
    if (item.kind === 'field' && Array.isArray(changes.options)) {
      reconcileActionOptions(item, oldOptions, refs);
    }
    reconcileSettings(form, refs, state.emailTypes);
    return commit(state, form, refs.length ? { lastRemovedRefs: refs } : {});
  }

  function addPage(state, title) {
    const form = clone(state.form);
    form.schema.pages.push(newPage(title));
    return commit(state, form);
  }

  function removePage(state, pageId) {
    const pages = state.form.schema.pages;
    const page = pages.find((p) => p.id === pageId);
    if (!page || pages.length <= 1) {
      return state;
    }
    const form = clone(state.form);
    const names = new Set(nodeFieldNames(page));
    const ids = nodeIds(page);
    form.schema.pages = form.schema.pages.filter((p) => p.id !== pageId);
    const refs = removeFromForm(form, ids, names, state.emailTypes);
    form.schema.pages.forEach((p) => {
      if (p.skip && p.skip.target === pageId) {
        p.skip = null;
        refs.push({ itemId: p.id, kind: 'skip', target: pageId });
      }
    });
    return commit(state, form, {
      lastRemovedRefs: refs,
      selectedId: ids.indexOf(state.selectedId) >= 0 ? null : state.selectedId,
    });
  }

  function movePage(state, pageId, newIndex) {
    const form = clone(state.form);
    const from = form.schema.pages.findIndex((p) => p.id === pageId);
    if (from < 0) {
      return state;
    }
    const [page] = form.schema.pages.splice(from, 1);
    insertAt(form.schema.pages, newIndex, page);
    return commit(state, form);
  }

  function updatePage(state, pageId, patch) {
    const form = clone(state.form);
    const page = form.schema.pages.find((p) => p.id === pageId);
    if (!page) {
      return state;
    }
    const changes = Object.assign({}, patch);
    delete changes.id;
    delete changes.items;
    // A skip with no rules would always match, so it is cleared instead.
    if ('skip' in changes && !(changes.skip && Array.isArray(changes.skip.rules) && changes.skip.rules.length)) {
      changes.skip = null;
    }
    Object.assign(page, changes);
    return commit(state, form);
  }

  function updateForm(state, patch) {
    const form = clone(state.form);
    ['identifier', 'title', 'description', 'is_active', 'store_ids'].forEach((key) => {
      if (key in patch) {
        form[key] = clone(patch[key]);
      }
    });
    return commit(state, form);
  }

  function updateSettings(state, patch) {
    const form = clone(state.form);
    form.settings = Object.assign({}, form.settings, clone(patch));
    return commit(state, form);
  }

  function select(state, itemId) {
    return Object.assign({}, state, { selectedId: itemId });
  }

  function exists(form, id) {
    let found = false;
    walk(form, (node) => {
      if (node.id === id) {
        found = true;
      }
    });
    return found;
  }

  function restore(state, form, past, future) {
    return {
      form,
      selectedId: state.selectedId && exists(form, state.selectedId) ? state.selectedId : null,
      // Undoing back to the loaded form is not an unsaved change.
      dirty: JSON.stringify(form) !== state.saved,
      past,
      future,
      saved: state.saved,
      emailTypes: state.emailTypes,
    };
  }

  function undo(state) {
    if (!state.past.length) {
      return state;
    }
    const past = state.past.slice();
    const form = past.pop();
    return restore(state, form, past, state.future.concat([clone(state.form)]).slice(-HISTORY_LIMIT));
  }

  function redo(state) {
    if (!state.future.length) {
      return state;
    }
    const future = state.future.slice();
    const form = future.pop();
    return restore(state, form, state.past.concat([clone(state.form)]).slice(-HISTORY_LIMIT), future);
  }

  function validate(state, config) {
    const out = [];
    const form = state.form;
    const types = {};
    ((config && config.fieldTypes) || []).forEach((t) => {
      types[t.code] = t;
    });
    const knownTypes = Object.keys(types).length > 0;
    const add = (itemId, message) => out.push({ itemId, message });

    if (config && config.group) {
      validateGroup(form, add);
    } else {
      if (!IDENTIFIER_RE.test(form.identifier || '')) {
        add(null, tr('The identifier must use lowercase letters, numbers and hyphens (max 64 characters).'));
      }
      const title = String(form.title || '').trim();
      if (title === '' || title.length > 255) {
        add(null, tr('The title is required and must be at most 255 characters.'));
      }
    }

    const seen = new Set();
    const before = new Set();
    walk(form, (node, kind) => {
      if (node.condition && Array.isArray(node.condition.rules)) {
        eachLeaf(node.condition.rules, (r) => {
          [r.field, r.value_field].forEach((name) => {
            if (name !== undefined && !before.has(name)) {
              add(node.id, tr('Condition references "%1", which is not a field earlier in the form.', name));
            }
          });
        });
        validateActionParams(node, add);
      }
      if (kind !== 'field') {
        return;
      }
      const label = node.label || node.name || node.id;
      if (knownTypes && !types[node.type]) {
        // Kept verbatim by the server; the builder cannot edit it.
        seen.add(node.name);
        before.add(node.name);
        return;
      }
      if (!NAME_RE.test(node.name || '')) {
        add(node.id, tr('Field "%1" has an invalid name; use lowercase letters, numbers and underscores, starting with a letter.', label));
      } else if (isReserved(node.name)) {
        add(node.id, tr('Field name "%1" is reserved.', node.name));
      }
      if (seen.has(node.name)) {
        add(node.id, tr('Duplicate field name "%1"; names must be unique.', node.name));
      }
      seen.add(node.name);
      if (String(node.label || '').trim() === '') {
        add(node.id, tr('Field "%1" needs a label.', node.name));
      }
      if (types[node.type] && types[node.type].hasOptions && !(node.options || []).length) {
        add(node.id, tr('Field "%1" needs at least one option.', label));
      }
      (node.rules || []).forEach((r) => {
        if (r.rule === 'pattern' && !isPatternAllowed(r.value)) {
          add(node.id, tr('Field "%1" has an invalid pattern (regular expression).', label));
        }
        if (r.rule === 'matches' && !before.has(r.value)) {
          add(node.id, tr('Field "%1" must match a field that appears earlier in the form.', label));
        }
      });
      if (isConditionSource(node.type, config)) {
        before.add(node.name);
      }
    });
    validateSkips(form, config, add);
    validateRoutes(form, add);
    return out;
  }

  function validateGroup(form, add) {
    const name = String(form.title || '').trim();
    if (name === '') {
      add(null, tr('Enter a name.'));
    } else if (name.length > GROUP_NAME_MAX) {
      add(null, tr('The name must be at most %1 characters.', GROUP_NAME_MAX));
    }
    if (!form.schema.pages.some((page) => (page.items || []).length)) {
      add(null, tr('A group needs at least one field.'));
    }
  }

  // hide_options needs at least one option chosen (the server refuses an empty list).
  function validateActionParams(node, add) {
    (node.condition.actions || []).forEach((entry) => {
      if (Array.isArray(entry.param) && !entry.param.length) {
        add(node.id, tr('Choose at least one option to hide for "%1".', node.label || node.name || node.id));
      }
    });
  }

  function validateRoutes(form, add) {
    const routes = form.settings && form.settings[SETTING_ROUTES];
    (Array.isArray(routes) ? routes : []).forEach((route, i) => {
      if (!route.condition || !Array.isArray(route.condition.rules) || !route.condition.rules.length) {
        add(null, tr('Routing rule %1 needs a condition.', i + 1));
      }
    });
  }

  // A skip may use the page's own fields and earlier ones, and must jump to a later page.
  function validateSkips(form, config, add) {
    const pages = form.schema.pages;
    const known = new Set();
    pages.forEach((page, index) => {
      fieldsOf({ schema: { pages: [page] } }).forEach((f) => {
        if (isConditionSource(f.type, config)) {
          known.add(f.name);
        }
      });
      if (!page.skip) {
        return;
      }
      const title = page.title || tr('Page %1', index + 1);
      eachLeaf(page.skip.rules, (r) => {
        [r.field, r.value_field].forEach((name) => {
          if (name !== undefined && !known.has(name)) {
            add(page.id, tr('Skip ahead on page "%1" uses "%2", which is not a field on or before that page.', title, name));
          }
        });
      });
      if (pages.findIndex((p) => p.id === page.skip.target) <= index) {
        add(page.id, tr('Skip ahead on page "%1" must jump to a later page.', title));
      }
    });
  }

  function serialize(state) {
    const f = state.form;
    return clone({
      form_id: f.id,
      identifier: f.identifier,
      title: f.title,
      is_active: f.is_active,
      store_ids: f.store_ids,
      schema: f.schema,
      settings: f.settings,
      translations: f.translations || {},
    });
  }

  // All store views is store 0, written as its Magento code. The maps cover real store views only.
  const ALL_STORE_VIEWS_CODE = 'admin';

  // Exports name store views by code so a file can move between installs with different store ids.
  function exportJson(state, storeCodes) {
    const out = serialize(state);
    delete out.form_id;
    out.schema.pages.forEach((page) => (page.items || []).forEach(withoutGroupMarks));
    out.store_codes = (out.store_ids || [])
      .map((id) => (Number(id) === 0 ? ALL_STORE_VIEWS_CODE : (storeCodes && storeCodes[id])))
      .filter(Boolean);
    delete out.store_ids;
    const byCode = {};
    Object.keys(out.translations).forEach((storeId) => {
      const code = storeCodes && storeCodes[storeId];
      if (code) {
        byCode[code] = out.translations[storeId];
      }
    });
    if (Object.keys(byCode).length) {
      out.translations = byCode;
    } else {
      delete out.translations;
    }
    return JSON.stringify(out, null, 2);
  }

  function importJson(state, text, storeIds) {
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      throw new Error(tr('The text is not valid JSON.'));
    }
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
      throw new Error(tr('The form definition must be a JSON object.'));
    }
    if (typeof data.identifier !== 'string' || data.identifier === '') {
      throw new Error(tr('The form definition is missing an identifier.'));
    }
    if (typeof data.title !== 'string') {
      throw new Error(tr('The form definition is missing a title.'));
    }
    if (!data.schema || typeof data.schema !== 'object' || Array.isArray(data.schema)) {
      throw new Error(tr('The form definition is missing a schema.'));
    }
    if (!Array.isArray(data.schema.pages) || !data.schema.pages.length) {
      throw new Error(tr('The schema must contain at least one page.'));
    }
    if (!data.schema.pages.every((p) => p && typeof p === 'object' && Array.isArray(p.items))) {
      throw new Error(tr('Every page in the schema needs an items list.'));
    }
    const idByCode = Object.assign({}, storeIds, { [ALL_STORE_VIEWS_CODE]: 0 });
    const storeIdFor = (code) => {
      if (!Object.prototype.hasOwnProperty.call(idByCode, code)) {
        throw new Error(tr('Store view %1 does not exist.', code));
      }
      return Number(idByCode[code]);
    };
    const form = {
      id: state.form.id,
      identifier: data.identifier,
      title: data.title,
      is_active: data.is_active !== false,
      store_ids: Array.isArray(data.store_codes) ? data.store_codes.map(storeIdFor) : [0],
      schema: data.schema,
      settings: data.settings && typeof data.settings === 'object' ? data.settings : {},
    };
    if ('translations' in state.form || data.translations) {
      const byId = {};
      const incoming = data.translations && typeof data.translations === 'object' ? data.translations : {};
      Object.keys(incoming).forEach((code) => {
        const storeId = idByCode[code];
        if (storeId !== undefined && incoming[code] && typeof incoming[code] === 'object') {
          byId[String(storeId)] = incoming[code];
        }
      });
      form.translations = cleanTranslations(byId);
    }
    return commit(state, clone(form), { selectedId: null });
  }

  function updateTranslation(state, storeId, path, entry) {
    const api = i18n();
    const form = clone(state.form);
    const translations = cleanTranslations(form.translations);
    const overlay = api.write(translations[storeId] || {}, path, entry);
    if (Object.keys(overlay).length) {
      translations[storeId] = overlay;
    } else {
      delete translations[storeId];
    }
    form.translations = translations;
    return commit(state, form);
  }

  const api = {
    isConditionSource, isPersonal,
    createState, isEmailAddress, uid, slugify, uniqueName, allFields, fieldsBefore,
    newField, newContent, newSection,
    addItem, moveItem, removeItem, duplicateItem, updateItem, extractGroup, insertGroup,
    addPage, removePage, movePage, updatePage,
    updateForm, updateSettings, updateTranslation, select, undo, redo,
    validate, serialize, importJson, exportJson,
  };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderModel = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
