(function (root) {
  'use strict';

  const NO_VALUE_OPERATORS = ['is_empty', 'not_empty'];
  const tabState = { id: null, tab: 'general' };

  function model() {
    return typeof module === 'object' && module.exports
      ? require('./builder-model.js')
      : root.FuranFormBuilder.builderModel;
  }

  function registry() {
    return typeof module === 'object' && module.exports
      ? require('./builder-registry.js')
      : (root.FuranFormBuilder || {}).builderRegistry;
  }

  function humanise(key) {
    const text = String(key).replace(/[_.-]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function i18n() {
    return typeof module === 'object' && module.exports
      ? require('./builder-i18n.js')
      : (root.FuranFormBuilder || {}).builderI18n;
  }

  function tr(text, ...args) {
    const api = i18n();
    return api
      ? api.t(text, ...args)
      : String(text).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m));
  }

  // Marks text for the PHP phrase list; it is translated where it is shown.
  const phrase = (text) => text;

  function el(doc, tag, attrs, children) {
    const node = doc.createElement(tag);
    Object.keys(attrs || {}).forEach((key) => {
      if (key === 'text') {
        node.textContent = attrs[key];
      } else if (key === 'class') {
        node.className = attrs[key];
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    (children || []).forEach((child) => node.appendChild(child));
    return node;
  }

  function wrap(doc, text, input, key) {
    const label = el(doc, 'label', { class: 'ffb-admin-control' }, [el(doc, 'span', { text })]);
    input.setAttribute('data-ffb-input', key);
    label.appendChild(input);
    return label;
  }

  function button(doc, text, key, onClick) {
    const danger = /(^|-)(remove|delete)$/.test(key);
    const b = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn ffb-admin-btn-outline' + (danger ? ' ffb-admin-btn-danger' : ''), text, 'data-ffb-input': key });
    b.addEventListener('click', onClick);
    return b;
  }

  function option(doc, value, text, selected) {
    const o = el(doc, 'option', { value: String(value), text });
    o.selected = Boolean(selected);
    return o;
  }

  function selectControl(doc, text, key, choices, current, onCommit) {
    const sel = el(doc, 'select');
    choices.forEach((c) => sel.appendChild(option(doc, c.value, c.label, String(c.value) === String(current))));
    sel.addEventListener('change', () => onCommit(sel.value));
    return wrap(doc, text, sel, key);
  }

  function textControl(doc, text, key, current, onCommit, opts) {
    const o = opts || {};
    const input = o.multiline ? el(doc, 'textarea') : el(doc, 'input', { type: o.type || 'text' });
    input.value = current === undefined || current === null ? '' : String(current);
    if (o.min !== undefined && o.min !== null) {
      input.min = String(o.min);
    }
    if (o.max !== undefined && o.max !== null) {
      input.max = String(o.max);
    }
    if (o.maxlength) {
      input.maxLength = o.maxlength;
    }
    input.addEventListener('change', () => onCommit(input.value));
    return wrap(doc, text, input, key);
  }

  function checkControl(doc, text, key, current, onCommit) {
    const input = el(doc, 'input', { type: 'checkbox' });
    input.checked = Boolean(current);
    input.addEventListener('change', () => onCommit(input.checked));
    const label = wrap(doc, text, input, key);
    label.classList.add('ffb-admin-control-check');
    return label;
  }

  function toList(text) {
    return text.split(',').map((s) => s.trim()).filter((s) => s !== '');
  }

  function toLines(text) {
    return text.split('\n').map((s) => s.trim()).filter((s) => s !== '');
  }

  function toNumber(text) {
    return text.trim() === '' ? '' : Number(text);
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  // Only types the server accepts as condition sources can be pointed at by conditions and `matches`.
  function referenceable(state, itemId, config) {
    return model().fieldsBefore(state, itemId)
      .filter((f) => model().isConditionSource(f.type, config))
      .map((f) => ({ value: f.name, label: f.label || f.name }));
  }

  const LIMIT_DEFAULTS = { maxLevels: 3, maxEntries: 25, maxRelativeDays: 3650 };
  const ONE_VALUE_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains'];
  const LIST_OPERATORS = ['is_one_of', 'is_not_one_of'];
  const SOURCE_VALUE = 'value';
  const SOURCE_FIELD = 'value_field';
  const SOURCE_DAYS = 'value_date';

  const limitsOf = (config) => Object.assign({}, LIMIT_DEFAULTS, config.conditionLimits || {});
  const isGroup = (entry) => !('field' in entry) && Array.isArray(entry.rules);

  function countEntries(rules) {
    return rules.reduce((n, entry) => n + 1 + (isGroup(entry) ? countEntries(entry.rules) : 0), 0);
  }

  function sourceOf(rule) {
    if (rule.value_date && typeof rule.value_date === 'object') {
      return SOURCE_DAYS;
    }
    return typeof rule.value_field === 'string' ? SOURCE_FIELD : SOURCE_VALUE;
  }

  // Exactly one value source per rule, as the server stores it.
  function setSource(rule, source, names) {
    delete rule.value;
    delete rule.value_field;
    delete rule.value_date;
    if (source === SOURCE_FIELD) {
      rule.value_field = names.length ? names[0].value : '';
    } else if (source === SOURCE_DAYS) {
      rule.value_date = { days: 0 };
    } else {
      rule.value = '';
    }
  }

  function dropEmptyGroups(group) {
    group.rules = group.rules.filter((entry) => {
      if (!isGroup(entry)) {
        return true;
      }
      dropEmptyGroups(entry);
      return entry.rules.length > 0;
    });
  }

  function groupAt(root, path) {
    return path.reduce((group, index) => group.rules[index], root);
  }

  // One recursive group editor serves conditions, page skips and routes; ctx.update(mutate) commits a changed copy of the root.
  function groupEditor(doc, ctx, group, path, level) {
    const prefix = path.length ? ctx.prefix + '-g' + path.join('-g') : ctx.prefix;
    const limits = ctx.limits;
    const box = el(doc, 'fieldset', { class: 'ffb-admin-cond-group', 'data-ffb-level': String(level) }, [
      el(doc, 'legend', { text: level === 1 ? tr('Rules') : tr('Nested group (level %1 of %2)', level, limits.maxLevels) }),
    ]);
    const edit = (mutate) => ctx.update((root) => {
      mutate(groupAt(root, path));
      dropEmptyGroups(root);
    });
    box.appendChild(selectControl(doc, tr('Match'), prefix + '-match',
      [{ value: 'all', label: tr('All rules') }, { value: 'any', label: tr('Any rule') }], group.match,
      (v) => edit((g) => { g.match = v; })));
    (group.rules || []).forEach((entry, i) => {
      if (isGroup(entry)) {
        const inner = groupEditor(doc, ctx, entry, path.concat([i]), level + 1);
        const remove = button(doc, tr('Remove group'), prefix + '-g' + i + '-remove', () => edit((g) => { g.rules.splice(i, 1); }));
        remove.setAttribute('aria-label', tr('Remove group %1', i + 1));
        inner.appendChild(remove);
        box.appendChild(inner);
        return;
      }
      box.appendChild(ruleRow(doc, ctx, entry, prefix, i, edit));
    });
    const full = countEntries(ctx.root().rules || []) >= limits.maxEntries;
    if (full) {
      if (level === 1) {
        box.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'cond-entry-limit',
          text: tr('A condition can have at most %1 rules and groups.', limits.maxEntries) }));
      }
    } else {
      box.appendChild(button(doc, tr('Add rule'), prefix + '-add-rule', () => edit((g) => { g.rules.push(ctx.newRule()); })));
      if (level < limits.maxLevels) {
        box.appendChild(button(doc, tr('Add group'), prefix + '-add-group', () => edit((g) => {
          g.rules.push({ match: 'all', rules: [ctx.newRule()] });
        })));
      } else {
        box.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'cond-depth-limit',
          text: tr('Groups can be nested at most %1 levels deep.', limits.maxLevels) }));
      }
    }
    return box;
  }

  function ruleRow(doc, ctx, rule, prefix, i, edit) {
    const key = prefix + '-' + i;
    const row = el(doc, 'div', { class: 'ffb-admin-row', role: 'group', 'aria-label': tr('Rule %1', i + 1) });
    const change = (mutate) => edit((g) => mutate(g.rules[i]));
    row.appendChild(selectControl(doc, tr('Field'), key + '-field', ctx.names, rule.field, (v) => change((r) => {
      r.field = v;
      const ops = ctx.operatorsFor(r).map((o) => o.value);
      if (ops.indexOf(r.operator) < 0 && ops.length) {
        r.operator = ops[0];
      }
      if (sourceOf(r) === SOURCE_DAYS && !ctx.relativeFormat(v)) {
        setSource(r, SOURCE_VALUE, ctx.names);
      }
    })));
    row.appendChild(selectControl(doc, tr('Operator'), key + '-operator', ctx.operatorsFor(rule), rule.operator, (v) => change((r) => {
      r.operator = v;
      if (!ctx.needsValue(v)) {
        setSource(r, SOURCE_VALUE, ctx.names);
      }
    })));
    const sources = ctx.sourcesFor(rule);
    const current = sourceOf(rule);
    if (!ctx.needsValue(rule.operator)) {
      const hidden = textControl(doc, tr('Value'), key + '-value', rule.value, () => {});
      hidden.hidden = true;
      hidden.querySelector('input').hidden = true;
      row.appendChild(hidden);
    } else {
      if (sources.length > 1) {
        row.appendChild(selectControl(doc, tr('Compare with'), key + '-source', sources, current,
          (v) => change((r) => setSource(r, v, ctx.names))));
      }
      row.appendChild(valueControl(doc, ctx, rule, key, current, change));
    }
    const remove = button(doc, tr('Remove'), key + '-remove', () => edit((g) => { g.rules.splice(i, 1); }));
    remove.setAttribute('aria-label', tr('Remove rule %1', i + 1));
    row.appendChild(remove);
    return row;
  }

  function valueControl(doc, ctx, rule, key, source, change) {
    if (source === SOURCE_FIELD) {
      return selectControl(doc, tr('Other field'), key + '-value-field', ctx.names, rule.value_field,
        (v) => change((r) => { r.value_field = v; }));
    }
    if (source === SOURCE_DAYS) {
      const max = ctx.limits.maxRelativeDays;
      const days = rule.value_date && Number.isInteger(rule.value_date.days) ? rule.value_date.days : 0;
      return textControl(doc, tr('Days from today (negative = past)'), key + '-days', days, (v) => change((r) => {
        const n = Math.trunc(Number(v));
        r.value_date = { days: Number.isFinite(n) ? Math.max(-max, Math.min(max, n)) : 0 };
      }), { type: 'number', min: -max, max });
    }
    const options = ctx.optionsOf(rule.field);
    if (options && ONE_VALUE_OPERATORS.indexOf(rule.operator) >= 0) {
      return optionPicker(doc, key + '-value', options, rule.value, (v) => change((r) => { r.value = v; }));
    }
    if (options && LIST_OPERATORS.indexOf(rule.operator) >= 0) {
      return optionTicks(doc, key + '-value', options, rule.value, (v) => change((r) => { r.value = v; }));
    }
    return textControl(doc, tr('Value'), key + '-value', rule.value, (v) => change((r) => { r.value = v; }));
  }

  // Rules store option values, so a value whose option was renamed or removed stays visible instead of vanishing.
  function choicesWithStored(options, stored) {
    const choices = options.map((o) => ({ value: String(o.value), label: o.label || String(o.value) }));
    stored.filter((v) => v !== '' && !choices.some((c) => c.value === v))
      .forEach((v) => choices.push({ value: v, label: tr('%1 (option no longer exists)', v) }));
    return choices;
  }

  function optionPicker(doc, key, options, current, onCommit) {
    const value = current === undefined || current === null ? '' : String(current);
    const choices = [{ value: '', label: tr('Choose an option') }].concat(choicesWithStored(options, [value]));
    return selectControl(doc, tr('Value'), key, choices, value, onCommit);
  }

  function optionTicks(doc, key, options, current, onCommit) {
    const stored = String(current === undefined || current === null ? '' : current).split('\n').map((v) => v.trim()).filter((v) => v !== '');
    const box = el(doc, 'fieldset', { class: 'ffb-admin-option-ticks', 'data-ffb-input': key }, [el(doc, 'legend', { text: tr('Values') })]);
    const ticks = choicesWithStored(options, stored).map((c) => {
      const input = el(doc, 'input', { type: 'checkbox', value: c.value });
      input.checked = stored.indexOf(c.value) >= 0;
      box.appendChild(el(doc, 'label', { class: 'ffb-admin-control ffb-admin-control-check' }, [el(doc, 'span', { text: c.label }), input]));
      return input;
    });
    box.addEventListener('change', () => onCommit(ticks.filter((t) => t.checked).map((t) => t.value).join('\n')));
    return box;
  }

  // Everything a rule editor needs from the builder config, for rules whose source fields are `names`.
  function ruleContext(doc, state, config, names, prefix, root, update) {
    const allOperators = config.operators || [];
    const fields = model().allFields(state);
    const typeOf = (name) => {
      const f = fields.find((x) => x.name === name);
      return f ? (config.fieldTypes || []).find((t) => t.code === f.type) || null : null;
    };
    const relativeFormat = (name) => {
      const type = typeOf(name);
      return type && type.relativeDate ? type.relativeDate : null;
    };
    const needsValue = (code) => {
      const op = allOperators.find((o) => o.code === code);
      return op && typeof op.needsValue === 'boolean' ? op.needsValue : NO_VALUE_OPERATORS.indexOf(code) < 0;
    };
    const optionsOf = (name) => {
      const f = fields.find((x) => x.name === name);
      const type = typeOf(name);
      return f && type && type.hasOptions && Array.isArray(f.options) && f.options.length ? f.options : null;
    };
    return {
      prefix, names, root, update,
      limits: limitsOf(config),
      relativeFormat, needsValue, optionsOf,
      // Operators declare the types they apply to; the rule's current operator always stays listed.
      operatorsFor: (rule) => {
        const source = fields.find((f) => f.name === rule.field);
        const known = source && (config.fieldTypes || []).some((t) => t.code === source.type);
        return allOperators
          .filter((o) => !known || !o.types || o.types.indexOf(source.type) >= 0 || o.code === rule.operator)
          .map((o) => ({ value: o.code, label: o.label }));
      },
      sourcesFor: (rule) => {
        const out = [{ value: SOURCE_VALUE, label: tr('A value') }];
        if (names.length) {
          out.push({ value: SOURCE_FIELD, label: tr('Another field') });
        }
        if (relativeFormat(rule.field) || sourceOf(rule) === SOURCE_DAYS) {
          out.push({ value: SOURCE_DAYS, label: tr('Days from today') });
        }
        return out;
      },
      newRule: () => ({
        field: names.length ? names[0].value : '',
        operator: allOperators.length ? allOperators[0].code : 'equals',
        value: '',
      }),
    };
  }

  const MAX_ACTIONS = 10;
  const EXCLUSIVE_ACTIONS = ['show', 'hide'];

  function actionsOf(cond) {
    if (Array.isArray(cond.actions) && cond.actions.length) {
      return cond.actions;
    }
    return [{ action: 'show' }];
  }

  // Actions the item can take, as the server accepts them (kind from `targets`, field types from `types`).
  function actionsFor(node, config) {
    const kind = node.kind || 'page';
    return (config.actions || []).filter((a) => (a.targets || []).indexOf(kind) >= 0
      && (kind !== 'field' || !a.types || a.types.indexOf(node.type) >= 0));
  }

  function unusedActions(valid, used, keep) {
    return valid.filter((a) => a.code === keep || (used.indexOf(a.code) < 0
      && !(EXCLUSIVE_ACTIONS.indexOf(a.code) >= 0 && used.some((u) => u !== keep && EXCLUSIVE_ACTIONS.indexOf(u) >= 0))));
  }

  const optionValues = (node) => (node.options || []).map((o) => String(o.value));

  // A new action starts with a parameter the server would accept.
  function newAction(code, node, config) {
    const def = (config.actions || []).find((a) => a.code === code);
    const first = optionValues(node)[0];
    if (def && def.param === 'options') {
      return { action: code, param: first === undefined ? [] : [first] };
    }
    if (def && def.param === 'value') {
      return { action: code, param: node.options && node.options.length ? first : '' };
    }
    return { action: code };
  }

  function paramControl(doc, node, entry, key, def, change) {
    if (def.param === 'options') {
      const picked = Array.isArray(entry.param) ? entry.param : [];
      const set = el(doc, 'fieldset', { class: 'ffb-admin-cond-options' }, [el(doc, 'legend', { text: tr('Options to hide') })]);
      (node.options || []).forEach((o, j) => {
        set.appendChild(checkControl(doc, o.label || o.value, key + '-' + j, picked.indexOf(String(o.value)) >= 0, (on) => change((e) => {
          const keep = optionValues(node).filter((v) => (v === String(o.value) ? on : (e.param || []).indexOf(v) >= 0));
          e.param = keep;
        })));
      });
      return set;
    }
    if (node.options && node.options.length) {
      return selectControl(doc, tr('Value to set'), key, node.options.map((o) => ({ value: o.value, label: o.label || o.value })),
        entry.param, (v) => change((e) => { e.param = v; }));
    }
    return textControl(doc, tr('Value to set'), key, entry.param, (v) => change((e) => { e.param = v; }));
  }

  function actionsEditor(doc, node, cond, config, update) {
    const box = el(doc, 'div', { class: 'ffb-admin-cond-actions' });
    const valid = actionsFor(node, config);
    const list = actionsOf(cond);
    const used = list.map((e) => e.action);
    list.forEach((entry, i) => {
      const row = el(doc, 'div', { class: 'ffb-admin-row' });
      const choices = unusedActions(valid, used, entry.action).map((a) => ({ value: a.code, label: a.label }));
      if (!choices.some((c) => c.value === entry.action)) {
        choices.unshift({ value: entry.action, label: tr('Unknown action (%1)', entry.action) });
      }
      const actionSelect = selectControl(doc, i === 0 ? tr('Action') : tr('Also'), 'cond-action-' + i, choices, entry.action,
        (v) => update((c) => { c.actions = actionsOf(c).map((e, n) => (n === i ? newAction(v, node, config) : e)); }));
      if (i > 0) {
        actionSelect.querySelector('select').setAttribute('aria-label', tr('Action %1', i + 1));
      }
      row.appendChild(actionSelect);
      const def = valid.find((a) => a.code === entry.action);
      if (def && def.param) {
        row.appendChild(paramControl(doc, node, entry, 'cond-action-' + i + '-param', def,
          (mutate) => update((c) => { c.actions = actionsOf(c); mutate(c.actions[i]); })));
      }
      if (list.length > 1) {
        const remove = button(doc, tr('Remove'), 'cond-action-' + i + '-remove', () => update((c) => {
          c.actions = actionsOf(c).filter((e, n) => n !== i);
        }));
        remove.setAttribute('aria-label', tr('Remove action %1', i + 1));
        row.appendChild(remove);
      }
      box.appendChild(row);
    });
    const next = unusedActions(valid, used, null)[0];
    if (next && list.length < MAX_ACTIONS) {
      box.appendChild(button(doc, tr('Add action'), 'cond-add-action', () => update((c) => {
        c.actions = actionsOf(c).concat([newAction(next.code, node, config)]);
      })));
    }
    return box;
  }

  function conditionEditor(doc, node, state, config, commit) {
    const wrapper = el(doc, 'div', { class: 'ffb-admin-conditions' });
    const cond = node.condition;
    if (!cond) {
      wrapper.appendChild(button(doc, tr('Add condition'), 'add-condition', () => {
        commit({ condition: { actions: [{ action: 'show' }], match: 'all', rules: [] } });
      }));
      return wrapper;
    }
    const update = (mutate) => {
      const next = clone(cond);
      next.actions = actionsOf(next);
      mutate(next);
      commit({ condition: next });
    };
    const names = referenceable(state, node.id, config);
    wrapper.appendChild(actionsEditor(doc, node, cond, config, update));
    const ctx = ruleContext(doc, state, config, names, 'cond', () => cond, update);
    wrapper.appendChild(groupEditor(doc, ctx, cond, [], 1));
    wrapper.appendChild(button(doc, tr('Remove condition'), 'cond-remove', () => commit({ condition: null })));
    return wrapper;
  }

  // The Skip ahead slot of a page: jumps over the pages before `target` when its rules match.
  function skipEditor(doc, page, state, config, commit) {
    const pages = state.form.schema.pages;
    const index = pages.findIndex((p) => p.id === page.id);
    if (index < 0 || index >= pages.length - 1) {
      return null;
    }
    const later = pages.slice(index + 1);
    const names = referenceable(state, later[0].id, config);
    const wrapper = el(doc, 'div', { class: 'ffb-admin-conditions ffb-admin-skip' }, [
      el(doc, 'h4', { class: 'ffb-admin-subtitle', text: tr('Skip ahead') }),
    ]);
    const skip = page.skip;
    if (!skip) {
      wrapper.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Jump over the pages in between when these rules match.') }));
      wrapper.appendChild(button(doc, tr('Add skip ahead'), 'add-skip', () => {
        const ctx = ruleContext(doc, state, config, names, 'skip', () => ({ rules: [] }), () => {});
        commit({ skip: { match: 'all', rules: [ctx.newRule()], target: later[0].id } });
      }));
      return wrapper;
    }
    const update = (mutate) => {
      const next = clone(skip);
      mutate(next);
      commit({ skip: next.rules.length ? next : null });
    };
    const title = (p) => p.title || tr('Page %1', pages.indexOf(p) + 1);
    const targets = later.map((p) => ({ value: p.id, label: title(p) }));
    if (!later.some((p) => p.id === skip.target)) {
      targets.unshift({ value: '', label: tr('Choose a later page') });
    }
    wrapper.appendChild(selectControl(doc, tr('Skip to page'), 'skip-target', targets, later.some((p) => p.id === skip.target) ? skip.target : '',
      (v) => update((s) => { s.target = v; })));
    const ctx = ruleContext(doc, state, config, names, 'skip', () => skip, update);
    wrapper.appendChild(groupEditor(doc, ctx, skip, [], 1));
    wrapper.appendChild(button(doc, tr('Remove skip ahead'), 'skip-remove', () => commit({ skip: null })));
    return wrapper;
  }

  function prefillControls(doc, field, config, commit) {
    const box = el(doc, 'div');
    const sources = (config.prefillSources || []).filter((s) => (s.types || []).indexOf(field.type) >= 0);
    const choices = [{ value: '', label: tr('(none)') }].concat(sources.map((s) => ({ value: s.code, label: s.label })));
    box.appendChild(selectControl(doc, tr('Prefill from'), 'prefill', choices, field.prefill || '', (v) => commit({ prefill: v })));
    const isProduct = String(field.prefill || '').indexOf('product.') === 0;
    const locked = checkControl(doc, tr('Locked (filled by the server)'), 'locked', isProduct || field.locked === true,
      (v) => commit({ locked: v }));
    if (isProduct) {
      locked.querySelector('input').disabled = true;
    }
    box.appendChild(locked);
    return box;
  }

  function generalPanel(doc, field, config, commit) {
    const p = el(doc, 'div');
    p.appendChild(textControl(doc, tr('Label'), 'label', field.label, (v) => commit({ label: v })));
    p.appendChild(textControl(doc, tr('Name'), 'name', field.name, (v) => commit({ name: v })));
    p.appendChild(textControl(doc, tr('Placeholder'), 'placeholder', field.placeholder, (v) => commit({ placeholder: v })));
    p.appendChild(textControl(doc, tr('Help text'), 'help', field.help, (v) => commit({ help: v })));
    p.appendChild(textControl(doc, tr('Default value'), 'default', field.default, (v) => commit({ default: v })));
    p.appendChild(selectControl(doc, tr('Width'), 'width',
      (config.widths || []).map((w) => ({ value: w.value, label: w.label })), field.width, (v) => commit({ width: v })));
    p.appendChild(checkControl(doc, tr('Required'), 'required', field.required, (v) => commit({ required: v })));
    p.appendChild(checkControl(doc, tr('Save in draft'), 'draft', field.draft !== false, (v) => commit({ draft: v })));
    p.appendChild(checkControl(doc, tr('Contains personal data'), 'personal', model().isPersonal(field, config),
      (v) => commit({ personal: v })));
    p.appendChild(prefillControls(doc, field, config, commit));
    p.appendChild(selectControl(doc, tr('Autocomplete'), 'autocomplete',
      (config.autocomplete || ['']).map((a) => ({ value: a, label: a === '' ? tr('(none)') : a })), field.autocomplete || '',
      (v) => commit({ autocomplete: v })));
    p.appendChild(textControl(doc, tr('CSS class'), 'css_class', field.css_class, (v) => commit({ css_class: v })));
    return p;
  }

  function optionsPanel(doc, field, commit) {
    const p = el(doc, 'div');
    const options = field.options || [];
    const update = (mutate) => {
      const next = clone(options);
      mutate(next);
      commit({ options: next });
    };
    options.forEach((opt, i) => {
      const block = el(doc, 'div', { class: 'ffb-admin-option' });
      block.appendChild(textControl(doc, tr('Option label'), 'option-' + i + '-label', opt.label,
        (v) => update((o) => { o[i].label = v; })));
      block.appendChild(textControl(doc, tr('Option value'), 'option-' + i + '-value', opt.value,
        (v) => update((o) => { o[i].value = v; })));
      block.appendChild(textControl(doc, tr('Option description'), 'option-' + i + '-description', opt.description,
        (v) => update((o) => { o[i].description = v; }), { maxlength: 255 }));
      const tools = el(doc, 'div', { class: 'ffb-admin-option-tools' });
      const up = button(doc, tr('Up'), 'option-' + i + '-up', () => update((o) => {
        o.splice(i - 1, 0, o.splice(i, 1)[0]);
      }));
      up.disabled = i === 0;
      const down = button(doc, tr('Down'), 'option-' + i + '-down', () => update((o) => {
        o.splice(i + 1, 0, o.splice(i, 1)[0]);
      }));
      down.disabled = i === options.length - 1;
      tools.appendChild(up);
      tools.appendChild(down);
      tools.appendChild(button(doc, tr('Remove'), 'option-' + i + '-remove', () => update((o) => { o.splice(i, 1); })));
      block.appendChild(tools);
      p.appendChild(block);
    });
    const add = el(doc, 'button', { type: 'button', class: 'ffb-admin-add-field', text: '+ ' + tr('Add option'), 'data-ffb-input': 'option-add' });
    add.addEventListener('click', () => update((o) => { o.push({ label: '', value: '' }); }));
    p.appendChild(add);
    return p;
  }

  function isPatternValid(pattern) {
    const safety = (root.FuranFormBuilder || {}).patternSafety;
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

  function ruleValueControl(doc, def, rule, i, state, field, config, update) {
    const key = 'rule-' + i + '-value';
    const type = def.valueType;
    if (type === 'none') {
      return null;
    }
    if (type === 'field') {
      const names = referenceable(state, field.id, config);
      return selectControl(doc, tr('Value'), key, names, rule.value, (v) => update((r) => { r[i].value = v; }));
    }
    const inputType = { int: 'number', number: 'number', date: 'date' }[type] || 'text';
    const numeric = type === 'int' || type === 'number';
    const control = textControl(doc, tr('Value'), key, rule.value,
      (v) => update((r) => { r[i].value = numeric ? toNumber(v) : v; }), { type: inputType });
    if (type === 'regex') {
      const input = control.querySelector('input');
      const hint = el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Invalid pattern'), 'data-ffb-hint': 'rule-' + i });
      hint.hidden = isPatternValid(input.value);
      input.addEventListener('input', () => { hint.hidden = isPatternValid(input.value); });
      control.appendChild(hint);
    }
    return control;
  }

  const RULE_HELP = {
    min_length: phrase('Shortest answer allowed, in characters.'),
    max_length: phrase('Longest answer allowed, in characters.'),
    pattern: phrase('A regular expression the whole answer must match.'),
    alpha: phrase('Letters, spaces, apostrophes and hyphens only.'),
    alphanumeric: phrase('Letters, numbers and spaces only.'),
    numeric: phrase('A number, such as 12 or 12.5.'),
    integer: phrase('A whole number, such as 12.'),
    email: phrase('An email address.'),
    url: phrase('A web address starting with http:// or https://.'),
    phone: phrase('A phone number: digits, spaces, brackets, dots or dashes.'),
    min: phrase('Lowest number allowed.'),
    max: phrase('Highest number allowed.'),
    min_date: phrase('Earliest date allowed.'),
    max_date: phrase('Latest date allowed.'),
    matches: phrase('Must be the same as an earlier field, such as a confirm email.'),
    min_selected: phrase('Fewest options that must be chosen.'),
    max_selected: phrase('Most options that can be chosen.'),
  };

  function ruleCard(doc, title, help, children) {
    const card = el(doc, 'div', { class: 'ffb-admin-rule' }, [el(doc, 'strong', { class: 'ffb-admin-rule-title', text: title })]);
    if (help) {
      card.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr(help) }));
    }
    children.filter(Boolean).forEach((c) => card.appendChild(c));
    return card;
  }

  function validationPanel(doc, field, type, state, config, commit) {
    const p = el(doc, 'div');
    const rules = field.rules || [];
    const defs = {};
    (config.rules || []).forEach((r) => { defs[r.code] = r; });
    const library = {};
    (config.ruleLibrary || []).forEach((r) => { library[r.ref] = r; });
    const allowed = (type && type.allowedRules) || [];
    const update = (mutate) => {
      const next = clone(rules);
      mutate(next);
      commit({ rules: next });
    };
    if (!rules.length) {
      p.appendChild(el(doc, 'p', { class: 'ffb-admin-empty-note', 'data-ffb-hint': 'no-rules',
        text: allowed.length
          ? tr('No rules yet. Add one to check the format of answers, such as a postcode or phone number.')
          : tr('This field type has no extra rules. Use Required on the General tab to make it mandatory.') }));
    }
    rules.forEach((rule, i) => {
      const remove = button(doc, tr('Remove'), 'rule-' + i + '-remove', () => update((r) => { r.splice(i, 1); }));
      if (rule.rule === 'preset') {
        const entry = library[rule.value];
        const message = textControl(doc, tr('Custom message'), 'rule-' + i + '-message', rule.message,
          (v) => update((r) => { r[i].message = v; }));
        if (entry) {
          message.querySelector('input').placeholder = entry.message;
        }
        const help = entry
          ? [entry.example ? tr('Example: %1', entry.example) : '', entry.saved ? tr('Saved rule.') : ''].filter(Boolean).join(' ')
          : tr('This rule no longer exists. Remove it or pick another.');
        p.appendChild(ruleCard(doc, entry ? entry.label : String(rule.value), help, [message, remove]));
        return;
      }
      const def = defs[rule.rule] || { label: rule.rule, valueType: 'string' };
      const message = textControl(doc, tr('Custom message'), 'rule-' + i + '-message', rule.message,
        (v) => update((r) => { r[i].message = v; }));
      p.appendChild(ruleCard(doc, def.label, RULE_HELP[rule.rule] || '',
        [ruleValueControl(doc, def, rule, i, state, field, config, update), message, remove]));
    });

    if (allowed.length) {
      const select = el(doc, 'select');
      select.appendChild(option(doc, '', tr('Add rule...'), true));
      const checks = el(doc, 'optgroup', { label: tr('Checks') });
      allowed.filter((code) => code !== 'preset').forEach((code) => {
        checks.appendChild(option(doc, 'rule:' + code, (defs[code] || { label: code }).label, false));
      });
      select.appendChild(checks);
      if (allowed.indexOf('preset') >= 0) {
        const groups = {};
        (config.ruleLibrary || []).forEach((entry) => {
          if (!groups[entry.group]) {
            groups[entry.group] = el(doc, 'optgroup', { label: entry.group });
          }
          const used = rules.some((r) => r.rule === 'preset' && r.value === entry.ref);
          const o = option(doc, 'preset:' + entry.ref, entry.label + (entry.example ? ' (' + entry.example + ')' : ''), false);
          o.disabled = used;
          groups[entry.group].appendChild(o);
        });
        Object.keys(groups).forEach((g) => select.appendChild(groups[g]));
      }
      select.addEventListener('change', () => {
        const [kind, ref] = [select.value.slice(0, select.value.indexOf(':')), select.value.slice(select.value.indexOf(':') + 1)];
        if (kind === 'rule') {
          update((r) => { r.push({ rule: ref, value: '', message: '' }); });
        } else if (kind === 'preset') {
          update((r) => { r.push({ rule: 'preset', value: ref, message: '' }); });
        }
      });
      p.appendChild(wrap(doc, tr('Add rule'), select, 'add-rule'));
      if (config.urls && config.urls.rules) {
        p.appendChild(el(doc, 'a', { class: 'ffb-admin-link-small', href: config.urls.rules, target: '_blank', rel: 'noopener', text: tr('Manage saved rules') }));
      }
    }
    return p;
  }

  const MASK_CUSTOM = '__custom';

  // A custom mask is as long as its pattern; a preset is judged by the length of its library example.
  function maskLength(mask, library) {
    const entry = (library || []).find((e) => e.ref === mask);
    return entry ? Array.from(entry.example || '').length : Array.from(mask).length;
  }

  // Preset select plus a pattern box for a custom mask (9 digit, a letter, A capital letter, * any character).
  function maskControl(doc, label, key, spec, value, set, presets, hints) {
    const text = value === undefined || value === null ? '' : String(value);
    const known = text === '' || presets.some((m) => m.code === text);
    const box = el(doc, 'div');
    const choices = [{ value: '', label: tr('(none)') }]
      .concat(presets.map((m) => ({ value: m.code, label: m.label })))
      .concat([{ value: MASK_CUSTOM, label: tr('Custom pattern...') }]);
    const custom = textControl(doc, tr('Mask pattern'), key + '-custom', known ? '' : text, set, { maxlength: spec.max_length });
    custom.hidden = known;
    const customInput = custom.querySelector('input');
    // One status node that stays in the DOM, so screen readers announce changes to its text.
    const warning = el(doc, 'p', { class: 'ffb-admin-hint', role: 'status', 'data-ffb-mask-warning': '' });
    const refresh = (mask) => {
      const longest = maskLength(mask, hints.library);
      warning.textContent = hints.maxLength && longest > hints.maxLength
        ? tr('The mask can make answers %1 characters long, but the longest answer allowed is %2.', longest, hints.maxLength) : '';
    };
    refresh(text);
    customInput.addEventListener('input', () => refresh(customInput.value));
    box.appendChild(selectControl(doc, label, key, choices, known ? text : MASK_CUSTOM, (v) => {
      custom.hidden = v !== MASK_CUSTOM;
      if (v !== MASK_CUSTOM) {
        set(v);
        refresh(v);
      } else {
        refresh(customInput.value);
        customInput.focus();
      }
    }));
    box.appendChild(custom);
    box.appendChild(warning);
    box.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('9 = digit, a = letter, A = capital letter, * = any character.') + ' '
      + tr('Answers are formatted when the shopper leaves the field. Typing and pasting are never blocked.') }));
    return box;
  }

  // opts.label / opts.key override the display text and input key (form settings contributors).
  function settingControl(doc, name, spec, current, commit, opts) {
    const o = opts || {};
    const key = o.key || 'setting-' + name;
    const label = o.label || spec.label || humanise(name);
    const value = current === undefined ? spec.default : current;
    const set = (v) => commit(name, v);
    const hook = registry() && registry().getInspectorControl(spec.type);
    if (hook) {
      try {
        const node = hook(spec, value, set);
        if (node && typeof node.nodeType === 'number') {
          return wrap(doc, label, node, key);
        }
      } catch (e) {
        // fall through to the generic control
      }
    }
    if (spec.format === 'mask') {
      return maskControl(doc, label, key, spec, value, set, o.masks || [], o.maskHints || {});
    }
    switch (spec.type) {
      case 'int':
      case 'number':
        return textControl(doc, label, key, value, (v) => set(toNumber(v)), { type: 'number', min: spec.min, max: spec.max });
      case 'bool':
        return checkControl(doc, label, key, value, set);
      case 'enum':
        return selectControl(doc, label, key, enumOptions(spec), value, set);
      case 'list':
        return spec.multiline
          ? textControl(doc, label, key, (value || []).join('\n'), (v) => set(toLines(v)), { multiline: true })
          : textControl(doc, label, key, (value || []).join(', '), (v) => set(toList(v)));
      default:
        return textControl(doc, label, key, value, set, { maxlength: spec.max_length, multiline: spec.max_length > 255 });
    }
  }

  // `value_labels` are the translated captions of an enum's values; a value without one shows as itself.
  function enumOptions(spec) {
    const captions = spec.value_labels || {};
    return (spec.values || []).map((v) => ({ value: v, label: captions[v] || v }));
  }

  function maskHints(field, config) {
    const limits = (field.rules || []).filter((r) => r.rule === 'max_length').map((r) => Number(r.value)).filter((n) => n > 0);
    return { maxLength: limits.length ? Math.min(...limits) : 0, library: config.ruleLibrary || [] };
  }

  function settingsPanel(doc, field, type, commit, config) {
    const p = el(doc, 'div');
    const spec = (type && type.settingsSpec) || {};
    const settings = field.settings || {};
    const current = (name) => (settings[name] === undefined ? (spec[name] || {}).default : settings[name]);
    // `visible_when` maps another setting to the values that show this one.
    const visible = (when) => !when || Object.keys(when).every((key) => [].concat(when[key]).indexOf(current(key)) !== -1);
    Object.keys(spec).forEach((name) => {
      if (!visible(spec[name].visible_when)) {
        return;
      }
      p.appendChild(settingControl(doc, name, spec[name], settings[name], (n, v) => {
        commit({ settings: Object.assign({}, settings, { [n]: v }) });
      }, { masks: config.masks || [], maskHints: maskHints(field, config) }));
      if (typeof spec[name].hint === 'string' && spec[name].hint !== '') {
        p.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: spec[name].hint }));
      }
    });
    return p;
  }

  function tabs(doc, container, panels) {
    const bar = el(doc, 'div', { class: 'ffb-admin-tabs', role: 'tablist' });
    const show = (name) => {
      tabState.tab = name;
      panels.forEach((t) => { t.panel.hidden = t.name !== name; });
      Array.from(bar.children).forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === name)));
    };
    if (!panels.some((t) => t.name === tabState.tab)) {
      tabState.tab = panels[0].name;
    }
    panels.forEach((t) => {
      const b = el(doc, 'button', { type: 'button', role: 'tab', class: 'ffb-admin-tab', text: t.title, 'data-tab': t.name });
      // Pressing a tab blurs an edited input, whose commit re-renders the inspector before the click lands.
      b.addEventListener('mousedown', () => { tabState.tab = t.name; });
      b.addEventListener('click', () => show(t.name));
      bar.appendChild(b);
      container.appendChild(t.panel);
    });
    container.insertBefore(bar, container.firstChild);
    show(tabState.tab);
  }

  function fieldInspector(doc, container, field, state, config, actions) {
    const type = (config.fieldTypes || []).find((t) => t.code === field.type);
    const commit = (patch) => actions.apply('updateItem', field.id, patch);
    const panels = [
      { name: 'general', title: tr('General'), panel: generalPanel(doc, field, config, commit) },
    ];
    if (type && type.hasOptions) {
      panels.push({ name: 'options', title: tr('Options'), panel: optionsPanel(doc, field, commit) });
    }
    panels.push({ name: 'validation', title: tr('Validation'), panel: validationPanel(doc, field, type, state, config, commit) });
    panels.push({ name: 'conditions', title: tr('Conditions'), panel: conditionEditor(doc, field, state, config, commit) });
    if (type && Object.keys(type.settingsSpec || {}).length) {
      panels.push({ name: 'settings', title: tr('Settings'), panel: settingsPanel(doc, field, type, commit, config) });
    }
    tabs(doc, container, panels);
  }

  function contentInspector(doc, container, item, state, config, actions) {
    const commit = (patch) => actions.apply('updateItem', item.id, patch);
    const p = el(doc, 'div');
    if (item.type === 'heading') {
      p.appendChild(textControl(doc, tr('Text'), 'text', item.text, (v) => commit({ text: v })));
      p.appendChild(selectControl(doc, tr('Level'), 'level',
        [2, 3, 4].map((n) => ({ value: n, label: 'H' + n })), item.level, (v) => commit({ level: Number(v) })));
    } else if (item.type === 'paragraph' || item.type === 'rich_text') {
      p.appendChild(textControl(doc, tr('Text'), 'text', item.text, (v) => commit({ text: v }), { multiline: true }));
    } else if (item.type === 'notice') {
      p.appendChild(textControl(doc, tr('Title'), 'title', item.title, (v) => commit({ title: v })));
      p.appendChild(textControl(doc, tr('Text'), 'text', item.text, (v) => commit({ text: v }), { multiline: true }));
      p.appendChild(selectControl(doc, tr('Style'), 'style',
        [['info', phrase('Info')], ['success', phrase('Success')], ['warning', phrase('Warning')]].map(([value, label]) => ({ value, label: tr(label) })),
        item.style, (v) => commit({ style: v })));
    } else if (item.type === 'summary') {
      p.appendChild(textControl(doc, tr('Title'), 'title', item.title, (v) => commit({ title: v })));
    }
    p.appendChild(selectControl(doc, tr('Width'), 'width',
      (config.widths || []).map((w) => ({ value: w.value, label: w.label })), item.width, (v) => commit({ width: v })));
    p.appendChild(conditionEditor(doc, item, state, config, commit));
    container.appendChild(p);
  }

  function sectionInspector(doc, container, item, state, config, actions) {
    const commit = (patch) => actions.apply('updateItem', item.id, patch);
    container.appendChild(textControl(doc, tr('Title'), 'title', item.title, (v) => commit({ title: v })));
    container.appendChild(textControl(doc, tr('Description'), 'description', item.description, (v) => commit({ description: v }), { multiline: true }));
    container.appendChild(conditionEditor(doc, item, state, config, commit));
  }

  function pageInspector(doc, container, page, state, config, actions) {
    const commit = (patch) => actions.apply('updatePage', page.id, patch);
    container.appendChild(textControl(doc, tr('Title'), 'title', page.title, (v) => commit({ title: v })));
    container.appendChild(conditionEditor(doc, page, state, config, commit));
    const skip = skipEditor(doc, page, state, config, commit);
    if (skip) {
      container.appendChild(skip);
    }
  }

  const ALL_GROUPS = 32000;

  // A group of rules with no actions (routes, send when); `commit` gets the changed group, or null once its last rule is gone.
  function rulesBox(doc, state, config, names, prefix, current, commit) {
    const ctx = ruleContext(doc, state, config, names, prefix, () => current || { rules: [] }, (mutate) => {
      const next = clone(current);
      mutate(next);
      commit(next.rules.length ? next : null);
    });
    if (!current) {
      return button(doc, tr('Add condition'), prefix + '-add', () => commit(freshGroup(doc, state, config, names, prefix)));
    }
    return groupEditor(doc, ctx, current, [], 1);
  }

  // Same rule as SettingsNormalizer: a type flagged `routable: false` is excluded; an unregistered type stays routable.
  function routableNames(state, config) {
    const types = config.fieldTypes || [];
    const routable = (f) => !types.some((t) => t.code === f.type && t.routable === false);
    return model().allFields(state).filter(routable).map((f) => ({ value: f.name, label: f.label || f.name }));
  }

  function freshGroup(doc, state, config, names, prefix) {
    return { match: 'all', rules: [ruleContext(doc, state, config, names, prefix, () => ({}), () => {}).newRule()] };
  }

  // 'Send only when' for one email; no condition means it is always sent.
  function sendWhenControl(doc, state, config, key, current, commit) {
    const names = routableNames(state, config);
    const box = el(doc, 'div', { class: 'ffb-admin-send-when' }, [
      el(doc, 'h4', { class: 'ffb-admin-subtitle', text: tr('Send only when') }),
    ]);
    if (!names.length) {
      return null;
    }
    if (!current) {
      box.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Always sent. Add a condition to send only when it matches.') }));
      box.appendChild(button(doc, tr('Add condition'), key + '.add',
        () => commit(freshGroup(doc, state, config, names, key))));
      return box;
    }
    box.appendChild(rulesBox(doc, state, config, names, key, current, commit));
    box.appendChild(button(doc, tr('Remove condition'), key + '.remove', () => commit(null)));
    return box;
  }

  function routesControl(doc, state, config, current, commit) {
    const routes = Array.isArray(current) ? current : [];
    const names = routableNames(state, config);
    const box = el(doc, 'div', { class: 'ffb-admin-routes' }, [
      el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Send to other addresses when a condition matches. "Instead" drops the default recipients.') }),
    ]);
    const change = (index, patch) => commit(routes.map((r, i) => (i === index ? Object.assign({}, r, patch) : r)));
    routes.forEach((route, i) => {
      const key = 'notify_routes.' + i;
      box.appendChild(el(doc, 'div', { class: 'ffb-admin-route' }, [
        rulesBox(doc, state, config, names, key + '.condition', route.condition || null, (group) => change(i, { condition: group })),
        textControl(doc, tr('Send to (comma separated)'), key + '.emails', (route.emails || []).join(', '), (v) => change(i, { emails: toList(v) })),
        selectControl(doc, tr('Mode'), key + '.mode',
          [{ value: 'add', label: tr('In addition') }, { value: 'replace', label: tr('Instead') }], route.mode || 'add',
          (v) => change(i, { mode: v })),
        button(doc, tr('Remove rule'), key + '.remove', () => commit(routes.filter((r, j) => j !== i))),
      ]));
    });
    if (names.length > 0 && routes.length < 20) {
      box.appendChild(button(doc, tr('Add routing rule'), 'notify_routes.add',
        () => commit(routes.concat([{ condition: freshGroup(doc, state, config, names, 'notify_routes'), emails: [], mode: 'add' }]))));
    }
    return box;
  }

  const NAIVE_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

  // Same rule as Model\Form\ScheduleTimezone::defaultFor: only used to pre-select a form that has no stored zone.
  function scheduleDefaultZone(storeIds, schedule) {
    const zones = (schedule && schedule.storeTimezones) || {};
    const ids = (storeIds || []).map(Number);
    const all = ids.length === 0 || ids.indexOf(0) >= 0;
    const found = {};
    Object.keys(zones).forEach((id) => {
      if (all || ids.indexOf(Number(id)) >= 0) {
        found[zones[id]] = true;
      }
    });
    const list = Object.keys(found);
    return list.length === 1 ? list[0] : ((schedule && schedule.defaultTimezone) || 'UTC');
  }

  // Wall-clock time of a UTC instant in an IANA zone, for an instant the server did not pre-convert.
  function instantToLocal(iso, zone) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    try {
      const parts = {};
      new Intl.DateTimeFormat('en-CA', {
        timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
      }).formatToParts(date).forEach((p) => {
        parts[p.type] = p.value;
      });
      return parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
    } catch (e) {
      return '';
    }
  }

  // The stored instant stays in the state until the admin edits that input; edited values are naive local times.
  function scheduleView(state, config) {
    const settings = state.form.settings || {};
    const schedule = config.schedule || {};
    const original = (config.form && config.form.settings) || {};
    const zone = settings.schedule_timezone || scheduleDefaultZone(state.form.store_ids, schedule);
    const shown = (key) => {
      const value = settings[key] || '';
      if (value === '' || NAIVE_TIME.test(value)) {
        return value;
      }
      const unchanged = value === original[key] && (settings.schedule_timezone || '') === (original.schedule_timezone || '');
      return (unchanged && schedule.local && schedule.local[key]) || instantToLocal(value, zone);
    };
    return { zone, open_from: shown('open_from'), close_at: shown('close_at') };
  }

  function scheduleBox(doc, state, config, actions) {
    const view = scheduleView(state, config);
    const choices = ((config.schedule && config.schedule.timezones) || []).slice();
    if (!choices.some((c) => c.value === view.zone)) {
      choices.push({ value: view.zone, label: view.zone });
    }
    const commit = (patch) => actions.apply('updateSettings', patch);
    const timeInput = (key, label) => textControl(doc, label, 'settings.' + key, view[key],
      (v) => commit({ [key]: v, schedule_timezone: view.zone }), { type: 'datetime-local' });
    return [
      timeInput('open_from', tr('Opens at')),
      timeInput('close_at', tr('Closes at')),
      selectControl(doc, tr('Timezone'), 'settings.schedule_timezone', choices, view.zone,
        (v) => commit({ schedule_timezone: v, open_from: view.open_from, close_at: view.close_at })),
      el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'schedule',
        text: tr('Leave both empty for a form that is always open. Times are entered in the timezone above and saved as UTC.') }),
    ];
  }

  function groupInspector(doc, container, state, config, actions) {
    const patch = (key) => (v) => actions.apply('updateForm', { [key]: v });
    container.appendChild(el(doc, 'fieldset', { class: 'ffb-admin-group' }, [
      el(doc, 'legend', { text: tr('Group') }),
      textControl(doc, tr('Name'), 'title', state.form.title, patch('title'), { maxlength: 120 }),
      el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'group-name', text: tr('Shown in the form builder when you add a group to a form.') }),
      textControl(doc, tr('Description'), 'description', state.form.description, patch('description'), { multiline: true, maxlength: 500 }),
    ]));
    const usage = (config.group && config.group.usage) || { rows: [], total: 0 };
    const rows = Array.isArray(usage.rows) ? usage.rows : [];
    const used = el(doc, 'fieldset', { class: 'ffb-admin-group', 'data-ffb-group-usage': true }, [el(doc, 'legend', { text: tr('Used in') })]);
    if (!rows.length) {
      used.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('No form contains this group.') }));
    } else {
      used.appendChild(el(doc, 'ul', { class: 'ffb-admin-usage' }, rows.map((row) => el(doc, 'li', {}, [
        row.url ? el(doc, 'a', { href: row.url, text: row.title }) : el(doc, 'span', { text: row.title }),
      ]))));
      if (usage.total > rows.length) {
        used.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('And %1 more.', usage.total - rows.length) }));
      }
    }
    used.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Each form keeps its own copy of the fields. Changes here apply to forms the group is added to next.') }));
    container.appendChild(used);
  }

  function formInspector(doc, container, state, config, actions) {
    const form = state.form;
    const s = form.settings || {};
    const formPatch = (patch) => actions.apply('updateForm', patch);
    const setting = (key, label, opts) => {
      const o = opts || {};
      const k = 'settings.' + key;
      const commit = (v) => actions.apply('updateSettings', { [key]: v });
      if (o.bool) {
        return checkControl(doc, label, k, s[key], commit);
      }
      if (o.list) {
        return textControl(doc, label, k, (s[key] || []).join(', '), (v) => commit(toList(v)));
      }
      return textControl(doc, label, k, s[key], commit, o);
    };
    const canNotify = !(config.permissions && config.permissions.notifications === false);
    const permissionNote = () => el(doc, 'p', {
      class: 'ffb-admin-hint',
      'data-ffb-hint': 'notifications-permission',
      text: tr('Notification settings need permission to view submissions.'),
    });
    const box = (title, children) => {
      const fs = el(doc, 'fieldset', { class: 'ffb-admin-group' }, [el(doc, 'legend', { text: title })]);
      children.filter(Boolean).forEach((c) => fs.appendChild(c));
      container.appendChild(fs);
    };

    box(tr('Form'), [
      textControl(doc, tr('Identifier'), 'identifier', form.identifier, (v) => formPatch({ identifier: v })),
      textControl(doc, tr('Title'), 'title', form.title, (v) => formPatch({ title: v })),
      checkControl(doc, tr('Active'), 'is_active', form.is_active, (v) => formPatch({ is_active: v })),
    ]);
    const stores = el(doc, 'select', { multiple: 'multiple' });
    (config.stores || []).forEach((st) => stores.appendChild(option(doc, st.value, st.label, (form.store_ids || []).map(Number).indexOf(Number(st.value)) >= 0)));
    stores.addEventListener('change', () => formPatch({ store_ids: Array.from(stores.selectedOptions).map((o) => Number(o.value)) }));
    container.querySelector('fieldset').appendChild(wrap(doc, tr('Store views'), stores, 'store_ids'));

    box(tr('Display'), [
      setting('submit_label', tr('Submit label')),
      setting('next_label', tr('Next label')),
      setting('prev_label', tr('Back label')),
      setting('success_message', tr('Success message'), { multiline: true }),
      setting('show_reference', tr('Show the reference under the message'), { bool: true }),
      el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'show-reference',
        text: tr('Adds the submission reference under the message with a copy button. Not added when the message already uses {{reference}} or submissions are not stored.') }),
      setting('success_redirect', tr('Success redirect URL')),
      setting('show_progress', tr('Show progress'), { bool: true }),
    ]);
    box(tr('Search engines'), [
      setting('page_title', tr('Page title')),
      setting('meta_description', tr('Meta description'), { multiline: true }),
      setting('seo_noindex', tr('Hide this form from search engines'), { bool: true }),
      el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': 'seo-noindex',
        text: tr('Adds noindex and leaves it out of the sitemap. Use for thank-you or internal forms.') }),
    ]);
    box(tr('Appearance'), [
      selectControl(doc, tr('Styling'), 'settings.style',
        [{ value: 'module', label: tr('Form builder styles') }, { value: 'theme', label: tr('Theme styles only') }],
        s.style || 'module', (v) => actions.apply('updateSettings', { style: v })),
      textControl(doc, tr('Accent colour'), 'settings.accent_color', s.accent_color || '',
        (v) => actions.apply('updateSettings', { accent_color: String(v).trim().toLowerCase() }), { maxlength: 7 }),
    ]);
    box(tr('Schedule'), scheduleBox(doc, state, config, actions).concat([
      setting('not_open_message', tr('Message before opening'), { multiline: true }),
      setting('closed_message', tr('Message after closing'), { multiline: true }),
    ]));
    box(tr('Data'), [
      setting('store_submissions', tr('Store submissions'), { bool: true }),
      canNotify ? setting('store_ip', tr('Store IP address'), { bool: true }) : null,
      selectControl(doc, tr('Draft mode'), 'settings.draft_mode',
        [{ value: 'session', label: tr('Session') }, { value: 'local', label: tr('Local') }, { value: 'off', label: tr('Off') }],
        s.draft_mode || 'session', (v) => actions.apply('updateSettings', { draft_mode: v })),
      s.draft_mode === 'local' ? el(doc, 'p', {
        class: 'ffb-admin-hint',
        'data-ffb-hint': 'draft-local',
        text: tr('Answers stay in this browser until the draft expires. Avoid for forms with personal data on shared computers.'),
      }) : null,
    ]);
    box(tr('Notifications'), canNotify ? [
      setting('notify_enabled', tr('Send notification'), { bool: true }),
      setting('notify_recipients', tr('Recipients (comma separated)'), { list: true }),
      setting('notify_cc', tr('CC'), { list: true }),
      setting('notify_bcc', tr('BCC'), { list: true }),
      setting('notify_subject', tr('Subject')),
      routesControl(doc, state, config, s.notify_routes, (routes) => actions.apply('updateSettings', { notify_routes: routes })),
      sendWhenControl(doc, state, config, 'notify_when', s.notify_when || null,
        (group) => actions.apply('updateSettings', { notify_when: group })),
    ] : [permissionNote()]);
    const emailFields = model().allFields(state).filter((f) => model().isEmailAddress(f.type, config))
      .map((f) => ({ value: f.name, label: f.label || f.name }));
    const emailChoices = [{ value: '', label: tr('(none)') }].concat(emailFields);
    const hint = (key, text) => el(doc, 'p', { class: 'ffb-admin-hint', 'data-ffb-hint': key, text });
    const loginRequired = Boolean(s.access_login_required);
    const allowedGroups = (s.access_groups || []).map(Number);
    const groupChoices = (config.customerGroups || [])
      .filter((g) => Number(g.value) !== ALL_GROUPS && !(loginRequired && Number(g.value) === 0));
    box(tr('Access'), [
      setting('access_login_required', tr('Login required'), { bool: true }),
      hint('access-groups', tr('Only these customer groups can fill in the form. Leave all unticked to allow everyone.')),
    ].concat(groupChoices.map((g) => checkControl(doc, g.label, 'settings.access_groups.' + g.value,
      allowedGroups.indexOf(Number(g.value)) >= 0, (on) => {
        const next = allowedGroups.filter((id) => id !== Number(g.value));
        if (on) {
          next.push(Number(g.value));
        }
        actions.apply('updateSettings', { access_groups: next });
      }))));
    box(tr('Customers'), [
      selectControl(doc, tr('Customer email field'), 'settings.email_field', emailChoices, s.email_field || '',
        (v) => actions.apply('updateSettings', { email_field: v })),
      selectControl(doc, tr('Survey mode'), 'settings.survey_mode',
        [{ value: 'off', label: tr('Off') }, { value: 'once', label: tr('One response') },
          { value: 'edit', label: tr('One response, editable') }],
        s.survey_mode || 'off', (v) => actions.apply('updateSettings', { survey_mode: v })),
      hint('survey-mode', tr('Survey mode needs "Store submissions". One response applies only to logged-in customers unless guest dedupe is on.')),
      setting('guest_dedupe', tr('Guest dedupe by email'), { bool: true }),
      hint('guest-dedupe', tr('Guest dedupe trusts the email the visitor types, which is not verified: a repeat answer with an already used email is accepted silently and not stored. Logged-in customers are only limited by their own account.')),
      setting('account_edit', tr('Customers can edit their submissions'), { bool: true }),
      setting('account_delete', tr('Customers can delete their submissions'), { bool: true }),
      selectControl(doc, tr('Customer replies'), 'settings.customer_replies',
        [{ value: 'default', label: tr('Store default') }, { value: 'on', label: tr('Allowed') },
          { value: 'off', label: tr('Not allowed') }],
        s.customer_replies || 'default', (v) => actions.apply('updateSettings', { customer_replies: v })),
      hint('customer-replies', tr('Whether customers and guests can answer your replies. Store default follows the store configuration. You can always reply.')),
    ]);
    box(tr('Autoresponder'), [
      setting('autoresponder_enabled', tr('Send autoresponder'), { bool: true }),
      selectControl(doc, tr('Email field'), 'settings.autoresponder_email_field',
        [{ value: '', label: tr('(none)') }].concat(emailFields), s.autoresponder_email_field || '',
        (v) => actions.apply('updateSettings', { autoresponder_email_field: v })),
      setting('autoresponder_subject', tr('Subject')),
      setting('autoresponder_body', tr('Body'), { multiline: true }),
      sendWhenControl(doc, state, config, 'autoresponder_when', s.autoresponder_when || null,
        (group) => actions.apply('updateSettings', { autoresponder_when: group })),
    ]);
    contributorPanels(doc, container, state, config, actions);
  }

  function contributorPanel(doc, contributor, current, actions) {
    const box = el(doc, 'fieldset', { class: 'ffb-admin-group' }, [el(doc, 'legend', { text: contributor.label })]);
    const values = current && typeof current === 'object' ? current : {};
    Object.keys(contributor.spec || {}).forEach((name) => {
      const spec = contributor.spec[name];
      const control = settingControl(doc, name, spec, values[name], (n, v) => {
        actions.commit(contributor.code, Object.assign({}, values, { [n]: v }));
      }, { label: spec.label, key: 'settings.ext.' + contributor.code + '.' + name });
      if (contributor.readOnly) {
        control.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = true; });
      }
      box.appendChild(control);
    });
    if (contributor.readOnly) {
      box.appendChild(el(doc, 'p', {
        class: 'ffb-admin-hint',
        'data-ffb-hint': 'contributor-read-only-' + contributor.code,
        text: tr('You do not have permission to change these settings.'),
      }));
    }
    return box;
  }

  function contributorPanels(doc, container, state, config, actions) {
    const ext = ((state.form.settings || {}).ext) || {};
    (config.settingsContributors || []).forEach((contributor) => {
      container.appendChild(contributorPanel(doc, contributor, ext[contributor.code], {
        commit: (code, values) => actions.apply('updateSettings', { ext: Object.assign({}, ext, { [code]: values }) }),
      }));
    });
  }

  function itemActions(doc, container, id, actions) {
    const bar = el(doc, 'div', { class: 'ffb-admin-actions' });
    bar.appendChild(button(doc, tr('Duplicate'), 'duplicate', () => actions.apply('duplicateItem', id)));
    bar.appendChild(button(doc, tr('Delete'), 'delete', () => actions.apply('removeItem', id)));
    bar.appendChild(button(doc, tr('Form settings'), 'form-settings', () => actions.select(null)));
    container.appendChild(bar);
  }

  function findNode(form, id) {
    const page = form.schema.pages.find((p) => p.id === id);
    if (page) {
      return { kind: 'page', node: page };
    }
    let found = null;
    form.schema.pages.forEach((pg) => {
      (pg.items || []).forEach((item) => {
        if (item.id === id) {
          found = { kind: item.kind, node: item };
        }
        (item.kind === 'section' ? item.items || [] : []).forEach((child) => {
          if (child.id === id) {
            found = { kind: child.kind, node: child };
          }
        });
      });
    });
    return found;
  }

  const FORM_TEXT_LABELS = {
    submit_label: phrase('Submit label'),
    next_label: phrase('Next label'),
    prev_label: phrase('Back label'),
    success_message: phrase('Success message'),
    page_title: phrase('Page title'),
    meta_description: phrase('Meta description'),
    autoresponder_subject: phrase('Autoresponder subject'),
    autoresponder_body: phrase('Autoresponder body'),
    closed_message: phrase('Message after closing'),
    not_open_message: phrase('Message before opening'),
  };
  const PART_LABELS = {
    label: phrase('Label'), help: phrase('Help text'), placeholder: phrase('Placeholder'), title: phrase('Title'), description: phrase('Description'), text: phrase('Text'),
  };

  function humanize(key) {
    const words = String(key).replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
  }

  function partLabel(entry, node, config) {
    const path = entry.path;
    if (path[0] === 'form') {
      return tr(FORM_TEXT_LABELS[path[1]] || humanize(path[1]));
    }
    const rest = path.slice(2);
    if (rest.length === 1) {
      return tr(PART_LABELS[rest[0]] || humanize(rest[0]));
    }
    if (rest[0] === 'options') {
      const opt = ((node && node.options) || []).find((o) => String(o.value) === rest[1]);
      const name = opt && opt.label ? opt.label : rest[1];
      return rest[2] === 'description' ? tr('Description of option "%1"', name) : tr('Option "%1"', name);
    }
    if (rest[0] === 'rules') {
      const rule = ((node && node.rules) || []).find((r) => i18n().ruleKey(r) === rest[1]);
      let name = rest[1];
      if (rule && rule.rule === 'preset') {
        const hit = (config.ruleLibrary || []).find((e) => e.ref === rule.value);
        name = hit ? hit.label : String(rule.value);
      } else if (rule) {
        const hit = (config.rules || []).find((r) => r.code === rule.rule);
        name = hit ? hit.label : rule.rule;
      }
      return tr('Error message: %1', name);
    }
    if (rest[0] === 'settings') {
      return rest.length > 2 ? tr('%1, line %2', humanize(rest[1]), Number(rest[2]) + 1) : humanize(rest[1]);
    }
    return rest.join(' ');
  }

  function translationControl(doc, entry, n, node, config, ctx, actions) {
    const api = i18n();
    const current = api.read(ctx.overlay, entry.path);
    const status = api.status(ctx.overlay, entry);
    const box = el(doc, 'div', { class: 'ffb-admin-translation' + (status === 'outdated' ? ' is-outdated' : ''), 'data-ffb-translation': String(n) });
    const input = entry.multiline ? el(doc, 'textarea', { rows: '3' }) : el(doc, 'input', { type: 'text' });
    input.value = current ? current.t : '';
    input.placeholder = entry.text;
    input.maxLength = entry.max;
    if (!ctx.canEdit) {
      input.readOnly = true;
    }
    input.addEventListener('change', () => {
      actions.translate(entry.path, input.value.trim() === '' ? null : { t: input.value, s: entry.text });
    });
    box.appendChild(wrap(doc, partLabel(entry, node, config), input, 'tr-' + n));
    box.appendChild(el(doc, 'p', { class: 'ffb-admin-hint ffb-admin-translation-default', text: tr('Default: %1', entry.text) }));
    if (status === 'outdated') {
      box.appendChild(el(doc, 'p', { class: 'ffb-admin-translation-review', 'data-ffb-review': String(n),
        text: tr('Needs review: the default text changed from "%1".', current.s) }));
      if (ctx.canEdit) {
        box.appendChild(button(doc, tr('Mark as reviewed'), 'tr-' + n + '-reviewed', () => {
          actions.translate(entry.path, { t: current.t, s: entry.text });
        }));
      }
    }
    return box;
  }

  function renderTranslation(container, state, config, actions, ctx) {
    const doc = container.ownerDocument;
    const target = state.selectedId ? findNode(state.form, state.selectedId) : null;
    const id = target ? target.node.id : null;
    const entries = i18n().collect(state.form.schema, state.form.settings, ctx.typeSpecs)
      .filter((e) => (id ? e.path[0] === 'items' && e.path[1] === id : e.path[0] === 'form'));
    container.appendChild(el(doc, 'h2', { class: 'ffb-admin-panel-title', text: id ? tr('Translate') : tr('Form texts') }));
    container.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: ctx.canEdit
      ? tr('Leave a text empty to use the default.')
      : tr('You can view these translations but not change them.') }));
    if (!entries.length) {
      container.appendChild(el(doc, 'p', { class: 'ffb-admin-empty-note', 'data-ffb-hint': 'no-texts',
        text: id ? tr('This item has no text to translate.') : tr('This form has no texts to translate yet.') }));
    }
    entries.forEach((entry, n) => container.appendChild(translationControl(doc, entry, n, target ? target.node : null, config, ctx, actions)));
    if (id) {
      container.appendChild(button(doc, tr('Form texts'), 'form-settings', () => actions.select(null)));
    } else {
      container.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Select an item on the canvas to translate its texts.') }));
    }
  }

  function render(container, state, config, actions, translation) {
    const doc = container.ownerDocument;
    while (container.firstChild) {
      container.removeChild(container.firstChild);
    }
    if (translation && i18n()) {
      renderTranslation(container, state, config, actions, translation);
      return;
    }
    const target = state.selectedId ? findNode(state.form, state.selectedId) : null;
    if (!target) {
      (config.group ? groupInspector : formInspector)(doc, container, state, config, actions);
      return;
    }
    if (tabState.id !== state.selectedId) {
      tabState.id = state.selectedId;
      tabState.tab = 'general';
    }
    const builders = { field: fieldInspector, content: contentInspector, section: sectionInspector, page: pageInspector };
    builders[target.kind](doc, container, target.node, state, config, actions);
    if (target.kind !== 'page') {
      itemActions(doc, container, target.node.id, actions);
    } else {
      container.appendChild(button(doc, tr('Form settings'), 'form-settings', () => actions.select(null)));
    }
  }

  const api = { render, referenceable, scheduleDefaultZone };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderInspector = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
