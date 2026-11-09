(function (root) {
  'use strict';

  const API_URL = 'https://www.google.com/recaptcha/api.js';
  const CALLBACK = 'ffbRecaptchaOnload';
  const POLL_MS = 50;
  let apiPromise = null;

  function whenReady(resolve) {
    const g = root.grecaptcha;
    if (g && typeof g.render === 'function') {
      resolve(g);
    } else if (g && typeof g.ready === 'function') {
      g.ready(() => resolve(root.grecaptcha));
    } else {
      setTimeout(() => whenReady(resolve), POLL_MS);
    }
  }

  function loadApi(hl) {
    if (apiPromise) {
      return apiPromise;
    }
    apiPromise = new Promise((resolve) => {
      const doc = root.document;
      const hasApi = root.grecaptcha || doc.querySelector('script[src*="/recaptcha/api.js"]');
      if (hasApi) {
        whenReady(resolve);
        return;
      }
      root[CALLBACK] = () => resolve(root.grecaptcha);
      const script = doc.createElement('script');
      script.async = true;
      script.src = API_URL + '?onload=' + CALLBACK + '&render=explicit' + (hl ? '&hl=' + encodeURIComponent(hl) : '');
      doc.head.appendChild(script);
    });
    return apiPromise;
  }

  /** One widget per form instance: create(element, config.recaptcha) -> { getToken(), reset() }. */
  function create(element, config) {
    const rendering = config.rendering || {};
    let widgetId = null;
    let grecaptcha = null;
    let pending = null;

    function settle(method, value) {
      const current = pending;
      pending = null;
      if (current) {
        current[method](value);
      }
    }

    function render(g) {
      grecaptcha = g;
      if (widgetId === null) {
        widgetId = g.render(element, Object.assign({}, rendering, {
          callback: (token) => settle('resolve', token),
          'error-callback': () => settle('reject', new Error('reCAPTCHA error')),
          'expired-callback': () => {},
        }));
      }
      return g;
    }

    function getToken() {
      return loadApi(rendering.hl).then(render).then((g) => {
        if (!config.invisible) {
          return g.getResponse(widgetId);
        }
        if (!pending) {
          const entry = {};
          entry.promise = new Promise((resolve, reject) => {
            entry.resolve = resolve;
            entry.reject = reject;
          });
          pending = entry;
        }
        const promise = pending.promise;
        g.execute(widgetId);
        return promise;
      });
    }

    function reset() {
      pending = null;
      if (grecaptcha && widgetId !== null) {
        grecaptcha.reset(widgetId);
      }
    }

    return { getToken, reset };
  }

  const api = { create, loadApi };
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).recaptcha = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
