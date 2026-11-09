(function (root) {
  'use strict';

  const CODE = /^[a-z][a-z0-9_.-]{0,63}$/;
  const BUILTIN_PREVIEWS = [
    'text', 'email', 'tel', 'url', 'number', 'date', 'datetime', 'time', 'month', 'suggest', 'color', 'hidden', 'password',
    'textarea', 'select', 'multiselect', 'country', 'radio', 'checkboxes', 'checkbox', 'consent', 'rating', 'range',
    'file', 'scale', 'cards', 'matrix', 'date_range', 'address', 'map', 'toggle', 'list',
  ];
  const BUILTIN_SETTING_TYPES = ['int', 'number', 'bool', 'enum', 'list', 'string'];

  const previews = new Map();
  const controls = new Map();

  function register(map, builtin, code, fn) {
    if (typeof code !== 'string' || !CODE.test(code) || builtin.indexOf(code) >= 0) {
      throw new Error('Invalid or built-in builder hook code: ' + code);
    }
    if (typeof fn !== 'function') {
      throw new Error('Builder hook must be a function: ' + code);
    }
    map.set(code, fn);
  }

  // Whichever builder script runs first creates the object; later scripts reuse it.
  const ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  const api = ns.builderRegistry && typeof ns.builderRegistry === 'object' ? ns.builderRegistry : {};
  api.preview = (code, fn) => register(previews, BUILTIN_PREVIEWS, code, fn);
  api.inspectorControl = (settingType, fn) => register(controls, BUILTIN_SETTING_TYPES, settingType, fn);
  api.getPreview = (code) => previews.get(code) || null;
  api.getInspectorControl = (settingType) => controls.get(settingType) || null;
  // Scripts loading before this one may pre-create `builderRegistry = {queue: [['preview', code, fn], ...]}`.
  const queued = Array.isArray(api.queue) ? api.queue : [];
  api.queue = [];
  queued.forEach((entry) => {
    try {
      api[entry[0]].apply(api, entry.slice(1));
    } catch (e) {
      if (root.console) {
        root.console.warn('Form builder hook rejected', entry[1], e);
      }
    }
  });
  ns.builderRegistry = api;

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
