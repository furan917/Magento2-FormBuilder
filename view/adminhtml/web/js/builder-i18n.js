(function (root) {
  'use strict';

  // Mirrors Model/I18n/TextCatalog.php; Test/fixtures/translatable-texts.json keeps the two in step.
  const FORM_TEXTS = [
    ['submit_label', 50, false],
    ['next_label', 50, false],
    ['prev_label', 50, false],
    ['success_message', 2000, true],
    ['page_title', 255, false],
    ['meta_description', 500, false],
    ['autoresponder_subject', 255, false],
    ['autoresponder_body', 5000, true],
    ['closed_message', 500, false],
    ['not_open_message', 500, false],
  ];
  const CONTENT_LIMITS = { heading: [255, false], notice: [1000, true] };

  let phrases = {};

  /** Admin UI text: the English phrase is the key, as with Magento's __(). */
  function t(text, ...args) {
    const out = Object.prototype.hasOwnProperty.call(phrases, text) && phrases[text] ? phrases[text] : text;
    return String(out).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m));
  }

  function setPhrases(dict) {
    phrases = dict && typeof dict === 'object' && !Array.isArray(dict) ? dict : {};
  }

  function ruleKey(rule) {
    const code = String((rule && rule.rule) || '');
    return code === 'preset' ? code + ':' + String(rule.value === undefined || rule.value === null ? '' : rule.value) : code;
  }

  function specOf(typeSpecs, type) {
    const spec = typeSpecs && typeSpecs[type];
    return spec && typeof spec === 'object' && !Array.isArray(spec) ? spec : {};
  }

  // typeSpecs: field type code => settingsSpec.
  function collect(schema, settings, typeSpecs) {
    const entries = [];
    const add = (path, text, max, multiline, url) => {
      if (typeof text === 'string' && text.trim() !== '') {
        entries.push({ path, text, max, multiline: !!multiline, url: !!url });
      }
    };
    const s = settings || {};
    FORM_TEXTS.forEach(([key, max, multiline]) => add(['form', key], s[key], max, multiline));

    const field = (id, f) => {
      add(['items', id, 'label'], f.label, 255);
      add(['items', id, 'help'], f.help, 1000, true);
      add(['items', id, 'placeholder'], f.placeholder, 255);
      (f.options || []).forEach((o) => {
        const value = String(o.value === undefined || o.value === null ? '' : o.value);
        add(['items', id, 'options', value, 'label'], o.label, 255);
        add(['items', id, 'options', value, 'description'], o.description, 255);
      });
      (f.rules || []).forEach((r) => add(['items', id, 'rules', ruleKey(r), 'message'], r.message, 255));
      const spec = specOf(typeSpecs, String(f.type || ''));
      const values = f.settings && typeof f.settings === 'object' ? f.settings : {};
      Object.keys(spec).forEach((key) => {
        const def = spec[key] || {};
        if (!def.translatable) {
          return;
        }
        const value = values[key] === undefined ? '' : values[key];
        if (def.type === 'list') {
          const list = Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []);
          list.forEach((entry, i) => add(['items', id, 'settings', key, String(i)], entry, 255));
          return;
        }
        add(['items', id, 'settings', key], value, Number(def.max_length || 255), !!def.multiline, def.format === 'url');
      });
    };
    const items = (list) => (list || []).forEach((item) => {
      const id = String(item.id || '');
      if (item.kind === 'section') {
        add(['items', id, 'title'], item.title, 255);
        add(['items', id, 'description'], item.description, 1000, true);
        items(item.items);
      } else if (item.kind === 'content') {
        const [max, multiline] = CONTENT_LIMITS[item.type] || [5000, true];
        add(['items', id, 'title'], item.title, 255);
        add(['items', id, 'text'], item.text, max, multiline);
      } else if (item.kind === 'field') {
        field(id, item);
      }
    });
    ((schema && schema.pages) || []).forEach((page) => {
      add(['items', String(page.id || ''), 'title'], page.title, 255);
      items(page.items);
    });
    return entries;
  }

  function read(overlay, path) {
    let node = overlay;
    for (let i = 0; i < path.length; i++) {
      if (!node || typeof node !== 'object' || !Object.prototype.hasOwnProperty.call(node, path[i])) {
        return null;
      }
      node = node[path[i]];
    }
    return node && typeof node === 'object' && !Array.isArray(node) ? node : null;
  }

  // An empty entry removes the text and any parents left empty.
  function write(overlay, path, entry) {
    const out = overlay && typeof overlay === 'object' ? Object.assign({}, structuredClone(overlay)) : {};
    const trail = [out];
    let node = out;
    for (let i = 0; i < path.length - 1; i++) {
      // PHP sends {"0": ...} as a JSON list, so lists are kept as objects rather than replaced.
      if (Array.isArray(node[path[i]])) {
        node[path[i]] = Object.assign({}, node[path[i]]);
      } else if (!node[path[i]] || typeof node[path[i]] !== 'object') {
        node[path[i]] = {};
      }
      node = node[path[i]];
      trail.push(node);
    }
    const last = path[path.length - 1];
    if (entry && typeof entry.t === 'string' && entry.t !== '') {
      node[last] = { t: entry.t, s: String(entry.s === undefined ? '' : entry.s) };
      return out;
    }
    delete node[last];
    for (let i = trail.length - 1; i > 0; i--) {
      if (Object.keys(trail[i]).length === 0) {
        delete trail[i - 1][path[i - 1]];
      }
    }
    return out;
  }

  function status(overlay, entry) {
    const value = read(overlay, entry.path);
    if (!value || typeof value.t !== 'string' || value.t === '') {
      return 'missing';
    }
    return value.s === entry.text ? 'done' : 'outdated';
  }

  function progress(schema, settings, overlay, typeSpecs) {
    const stats = { total: 0, translated: 0, outdated: 0 };
    collect(schema, settings, typeSpecs).forEach((entry) => {
      stats.total++;
      const st = status(overlay, entry);
      if (st !== 'missing') {
        stats.translated++;
        stats.outdated += st === 'outdated' ? 1 : 0;
      }
    });
    return stats;
  }

  // Mirrors Translator::apply.
  function apply(schema, settings, overlay, typeSpecs) {
    const outSchema = structuredClone(schema);
    const outSettings = structuredClone(settings || {});
    if (!overlay || typeof overlay !== 'object') {
      return { schema: outSchema, settings: outSettings };
    }
    const text = (path) => {
      const value = read(overlay, path);
      return value && typeof value.t === 'string' && value.t !== '' ? value.t : null;
    };
    FORM_TEXTS.forEach(([key]) => {
      const v = text(['form', key]);
      if (v !== null && typeof outSettings[key] === 'string' && outSettings[key].trim() !== '') {
        outSettings[key] = v;
      }
    });
    const known = {};
    collect(schema, settings, typeSpecs).forEach((e) => { known[e.path.join('\u0000')] = true; });
    const pick = (path) => (known[path.join('\u0000')] ? text(path) : null);
    const item = (node) => {
      const id = String(node.id || '');
      ['label', 'help', 'placeholder', 'title', 'description', 'text'].forEach((key) => {
        const v = pick(['items', id, key]);
        if (v !== null) {
          node[key] = v;
        }
      });
      (node.options || []).forEach((o) => {
        ['label', 'description'].forEach((key) => {
          const v = pick(['items', id, 'options', String(o.value), key]);
          if (v !== null) {
            o[key] = v;
          }
        });
      });
      (node.rules || []).forEach((r) => {
        const v = pick(['items', id, 'rules', ruleKey(r), 'message']);
        if (v !== null) {
          r.message = v;
        }
      });
      const values = node.settings && typeof node.settings === 'object' ? node.settings : null;
      if (values) {
        Object.keys(values).forEach((key) => {
          if (Array.isArray(values[key])) {
            values[key] = values[key].map((entry, i) => {
              const v = pick(['items', id, 'settings', key, String(i)]);
              return v === null ? entry : v;
            });
          } else {
            const v = pick(['items', id, 'settings', key]);
            if (v !== null) {
              values[key] = v;
            }
          }
        });
      }
      (node.items || []).forEach(item);
    };
    outSchema.pages.forEach(item);
    return { schema: outSchema, settings: outSettings };
  }

  const api = { FORM_TEXTS, t, setPhrases, ruleKey, collect, read, write, status, progress, apply };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderI18n = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
