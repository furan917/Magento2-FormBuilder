(function (root) {
  'use strict';

  var ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  var core = ns.core;
  if (ns.createDraftSync || !core) {
    return;
  }

  var NEVER_STORED = ['form_key', 'ffb_token', 'ffb_website', 'g-recaptcha-response'];
  var SAVE_DEBOUNCE = 400;
  var SERVER_DEBOUNCE = 5000;

  var drafts = {
    create: function (storage, key, ttlHours) {
      var attempt = function (fn, fallback) {
        try {
          return storage ? fn() : fallback;
        } catch (e) {
          return fallback;
        }
      };
      var clear = function () { attempt(function () { storage.removeItem(key); }); };
      return {
        load: function () {
          var raw = attempt(function () { return storage.getItem(key); }, null);
          if (raw === null || raw === undefined) {
            return null;
          }
          var data = null;
          try {
            data = JSON.parse(raw);
          } catch (e) {
            data = null;
          }
          var ttl = (parseFloat(ttlHours) || 0) * 3600000;
          if (!data || data.v !== 1 || typeof data.savedAt !== 'number' || typeof data.page !== 'string' ||
              !data.values || typeof data.values !== 'object' || Array.isArray(data.values) ||
              (ttl > 0 && Date.now() - data.savedAt > ttl)) {
            clear();
            return null;
          }
          return data;
        },
        // synced: the server has held this draft, so a server with none means it was cleared elsewhere.
        save: function (values, page, synced) {
          attempt(function () {
            storage.setItem(key, JSON.stringify({ v: 1, savedAt: Date.now(), page: page, values: values, synced: !!synced }));
          });
        },
        markSynced: function () {
          var data = this.load();
          if (data) {
            data.synced = true;
            attempt(function () { storage.setItem(key, JSON.stringify(data)); });
          }
        },
        clear: clear
      };
    }
  };

  var storageFor = function (mode) {
    try {
      if (mode === 'session') {
        return root.sessionStorage;
      }
      if (mode === 'local') {
        return root.localStorage;
      }
    } catch (e) { /* storage blocked */ }
    return null;
  };

  var isDraftable = function (field) {
    // File and hidden values never reach browser or server drafts, whatever the draft flag says.
    return field.draft !== false && field.type !== 'file' && field.type !== 'hidden' && NEVER_STORED.indexOf(field.name) === -1;
  };

  var ownValue = function (values, name) {
    return Object.prototype.hasOwnProperty.call(values, name);
  };

  // Server copy only for customers in "local" mode, never while editing a submission.
  // Inputs: cfg {mode,key,ttlHours,serverUrl}, formId, fields, readValues() -> {name: value}, applyValues({name: value}),
  // getPage(), isCustomer(), isEditing(), isLocked(name), isDirty(name), anyDirty(), prefilledJson(name), formKey(),
  // dropValues({name: value}) puts back what a dropped browser draft replaced.
  var createDraftSync = function (o) {
    var cfg = o.cfg;
    var store = drafts.create(storageFor(cfg.mode), cfg.key, cfg.ttlHours);
    var draftable = o.fields.filter(isDraftable);
    var browserSavedAt = 0;
    var restored = null;
    var synced = false;
    var notBefore = 0;
    var saveTimer = null;
    var serverReady = false;
    var serverClosed = false;
    var serverHas = false;
    var serverSaving = false;
    var serverPending;
    var serverTimer = null;

    var serverSyncOn = function () {
      return cfg.mode === 'local' && typeof cfg.serverUrl === 'string' && cfg.serverUrl !== '' && !o.isEditing();
    };

    var serverRequest = function (params, keepalive) {
      var body = new root.URLSearchParams(params);
      body.set('form_id', String(o.formId));
      body.set('form_key', o.formKey());
      return root.fetch(cfg.serverUrl, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: !!keepalive,
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: body
      });
    };

    var flushServer = function () {
      if (serverSaving || serverClosed || serverPending === undefined) { return; }
      var values = serverPending;
      serverPending = undefined;
      if (values === null && !serverHas) { return; }
      serverSaving = true;
      serverHas = values !== null;
      serverRequest(values === null ? { action: 'clear' } : { values: JSON.stringify(values) })
        .then(function (res) {
          if (res && res.ok && values !== null && !synced) {
            synced = true;
            store.markSynced();
          }
        })
        .catch(function () { /* the next edit retries */ })
        .then(function () {
          serverSaving = false;
          if (serverPending !== undefined && !serverClosed) {
            serverTimer = setTimeout(flushServer, SERVER_DEBOUNCE);
          }
        });
    };

    var syncServer = function (values) {
      if (!serverReady || serverClosed || !o.isCustomer() || !serverSyncOn()) { return; }
      serverPending = values;
      clearTimeout(serverTimer);
      serverTimer = setTimeout(flushServer, SERVER_DEBOUNCE);
    };

    var clearServer = function (final) {
      if (!o.isCustomer() || !serverSyncOn()) { return; }
      if (final) { serverClosed = true; }
      clearTimeout(serverTimer);
      serverPending = undefined;
      serverHas = false;
      serverRequest({ action: 'clear' }, final).catch(function () { /* expires on its own */ });
    };

    var save = function () {
      clearTimeout(saveTimer);
      var values = {};
      var any = false;
      var all = o.readValues();
      draftable.filter(function (f) {
        return !o.isLocked(f.name) &&
          !(o.prefilledJson(f.name) !== undefined && JSON.stringify(core.normalizeValue(f, all[f.name])) === o.prefilledJson(f.name));
      }).forEach(function (f) {
        values[f.name] = all[f.name];
        if (!core.isEmpty(core.normalizeValue(f, all[f.name]))) { any = true; }
      });
      if (any) {
        store.save(values, o.getPage(), synced);
      } else {
        store.clear();
      }
      syncServer(any ? values : null);
    };

    var schedule = function () {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(save, SAVE_DEBOUNCE);
    };

    // Undoes a restored browser draft that a newer answer replaced; what the visitor typed since stays.
    var dropRestored = function () {
      if (!restored) { return; }
      var back = {};
      Object.keys(restored).forEach(function (name) {
        if (!o.isDirty(name)) { back[name] = restored[name]; }
      });
      restored = null;
      browserSavedAt = 0;
      synced = false;
      store.clear();
      o.dropValues(back);
    };

    var applyDraft = function (values, skip) {
      var picked = {};
      draftable.forEach(function (f) {
        if (ownValue(values, f.name) && !skip(f.name)) { picked[f.name] = values[f.name]; }
      });
      o.applyValues(picked);
    };

    return {
      save: save,
      schedule: schedule,
      enabled: serverSyncOn,
      // Returns the saved page id, or null when there is no usable browser draft.
      restore: function () {
        var data = store.load();
        if (!data) {
          return null;
        }
        browserSavedAt = data.savedAt;
        synced = data.synced === true;
        var before = o.readValues();
        restored = {};
        draftable.forEach(function (f) {
          if (ownValue(data.values, f.name)) { restored[f.name] = before[f.name]; }
        });
        applyDraft(data.values, function () { return false; });
        return data.page;
      },
      // at: when the answer being refilled was saved (epoch ms); older drafts never replace it.
      notBefore: function (at) {
        notBefore = at;
        if (browserSavedAt > 0 && browserSavedAt <= at) { dropRestored(); }
      },
      loadServer: function (locked) {
        return root.fetch(cfg.serverUrl, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        }).then(function (res) { return res.ok ? res.json() : null; })
          .then(function (data) {
            var values = data && data.values && typeof data.values === 'object' && !Array.isArray(data.values) ? data.values : null;
            serverHas = values !== null;
            // Only an answer that says "no draft" counts; a failed request proves nothing.
            if (data && data.values === null && synced) {
              dropRestored();
            }
            // Server and browser clocks may differ; a tie keeps the browser draft.
            if (values && typeof data.updatedAt === 'number' && data.updatedAt > browserSavedAt && data.updatedAt > notBefore) {
              synced = true;
              applyDraft(values, function (name) { return o.isDirty(name) || o.isLocked(name) || locked.indexOf(name) !== -1; });
            }
          })
          .catch(function () { /* the browser draft still works */ })
          .then(function () {
            serverReady = true;
            if (o.anyDirty()) { schedule(); }
          });
      },
      // final: the form is done (submitted), so no later edit may write a server draft again.
      discard: function (final) {
        clearTimeout(saveTimer);
        restored = null;
        synced = false;
        store.clear();
        clearServer(final);
      }
    };
  };

  ns.createDraftSync = createDraftSync;
})(typeof globalThis !== 'undefined' ? globalThis : this);
