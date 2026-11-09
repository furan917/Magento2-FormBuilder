(function (root) {
  'use strict';

  var ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  if (ns.init) {
    return;
  }
  var core = ns.core;
  var types = ns.types;
  var missing = [['ffb-types.js', types], ['ffb-drafts.js', ns.createDraftSync], ['ffb-submit.js', ns.submit]]
    .filter(function (part) { return !part[1]; }).map(function (part) { return part[0]; });
  if (!core || missing.length) {
    if (core && typeof console !== 'undefined') {
      console.warn('Furan_FormBuilder: ' + missing.join(', ') + ' did not load before ffb-form.js; the form is left as plain HTML. Check that the theme form.phtml prints every script.');
    }
    return;
  }

  var doc = root.document;
  var sameOrigin = core.sameOrigin;
  var safeAsset = core.safeAsset;
  var asObject = types.asObject;
  var controlsOf = types.controlsOf;
  var readValue = types.readValue;
  var writeValue = types.writeValue;
  var lockControls = types.lockControls;
  var loadRecaptchaHelper = types.loadRecaptchaHelper;
  var loadMapAssets = types.loadMapAssets;
  var summaryText = types.summaryText;
  var markRequired = types.markRequired;
  var applyHiddenOptions = types.applyHiddenOptions;
  var jsonOf = types.jsonOf;
  var fileError = types.fileError;
  var applyMasks = types.applyMasks;
  var fillHidden = types.fillHidden;
  var maskedText = types.maskedText;
  var showRange = types.showRange;
  var setRange = types.setRange;
  var syncOutputs = types.syncOutputs;
  var handleListClick = types.handleListClick;

  var isDay = function (value) {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
  };

  // Placeholder for relative dates until the token brings the store's own date.
  var browserToday = function () {
    var now = new root.Date();
    var two = function (n) { return (n < 10 ? '0' : '') + n; };
    return now.getFullYear() + '-' + two(now.getMonth() + 1) + '-' + two(now.getDate());
  };

  var isSafeRedirect = function (target) {
    if (typeof target !== 'string' || target === '' || /[\\\u0000-\u001f]/.test(target)) {
      return false;
    }
    if (/^\/(?![\/\\])/.test(target)) {
      return true;
    }
    try {
      var url = new URL(target, root.location.href);
      return (url.protocol === 'https:' || url.protocol === 'http:') && url.origin === root.location.origin;
    } catch (e) {
      return false;
    }
  };

  // An attribute, not a child script: themes may move every <script> to the end of the body.
  var readConfig = function (rootEl) {
    var raw = rootEl.getAttribute('data-ffb-config');
    try {
      return raw === null ? null : JSON.parse(raw);
    } catch (e) {
      return null;
    }
  };

  // "#ffb-edit=<token>" from My submissions; a hash never reaches the server or the page cache.
  var readEditToken = function () {
    var parts = String((root.location && root.location.hash) || '').replace(/^#/, '').split('&');
    for (var i = 0; i < parts.length; i++) {
      var m = /^ffb-edit=([0-9]{1,12}\.[0-9]{1,12}\.[0-9a-f]{64})$/.exec(parts[i]);
      if (m) { return m[1]; }
    }
    return '';
  };

  var returnLink = function (base, register) {
    return String(base) + (String(base).indexOf('?') === -1 ? '?' : '&') +
      'return=' + encodeURIComponent(root.location.href) + (register ? '&register=1' : '');
  };

  var IMPLICIT_SUBMIT_TYPES = ['text', 'search', 'email', 'tel', 'url', 'password', 'number', 'date', 'time',
    'datetime-local', 'month', 'week'];

  var wrapperFromEvent = function (event) {
    return event.target && event.target.closest ? event.target.closest('[data-ffb-name]') : null;
  };


  // A message that already quotes the reference gets no second copy of it.
  var renderSuccess = function (inst, message, reference) {
    var box = inst.successEl;
    var body = doc.createElement('div');
    var text = doc.createElement('p');
    box.textContent = '';
    body.className = 'ffb-success-body';
    text.className = 'ffb-success-text';
    text.textContent = message;
    body.appendChild(text);
    if (inst.config.showReference === true && reference !== '' && message.indexOf(reference) === -1) {
      var line = doc.createElement('p');
      var label = doc.createElement('span');
      var code = doc.createElement('code');
      line.className = 'ffb-reference';
      label.className = 'ffb-reference-label';
      label.textContent = String(inst.messages.reference || '');
      code.textContent = reference;
      line.appendChild(label);
      line.appendChild(doc.createTextNode(' '));
      line.appendChild(code);
      var clipboard = root.navigator && root.navigator.clipboard;
      if (clipboard && typeof clipboard.writeText === 'function') {
        var copy = doc.createElement('button');
        copy.type = 'button';
        copy.className = 'ffb-reference-copy';
        copy.textContent = String(inst.messages.copy || '');
        copy.addEventListener('click', function () {
          clipboard.writeText(reference).then(function () {
            copy.textContent = String(inst.messages.copied || '');
          }, function () {});
        });
        line.appendChild(doc.createTextNode(' '));
        line.appendChild(copy);
      }
      body.appendChild(line);
    }
    box.appendChild(body);
  };

  // `inst` carries one form's elements and mutable state (inst.s) to the parts below.
  var createInstance = function (rootEl, form, config) {
    var schema = config.schema || { pages: [] };
    var inst = {
      rootEl: rootEl,
      form: form,
      config: config,
      messages: asObject(config.messages),
      context: asObject(config.context),
      regionsUrl: sameOrigin(config.regionsUrl) || '',
      editToken: readEditToken(),
      schema: schema,
      pageIds: [],
      fields: [],
      pageOf: {},
      fieldByName: {},
      statusEl: form.querySelector('[data-ffb-status]'),
      successEl: rootEl.querySelector('[data-ffb-success]'),
      submitBtn: form.querySelector('[data-ffb-submit]'),
      prevBtn: form.querySelector('[data-ffb-prev]'),
      nextBtn: form.querySelector('[data-ffb-next]'),
      progressEl: form.querySelector('[data-ffb-progress]'),
      s: {
        visibility: {},
        requiredNow: {},
        hiddenOptionsNow: {},
        filledByAction: {},
        actionsOpen: false,
        today: browserToday(),
        current: null,
        contextApplied: false,
        customerKnown: false,
        scheduleClosed: false,
        editRejected: false,
        touched: {},
        dirty: {},
        blurred: [],
        visibilityPending: false,
        prefilled: {},
        lockedNames: {},
        restoredNote: null
      }
    };
    (schema.pages || []).forEach(function (page) {
      inst.pageIds.push(page.id);
      var walk = function (items) {
        (items || []).forEach(function (item) {
          if (item.kind === 'field') {
            item.settings = asObject(item.settings);
            inst.fields.push(item);
            inst.pageOf[item.name] = page.id;
            inst.fieldByName[item.name] = item;
          } else if (item.kind === 'section') {
            walk(item.items);
          }
        });
      };
      walk(page.items);
    });
    inst.s.current = inst.pageIds[0];

    // Detail never carries answer values (personal data).
    inst.emit = function (type, detail, cancelable) {
      var event = new root.CustomEvent('ffb:' + type, {
        bubbles: true,
        cancelable: !!cancelable,
        detail: Object.assign({ formId: config.formId }, detail)
      });
      rootEl.dispatchEvent(event);
      return !event.defaultPrevented;
    };

    var wrapperMap = null;
    // Built once; rebuilt when a cached wrapper has left the document.
    inst.wrapperOf = function (name) {
      var cached = wrapperMap && wrapperMap[name];
      if (wrapperMap === null || (cached && !cached.isConnected)) {
        wrapperMap = Object.create(null);
        Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-name]'), function (el) {
          var key = el.getAttribute('data-ffb-name');
          if (!(key in wrapperMap)) { wrapperMap[key] = el; }
        });
      }
      return wrapperMap[name] || null;
    };

    inst.setStatus = function (text) {
      if (inst.statusEl) { inst.statusEl.textContent = text || ''; }
    };
    return inst;
  };

  var viewPart = function (inst) {
    var s = inst.s;
    var form = inst.form;
    var fields = inst.fields;
    var pageIds = inst.pageIds;
    var wrapperOf = inst.wrapperOf;
    var messages = inst.messages;

    var collectValues = function () {
      var values = {};
      fields.forEach(function (f) {
        var w = wrapperOf(f.name);
        values[f.name] = w ? readValue(w) : '';
      });
      return values;
    };

    var isCondHidden = function (el) {
      for (var node = el.closest('[data-ffb-item]'); node; node = node.parentElement && node.parentElement.closest('[data-ffb-item]')) {
        if (s.visibility[node.getAttribute('data-ffb-item')] === false) {
          return true;
        }
      }
      return false;
    };

    var pageVisible = function (id) { return s.visibility[id] !== false; };
    var visiblePageIds = function () { return pageIds.filter(pageVisible); };

    var visibleValues = function () {
      var values = core.normalizeValues(fields, collectValues());
      fields.forEach(function (f) {
        if (s.visibility[f.id] === false || (inst.pageOf[f.name] && s.visibility[inst.pageOf[f.name]] === false)) {
          delete values[f.name];
        }
      });
      return values;
    };

    var fillSummaries = function () {
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-summary]'), function (box) {
        var page = box.closest('[data-ffb-page]');
        var list = box.querySelector('.ffb-summary-list');
        if (!list || (page && page.getAttribute('data-ffb-page') !== s.current)) {
          return;
        }
        var editLabel = box.getAttribute('data-ffb-edit-label') || 'Edit';
        var yesLabel = box.getAttribute('data-ffb-yes-label') || 'Yes';
        while (list.firstChild) { list.removeChild(list.firstChild); }
        Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-name]'), function (wrapper) {
          var f = inst.fieldByName[wrapper.getAttribute('data-ffb-name')];
          // Only answers given before the summary (DOCUMENT_POSITION_PRECEDING).
          var before = (box.compareDocumentPosition(wrapper) & 2) !== 0;
          // A hidden field is never an answer the visitor gave; the type code is the only marker there is.
          if (!f || !before || f.type === 'hidden' || !pageVisible(inst.pageOf[f.name]) || s.visibility[f.id] === false || isCondHidden(wrapper)) {
            return;
          }
          var row = doc.createElement('div');
          row.className = 'ffb-summary-row';
          var dt = doc.createElement('dt');
          dt.textContent = f.label;
          var dd = doc.createElement('dd');
          dd.textContent = summaryText(f, wrapper, yesLabel);
          var action = doc.createElement('dd');
          action.className = 'ffb-summary-action';
          var edit = doc.createElement('button');
          edit.type = 'button';
          edit.className = 'ffb-summary-edit';
          edit.textContent = editLabel;
          edit.setAttribute('aria-label', editLabel + ': ' + f.label);
          edit.addEventListener('click', function () {
            inst.goTo(inst.pageOf[f.name]);
            inst.focusField(f.name);
            inst.announceStep();
          });
          action.appendChild(edit);
          row.appendChild(dt);
          row.appendChild(dd);
          row.appendChild(action);
          list.appendChild(row);
        });
      });
    };

    var renderPages = function () {
      var visiblePages = visiblePageIds();
      if (!pageVisible(s.current) && visiblePages.length) {
        s.current = visiblePages[0];
      }
      var pos = visiblePages.indexOf(s.current);
      pageIds.forEach(function (id) {
        var el = form.querySelector('[data-ffb-page="' + id + '"]');
        if (el) {
          el.hidden = id !== s.current || !pageVisible(id);
        }
        var step = form.querySelector('[data-ffb-step="' + id + '"]');
        if (step) {
          step.hidden = !pageVisible(id);
          if (id === s.current) {
            step.setAttribute('aria-current', 'step');
          } else {
            step.removeAttribute('aria-current');
          }
        }
      });
      if (inst.progressEl && messages.stepOf) {
        inst.progressEl.setAttribute('aria-label', String(messages.stepOf)
          .replace('%1', String(pos + 1)).replace('%2', String(visiblePages.length)));
      }
      if (inst.prevBtn) { inst.prevBtn.hidden = pos <= 0; }
      if (inst.nextBtn) { inst.nextBtn.hidden = pos >= visiblePages.length - 1; }
      if (inst.submitBtn) { inst.submitBtn.hidden = pos < visiblePages.length - 1; }
      fillSummaries();
      // Leaflet renders grey tiles if its container was hidden while it measured itself.
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-type="map"]'), function (w) {
        if (w.ffbMap && !isCondHidden(w) && !w.closest('[hidden]')) { w.ffbMap.invalidateSize(); }
      });
    };

    // Consent boxes are never required or pre-filled, whatever a stored condition says.
    var consentNames = function () {
      return fields.filter(function (f) {
        var w = wrapperOf(f.name);
        return w && w.querySelector('[data-ffb-impersonation-lock]');
      }).map(function (f) { return f.name; });
    };

    // Writes what actions ask for; returns true when an answer changed so the next pass sees it.
    var applyValues = function (state, consent) {
      var changed = false;
      fields.forEach(function (f) {
        var w = wrapperOf(f.name);
        if (!w || state.visible[f.id] === false || consent.indexOf(f.name) !== -1) { return; }
        var before = jsonOf(w);
        var after = before;
        if (f.overwritable) {
          if (s.actionsOpen && state.values[f.name] !== undefined && JSON.stringify(state.values[f.name]) !== before) {
            writeValue(w, state.values[f.name]);
            if (s.lockedNames[f.name]) { lockControls(w); }
            after = jsonOf(w);
          }
        } else if (s.actionsOpen && state.defaults[f.id] !== undefined && !s.touched[f.name] &&
            (core.isEmpty(core.normalizeValue(f, readValue(w))) || before === s.filledByAction[f.name])) {
          writeValue(w, state.defaults[f.id]);
          after = s.filledByAction[f.name] = jsonOf(w);
        }
        changed = changed || after !== before;
      });
      return changed;
    };

    var applyState = function (state, consent) {
      s.visibility = state.visible;
      s.requiredNow = state.required;
      s.hiddenOptionsNow = state.hiddenOptions;
      var changed = false;
      fields.forEach(function (f) {
        var w = wrapperOf(f.name);
        if (!w) { return; }
        markRequired(w, state.required[f.id] === true && !s.lockedNames[f.name]);
        changed = applyHiddenOptions(w, state.hiddenOptions[f.id] || []) || changed;
      });
      return applyValues(state, consent) || changed;
    };

    var applyVisibility = function () {
      var consent = consentNames();
      // Each pass can write an answer that later conditions read; a form cannot need more passes than fields.
      for (var pass = 0; pass <= fields.length; pass++) {
        if (!applyState(core.computeState(inst.schema, collectValues(), s.today, consent), consent)) { break; }
      }
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-item]'), function (el) {
        var id = el.getAttribute('data-ffb-item');
        if (!el.hasAttribute('data-ffb-page')) {
          el.hidden = s.visibility[id] === false;
        }
        controlsOf(el).forEach(function (c) {
          if (c.closest('[data-ffb-item]') === el) {
            c.disabled = isCondHidden(el) || c.ffbLockOff === true || c.ffbOptionHidden === true;
          }
        });
      });
      renderPages();
      clearFixStatus();
    };

    // Errors inside hidden fields or pages do not count.
    var clearFixStatus = function () {
      var fixErrors = String(messages.fixErrors || '');
      if (!fixErrors || !inst.statusEl || inst.statusEl.textContent !== fixErrors) { return; }
      var open = Array.prototype.some.call(form.querySelectorAll('[data-ffb-error]:not([hidden])'), function (e) {
        return !e.closest('[hidden]');
      });
      if (!open) { inst.setStatus(''); }
    };

    // Set values wait for draft, prefill and earlier answers so they never count as the visitor's own.
    var openActions = function () {
      if (!s.actionsOpen) {
        s.actionsOpen = true;
        applyVisibility();
      }
    };

    inst.collectValues = collectValues;
    inst.isCondHidden = isCondHidden;
    inst.pageVisible = pageVisible;
    inst.visiblePageIds = visiblePageIds;
    inst.visibleValues = visibleValues;
    inst.renderPages = renderPages;
    inst.applyVisibility = applyVisibility;
    inst.clearFixStatus = clearFixStatus;
    inst.openActions = openActions;
  };

  var validationPart = function (inst) {
    var s = inst.s;
    var form = inst.form;
    var config = inst.config;
    var wrapperOf = inst.wrapperOf;

    var showError = function (name, message) {
      var w = wrapperOf(name);
      if (!w) {
        return;
      }
      var err = w.querySelector('[data-ffb-error]');
      if (err) {
        err.textContent = message || '';
        err.hidden = !message;
      }
      controlsOf(w).forEach(function (c) {
        if (message) {
          c.setAttribute('aria-invalid', 'true');
        } else {
          c.removeAttribute('aria-invalid');
        }
      });
      if (!message) { inst.clearFixStatus(); }
    };

    var focusField = function (name) {
      var w = wrapperOf(name);
      var target = w && (controlsOf(w).filter(function (c) { return c.type !== 'hidden'; })[0]);
      if (target) { target.focus(); }
    };

    // Loaded region lists join the directory; a country whose list is still loading is not held to its region rule.
    var directoryNow = function () {
      if (!config.directory) {
        return undefined;
      }
      var directory = Object.assign({}, config.directory, { regions: {} });
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-type="address"] [name$="[country_id]"]'), function (select) {
        var entry = types.regionEntry(inst.regionsUrl, select.value);
        if (!entry) { return; }
        if (entry.done) {
          directory.regions[select.value] = {};
          entry.list.forEach(function (r) { directory.regions[select.value][r.id] = r.name; });
        } else {
          directory.regionsRequired = (directory.regionsRequired || []).filter(function (c) { return c !== select.value; });
        }
      });
      return directory;
    };

    // The schema item is the baseline for every pass, so a require action is applied to a copy.
    // A locked answer comes from the server, so it is never required of the visitor.
    var withRequired = function (f) {
      var now = s.lockedNames[f.name] ? false : s.requiredNow[f.id];
      return now === undefined || now === !!f.required ? f : Object.assign({}, f, { required: now });
    };

    var validate = function (scope) {
      var values = inst.visibleValues();
      var active = scope.filter(function (f) { return Object.prototype.hasOwnProperty.call(values, f.name); }).map(withRequired);
      var errors = core.validateFields(active, values, directoryNow());
      active.forEach(function (f) {
        if (f.type === 'file' && !errors[f.name]) {
          var w = wrapperOf(f.name);
          var message = w ? fileError(config, f, w.querySelector('input[type="file"]')) : null;
          if (message) { errors[f.name] = message; }
        }
      });
      return errors;
    };

    inst.showError = showError;
    inst.focusField = focusField;
    inst.validate = validate;
    inst.applyErrors = function (scope, errors) {
      var first = null;
      scope.forEach(function (f) {
        showError(f.name, errors[f.name] || '');
        if (!first && errors[f.name]) { first = f; }
      });
      return first;
    };
    inst.updateHint = function (wrapper) {
      var field = inst.fieldByName[wrapper.getAttribute('data-ffb-name')];
      var hint = wrapper.querySelector('[data-ffb-hint]');
      if (!field || !hint) { return; }
      var value = core.normalizeValue(field, readValue(wrapper));
      hint.textContent = value !== '' && core.postcodeHint(value, config.directory)
        ? String(asObject(field.messages).postcode_hint || '') : '';
    };
    inst.revalidate = function (wrapper, onlyIfInvalid) {
      var name = wrapper && wrapper.getAttribute('data-ffb-name');
      var field = name && inst.fieldByName[name];
      if (!field) {
        return;
      }
      var err = wrapper.querySelector('[data-ffb-error]');
      if (onlyIfInvalid && (!err || err.hidden)) {
        return;
      }
      var errors = validate([field]);
      showError(name, errors[name] || '');
    };
  };

  var contextPart = function (inst) {
    var s = inst.s;
    var form = inst.form;
    var rootEl = inst.rootEl;
    var config = inst.config;
    var messages = inst.messages;
    var context = inst.context;
    var wrapperOf = inst.wrapperOf;

    var showGate = function (access) {
      var reason = access.reason;
      var key = { login: 'gateLogin', group: 'gateGroup', answered: 'gateAnswered' }[reason] || 'gateLogin';
      var loginUrl = sameOrigin(context.loginUrl) || sameOrigin(access.loginUrl);
      var gate = doc.createElement('div');
      gate.className = 'ffb-gate';
      gate.setAttribute('data-ffb-gate', '');
      gate.setAttribute('role', 'status');
      var text = doc.createElement('p');
      text.className = 'ffb-gate-text';
      text.textContent = String(messages[key] || '');
      gate.appendChild(text);
      if (loginUrl && reason !== 'answered' && !(reason === 'group' && s.customerKnown)) {
        var actions = doc.createElement('div');
        actions.className = 'ffb-gate-actions';
        gate.appendChild(actions);
        [['gateLoginLink', false], ['gateRegisterLink', true]].forEach(function (spec) {
          var a = doc.createElement('a');
          a.className = spec[1] ? 'ffb-gate-link ffb-gate-secondary' : 'ffb-gate-link ffb-btn ffb-btn-primary';
          a.href = returnLink(loginUrl, spec[1]);
          a.textContent = String(messages[spec[0]] || '');
          actions.appendChild(a);
        });
      }
      form.hidden = true;
      rootEl.insertBefore(gate, form);
    };

    // The schedule decides on the server; a cached open page learns it from the token response.
    var showClosed = function (schedule) {
      s.scheduleClosed = true;
      var box = doc.createElement('div');
      box.className = 'ffb-closed';
      box.setAttribute('data-ffb-closed', '');
      box.setAttribute('role', 'status');
      var text = doc.createElement('p');
      text.className = 'ffb-closed-text';
      text.textContent = String(schedule.message || '');
      box.appendChild(text);
      box.setAttribute('tabindex', '-1');
      var hadFocus = form.contains(doc.activeElement);
      form.hidden = true;
      rootEl.insertBefore(box, form);
      if (hadFocus) { box.focus(); }
    };

    var isReplaceable = function (f, w) {
      if (s.touched[f.name]) { return false; }
      var current = readValue(w);
      return core.isEmpty(core.normalizeValue(f, current)) ||
        (typeof f.default === 'string' && f.default !== '' && current === f.default) ||
        JSON.stringify(current) === s.filledByAction[f.name];
    };

    var applyContext = function (data) {
      var access = asObject(data.access);
      s.customerKnown = data.customer === true;
      if (access.allowed === false) {
        showGate(access);
        return;
      }
      var prefill = asObject(data.prefill);
      var previous = asObject(data.previous);
      var product = asObject(data.product);
      var invalidText = String((s.customerKnown ? messages.editInvalid : messages.editSignIn) || '');
      // A survey answer refilled instead is edited in place, so the link's notice would be wrong.
      if (s.editRejected && invalidText && Object.keys(previous).length === 0 && !rootEl.querySelector('[data-ffb-edit-invalid]')) {
        var invalid = doc.createElement('p');
        invalid.className = 'ffb-editing ffb-edit-invalid';
        invalid.setAttribute('data-ffb-edit-invalid', '');
        invalid.setAttribute('role', 'status');
        invalid.textContent = invalidText;
        rootEl.insertBefore(invalid, form);
      }
      if (((inst.editToken && !s.editRejected) || Object.keys(previous).length > 0) && messages.editing &&
        !rootEl.querySelector('[data-ffb-editing]')) {
        var editing = doc.createElement('p');
        editing.className = 'ffb-editing';
        editing.setAttribute('data-ffb-editing', '');
        editing.setAttribute('role', 'status');
        editing.textContent = String(messages.editing);
        rootEl.insertBefore(editing, form);
      }
      var locked = Array.isArray(data.locked) ? data.locked : [];
      inst.fields.forEach(function (f) {
        var w = wrapperOf(f.name);
        var source = typeof f.prefill === 'string' ? f.prefill : '';
        var value = source.indexOf('product.') === 0 ? product[source.slice(8)] : prefill[f.name];
        if (previous[f.name] !== undefined && previous[f.name] !== null && locked.indexOf(f.name) === -1) {
          value = previous[f.name];
        }
        if (!w) { return; }
        // The server stores a locked field's account value, blank included, whatever the browser holds.
        var isLocked = locked.indexOf(f.name) !== -1;
        if (isLocked && (value === undefined || value === null)) { value = ''; }
        var apply = value !== undefined && value !== null && (isLocked || isReplaceable(f, w));
        Promise.resolve(apply ? writeValue(w, value) : null).then(function () {
          if (apply) {
            s.prefilled[f.name] = JSON.stringify(core.normalizeValue(f, readValue(w)));
            syncOutputs(form);
          }
          if (isLocked) {
            s.lockedNames[f.name] = true;
            lockServerOwned(w);
          }
        });
      });
    };

    // An admin browsing as a customer can never give consent for them; the server forces the answer too.
    var lockImpersonatedConsent = function () {
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-impersonation-lock]'), function (box) {
        var wrapper = box.closest('[data-ffb-item]');
        box.checked = false;
        box.ffbLockOff = true;
        box.disabled = true;
        var note = wrapper ? wrapper.querySelector('[data-ffb-lock-note]') : null;
        if (note) {
          note.hidden = false;
          if (note.id) { box.setAttribute('aria-describedby', (note.id + ' ' + (box.getAttribute('aria-describedby') || '')).trim()); }
        }
      });
      inst.applyVisibility();
    };

    inst.tokenUrl = function () {
      var base = sameOrigin(config.tokenUrl);
      if (!base || (!context.productId && !inst.editToken)) {
        return base;
      }
      var url = new root.URL(base);
      if (context.productId) { url.searchParams.set('product_id', String(context.productId)); }
      if (inst.editToken) { url.searchParams.set('edit', inst.editToken); }
      return url.toString();
    };

    // The token response: schedule, store date, edit token, draft, context. Returns a promise to wait for, or null.
    inst.onToken = function (data) {
      var schedule = data ? asObject(data.schedule) : {};
      if (schedule.state === 'closed' || schedule.state === 'not_yet') {
        if (!s.scheduleClosed) { showClosed(schedule); }
        return null;
      }
      if (data && isDay(data.today) && data.today !== s.today) {
        s.today = data.today;
        inst.applyVisibility();
      }
      // A signed-out visitor keeps the link: it may work once they sign in.
      if (data && inst.editToken && data.edit !== inst.editToken) {
        s.editRejected = true;
        if (data.customer === true) {
          clearEditHash(inst.editToken);
          inst.editToken = '';
        }
      }
      var applied = null;
      if (data && !s.contextApplied) {
        s.contextApplied = true;
        if (typeof data.previousAt === 'number') { inst.draft.notBefore(data.previousAt); }
        if (data.customer === true && asObject(data.access).allowed !== false && inst.draft.enabled()) {
          s.customerKnown = true;
          // Server draft first, so prefill only fills what is still empty.
          applied = inst.draft.loadServer(Array.isArray(data.locked) ? data.locked : []).then(function () { applyContext(data); });
        } else {
          applyContext(data);
        }
      }
      // After draft, prefill and previous answers, so nothing writes a tick back.
      return Promise.resolve(applied).then(function () {
        if (data && data.impersonating === true) { lockImpersonatedConsent(); }
      });
    };
  };

  var flowPart = function (inst) {
    var s = inst.s;
    var form = inst.form;
    var fields = inst.fields;
    var pageIds = inst.pageIds;
    var messages = inst.messages;
    var wrapperOf = inst.wrapperOf;
    var setStatus = inst.setStatus;

    var goTo = function (id) {
      var changed = id !== s.current;
      s.current = id;
      inst.renderPages();
      inst.draft.save();
      if (changed) {
        var list = inst.visiblePageIds();
        inst.emit('pagechange', { page: list.indexOf(id) + 1, pages: list.length });
      }
    };

    var focusPage = function () {
      var page = form.querySelector('[data-ffb-page="' + s.current + '"]');
      if (!page) { return; }
      var target = Array.prototype.filter.call(page.querySelectorAll('h1, h2, h3, h4, h5, h6, :scope > legend'), function (el) {
        return !el.closest('[hidden]');
      })[0] || page;
      target.setAttribute('tabindex', '-1');
      target.focus();
      announceStep();
    };

    var announceStep = function () {
      var list = inst.visiblePageIds();
      if (messages.stepOf) {
        setStatus(String(messages.stepOf).replace('%1', String(list.indexOf(s.current) + 1)).replace('%2', String(list.length)));
      }
    };

    var pageFields = function (id) {
      return fields.filter(function (f) { return inst.pageOf[f.name] === id; });
    };

    var resetForm = function () {
      form.reset();
      Array.prototype.forEach.call(form.querySelectorAll('input[type="range"][data-ffb-nodefault]'), function (r) {
        r.setAttribute('data-ffb-unset', '');
      });
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-type="list"]'), function (w) { types.listSetCount(w, 1); });
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-type="address"]'), function (w) { w.ffbAddress.reset(); });
      fillHidden(fields, wrapperOf);
      inst.sub.applySecrets();
      syncOutputs(form);
      s.touched = {};
      s.filledByAction = {};
      s.blurred = [];
      s.visibilityPending = false;
      Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-error]'), function (e) { e.textContent = ''; e.hidden = true; });
      Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (e) { e.removeAttribute('aria-invalid'); });
      s.current = pageIds[0];
      inst.applyVisibility();
    };

    var showFieldErrors = function (serverErrors) {
      var names = Object.keys(asObject(serverErrors)).filter(function (n) { return inst.fieldByName[n]; });
      var firstName = null;
      fields.forEach(function (f) {
        var message = names.indexOf(f.name) !== -1 ? String(serverErrors[f.name]) : '';
        inst.showError(f.name, message);
        if (message && !firstName) { firstName = f.name; }
      });
      if (firstName) {
        inst.emit('invalid', { fields: names });
        goTo(inst.pageOf[firstName]);
        inst.focusField(firstName);
      }
      return firstName !== null;
    };

    inst.goTo = goTo;
    inst.announceStep = announceStep;
    inst.showFieldErrors = showFieldErrors;

    inst.goNext = function () {
      inst.flushDeferred();
      var scope = pageFields(s.current);
      var errors = inst.validate(scope);
      var first = inst.applyErrors(scope, errors);
      if (first) {
        inst.emit('invalid', { fields: Object.keys(errors) });
        inst.focusField(first.name);
        return;
      }
      setStatus('');
      var list = inst.visiblePageIds();
      var idx = list.indexOf(s.current);
      if (idx < list.length - 1) {
        goTo(list[idx + 1]);
        focusPage();
      }
    };

    inst.goPrev = function () {
      inst.flushDeferred();
      var list = inst.visiblePageIds();
      var idx = list.indexOf(s.current);
      if (idx > 0) {
        goTo(list[idx - 1]);
        focusPage();
      }
    };

    inst.isLastPage = function () {
      var list = inst.visiblePageIds();
      return list.indexOf(s.current) >= list.length - 1;
    };

    // Draft values count as typed: set values and prefill never overwrite them.
    inst.applyDraftValues = function (values) {
      Object.keys(values).forEach(function (name) {
        var w = wrapperOf(name);
        if (w) {
          writeValue(w, values[name]);
          s.touched[name] = true;
        }
      });
      inst.applyVisibility();
      syncOutputs(form);
    };

    inst.dropDraftValues = function (values) {
      Object.keys(values).forEach(function (name) {
        var w = wrapperOf(name);
        if (w) {
          writeValue(w, values[name]);
          delete s.touched[name];
        }
      });
      if (!Object.keys(s.dirty).length) { s.current = pageIds[0]; }
      if (s.restoredNote && inst.statusEl && inst.statusEl.contains(s.restoredNote)) { setStatus(''); }
      s.restoredNote = null;
      inst.applyVisibility();
      syncOutputs(form);
    };

    inst.restoreDraft = function () {
      var page = inst.draft.restore();
      if (page === null) {
        return;
      }
      if (pageIds.indexOf(page) !== -1 && inst.pageVisible(page)) {
        s.current = page;
        inst.renderPages();
      }
      if (inst.statusEl) {
        inst.statusEl.textContent = '';
        var note = doc.createElement('span');
        note.textContent = String(messages.restored || '') + ' ';
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'ffb-btn-link';
        btn.textContent = String(messages.startOver || '');
        btn.addEventListener('click', function () {
          inst.draft.discard(false);
          resetForm();
          setStatus('');
        });
        inst.statusEl.appendChild(note);
        inst.statusEl.appendChild(btn);
        s.restoredNote = note;
      }
    };

    // Structured types post several inputs per field; normalising one input alone would blank it.
    inst.prepareForSend = function () {
      fields.filter(function (f) { return ['address', 'date_range', 'map'].indexOf(f.type) === -1; }).forEach(function (f) {
        var w = wrapperOf(f.name);
        controlsOf(w || doc.createElement('div')).forEach(function (c) {
          if (c.tagName === 'TEXTAREA' || (c.tagName === 'INPUT' && ['checkbox', 'radio', 'file'].indexOf(c.type) === -1)) {
            var clean = core.normalizeValue(f, c.value);
            if (typeof clean === 'string' && clean !== c.value) { c.value = clean; }
          }
        });
      });
    };

    inst.onSuccess = function (data) {
      setStatus('');
      inst.draft.discard(true);
      clearEditHash(inst.editToken);
      resetForm();
      form.hidden = true;
      if (inst.successEl) {
        renderSuccess(inst, String(data.message || ''), typeof data.reference === 'string' ? data.reference : '');
        inst.successEl.hidden = false;
        inst.successEl.focus();
      }
      inst.emit('success', typeof data.reference === 'string' ? { reference: data.reference } : {});
      if (isSafeRedirect(data.redirect)) {
        ns.navigate(data.redirect);
      }
    };

    inst.submit = function () {
      if (inst.sub.isBusy() || s.scheduleClosed) {
        return;
      }
      inst.flushDeferred();
      var errors = inst.validate(fields);
      var first = inst.applyErrors(fields, errors);
      if (first) {
        inst.emit('invalid', { fields: Object.keys(errors) });
        goTo(inst.pageOf[first.name]);
        inst.focusField(first.name);
        setStatus(String(messages.fixErrors || ''));
        return;
      }
      setStatus('');
      if (inst.emit('submit', {}, true)) {
        inst.sub.start();
      }
    };
  };

  var mapsPart = function (inst) {
    var mapsConfig = Object.assign({}, asObject(inst.config.maps));
    mapsConfig.searchUrl = sameOrigin(mapsConfig.searchUrl) || '';
    // injectAsset refuses the assets loaded here; these two are used by ffb-map.js.
    mapsConfig.assets = Object.assign({}, asObject(mapsConfig.assets));
    ['googleJs', 'imagePath'].forEach(function (k) {
      if (mapsConfig.assets[k] !== undefined) { mapsConfig.assets[k] = safeAsset(mapsConfig.assets[k]) || ''; }
    });
    var mapMessages = asObject(mapsConfig.messages);
    Array.prototype.forEach.call(inst.form.querySelectorAll('[data-ffb-map-notice]'), function (notice) {
      if (mapMessages.privacy) { notice.textContent = String(mapMessages.privacy); }
    });

    inst.loadMap = function (button) {
      var box = button.closest('[data-ffb-map]');
      var wrapper = box && box.closest('[data-ffb-type="map"]');
      if (!wrapper || wrapper.ffbMap || button.disabled) {
        return;
      }
      var placeholder = box.querySelector('[data-ffb-map-placeholder]');
      var canvas = box.querySelector('[data-ffb-map-canvas]');
      var centre = box.querySelector('[data-ffb-map-centre]');
      var status = box.querySelector('[data-ffb-map-status]');
      button.disabled = true;
      loadMapAssets(asObject(mapsConfig.assets), mapsConfig.provider).then(function () {
        placeholder.hidden = true;
        canvas.hidden = false;
        if (centre) { centre.hidden = false; }
        wrapper.ffbMap = ns.map.init(box, mapsConfig);
      }).catch(function () {
        placeholder.hidden = false;
        canvas.hidden = true;
        button.disabled = false;
        if (status) { status.textContent = String(mapMessages.loadError || ''); }
      });
    };
  };

  // The reCAPTCHA widget for one form, or null when the form has none.
  var recaptchaFor = function (inst) {
    var cfg = inst.config.recaptcha && typeof inst.config.recaptcha === 'object' ? inst.config.recaptcha : null;
    if (!cfg) {
      return null;
    }
    var container = doc.createElement('div');
    container.className = 'ffb-recaptcha';
    container.setAttribute('data-ffb-recaptcha', '');
    var submitNav = inst.submitBtn && inst.submitBtn.closest('.ffb-nav');
    inst.form.insertBefore(container, submitNav && submitNav.parentNode === inst.form ? submitNav : inst.statusEl);

    var widget = null;
    var getWidget = function () {
      if (!widget) {
        widget = loadRecaptchaHelper(cfg.scriptUrl).then(function (helper) {
          return helper.create(container, cfg);
        });
        widget.catch(function () { widget = null; });
      }
      return widget;
    };

    return {
      getToken: function () { return getWidget().then(function (w) { return w.getToken(); }); },
      reset: function () {
        if (widget) {
          widget.then(function (w) { w.reset(); }, function () {});
        }
      },
      // First interaction: a checkbox must be visible to be ticked; invisible types only need the API loaded.
      warm: function () {
        var warm = cfg.invisible
          ? loadRecaptchaHelper(cfg.scriptUrl).then(function (helper) { return helper.loadApi(cfg.rendering && cfg.rendering.hl); })
          : getWidget().then(function (w) { return w.getToken(); });
        warm.catch(function () { /* surfaced on submit */ });
      }
    };
  };

  var clearEditHash = function (editToken) {
    if (editToken && root.history && root.history.replaceState) {
      root.history.replaceState(null, '', root.location.pathname + root.location.search);
    }
  };

  var bindEvents = function (inst) {
    var s = inst.s;
    var form = inst.form;

    var enter = function () {
      if (inst.isLastPage()) {
        inst.submit();
      } else {
        inst.goNext();
      }
    };

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      enter();
    });

    // Implicit submission only: single-line text inputs, never a link, a choice or the map.
    form.addEventListener('keydown', function (event) {
      var t = event.target;
      if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing || !t || t.tagName !== 'INPUT') {
        return;
      }
      if (t.type === 'radio' || t.type === 'checkbox') {
        event.preventDefault();
        return;
      }
      if (IMPLICIT_SUBMIT_TYPES.indexOf(t.type) === -1) {
        return;
      }
      // Stops the browser's own implicit submission too, which would reach enter() through the submit event.
      event.preventDefault();
      if (!t.closest('[data-ffb-type="map"]')) { enter(); }
    });

    if (inst.nextBtn) { inst.nextBtn.addEventListener('click', inst.goNext); }
    if (inst.prevBtn) { inst.prevBtn.addEventListener('click', inst.goPrev); }

    ['pointerup', 'keydown'].forEach(function (type) {
      form.addEventListener(type, function (event) {
        var t = event.target;
        if (t && t.type === 'range' && (type === 'pointerup' || /^(Arrow|Home|End|Page)/.test(String(event.key)))) {
          setRange(t);
          t.dispatchEvent(new root.Event('change', { bubbles: true }));
        }
      });
    });

    form.addEventListener('input', function (event) {
      var t = event.target;
      if (t && t.type === 'range') {
        t.removeAttribute('data-ffb-unset');
        showRange(t);
      }
      var typedIn = wrapperFromEvent(event);
      if (typedIn) { s.touched[typedIn.getAttribute('data-ffb-name')] = s.dirty[typedIn.getAttribute('data-ffb-name')] = true; }
      inst.applyVisibility();
      inst.revalidate(typedIn, true);
      inst.sub.ensureToken();
      inst.draft.schedule();
    });

    form.addEventListener('click', function (event) {
      var mapButton = event.target.closest && event.target.closest('[data-ffb-map-load]');
      if (mapButton) {
        inst.loadMap(mapButton);
      } else {
        handleListClick(event, form);
      }
    });

    // A blur (pointerdown, change, focusout) that renders errors or reveals fields mid-click would push the clicked
    // control away, so that work waits until the click is done; Next, Back and submit run it first.
    var pointerDown = false;
    var deferRevalidate = function (w, onlyIfInvalid) {
      if (!pointerDown) {
        inst.revalidate(w, onlyIfInvalid);
        return;
      }
      var queued = s.blurred.filter(function (e) { return e.w === w; })[0];
      if (queued) {
        queued.onlyIfInvalid = queued.onlyIfInvalid && onlyIfInvalid;
      } else {
        s.blurred.push({ w: w, onlyIfInvalid: onlyIfInvalid });
      }
    };
    inst.flushDeferred = function () {
      var list = s.blurred;
      s.blurred = [];
      if (s.visibilityPending) {
        s.visibilityPending = false;
        inst.applyVisibility();
      }
      list.forEach(function (e) {
        if (e.w.isConnected && !inst.isCondHidden(e.w)) { inst.revalidate(e.w, e.onlyIfInvalid); }
      });
    };
    var release = function () {
      pointerDown = false;
      root.setTimeout(inst.flushDeferred, 0);
    };

    form.addEventListener('change', function (event) {
      var changed = wrapperFromEvent(event);
      if (changed) { s.touched[changed.getAttribute('data-ffb-name')] = s.dirty[changed.getAttribute('data-ffb-name')] = true; }
      if (pointerDown) {
        s.visibilityPending = true;
      } else {
        inst.applyVisibility();
      }
      if (changed) { deferRevalidate(changed, !!changed.ffbAddress); }
      inst.draft.save();
    });

    doc.addEventListener('pointerdown', function () { pointerDown = true; }, true);
    doc.addEventListener('pointerup', release, true);
    doc.addEventListener('pointercancel', release, true);

    form.addEventListener('focusout', function (event) {
      var w = wrapperFromEvent(event);
      // Focus still inside the field (address parts, a consent's privacy link): it is not done yet.
      if (w && !inst.isCondHidden(w) && !w.contains(event.relatedTarget)) {
        formatMasked(inst, w);
        deferRevalidate(w, false);
      }
    });

    form.addEventListener('focusin', function () {
      inst.sub.ensureToken();
      if (inst.recaptcha) { inst.recaptcha.warm(); }
    });
  };

  // The server owns a locked answer and never requires it.
  var lockServerOwned = function (wrapper) {
    lockControls(wrapper);
    controlsOf(wrapper).forEach(function (c) { c.removeAttribute('required'); });
    markRequired(wrapper, false);
  };

  var formatMasked = function (inst, wrapper) {
    var control = wrapper.ffbMasked ? wrapper.querySelector('input') : null;
    var formatted = control ? maskedText(wrapper, control.value) : null;
    if (control && formatted !== control.value) {
      control.value = formatted;
      inst.applyVisibility();
      inst.draft.schedule();
    }
  };

  // Calls a part method by name when invoked, so a factory can be built before the parts are installed.
  var later = function (inst, name) {
    return function () { return inst[name].apply(inst, arguments); };
  };

  // Order contract: instance, reCAPTCHA, draft and submit are built first (parts are only named, never called); then the parts.
  var init = function (rootEl) {
    if (!rootEl || rootEl.dataset.ffbReady === '1') {
      return;
    }
    var form = rootEl.querySelector('[data-ffb-form]');
    var config = readConfig(rootEl);
    if (!config || !form) {
      return;
    }
    rootEl.dataset.ffbReady = '1';
    rootEl.classList.add('ffb-js');

    var inst = createInstance(rootEl, form, config);
    var s = inst.s;
    inst.recaptcha = recaptchaFor(inst);

    var draftCfg = Object.assign({}, asObject(config.draft));
    draftCfg.serverUrl = sameOrigin(draftCfg.serverUrl) || '';
    inst.draft = ns.createDraftSync({
      cfg: draftCfg,
      formId: config.formId,
      fields: inst.fields,
      readValues: later(inst, 'collectValues'),
      applyValues: later(inst, 'applyDraftValues'),
      dropValues: later(inst, 'dropDraftValues'),
      getPage: function () { return s.current; },
      isCustomer: function () { return s.customerKnown; },
      isEditing: function () { return inst.editToken !== ''; },
      isLocked: function (name) { return s.lockedNames[name] === true; },
      isDirty: function (name) { return s.dirty[name] === true; },
      anyDirty: function () { return Object.keys(s.dirty).length > 0; },
      prefilledJson: function (name) { return s.prefilled[name]; },
      formKey: ns.submit.resolveFormKey
    });

    inst.sub = ns.submit.create({
      form: form,
      tokenInput: form.querySelector('[name="ffb_token"]'),
      keyInput: form.querySelector('[name="form_key"]'),
      submitBtn: inst.submitBtn,
      messages: inst.messages,
      submitUrl: sameOrigin(config.submitUrl),
      getTokenUrl: later(inst, 'tokenUrl'),
      productId: inst.context.productId,
      getEditToken: function () { return inst.editToken && !s.editRejected ? inst.editToken : ''; },
      recaptcha: inst.recaptcha,
      prepare: later(inst, 'prepareForSend'),
      setStatus: inst.setStatus,
      onToken: later(inst, 'onToken'),
      onSettled: later(inst, 'openActions'),
      onSuccess: later(inst, 'onSuccess'),
      onFieldErrors: later(inst, 'showFieldErrors')
    });

    [viewPart, validationPart, contextPart, flowPart, mapsPart].forEach(function (part) { part(inst); });
    bindEvents(inst);

    Array.prototype.forEach.call(form.querySelectorAll('[data-ffb-type="address"]'), function (w) {
      w.ffbAddress = types.addressController(w, inst.regionsUrl, function () { inst.updateHint(w); });
      w.ffbAddress.sync();
    });
    applyMasks(inst.fields, inst.wrapperOf);
    fillHidden(inst.fields, inst.wrapperOf);
    inst.sub.applySecrets();
    syncOutputs(form);
    inst.applyVisibility();
    inst.restoreDraft();
    if (!inst.context.eager && !inst.editToken) { inst.openActions(); }
    inst.fields.forEach(function (f) {
      var w = inst.wrapperOf(f.name);
      if (w && f.locked && typeof f.prefill === 'string' && f.prefill.indexOf('product.') === 0) {
        s.lockedNames[f.name] = true;
        lockServerOwned(w);
      }
    });
    if (inst.context.eager || inst.editToken) { inst.sub.ensureToken(); }
    inst.emit('ready', {});
  };

  ns.navigate = function (url) { root.location.assign(url); };
  ns.init = init;

  var initAll = function () {
    Array.prototype.forEach.call(doc.querySelectorAll('[data-ffb-root]'), init);
  };
  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
