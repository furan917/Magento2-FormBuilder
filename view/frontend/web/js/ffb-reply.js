(function (root) {
  'use strict';

  const MAX = 5000;
  const MAX_ROWS = 16;
  // Screen readers hear the count only when one of these is crossed, not on every keystroke.
  const THRESHOLDS = [500, 100, 0];
  const forms = new WeakMap();

  // Approximates the server (code points after trim, CRLF as one); MessageSanitizer remains the authority.
  function length(value) {
    return Array.from(String(value).replace(/\r\n?/g, '\n').trim()).length;
  }

  function rows(scrollHeight, lineHeight, minRows, maxRows) {
    if (!(lineHeight > 0)) {
      return minRows;
    }
    return Math.min(maxRows, Math.max(minRows, Math.ceil(scrollHeight / lineHeight)));
  }

  function band(remaining) {
    return THRESHOLDS.filter((t) => remaining <= t).length;
  }

  function grow(input, minRows) {
    const view = input.ownerDocument.defaultView;
    const style = view.getComputedStyle(input);
    const fontSize = parseFloat(style.fontSize) || 16;
    const lineHeight = parseFloat(style.lineHeight) || fontSize * 1.2;
    const padding = (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0);
    input.rows = minRows;
    input.rows = rows(input.scrollHeight - padding, lineHeight, minRows, MAX_ROWS);
  }

  function init(form) {
    const input = form.querySelector('[data-ffb-reply-body]');
    if (!input || forms.has(form)) {
      return;
    }
    const count = form.querySelector('[data-ffb-reply-count]');
    const live = form.querySelector('[data-ffb-reply-live]');
    const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    // maxlength counts UTF-16 units (an emoji is two), so the limit in characters comes from data-ffb-max.
    const max = parseInt(input.getAttribute('data-ffb-max'), 10) || MAX;
    const minRows = input.rows || 4;
    const state = { submitting: false, band: band(max - length(input.value)), update: null };

    state.update = () => {
      const used = length(input.value);
      const remaining = Math.max(0, max - used);
      const next = band(remaining);
      if (count) {
        count.textContent = used + ' / ' + max;
        count.classList.toggle('is-near', next > 0);
        count.classList.toggle('is-over', used > max);
        count.hidden = false;
      }
      if (live && next > state.band) {
        live.textContent = String(count ? count.getAttribute('data-ffb-left') || '%1' : '%1').replace('%1', remaining);
      }
      state.band = next;
      buttons.forEach((button) => {
        button.disabled = state.submitting || used === 0 || used > max;
      });
      grow(input, minRows);
    };

    forms.set(form, state);
    input.addEventListener('input', state.update);
    state.update();
  }

  function onSubmit(event) {
    const state = forms.get(event.target);
    if (!state) {
      return;
    }
    if (state.submitting) {
      event.preventDefault();
      return;
    }
    if (event.defaultPrevented) {
      return;
    }
    state.submitting = true;
    state.update();
  }

  function boot(doc) {
    doc.querySelectorAll('form[data-ffb-reply]').forEach(init);
  }

  const api = { length, rows, init };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).reply = api;
    const doc = root.document;
    doc.addEventListener('submit', onSubmit);
    // Back-forward cache restores the page with the button still disabled.
    root.addEventListener('pageshow', (event) => {
      if (!event.persisted) {
        return;
      }
      doc.querySelectorAll('form[data-ffb-reply]').forEach((form) => {
        const state = forms.get(form);
        if (state) {
          state.submitting = false;
          state.update();
        }
      });
    });
    if (doc.readyState === 'loading') {
      doc.addEventListener('DOMContentLoaded', () => boot(doc));
    } else {
      boot(doc);
    }
  }
}(typeof window !== 'undefined' ? window : globalThis));
