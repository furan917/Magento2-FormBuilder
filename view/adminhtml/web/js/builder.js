(function (root) {
  'use strict';

  const APPLY_ALLOWLIST = ['updateItem', 'updatePage', 'updateForm', 'updateSettings', 'updateTranslation', 'removeItem', 'duplicateItem', 'select'];
  // Marks text for the PHP phrase list; it is translated where it is shown.
  const phrase = (text) => text;
  const GROUPS = [['input', phrase('Inputs')], ['choice', phrase('Choices')], ['advanced', phrase('Advanced')]];
  const NOTICE_MS = 6000;
  const HISTORY_LIMIT = 50;
  const WIDTH_CLASSES = { full: 'full', two_thirds: 'two-thirds', half: 'half', third: 'third' };
  const INSPECTOR_FOCUS_KEYS = ['label', 'title', 'text'];

  function el(doc, tag, attrs, children) {
    const node = doc.createElement(tag);
    Object.keys(attrs || {}).forEach((key) => {
      const value = attrs[key];
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'text') {
        node.textContent = value;
      } else {
        node.setAttribute(key, value === true ? '' : String(value));
      }
    });
    (children || []).forEach((child) => child && node.appendChild(child));
    return node;
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function init(rootEl) {
    const win = rootEl.ownerDocument.defaultView;
    const doc = rootEl.ownerDocument;
    const model = win.FuranFormBuilder.builderModel;
    const preview = win.FuranFormBuilder.builderPreview;
    const presets = win.FuranFormBuilder.builderPresets;
    const config = JSON.parse(doc.getElementById('ffb-builder-config').textContent);
    const form = rootEl.closest('form') || doc.getElementById('ffb-admin-form');
    const typeByCode = {};
    (config.fieldTypes || []).forEach((t) => { typeByCode[t.code] = t; });
    const urls = config.urls || {};
    const canSave = !(config.permissions && config.permissions.save === false);
    // Group mode edits the one page of a field group; the form's own pages and settings do not apply.
    const groupMode = Boolean(config.group);
    // Group endpoints are only present for admins who may use them.
    const groupUrls = config.fieldGroups && !Array.isArray(config.fieldGroups) ? config.fieldGroups : {};
    const i18n = win.FuranFormBuilder.builderI18n || null;
    if (i18n) {
      i18n.setPhrases(config.phrases);
    }
    const tr = (text, ...args) => (i18n
      ? i18n.t(text, ...args)
      : String(text).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m)));
    const typeSpecs = {};
    (config.fieldTypes || []).forEach((t) => { typeSpecs[t.code] = t.settingsSpec; });
    // The sticky toolbar covers content scrolling under it, so it takes the admin page's own background.
    rootEl.style.setProperty('--ffb-page-bg', win.getComputedStyle(doc.body).backgroundColor || '#fff');

    let state = model.createState(config.form, config);
    let pageId = state.form.schema.pages[0].id;
    let dragging = null;
    let noticeTimer = null;
    let leaveGuard = true;
    let lastFocus = null;
    let focusInspector = false;
    // Store view being translated; null edits the default texts and the structure.
    let storeView = null;

    const refs = {};

    function setState(next) {
      state = next;
      render();
    }

    function apply(fnName, ...args) {
      if (APPLY_ALLOWLIST.indexOf(fnName) < 0 || typeof model[fnName] !== 'function') {
        throw new Error('Action not allowed: ' + fnName);
      }
      if (!canSave && fnName !== 'select') {
        return;
      }
      const next = model[fnName](state, ...args);
      if (next.lastRemovedRefs && next.lastRemovedRefs.length && next.lastRemovedRefs !== state.lastRemovedRefs) {
        notify(next.lastRemovedRefs.length === 1
          ? tr('1 condition or rule reference to a removed field was also removed.')
          : tr('%1 condition or rule references to removed fields were also removed.', next.lastRemovedRefs.length));
      }
      setState(next);
    }

    const actions = {
      apply,
      select: (id) => apply('select', id),
      notify: (message) => notify(message),
      translate: (path, entry) => apply('updateTranslation', String(storeView), path, entry),
    };

    function translationStores() {
      if (!i18n) {
        return [];
      }
      const ids = (state.form.store_ids || []).map(Number);
      const all = ids.length === 0 || ids.indexOf(0) >= 0;
      return (config.translationStores || []).filter((st) => all || ids.indexOf(Number(st.id)) >= 0);
    }

    function translating() {
      return storeView !== null;
    }

    // The layout cannot change while translating or without save rights.
    function locked() {
      return translating() || !canSave;
    }

    function overlay() {
      return ((state.form.translations || {})[storeView]) || {};
    }

    function shownSchema() {
      return translating() ? i18n.apply(state.form.schema, state.form.settings, overlay(), typeSpecs).schema : state.form.schema;
    }

    function storeLabel(st) {
      return st.locale ? st.name + ' (' + st.locale + ')' : st.name;
    }

    function translationStatus() {
      const byItem = {};
      if (!translating()) {
        return byItem;
      }
      const current = overlay();
      i18n.collect(state.form.schema, state.form.settings, typeSpecs).forEach((entry) => {
        if (entry.path[0] !== 'items') {
          return;
        }
        const id = entry.path[1];
        const st = i18n.status(current, entry);
        const was = byItem[id];
        byItem[id] = was === 'outdated' || st === 'outdated' ? 'outdated' : (was === 'missing' || st === 'missing' ? 'missing' : 'done');
      });
      return byItem;
    }

    function notify(message) {
      refs.notice.textContent = message;
      win.clearTimeout(noticeTimer);
      noticeTimer = win.setTimeout(() => { refs.notice.textContent = ''; }, NOTICE_MS);
    }

    function announce(message) {
      refs.live.textContent = '';
      refs.live.textContent = message;
    }

    function findNode(id) {
      let found = null;
      state.form.schema.pages.forEach((page) => {
        (page.items || []).forEach((item, index) => {
          if (item.id === id) {
            found = { item, list: page.items, page, section: null, index };
          }
          (item.items || []).forEach((child, childIndex) => {
            if (child.id === id) {
              found = { item: child, list: item.items, page, section: item, index: childIndex };
            }
          });
        });
      });
      return found;
    }

    function currentPage() {
      const pages = state.form.schema.pages;
      return pages.find((p) => p.id === pageId) || pages[0];
    }

    function syncPage() {
      const sel = state.selectedId;
      if (sel) {
        const node = findNode(sel);
        const asPage = state.form.schema.pages.find((p) => p.id === sel);
        if (node) {
          pageId = node.page.id;
        } else if (asPage) {
          pageId = asPage.id;
        }
      }
      pageId = currentPage().id;
    }

    function addTarget() {
      const sel = state.selectedId && findNode(state.selectedId);
      if (sel && sel.item.kind === 'section') {
        return { pageId: sel.page.id, sectionId: sel.item.id, index: sel.item.items.length };
      }
      if (sel && sel.section) {
        return { pageId: sel.page.id, sectionId: sel.section.id, index: sel.index + 1 };
      }
      if (sel && sel.page.id === pageId) {
        return { pageId: sel.page.id, sectionId: null, index: sel.index + 1 };
      }
      const page = currentPage();
      return { pageId: page.id, sectionId: null, index: page.items.length };
    }

    function createFromSpec(spec) {
      if (spec.indexOf('field:') === 0) {
        return model.newField(spec.slice(6), config, state);
      }
      if (spec.indexOf('content:') === 0) {
        return model.newContent(spec.slice(8));
      }
      return spec === 'section' ? model.newSection() : null;
    }

    function labelForSpec(spec) {
      const code = spec.split(':')[1];
      if (spec.indexOf('preset:') === 0) {
        const hit = presets.DEFINITIONS.find((p) => p.code === code);
        return hit ? tr(hit.label) : spec;
      }
      const list = spec.indexOf('field:') === 0 ? config.fieldTypes : config.contentTypes;
      const hit = (list || []).find((t) => t.code === code);
      return hit ? hit.label : (spec === 'section' ? tr('Section') : spec);
    }

    function addFromSpec(spec, target) {
      if (spec === 'page') {
        const title = tr('Page %1', state.form.schema.pages.length + 1);
        let next = model.addPage(state, title);
        const pages = next.form.schema.pages;
        pageId = pages[pages.length - 1].id;
        next = model.select(next, pageId);
        setState(next);
        announce(tr('Added %1', title));
        return;
      }
      if (spec.indexOf('preset:') === 0) {
        addPreset(spec.slice(7), target || addTarget());
        return;
      }
      const item = createFromSpec(spec);
      if (!item) {
        return;
      }
      const where = target || addTarget();
      if (item.kind === 'section' && where.sectionId) {
        where.sectionId = null;
        where.index = state.form.schema.pages.find((p) => p.id === where.pageId).items.length;
      }
      const added = model.addItem(state, item, where);
      if (added === state) {
        return;
      }
      pageId = where.pageId;
      focusInspector = true;
      setState(model.select(added, item.id));
      announce(tr('Added %1', labelForSpec(spec)));
    }

    // Inserts all preset items as one undo step; the last one is selected so the next add follows the preset.
    function addPreset(code, where) {
      const items = presets.build(code, model, config, state);
      if (!items || (where.sectionId && items.some((i) => i.kind === 'section'))) {
        return;
      }
      let next = state;
      items.forEach((item, i) => {
        next = model.addItem(next, item, { pageId: where.pageId, sectionId: where.sectionId, index: where.index + i });
      });
      if (next === state) {
        return;
      }
      next = Object.assign({}, next, { past: state.past.concat([structuredClone(state.form)]).slice(-HISTORY_LIMIT) });
      pageId = where.pageId;
      focusInspector = true;
      setState(model.select(next, items[items.length - 1].id));
      announce(tr('Added %1 item(s)', items.length));
    }

    function moveBy(itemId, dir) {
      const node = findNode(itemId);
      if (!node) {
        return;
      }
      const pageItems = node.page.items;
      let target;
      const index = node.list.indexOf(node.item);
      const last = node.list.length - 1;
      if (dir < 0 && index > 0) {
        target = { pageId: node.page.id, sectionId: node.section ? node.section.id : null, index: index - 1 };
      } else if (dir > 0 && index < last) {
        target = { pageId: node.page.id, sectionId: node.section ? node.section.id : null, index: index + 1 };
      } else if (node.section) {
        const at = pageItems.indexOf(node.section);
        target = { pageId: node.page.id, sectionId: null, index: dir < 0 ? at : at + 1 };
      } else {
        return;
      }
      const moved = model.moveItem(state, itemId, target);
      lastFocus = '[data-ffb-card="' + itemId + '"] > .ffb-admin-card-actions [data-ffb-act="' + (dir < 0 ? 'up' : 'down') + '"]';
      setState(moved);
      const now = findNode(itemId);
      announce(tr('Moved to position %1 of %2', now.list.indexOf(now.item) + 1, now.list.length));
    }

    function canMove(node, dir) {
      const index = node.list.indexOf(node.item);
      if (dir < 0) {
        return index > 0 || !!node.section;
      }
      return index < node.list.length - 1 || !!node.section;
    }

    function dragCards(listEl) {
      const dragId = dragging && dragging.id;
      return Array.from(listEl.children).filter((c) => c.hasAttribute('data-ffb-card') && c.getAttribute('data-ffb-card') !== dragId);
    }

    // Cards sit in a 6-column grid: a card is "before" the pointer when the pointer is below it, or beside it and past its midpoint.
    function dropIndex(listEl, clientY, clientX) {
      let index = 0;
      dragCards(listEl).forEach((card) => {
        const rect = card.getBoundingClientRect();
        const below = clientY > rect.bottom;
        const sameRow = clientY >= rect.top && clientY <= rect.bottom;
        if (below || (sameRow && clientX > rect.left + rect.width / 2)) {
          index += 1;
        }
      });
      return index;
    }

    function clearIndicators() {
      doc.querySelectorAll('.ffb-admin-drop-active').forEach((n) => n.classList.remove('ffb-admin-drop-active'));
      doc.querySelectorAll('.ffb-admin-drop-before,.ffb-admin-drop-after').forEach((n) => n.classList.remove('ffb-admin-drop-before', 'ffb-admin-drop-after'));
    }

    function showIndicator(listEl, clientY, clientX) {
      clearIndicators();
      listEl.classList.add('ffb-admin-drop-active');
      const cards = dragCards(listEl);
      const index = dropIndex(listEl, clientY, clientX);
      if (cards[index]) {
        cards[index].classList.add('ffb-admin-drop-before');
      } else if (cards.length) {
        cards[cards.length - 1].classList.add('ffb-admin-drop-after');
      }
    }

    function listTarget(listEl, clientY, clientX) {
      return {
        pageId: listEl.getAttribute('data-ffb-page'),
        sectionId: listEl.getAttribute('data-ffb-section') || null,
        index: dropIndex(listEl, clientY, clientX),
      };
    }

    function wireDropZone(listEl) {
      listEl.addEventListener('dragover', (e) => {
        if (!dragging) {
          return;
        }
        if (isDraggingSection() && listEl.getAttribute('data-ffb-section')) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        showIndicator(listEl, e.clientY, e.clientX);
      });
      listEl.addEventListener('drop', (e) => {
        if (!dragging) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        const target = listTarget(listEl, e.clientY, e.clientX);
        const payload = dragging;
        dragging = null;
        clearIndicators();
        if (payload.id) {
          const moved = model.moveItem(state, payload.id, target);
          if (moved !== state) {
            setState(model.select(moved, payload.id));
            announce(tr('Item moved'));
          }
        } else {
          addFromSpec(payload.spec, target);
        }
      });
      listEl.addEventListener('dragleave', (e) => {
        if (!listEl.contains(e.relatedTarget)) {
          listEl.classList.remove('ffb-admin-drop-active');
        }
      });
    }

    function isDraggingSection() {
      const hit = dragging && dragging.id && findNode(dragging.id);
      return !!(dragging && (dragging.spec === 'section' || (hit && hit.item.kind === 'section')));
    }

    function dropOnPage(payload, targetPageId) {
      const page = state.form.schema.pages.find((p) => p.id === targetPageId);
      if (!page) {
        return;
      }
      const target = { pageId: page.id, sectionId: null, index: page.items.length };
      if (payload.id) {
        const node = findNode(payload.id);
        if (node && node.page.id === page.id && !node.section) {
          return;
        }
        const moved = model.moveItem(state, payload.id, target);
        if (moved !== state) {
          setState(model.select(moved, payload.id));
          announce(tr('Moved to %1', page.title || tr('page')));
        }
      } else {
        addFromSpec(payload.spec, target);
      }
    }

    function wireTabDrop(tab, page) {
      tab.addEventListener('dragover', (e) => {
        if (!dragging) {
          return;
        }
        e.preventDefault();
        clearIndicators();
        tab.classList.add('ffb-admin-drop-active');
      });
      tab.addEventListener('dragleave', () => tab.classList.remove('ffb-admin-drop-active'));
      tab.addEventListener('drop', (e) => {
        if (!dragging) {
          return;
        }
        e.preventDefault();
        const payload = dragging;
        dragging = null;
        clearIndicators();
        dropOnPage(payload, page.id);
      });
    }

    function startDrag(e, payload) {
      dragging = payload;
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'copyMove';
        e.dataTransfer.setData('text/plain', payload.id || payload.spec);
      }
    }

    function endDrag() {
      dragging = null;
      clearIndicators();
    }

    function openDialog(opts) {
      const previous = doc.activeElement;
      const titleId = 'ffb-dialog-title';
      const body = el(doc, 'div', { class: 'ffb-admin-dialog-body' });
      (opts.body ? [opts.body] : []).forEach((n) => body.appendChild(n));
      const error = el(doc, 'p', { class: 'ffb-admin-dialog-error', role: 'alert', 'data-ffb-dialog-error': true });
      const confirm = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn ffb-admin-btn-primary', 'data-ffb-dialog-confirm': true, text: opts.confirmLabel || tr('OK') });
      const cancel = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn', 'data-ffb-dialog-cancel': true, text: opts.cancelLabel || tr('Cancel') });
      const box = el(doc, 'div', { class: 'ffb-admin-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, 'data-ffb-dialog': true }, [
        el(doc, 'h2', { id: titleId, class: 'ffb-admin-dialog-title', text: opts.title }),
        opts.message ? el(doc, 'p', { text: opts.message }) : null,
        body, error,
        el(doc, 'div', { class: 'ffb-admin-dialog-actions' }, opts.hideConfirm ? [cancel] : [cancel, confirm]),
      ]);
      const overlay = el(doc, 'div', { class: 'ffb-admin-overlay' }, [box]);

      function close() {
        doc.removeEventListener('keydown', onKey, true);
        if (overlay.parentNode) {
          overlay.parentNode.removeChild(overlay);
        }
        if (previous && previous.focus && doc.contains(previous)) {
          previous.focus();
        }
      }
      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
        } else if (e.key === 'Tab') {
          const items = Array.from(box.querySelectorAll('button, textarea, input, select')).filter((n) => !n.disabled);
          const first = items[0];
          const lastItem = items[items.length - 1];
          if (!box.contains(doc.activeElement)) {
            e.preventDefault();
            first.focus();
          } else if (e.shiftKey && doc.activeElement === first) {
            e.preventDefault();
            lastItem.focus();
          } else if (!e.shiftKey && doc.activeElement === lastItem) {
            e.preventDefault();
            first.focus();
          }
        }
      }
      cancel.addEventListener('click', close);
      confirm.addEventListener('click', () => {
        const result = opts.onConfirm ? opts.onConfirm({ showError: (m) => { error.textContent = m; } }) : true;
        if (result !== false) {
          close();
        }
      });
      doc.addEventListener('keydown', onKey, true);
      refs.root.appendChild(overlay);
      const focusTarget = opts.focus ? box.querySelector(opts.focus) : cancel;
      (focusTarget || cancel).focus();
      return close;
    }

    function specIcon(spec) {
      const [kind, code] = spec.split(':');
      return preview.icon(doc, kind === 'field' || kind === 'content' ? code : kind);
    }

    function typeButton(spec, label, extra) {
      const btn = el(doc, 'button', Object.assign({ type: 'button', class: 'ffb-admin-palette-item', 'data-ffb-label': label.toLowerCase() }, extra), [
        specIcon(spec),
        el(doc, 'span', { class: 'ffb-admin-palette-label', text: label }),
      ]);
      return btn;
    }

    function paletteButton(spec, label) {
      const btn = typeButton(spec, label, { draggable: 'true', 'data-ffb-add': spec, 'data-ffb-focus': 'add:' + spec });
      btn.addEventListener('click', () => addFromSpec(spec));
      btn.addEventListener('dragstart', (e) => startDrag(e, { spec }));
      btn.addEventListener('dragend', endDrag);
      return btn;
    }

    // Groups of [spec, label] entries shared by the palette and the type picker.
    function catalog(inSection) {
      const types = config.fieldTypes || [];
      const known = GROUPS.map(([cat]) => cat);
      const groups = [];
      GROUPS.concat([['other', phrase('Other')]]).forEach(([cat, english]) => {
        const title = tr(english);
        const items = types.filter((t) => (cat === 'other' ? known.indexOf(t.category) < 0 : t.category === cat));
        if (items.length) {
          groups.push({ code: cat, title, entries: items.map((t) => ['field:' + t.code, t.label]) });
        }
      });
      const layout = inSection ? [] : [['section', tr('Section')]];
      groups.push({
        code: 'layout', title: tr('Layout'),
        entries: layout.concat((config.contentTypes || []).map((t) => ['content:' + t.code, t.label])),
      });
      const presetEntries = presets.available(config)
        .filter((p) => !(inSection && p.hasSection))
        .map((p) => ['preset:' + p.code, tr(p.label)]);
      if (presetEntries.length) {
        groups.push({ code: 'presets', title: tr('Presets'), entries: presetEntries });
      }
      return groups;
    }

    function paletteGroup(idPrefix, code, title, buttons) {
      const headingId = idPrefix + code;
      return el(doc, 'div', { class: 'ffb-admin-palette-group', 'data-ffb-group': code, role: 'group', 'aria-labelledby': headingId }, [
        el(doc, 'h3', { id: headingId, class: 'ffb-admin-palette-title', text: title }),
        el(doc, 'div', { class: 'ffb-admin-palette-list' }, buttons),
      ]);
    }

    function filterGroups(container, query) {
      const q = query.trim().toLowerCase();
      container.querySelectorAll('.ffb-admin-palette-group').forEach((group) => {
        let visible = 0;
        group.querySelectorAll('.ffb-admin-palette-item').forEach((btn) => {
          const show = q === '' || btn.getAttribute('data-ffb-label').indexOf(q) >= 0;
          btn.hidden = !show;
          visible += show ? 1 : 0;
        });
        group.hidden = visible === 0;
      });
    }

    function searchInput(label, onInput) {
      const input = el(doc, 'input', { type: 'search', class: 'ffb-admin-input ffb-admin-search', 'aria-label': label, placeholder: label, autocomplete: 'off' });
      input.addEventListener('input', () => onInput(input.value));
      return input;
    }

    function buildPalette() {
      const wrap = refs.palette;
      wrap.appendChild(el(doc, 'h2', { class: 'ffb-admin-panel-title', text: groupMode ? tr('Add to group') : tr('Add to form') }));
      wrap.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Click or press Enter to add. You can also drag items onto the canvas.') }));
      const search = searchInput(tr('Search field types'), (value) => {
        filterGroups(groups, value);
      });
      search.setAttribute('data-ffb-palette-search', '');
      const groups = el(doc, 'div', { class: 'ffb-admin-palette-groups' });
      catalog(false).forEach((g) => {
        if (g.code === 'layout' && !groupMode) {
          g.entries.push(['page', tr('Page break')]);
        }
        groups.appendChild(paletteGroup('ffb-palette-', g.code, g.title, g.entries.map(([spec, label]) => paletteButton(spec, label))));
      });
      wrap.appendChild(search);
      wrap.appendChild(groups);
      if (groupUrls.list && groupUrls.insert && canSave) {
        const open = el(doc, 'button', { type: 'button', class: 'ffb-admin-palette-item', 'data-ffb-insert-group': true, 'data-ffb-focus': 'insert-group' }, [
          preview.icon(doc, 'cards'),
          el(doc, 'span', { class: 'ffb-admin-palette-label', text: tr('Insert a field group...') }),
        ]);
        open.addEventListener('click', insertGroupDialog);
        wrap.appendChild(paletteGroup('ffb-palette-', 'groups', tr('Field groups'), [open]));
      }
    }

    function postGroupJson(url, params) {
      const key = form.querySelector('input[name="form_key"]');
      const body = new URLSearchParams(Object.assign({ form_key: key ? key.value : '' }, params));
      return win.fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body,
      }).then((response) => response.json().catch(() => ({ success: false })))
        .then((data) => (data && typeof data === 'object' ? data : { success: false }))
        .catch(() => ({ success: false }));
    }

    function problems(data) {
      const list = Array.isArray(data.errors) ? data.errors.filter((e) => typeof e === 'string' && e !== '') : [];
      return list.length ? list.join(' ') : tr('Something went wrong. Please try again.');
    }

    function saveGroupDialog(item) {
      const name = el(doc, 'input', { type: 'text', class: 'ffb-admin-input', name: 'group_name', maxlength: '120', required: true, autocomplete: 'off', id: 'ffb-group-name' });
      const description = el(doc, 'textarea', { class: 'ffb-admin-input', name: 'group_description', rows: '3', maxlength: '500', id: 'ffb-group-description' });
      const body = el(doc, 'div', {}, [
        el(doc, 'label', { class: 'ffb-admin-dialog-label', for: 'ffb-group-name', text: tr('Name') }), name,
        el(doc, 'label', { class: 'ffb-admin-dialog-label', for: 'ffb-group-description', text: tr('Description') }), description,
      ]);
      let pending = false;
      const close = openDialog({
        title: tr('Save as group'), body, confirmLabel: tr('Save group'), focus: '#ffb-group-name',
        onConfirm: ({ showError }) => {
          if (pending) {
            return false;
          }
          if (name.value.trim() === '') {
            showError(tr('Enter a name.'));
            name.focus();
            return false;
          }
          const group = model.extractGroup(state, [item.id]);
          pending = true;
          postGroupJson(groupUrls.create, {
            payload: JSON.stringify({ name: name.value, description: description.value, fields: group.fields, translations: group.translations }),
          }).then((data) => {
            pending = false;
            if (!data.success) {
              showError(problems(data));
              return;
            }
            close();
            notify(tr('Saved as the group "%1".', data.name || name.value)
              + (group.removed.length === 1 ? ' ' + tr('1 reference to a field outside the selection was left out of the group.')
                : group.removed.length ? ' ' + tr('%1 references to fields outside the selection were left out of the group.', group.removed.length) : ''));
          });
          return false;
        },
      });
    }

    function insertGroupDialog() {
      const list = el(doc, 'div', { class: 'ffb-admin-palette-list ffb-admin-group-choices', role: 'group', 'aria-label': tr('Field groups') });
      const status = el(doc, 'p', { class: 'ffb-admin-hint', role: 'status', text: tr('Loading field groups...') });
      const body = el(doc, 'div', {}, [status, list]);
      let more = null;
      let showError = () => {};
      const close = openDialog({ title: tr('Insert a field group'), body, hideConfirm: true, cancelLabel: tr('Close'), onConfirm: () => true });
      showError = (message) => {
        const box = doc.querySelector('[data-ffb-dialog-error]');
        if (box) {
          box.textContent = message;
        }
      };

      let choosing = false;
      function choose(group) {
        if (choosing) {
          return;
        }
        choosing = true;
        list.setAttribute('aria-busy', 'true');
        list.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        status.textContent = tr('Inserting...');
        const release = (message) => {
          choosing = false;
          list.removeAttribute('aria-busy');
          list.querySelectorAll('button').forEach((b) => { b.disabled = false; });
          status.textContent = '';
          showError(message);
        };
        postGroupJson(groupUrls.insert, { id: String(group.id) }).then((data) => {
          if (!data.success) {
            release(problems(data));
            return;
          }
          const where = addTarget();
          const next = model.insertGroup(state, data, where);
          if (next === state) {
            release(tr('This group cannot be placed here.'));
            return;
          }
          close();
          pageId = where.pageId;
          focusInspector = true;
          setState(next);
          announce(tr('Inserted the group "%1".', group.name));
          notify(tr('Inserted the group "%1".', group.name));
        });
      }

      function load(after) {
        postGroupJson(groupUrls.list, after ? { after: String(after) } : {}).then((data) => {
          if (more) {
            more.remove();
            more = null;
          }
          if (!data.success) {
            status.textContent = problems(data);
            return;
          }
          status.textContent = data.groups.length || list.children.length ? '' : tr('No field groups yet. Use "Save as group" on a field or section.');
          let firstNew = null;
          data.groups.forEach((group) => {
            const button = el(doc, 'button', { type: 'button', class: 'ffb-admin-palette-item', 'data-ffb-group-id': String(group.id) }, [
              el(doc, 'span', { class: 'ffb-admin-palette-label', text: group.name }),
              group.description ? el(doc, 'span', { class: 'ffb-admin-card-desc', text: group.description }) : null,
            ].filter(Boolean));
            button.addEventListener('click', () => choose(group));
            list.appendChild(button);
            firstNew = firstNew || button;
          });
          if (data.next) {
            more = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn', 'data-ffb-groups-more': true, text: tr('Load more') });
            more.addEventListener('click', () => load(data.next));
            body.appendChild(more);
          }
          if (firstNew) {
            firstNew.focus();
          } else if (after) {
            body.closest('[data-ffb-dialog]').querySelector('[data-ffb-dialog-cancel]').focus();
          }
        });
      }
      load(0);
    }

    function openPicker(where, opener) {
      const previous = opener || doc.activeElement;
      const titleId = 'ffb-picker-title';
      const groups = el(doc, 'div', { class: 'ffb-admin-palette-groups' });
      const search = searchInput(tr('Search field types'), (value) => filterGroups(groups, value));
      search.setAttribute('data-ffb-picker-search', '');
      const box = el(doc, 'div', { class: 'ffb-admin-dialog ffb-admin-picker', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, 'data-ffb-picker': true }, [
        el(doc, 'h2', { id: titleId, class: 'ffb-admin-dialog-title', text: tr('Add field') }),
        search, groups,
        el(doc, 'div', { class: 'ffb-admin-dialog-actions' }, []),
      ]);
      const overlay = el(doc, 'div', { class: 'ffb-admin-overlay' }, [box]);
      const cancel = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn', 'data-ffb-picker-cancel': true, text: tr('Cancel') });
      box.lastChild.appendChild(cancel);

      function close() {
        doc.removeEventListener('keydown', onKey, true);
        if (overlay.parentNode) {
          overlay.parentNode.removeChild(overlay);
        }
        if (previous && previous.focus && doc.contains(previous)) {
          previous.focus();
        }
      }
      function visible() {
        return Array.from(box.querySelectorAll('button, input')).filter((n) => !n.disabled && !n.closest('[hidden]') && !n.hidden);
      }
      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
        } else if (e.key === 'Enter' && e.target === search) {
          const first = groups.querySelector('.ffb-admin-palette-item:not([hidden])');
          if (first) {
            e.preventDefault();
            first.click();
          }
        } else if (e.key === 'Tab') {
          const items = visible();
          if (e.shiftKey && doc.activeElement === items[0]) {
            e.preventDefault();
            items[items.length - 1].focus();
          } else if (!e.shiftKey && doc.activeElement === items[items.length - 1]) {
            e.preventDefault();
            items[0].focus();
          }
        }
      }
      catalog(!!where.sectionId).forEach((g) => {
        const buttons = g.entries.map(([spec, label]) => {
          const btn = typeButton(spec, label, { 'data-ffb-pick': spec });
          btn.addEventListener('click', () => {
            close();
            addFromSpec(spec, { pageId: where.pageId, sectionId: where.sectionId, index: where.index });
          });
          return btn;
        });
        groups.appendChild(paletteGroup('ffb-picker-', g.code, g.title, buttons));
      });
      cancel.addEventListener('click', close);
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
          close();
        }
      });
      doc.addEventListener('keydown', onKey, true);
      refs.root.appendChild(overlay);
      search.focus();
    }

    function badge(text, cls) {
      return el(doc, 'span', { class: 'ffb-admin-badge' + (cls ? ' ' + cls : ''), text });
    }

    function widthLabel(value) {
      const hit = (config.widths || []).find((w) => w.value === value);
      return hit ? hit.label : value;
    }

    function cardTitle(item) {
      if (item.kind === 'field') {
        return item.label || tr('(no label) %1', item.name);
      }
      if (item.kind === 'section') {
        return item.title || tr('(untitled section)');
      }
      if (item.type === 'heading' || item.type === 'paragraph' || item.type === 'rich_text' || item.type === 'notice') {
        const text = String(item.text || '');
        return text.length > 80 ? text.slice(0, 77) + '...' : (text || tr('(empty)'));
      }
      if (item.type === 'summary') {
        return String(item.title || tr('Summary'));
      }
      return item.type === 'divider' ? tr('Divider') : tr('Spacer');
    }

    function typeName(item) {
      if (item.kind === 'field') {
        return (typeByCode[item.type] || {}).label || item.type;
      }
      if (item.kind === 'section') {
        return tr('Section');
      }
      const hit = (config.contentTypes || []).find((t) => t.code === item.type);
      return hit ? hit.label : item.type;
    }

    function actionButton(item, act, label, glyph, handler, disabled) {
      const btn = el(doc, 'button', {
        type: 'button', class: 'ffb-admin-icon-btn', 'data-ffb-act': act, 'aria-label': label + ': ' + cardTitle(item),
        title: label, disabled: disabled, text: glyph,
      });
      btn.addEventListener('click', handler);
      return btn;
    }

    function widthClass(item) {
      return 'ffb-admin-w-' + (item.kind === 'section' ? 'full' : (WIDTH_CLASSES[item.width] || 'full'));
    }

    function cycleWidth(item) {
      const widths = (config.widths || []).map((w) => w.value);
      if (!widths.length) {
        return;
      }
      const next = widths[(widths.indexOf(item.width) + 1) % widths.length];
      lastFocus = '[data-ffb-card="' + item.id + '"] > .ffb-admin-card-actions [data-ffb-act="width"]';
      apply('updateItem', item.id, { width: next });
      announce(tr('Width: %1', widthLabel(next)));
    }

    function moveTargets(item) {
      const out = [];
      state.form.schema.pages.forEach((page, i) => {
        const pageTitle = page.title || tr('Page %1', i + 1);
        out.push({ label: pageTitle, pageId: page.id, sectionId: null, list: page.items });
        if (item.kind !== 'section') {
          page.items.filter((x) => x.kind === 'section').forEach((sec) => {
            out.push({ label: pageTitle + ' > ' + (sec.title || tr('(untitled section)')), pageId: page.id, sectionId: sec.id, list: sec.items });
          });
        }
      });
      return out;
    }

    function moveToDialog(item) {
      const targets = moveTargets(item);
      const select = el(doc, 'select', { class: 'ffb-admin-input', 'aria-label': tr('Destination'), 'data-ffb-moveto-select': true },
        targets.map((t, i) => el(doc, 'option', { value: String(i), text: t.label })));
      const current = targets.findIndex((t) => t.list.some((x) => x.id === item.id));
      select.value = String(Math.max(current, 0));
      openDialog({
        title: tr('Move to'), message: tr('Move "%1" to the end of:', cardTitle(item)), body: select, confirmLabel: tr('Move'), focus: 'select',
        onConfirm: () => {
          const t = targets[Number(select.value)];
          const remaining = t.list.filter((x) => x.id !== item.id).length;
          const moved = model.moveItem(state, item.id, { pageId: t.pageId, sectionId: t.sectionId, index: remaining });
          if (moved !== state) {
            pageId = t.pageId;
            lastFocus = '[data-ffb-card="' + item.id + '"] > .ffb-admin-card-actions [data-ffb-act="moveto"]';
            setState(model.select(moved, item.id));
            announce(tr('Moved to %1', t.label));
          }
        },
      });
    }

    function labelRow(item) {
      const row = el(doc, 'div', { class: 'ffb-admin-preview-label' });
      row.appendChild(el(doc, 'span', { text: item.label || tr('(no label) %1', item.name) }));
      if (item.required) {
        row.appendChild(el(doc, 'span', { class: 'ffb-admin-required', 'aria-hidden': 'true', text: '*' }));
      }
      return row;
    }

    function customPreview(item) {
      const registry = win.FuranFormBuilder.builderRegistry;
      const hook = registry && registry.getPreview(item.type);
      if (!hook) {
        return null;
      }
      try {
        const node = hook(item);
        return node && typeof node.nodeType === 'number' ? node : null;
      } catch (e) {
        return null;
      }
    }

    function buildPreview(item) {
      const wrap = el(doc, 'div', { class: 'ffb-admin-preview' });
      if (item.kind === 'content') {
        wrap.appendChild(preview.content(doc, item));
        return wrap;
      }
      const ownLabel = preview.hasOwnLabel(item);
      if (!ownLabel) {
        wrap.appendChild(labelRow(item));
      }
      const mock = customPreview(item) || preview.control(doc, item, typeByCode[item.type]);
      if (ownLabel && item.required) {
        mock.appendChild(el(doc, 'span', { class: 'ffb-admin-required', text: '*' }));
      }
      wrap.appendChild(mock);
      if (item.help) {
        wrap.appendChild(el(doc, 'div', { class: 'ffb-admin-preview-help', text: item.help }));
      }
      return wrap;
    }

    function buildBadges(item) {
      const status = translating() ? (refs.translationStatus || {})[item.id] : null;
      return el(doc, 'span', { class: 'ffb-admin-badges' }, [
        status === 'outdated' ? badge(tr('Needs review'), 'ffb-admin-badge-review') : null,
        status === 'missing' ? badge(tr('Not translated'), 'ffb-admin-badge-missing') : null,
        badge(typeName(item), 'ffb-admin-badge-type'),
        item.type === 'hidden' ? badge(tr('Hidden'), 'ffb-admin-badge-hidden') : null,
        item.condition ? badge(tr('Conditional'), 'ffb-admin-badge-cond') : null,
        (item.rules || []).length ? badge(item.rules.length === 1 ? tr('1 rule') : tr('%1 rules', item.rules.length), 'ffb-admin-badge-rules') : null,
        item.width && item.kind !== 'section' ? badge(widthLabel(item.width)) : null,
      ]);
    }

    function isUnavailable(item) {
      return item.kind === 'field' && !typeByCode[item.type];
    }

    function buildToolbarFor(item, node) {
      if (isUnavailable(item)) {
        return el(doc, 'div', { class: 'ffb-admin-card-actions' }, [
          actionButton(item, 'up', tr('Move up'), '↑', () => moveBy(item.id, -1), !canMove(node, -1)),
          actionButton(item, 'down', tr('Move down'), '↓', () => moveBy(item.id, 1), !canMove(node, 1)),
          actionButton(item, 'moveto', tr('Move to...'), '⇄', () => moveToDialog(item)),
          actionButton(item, 'del', tr('Delete'), '×', () => {
            lastFocus = '[data-ffb-tab][aria-selected="true"]';
            apply('removeItem', item.id);
            announce(tr('Deleted'));
          }),
        ]);
      }
      const buttons = [
        actionButton(item, 'up', tr('Move up'), '↑', () => moveBy(item.id, -1), !canMove(node, -1)),
        actionButton(item, 'down', tr('Move down'), '↓', () => moveBy(item.id, 1), !canMove(node, 1)),
      ];
      if (item.kind !== 'section') {
        buttons.push(actionButton(item, 'width', tr('Change width (%1)', widthLabel(item.width)), '↔', () => cycleWidth(item)));
      }
      buttons.push(
        actionButton(item, 'moveto', tr('Move to...'), '⇄', () => moveToDialog(item)),
        groupUrls.create ? actionButton(item, 'savegroup', tr('Save as group'), '☆', () => saveGroupDialog(item)) : null,
        actionButton(item, 'dup', tr('Duplicate'), '⧉', () => {
          lastFocus = null;
          apply('duplicateItem', item.id);
        }),
        actionButton(item, 'del', tr('Delete'), '×', () => {
          const next = node.list[node.list.indexOf(item) + 1] || node.list[node.list.indexOf(item) - 1];
          lastFocus = next ? '[data-ffb-card="' + next.id + '"] [data-ffb-select]' : '[data-ffb-tab][aria-selected="true"]';
          apply('removeItem', item.id);
          announce(tr('Deleted'));
        })
      );
      return el(doc, 'div', { class: 'ffb-admin-card-actions' }, buttons.filter(Boolean));
    }

    function buildUnavailablePreview(item) {
      return el(doc, 'div', { class: 'ffb-admin-preview ffb-admin-preview-unavailable' }, [
        el(doc, 'span', { text: tr('This field type is unavailable (%1). The field is kept as it is and is not shown to visitors.', item.type) }),
      ]);
    }

    function buildCard(item, node) {
      const unavailable = isUnavailable(item);
      const selected = state.selectedId === item.id;
      const isSection = item.kind === 'section';
      const required = item.required ? [el(doc, 'span', { class: 'ffb-admin-sr', text: ' (required)' })] : [];
      const main = el(doc, 'button', {
        type: 'button', class: 'ffb-admin-card-main', 'data-ffb-select': true, 'aria-pressed': selected ? 'true' : 'false',
        disabled: unavailable,
      }, isSection
        ? [
          el(doc, 'span', { class: 'ffb-admin-card-title', text: cardTitle(item) }),
          item.description ? el(doc, 'span', { class: 'ffb-admin-card-desc', text: item.description }) : null,
          buildBadges(item),
        ]
        : [el(doc, 'span', { class: 'ffb-admin-sr', text: cardTitle(item) + ', ' + typeName(item) }), ...required]);
      main.addEventListener('click', () => apply('select', item.id));
      const card = el(doc, 'div', {
        class: 'ffb-admin-card ffb-admin-card-' + item.kind + ' ' + widthClass(item) + (selected ? ' is-selected' : '')
          + (unavailable ? ' ffb-admin-card-unavailable' : ''),
        'data-ffb-card': item.id, draggable: locked() ? null : 'true',
      }, isSection
        ? [main, locked() ? null : buildToolbarFor(item, node)]
        : [main, unavailable ? buildUnavailablePreview(item) : buildPreview(item), buildBadges(item), locked() ? null : buildToolbarFor(item, node)]);
      card.addEventListener('dragstart', (e) => {
        e.stopPropagation();
        if (locked()) {
          e.preventDefault();
          return;
        }
        startDrag(e, { id: item.id });
      });
      card.addEventListener('dragend', endDrag);
      if (isSection) {
        card.appendChild(buildList(node.page, item));
      }
      return card;
    }

    function addFieldButton(page, section) {
      const items = section ? section.items : page.items;
      const btn = el(doc, 'button', {
        type: 'button', class: 'ffb-admin-add-field', 'data-ffb-add-field': section ? section.id : page.id,
        'data-ffb-focus': 'add-field:' + (section ? section.id : page.id), text: tr('+ Add field'),
      });
      btn.addEventListener('click', () => openPicker({ pageId: page.id, sectionId: section ? section.id : null, index: items.length }, btn));
      return btn;
    }

    function buildList(page, section) {
      const items = section ? section.items : page.items;
      const list = el(doc, 'div', {
        class: 'ffb-admin-list' + (section ? ' ffb-admin-list-section' : ''),
        'data-ffb-list': section ? section.id : page.id,
        'data-ffb-page': page.id, 'data-ffb-section': section ? section.id : null,
      });
      if (!items.length) {
        list.appendChild(el(doc, 'p', { class: 'ffb-admin-empty', text: section ? tr('Drop items into this section.') : tr('This page is empty. Add items from the left or drag them here.') }));
      }
      items.forEach((item) => {
        const node = findNode(item.id);
        list.appendChild(buildCard(item, node));
      });
      if (!locked()) {
        list.appendChild(addFieldButton(page, section));
        wireDropZone(list);
      }
      return list;
    }

    function pageButton(text, attr, handler, disabled, label) {
      const btn = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn ffb-admin-btn-outline', disabled, 'aria-label': label || text, text });
      btn.setAttribute(attr, '');
      btn.addEventListener('click', handler);
      return btn;
    }

    // Clears a selection that lives on another page so the page switch is not undone by syncPage.
    function gotoPage(id) {
      const sel = state.selectedId;
      const node = sel && findNode(sel);
      const onTarget = sel === id || (node && node.page.id === id);
      pageId = id;
      lastFocus = '[data-ffb-tab="' + id + '"]';
      if (sel && !onTarget) {
        setState(model.select(state, null));
      } else {
        render();
      }
    }

    function buildTabs() {
      const pages = shownSchema().pages;
      const tabs = el(doc, 'div', { class: 'ffb-admin-pagetabs', role: 'tablist', 'aria-label': tr('Form pages') });
      pages.forEach((page, i) => {
        const active = page.id === pageId;
        const tab = el(doc, 'button', {
          type: 'button', role: 'tab', class: 'ffb-admin-pagetab' + (active ? ' is-active' : ''), 'data-ffb-tab': page.id,
          'aria-selected': active ? 'true' : 'false', tabindex: active ? '0' : '-1', text: page.title || tr('Page %1', i + 1),
        });
        tab.addEventListener('click', () => gotoPage(page.id));
        tab.addEventListener('keydown', (e) => {
          const step = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0);
          if (step) {
            e.preventDefault();
            const target = pages[(i + step + pages.length) % pages.length];
            gotoPage(target.id);
          }
        });
        if (!locked()) {
          wireTabDrop(tab, page);
        }
        tabs.appendChild(tab);
      });
      return tabs;
    }

    function buildPageBar(page) {
      const pages = state.form.schema.pages;
      const index = pages.indexOf(page);
      const title = el(doc, 'input', { type: 'text', class: 'ffb-admin-input', 'data-ffb-page-title': true, 'data-ffb-focus': 'page-title', value: page.title, 'aria-label': tr('Page title'), maxlength: 255 });
      title.value = page.title;
      title.disabled = !canSave;
      title.addEventListener('change', () => apply('updatePage', page.id, { title: title.value }));
      const del = pageButton(tr('Delete page'), 'data-ffb-page-delete', () => {
        openDialog({
          title: tr('Delete page'), message: tr('Delete "%1" and everything on it?', page.title || tr('this page')), confirmLabel: tr('Delete'),
          onConfirm: () => {
            const next = model.removePage(state, page.id);
            const refs = next.lastRemovedRefs || [];
            const skips = refs.filter((r) => r.kind === 'skip').length;
            const notes = [
              refs.length > skips ? tr('Removed conditions and rules that used fields on the deleted page.') : '',
              skips === 1 ? tr('Removed the skip ahead to the deleted page.') : '',
              skips > 1 ? tr('Removed %1 skip aheads to the deleted page.', skips) : '',
            ].filter(Boolean);
            if (notes.length) {
              notify(notes.join(' '));
            }
            lastFocus = '[data-ffb-tab][aria-selected="true"]';
            pageId = pages[Math.max(0, index - 1)] === page ? pages[1].id : pages[Math.max(0, index - 1)].id;
            setState(next);
          },
        });
      }, pages.length <= 1);
      del.classList.add('ffb-admin-btn-danger');
      return el(doc, 'div', { class: 'ffb-admin-pagebar' }, [
        el(doc, 'label', { class: 'ffb-admin-pagetitle' }, [el(doc, 'span', { text: tr('Page title') }), title]),
        pageButton(tr('Page settings'), 'data-ffb-page-settings', () => apply('select', page.id)),
      ].concat(canSave ? [
        pageButton('←', 'data-ffb-page-left', () => movePage(page, -1), index === 0, tr('Move page left')),
        pageButton('→', 'data-ffb-page-right', () => movePage(page, 1), index === pages.length - 1, tr('Move page right')),
        del,
      ] : []));
    }

    function movePage(page, dir) {
      const index = state.form.schema.pages.indexOf(page);
      lastFocus = dir < 0 ? '[data-ffb-page-left]' : '[data-ffb-page-right]';
      setState(model.movePage(state, page.id, index + dir));
    }

    // Read-only preview of the storefront page title; the tabpanel label already names the page.
    function pageHeading(page) {
      const title = String(page.title || '').trim();
      return title ? el(doc, 'div', { class: 'ffb-admin-page-heading', 'aria-hidden': 'true', text: title }) : null;
    }

    function renderCanvas() {
      const canvas = refs.canvas;
      clear(canvas);
      const pages = state.form.schema.pages;
      const page = currentPage();
      if (translating()) {
        refs.translationStatus = translationStatus();
        const shown = shownSchema().pages.find((p) => p.id === page.id) || page;
        const store = translationStores().find((st) => String(st.id) === storeView);
        canvas.appendChild(el(doc, 'p', { class: 'ffb-admin-translate-note', role: 'note', 'data-ffb-translate-note': true,
          text: tr('Translating for %1. The layout is locked; texts left empty use the default.', store ? storeLabel(store) : storeView) }));
        if (!groupMode) {
          canvas.appendChild(el(doc, 'div', { class: 'ffb-admin-tabbar' }, [buildTabs()]));
          canvas.appendChild(el(doc, 'div', { class: 'ffb-admin-pagebar' }, [
            pageButton(tr('Page texts'), 'data-ffb-page-settings', () => apply('select', page.id)),
          ]));
        }
        canvas.appendChild(el(doc, 'div', { role: 'tabpanel', class: 'ffb-admin-tabpanel', 'aria-label': shown.title || tr('Page %1', pages.indexOf(page) + 1) }, [groupMode ? null : pageHeading(shown), buildList(shown, null)]));
        return;
      }
      if (groupMode) {
        canvas.appendChild(el(doc, 'div', { role: 'region', class: 'ffb-admin-tabpanel', 'aria-label': tr('Fields in this group') }, [buildList(page, null)]));
        return;
      }
      const addBtn = canSave ? el(doc, 'button', { type: 'button', class: 'ffb-admin-btn ffb-admin-btn-outline', 'data-ffb-page-add': true, text: tr('+ Add page') }) : null;
      if (addBtn) {
        addBtn.addEventListener('click', () => addFromSpec('page'));
      }
      canvas.appendChild(el(doc, 'div', { class: 'ffb-admin-tabbar' }, [buildTabs(), addBtn]));
      canvas.appendChild(buildPageBar(page));
      canvas.appendChild(el(doc, 'div', { role: 'tabpanel', class: 'ffb-admin-tabpanel', 'aria-label': page.title || tr('Page %1', pages.indexOf(page) + 1) }, [pageHeading(page), buildList(page, null)]));
    }

    function renderInspector() {
      const container = refs.inspector;
      clear(container);
      const inspector = win.FuranFormBuilder && win.FuranFormBuilder.builderInspector;
      if (!inspector || typeof inspector.render !== 'function') {
        container.appendChild(el(doc, 'p', { class: 'ffb-admin-hint', text: tr('Inspector unavailable') }));
        return;
      }
      inspector.render(container, state, config, actions, translating()
        ? { storeId: storeView, overlay: overlay(), typeSpecs, canEdit: canSave }
        : null);
      if (!canSave && !translating()) {
        container.querySelectorAll('input, select, textarea, button').forEach((node) => {
          node.disabled = node.getAttribute('role') !== 'tab' && node.getAttribute('data-ffb-input') !== 'form-settings';
        });
      }
    }

    function toolbarButton(text, attr, handler) {
      const btn = el(doc, 'button', { type: 'button', class: 'ffb-admin-btn', text });
      btn.setAttribute(attr, '');
      btn.addEventListener('click', handler);
      return btn;
    }

    function undo() {
      setState(model.undo(state));
    }

    function redo() {
      setState(model.redo(state));
    }

    function storeIdsByCode() {
      const out = {};
      Object.keys(config.storeCodes || {}).forEach((id) => { out[config.storeCodes[id]] = Number(id); });
      return out;
    }

    function exportJson() {
      const URLApi = win.URL;
      if (!URLApi || !URLApi.createObjectURL) {
        return;
      }
      const blob = new win.Blob([model.exportJson(state, config.storeCodes || {})], { type: 'application/json' });
      const url = URLApi.createObjectURL(blob);
      const a = el(doc, 'a', { href: url, download: (state.form.identifier || 'form') + '.json' });
      doc.body.appendChild(a);
      a.click();
      doc.body.removeChild(a);
      win.setTimeout(() => URLApi.revokeObjectURL(url), 1000);
    }

    function importDialog() {
      const area = el(doc, 'textarea', { class: 'ffb-admin-textarea', rows: '12', 'aria-label': tr('Form JSON') });
      openDialog({
        title: tr('Import JSON'), message: tr('Paste an exported form definition. It replaces the current form (you can undo).'),
        body: area, confirmLabel: tr('Import'), focus: 'textarea',
        onConfirm: (ui) => {
          try {
            const next = model.importJson(state, area.value, storeIdsByCode());
            pageId = next.form.schema.pages[0].id;
            setState(next);
            return true;
          } catch (err) {
            ui.showError(err.message);
            return false;
          }
        },
      });
    }

    function buildToolbar() {
      const bar = refs.toolbar;
      const back = el(doc, 'a', { class: 'ffb-admin-btn ffb-admin-btn-back', href: urls.back || '#', 'data-ffb-back': true, text: tr('Back') });
      back.addEventListener('click', (e) => {
        if (!state.dirty || !canSave) {
          return;
        }
        e.preventDefault();
        openDialog({
          title: tr('Discard changes?'), message: tr('You have unsaved changes. Leave without saving?'), confirmLabel: tr('Leave'),
          onConfirm: () => { leaveGuard = false; win.location.assign(urls.back); },
        });
      });
      const previewLink = urls.preview
        ? el(doc, 'a', { class: 'ffb-admin-btn', href: urls.preview, target: '_blank', rel: 'noopener noreferrer', 'data-ffb-preview': true, text: tr('Preview') })
        : el(doc, 'button', { type: 'button', class: 'ffb-admin-btn', disabled: true, 'data-ffb-preview': true, title: tr('Save the form to enable preview'), text: tr('Preview') });
      refs.undo = toolbarButton(tr('Undo'), 'data-ffb-undo', undo);
      refs.redo = toolbarButton(tr('Redo'), 'data-ffb-redo', redo);
      bar.appendChild(back);
      bar.appendChild(buildStoreSwitch());
      const actions = el(doc, 'div', { class: 'ffb-admin-toolbar-actions' });
      bar.appendChild(actions);
      const savePair = (label) => {
        const save = toolbarButton(label, 'data-ffb-save', () => save1(false));
        const cont = toolbarButton(tr('Save & Continue'), 'data-ffb-save-continue', () => save1(true));
        save.classList.add('ffb-admin-btn-primary');
        cont.classList.add('ffb-admin-btn-outline');
        const pair = el(doc, 'span', { class: 'ffb-admin-toolbar-save' });
        pair.appendChild(save);
        pair.appendChild(cont);
        return pair;
      };
      if (groupMode) {
        if (canSave) {
          actions.appendChild(refs.undo);
          actions.appendChild(refs.redo);
        }
        if (urls.delete) {
          actions.appendChild(deleteButton());
        }
        if (!canSave) {
          actions.appendChild(el(doc, 'span', { class: 'ffb-admin-readonly', role: 'note', 'data-ffb-readonly': true, text: tr('You can view this group but not change it.') }));
          return;
        }
        actions.appendChild(savePair(tr('Save group')));
        return;
      }
      if (!canSave) {
        actions.appendChild(el(doc, 'span', { class: 'ffb-admin-readonly', role: 'note', 'data-ffb-readonly': true, text: tr('You can view this form but not change it.') }));
        [toolbarButton(tr('Export JSON'), 'data-ffb-export', exportJson), previewLink, urls.delete ? deleteButton() : null]
          .forEach((n) => n && actions.appendChild(n));
        return;
      }
      const duplicate = urls.duplicate ? toolbarButton(tr('Duplicate form'), 'data-ffb-duplicate', duplicateForm) : null;
      [refs.undo, refs.redo, toolbarButton(tr('Export JSON'), 'data-ffb-export', exportJson), toolbarButton(tr('Import JSON'), 'data-ffb-import', importDialog), duplicate, previewLink, urls.delete ? deleteButton() : null, savePair(tr('Save'))]
        .forEach((n) => n && actions.appendChild(n));
    }

    function buildStoreSwitch() {
      const select = el(doc, 'select', { class: 'ffb-admin-input ffb-admin-store-select', 'data-ffb-store-view': true, 'aria-label': tr('Store view') });
      select.addEventListener('change', () => {
        storeView = select.value === '' ? null : select.value;
        lastFocus = '[data-ffb-store-view]';
        if (state.selectedId && !findNode(state.selectedId) && !state.form.schema.pages.some((p) => p.id === state.selectedId)) {
          setState(model.select(state, null));
        } else {
          render();
        }
        announce(translating() ? tr('Translating for %1', select.options[select.selectedIndex].text) : tr('Editing the default texts'));
      });
      refs.storeSelect = select;
      refs.progress = el(doc, 'span', { class: 'ffb-admin-progress', role: 'status', 'data-ffb-translation-progress': true });
      refs.storeSwitch = el(doc, 'span', { class: 'ffb-admin-store-switch' }, [
        el(doc, 'label', { class: 'ffb-admin-sr', text: tr('Store view') }), select, refs.progress,
      ]);
      return refs.storeSwitch;
    }

    function renderStoreSwitch() {
      const stores = translationStores();
      if (storeView !== null && !stores.some((st) => String(st.id) === storeView)) {
        storeView = null;
      }
      refs.storeSwitch.hidden = stores.length === 0;
      const select = refs.storeSelect;
      clear(select);
      select.appendChild(el(doc, 'option', { value: '', text: tr('Default (all store views)') }));
      stores.forEach((st) => select.appendChild(el(doc, 'option', { value: String(st.id), text: storeLabel(st) })));
      select.value = storeView === null ? '' : storeView;
      if (!translating()) {
        refs.progress.textContent = '';
        return;
      }
      const stats = i18n.progress(state.form.schema, state.form.settings, overlay(), typeSpecs);
      refs.progress.textContent = tr('%1 of %2 translated', stats.translated, stats.total)
        + (stats.outdated ? ', ' + tr('%1 need review', stats.outdated) : '');
    }

    function deleteRecord(title, message, confirmLabel) {
      openDialog({
        title, message, confirmLabel,
        onConfirm: () => {
          const key = form.querySelector('input[name="form_key"]');
          const post = el(doc, 'form', { method: 'post', action: urls.delete, hidden: true, 'data-ffb-delete-form': true }, [
            el(doc, 'input', { type: 'hidden', name: 'form_key', value: key ? key.value : '' }),
            el(doc, 'input', { type: 'hidden', name: 'id', value: String(state.form.id) }),
          ]);
          doc.body.appendChild(post);
          leaveGuard = false;
          post.submit();
        },
      });
    }

    function deleteButton() {
      const button = groupMode
        ? toolbarButton(tr('Delete group'), 'data-ffb-delete', () => deleteRecord(
          tr('Delete group'), tr('Forms that already use this group keep their fields.'), tr('Delete this group')))
        : toolbarButton(tr('Delete form'), 'data-ffb-delete', () => deleteRecord(
          tr('Delete form'), tr('The form and all its submissions are deleted. This cannot be undone.'), tr('Delete this form')));
      button.classList.add('ffb-admin-btn-danger');
      return button;
    }

    function duplicateForm() {
      const go = () => {
        const key = form.querySelector('input[name="form_key"]');
        const post = el(doc, 'form', { method: 'post', action: urls.duplicate, hidden: true, 'data-ffb-duplicate-form': true }, [
          el(doc, 'input', { type: 'hidden', name: 'form_key', value: key ? key.value : '' }),
        ]);
        doc.body.appendChild(post);
        leaveGuard = false;
        post.submit();
      };
      if (!state.dirty) {
        go();
        return;
      }
      openDialog({
        title: tr('Duplicate form'),
        message: tr('The copy is made from the last saved version, so your unsaved changes will not be in it. Duplicate anyway?'),
        confirmLabel: tr('Duplicate'),
        onConfirm: () => { go(); },
      });
    }

    function renderToolbarState() {
      refs.undo.disabled = !state.past.length;
      refs.redo.disabled = !state.future.length;
      renderStoreSwitch();
    }

    function showSummary(entries) {
      const box = refs.summary;
      clear(box);
      box.hidden = entries.length === 0;
      if (!entries.length) {
        return;
      }
      box.appendChild(el(doc, 'h2', { class: 'ffb-admin-summary-title', text: tr('Please fix the following before saving') }));
      const ul = el(doc, 'ul');
      entries.forEach((entry) => {
        const li = el(doc, 'li');
        if (entry.itemId) {
          const link = el(doc, 'button', { type: 'button', class: 'ffb-admin-link', 'data-ffb-error-link': entry.itemId, text: entry.message });
          link.addEventListener('click', () => {
            apply('select', entry.itemId);
            const target = refs.inspector.querySelector('input, select, textarea, button');
            if (target) {
              target.focus();
            }
          });
          li.appendChild(link);
        } else {
          li.textContent = entry.message;
        }
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }

    function setInput(name, value) {
      const input = form.querySelector('input[name="' + name + '"]');
      if (input) {
        input.value = value;
      }
    }

    function save1(andContinue) {
      const errors = model.validate(state, config);
      if (errors.length) {
        showSummary(errors);
        refs.summary.focus();
        return;
      }
      showSummary([]);
      leaveGuard = false;
      setInput('back', andContinue ? 'edit' : '');
      if (groupMode) {
        setInput('name', state.form.title || '');
        setInput('description', state.form.description || '');
        setInput('fields', JSON.stringify(state.form.schema.pages[0].items));
        setInput('translations', JSON.stringify(state.form.translations || {}));
        form.submit();
        return;
      }
      const data = model.serialize(state);
      setInput('form_id', data.form_id === null || data.form_id === undefined ? '' : String(data.form_id));
      setInput('identifier', data.identifier);
      setInput('title', data.title);
      setInput('is_active', data.is_active ? '1' : '0');
      setInput('store_ids', JSON.stringify(data.store_ids));
      setInput('schema', JSON.stringify(data.schema));
      setInput('settings', JSON.stringify(data.settings));
      setInput('translations', JSON.stringify(data.translations || {}));
      form.submit();
    }

    // Rebuilding the canvas loses the browser's scroll anchor, so the page jumps unless it is put back.
    function render() {
      const x = win.scrollX;
      const y = win.scrollY;
      try {
        renderNow();
      } finally {
        if (win.scrollX !== x || win.scrollY !== y) {
          win.scrollTo(x, y);
        }
      }
    }

    function renderNow() {
      const wanted = lastFocus || focusSelector();
      lastFocus = null;
      if (storeView !== null && !translationStores().some((st) => String(st.id) === storeView)) {
        storeView = null;
      }
      refs.palette.hidden = locked();
      rootEl.classList.toggle('is-translating', translating());
      rootEl.classList.toggle('is-readonly', !canSave);
      syncPage();
      renderCanvas();
      renderInspector();
      renderToolbarState();
      if (focusInspector) {
        focusInspector = false;
        if (focusInspectorInput()) {
          return;
        }
      }
      if (wanted) {
        restoreFocus(wanted);
      }
    }

    function focusInspectorInput() {
      for (let i = 0; i < INSPECTOR_FOCUS_KEYS.length; i++) {
        const input = refs.inspector.querySelector('[data-ffb-input="' + INSPECTOR_FOCUS_KEYS[i] + '"]');
        if (input) {
          input.focus();
          return true;
        }
      }
      return false;
    }

    function restoreFocus(selector) {
      const target = doc.querySelector(selector);
      if (target && !target.disabled) {
        target.focus();
        return;
      }
      const inspectorKey = /^\[data-ffb-inspector\] \[data-ffb-input="([^"]+)"\]$/.exec(selector);
      if (inspectorKey) {
        const fallback = inspectorFallback(inspectorKey[1]);
        if (fallback) {
          fallback.focus();
        }
        return;
      }
      const bar = target && target.closest('.ffb-admin-card-actions');
      const alt = bar ? bar.querySelector('button:not([disabled])') : doc.querySelector('[data-ffb-tab][aria-selected="true"]');
      if (alt && (target || selector.indexOf('data-ffb-select') >= 0 || selector.indexOf('data-ffb-tab') >= 0)) {
        alt.focus();
      }
    }

    // A removed inspector control hands focus to the nearest Add button of its editor, else the editor's first control, else the tab.
    function inspectorFallback(key) {
      const usable = (node) => (node && !node.disabled && !node.closest('[hidden]') ? node : null);
      const byKey = (k) => usable(refs.inspector.querySelector('[data-ffb-input="' + k + '"]'));
      const parts = key.split(/([-.])/);
      for (let n = parts.length - 1; n > 0; n--) {
        const prefix = parts.slice(0, n).join('');
        const add = ['-add-rule', '-add-group', '-add-action', '-add', '.add'].map((tail) => byKey(prefix + tail)).find(Boolean);
        if (add) {
          return add;
        }
      }
      const root = parts[0];
      const first = Array.from(refs.inspector.querySelectorAll('[data-ffb-input]'))
        .find((n) => n.getAttribute('data-ffb-input').indexOf(root) === 0 && usable(n));
      return first || usable(refs.inspector.querySelector('.ffb-admin-tab[aria-selected="true"]'));
    }

    function focusSelector() {
      const active = doc.activeElement;
      if (!active || !rootEl.contains(active) || active === doc.body) {
        return null;
      }
      const key = active.getAttribute('data-ffb-focus');
      if (key) {
        return '[data-ffb-focus="' + key + '"]';
      }
      const card = active.closest('[data-ffb-card]');
      const act = active.getAttribute('data-ffb-act');
      if (card && act) {
        return '[data-ffb-card="' + card.getAttribute('data-ffb-card') + '"] > .ffb-admin-card-actions [data-ffb-act="' + act + '"]';
      }
      if (card && active.hasAttribute('data-ffb-select')) {
        return '[data-ffb-card="' + card.getAttribute('data-ffb-card') + '"] > .ffb-admin-card-main';
      }
      const input = active.getAttribute('data-ffb-input');
      if (input && refs.inspector.contains(active)) {
        return '[data-ffb-inspector] [data-ffb-input="' + input + '"]';
      }
      return null;
    }

    function buildShell() {
      clear(rootEl);
      rootEl.classList.add('ffb-admin-builder');
      refs.root = rootEl;
      refs.toolbar = el(doc, 'div', { class: 'ffb-admin-toolbar', role: 'toolbar', 'aria-label': tr('Form builder actions') });
      refs.notice = el(doc, 'div', { class: 'ffb-admin-notice', role: 'status', 'aria-live': 'polite', 'data-ffb-notice': true });
      refs.live = el(doc, 'div', { class: 'ffb-admin-sr', role: 'status', 'aria-live': 'polite', 'data-ffb-live': true });
      refs.summary = el(doc, 'div', { class: 'ffb-admin-summary', role: 'alert', tabindex: '-1', hidden: true, 'data-ffb-summary': true });
      refs.palette = el(doc, 'aside', { class: 'ffb-admin-palette', 'data-ffb-palette': true, 'aria-label': tr('Field palette') });
      refs.canvas = el(doc, 'section', { class: 'ffb-admin-canvas', 'data-ffb-canvas': true, 'aria-label': tr('Form canvas') });
      refs.inspector = el(doc, 'aside', { class: 'ffb-admin-inspector-col', 'data-ffb-inspector': true, 'aria-label': tr('Inspector') });
      [refs.toolbar, refs.notice, refs.live, refs.summary,
        el(doc, 'div', { class: 'ffb-admin-columns' }, [refs.inspector, refs.canvas, refs.palette])]
        .forEach((n) => rootEl.appendChild(n));
      buildToolbar();
      buildPalette();
    }

    function onKeydown(e) {
      const mod = e.ctrlKey || e.metaKey;
      const key = String(e.key).toLowerCase();
      if (!canSave || !mod || (key !== 'z' && key !== 'y')) {
        return;
      }
      const t = e.target;
      if (t && t.matches && t.matches('input, textarea, select, [contenteditable]')) {
        return;
      }
      e.preventDefault();
      if (key === 'y' || e.shiftKey) {
        redo();
      } else {
        undo();
      }
    }

    function onBeforeUnload(e) {
      if (leaveGuard && canSave && state.dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    }

    buildShell();
    doc.addEventListener('keydown', onKeydown);
    win.addEventListener('beforeunload', onBeforeUnload);
    showSummary((config.errors || []).map((message) => ({ itemId: null, message: String(message) })));
    render();
    return { getState: () => state };
  }

  function boot(doc) {
    const node = doc.querySelector('[data-ffb-builder]');
    if (node) {
      init(node);
    }
  }

  const api = { init, boot };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builder = api;
    if (root.document) {
      // Deferred scripts added later through layout run before DOMContentLoaded, so they can register hooks first.
      if (root.document.readyState !== 'complete') {
        root.document.addEventListener('DOMContentLoaded', () => boot(root.document));
      } else {
        boot(root.document);
      }
    }
  }
}(typeof window !== 'undefined' ? window : globalThis));
