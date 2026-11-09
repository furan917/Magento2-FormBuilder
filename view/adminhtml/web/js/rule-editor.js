(function (root) {
  'use strict';

  // Same compilation as ffb-core.js, so the tester shows exactly what the storefront will accept.
  function patternSafety() {
    return typeof module === 'object' && module.exports
      ? require('./pattern-safety.js')
      : (root.FuranFormBuilder || {}).patternSafety;
  }

  function compile(pattern) {
    let re;
    try {
      re = new RegExp('^(?:' + pattern + ')$', 'u');
    } catch (e) {
      return { error: 'Browsers cannot read this pattern: ' + e.message };
    }
    const safety = patternSafety();
    if (safety && safety.isRisky(pattern)) {
      return { error: 'This pattern is too complex: avoid repeats next to or inside other repeats, and back-references.' };
    }
    return { re };
  }

  function slug(text) {
    return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '').slice(0, 63);
  }

  function init(doc) {
    const form = doc.querySelector('[data-ffb-rule-form]');
    if (!form) {
      return;
    }
    const pattern = form.querySelector('[data-ffb-rule-pattern]');
    const patternError = form.querySelector('[data-ffb-rule-pattern-error]');
    const samples = doc.querySelector('[data-ffb-rule-samples]');
    const results = doc.querySelector('[data-ffb-rule-results]');
    const label = form.querySelector('[data-ffb-rule-label]');
    const code = form.querySelector('[data-ffb-rule-code]');

    const test = () => {
      const compiled = compile(pattern.value);
      patternError.hidden = !compiled.error;
      patternError.textContent = compiled.error || '';
      results.textContent = '';
      if (compiled.error || pattern.value === '') {
        return;
      }
      samples.value.split('\n').filter((line) => line !== '').forEach((line) => {
        const ok = compiled.re.test(line);
        const li = doc.createElement('li');
        li.className = ok ? 'is-match' : 'is-miss';
        const value = doc.createElement('code');
        value.textContent = line;
        const verdict = doc.createElement('span');
        verdict.textContent = ok ? 'Matches' : 'Does not match';
        li.append(value, verdict);
        results.appendChild(li);
      });
    };
    pattern.addEventListener('input', test);
    samples.addEventListener('input', test);
    test();

    if (code && label) {
      let touched = code.value !== '';
      code.addEventListener('input', () => { touched = true; });
      label.addEventListener('input', () => {
        if (!touched) {
          code.value = slug(label.value);
        }
      });
    }

    const del = doc.querySelector('[data-ffb-rule-delete]');
    const ask = del && del.querySelector('[data-ffb-rule-delete-ask]');
    if (ask) {
      const confirmBox = del.querySelector('[data-ffb-rule-delete-confirm]');
      ask.addEventListener('click', () => {
        ask.hidden = true;
        confirmBox.hidden = false;
        confirmBox.querySelector('[type="submit"]').focus();
      });
      del.querySelector('[data-ffb-rule-delete-cancel]').addEventListener('click', () => {
        confirmBox.hidden = true;
        ask.hidden = false;
        ask.focus();
      });
    }
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { compile, slug, init };
  } else if (root.document.readyState === 'loading') {
    root.document.addEventListener('DOMContentLoaded', () => init(root.document));
  } else {
    init(root.document);
  }
}(typeof window !== 'undefined' ? window : globalThis));
