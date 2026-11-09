(function (root) {
  'use strict';

  const WRAP = { bold: '**', italic: '*' };
  const LIST = {
    bullets: { test: /^- /, strip: /^- /, prefix: () => '- ' },
    numbers: { test: /^\d{1,3}\. /, strip: /^\d{1,3}\. /, prefix: (i) => (i + 1) + '. ' },
  };
  const SHORTCUTS = { b: 'bold', i: 'italic', k: 'link' };
  const URL_PLACEHOLDER = 'https://';

  function wrap(value, start, end, marker) {
    const m = marker.length;
    const surrounded = value.slice(start - m, start) === marker && value.slice(end, end + m) === marker
      // A single * next to another * belongs to bold, so italic wraps instead of unwrapping.
      && !(marker === '*' && (value[start - m - 1] === '*' || value[end + m] === '*'));
    if (surrounded) {
      return { value: value.slice(0, start - m) + value.slice(start, end) + value.slice(end + m), start: start - m, end: end - m };
    }
    return { value: value.slice(0, start) + marker + value.slice(start, end) + marker + value.slice(end), start: start + m, end: end + m };
  }

  function link(value, start, end, labels) {
    const selected = value.slice(start, end);
    const isUrl = /^(https?:\/\/|mailto:)\S+$/i.test(selected);
    const label = isUrl || selected === '' ? labels.linkText : selected;
    const url = isUrl ? selected : URL_PLACEHOLDER;
    const text = '[' + label + '](' + url + ')';
    const next = value.slice(0, start) + text + value.slice(end);
    return isUrl || selected === ''
      ? { value: next, start: start + 1, end: start + 1 + label.length }
      : { value: next, start: start + label.length + 3, end: start + label.length + 3 + url.length };
  }

  function list(value, start, end, kind) {
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const stop = end > start && value[end - 1] === '\n' ? end - 1 : end;
    let lineEnd = value.indexOf('\n', stop);
    lineEnd = lineEnd === -1 ? value.length : lineEnd;
    const lines = value.slice(lineStart, lineEnd).split('\n');
    const all = lines.every((line) => kind.test.test(line));
    const block = lines.map((line, i) => (all ? line.replace(kind.strip, '') : kind.prefix(i) + line.replace(kind.strip, ''))).join('\n');
    const after = lineStart + block.length;
    // A bare caret stays a caret, so typing continues the item instead of replacing it.
    return { value: value.slice(0, lineStart) + block + value.slice(lineEnd), start: start === end ? after : lineStart, end: after };
  }

  /** Pure: returns the new value and selection for a toolbar action. */
  function applyFormat(value, start, end, action, labels) {
    if (WRAP[action]) {
      return wrap(value, start, end, WRAP[action]);
    }
    if (action === 'link') {
      return link(value, start, end, labels);
    }
    if (LIST[action]) {
      return list(value, start, end, LIST[action]);
    }
    return { value, start, end };
  }

  // Replaces only the changed span so the browser's undo history keeps working where insertText is supported.
  function write(doc, input, next) {
    const old = input.value;
    let from = 0;
    while (from < old.length && from < next.value.length && old[from] === next.value[from]) {
      from++;
    }
    let tail = 0;
    while (tail < old.length - from && tail < next.value.length - from
      && old[old.length - 1 - tail] === next.value[next.value.length - 1 - tail]) {
      tail++;
    }
    const insert = next.value.slice(from, next.value.length - tail);
    input.focus();
    input.setSelectionRange(from, old.length - tail);
    const inserted = typeof doc.execCommand === 'function' && insert !== '' && doc.execCommand('insertText', false, insert);
    if (!inserted || input.value !== next.value) {
      input.value = next.value;
    }
    input.setSelectionRange(next.start, next.end);
  }

  function init(form) {
    const doc = form.ownerDocument;
    const input = form.querySelector('[data-ffb-md-input]');
    const preview = form.querySelector('[data-ffb-md-preview]');
    const head = form.querySelector('[data-ffb-md-head]');
    const markdown = (root.FuranFormBuilder || {}).replyMarkdown;
    if (!input || !preview || !head || !markdown) {
      return;
    }
    const labels = { linkText: input.getAttribute('data-ffb-link-text') || 'link' };
    const tools = Array.from(form.querySelectorAll('[data-ffb-md]'));
    const tabs = Array.from(form.querySelectorAll('[data-ffb-md-tab]'));

    const run = (action) => {
      write(doc, input, applyFormat(input.value, input.selectionStart, input.selectionEnd, action, labels));
    };
    const show = (name) => {
      const previewing = name === 'preview';
      if (previewing) {
        const fragment = markdown.render(input.value, doc);
        if (!fragment.childNodes.length) {
          fragment.appendChild(doc.createTextNode(preview.getAttribute('data-ffb-empty') || ''));
        }
        preview.replaceChildren(fragment);
      }
      input.hidden = previewing;
      preview.hidden = !previewing;
      tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.getAttribute('data-ffb-md-tab') === name)));
      tools.forEach((tool) => { tool.disabled = previewing; });
      if (!previewing) {
        input.focus();
      }
    };

    tools.forEach((tool) => tool.addEventListener('click', () => run(tool.getAttribute('data-ffb-md'))));
    tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.getAttribute('data-ffb-md-tab'))));
    input.addEventListener('keydown', (event) => {
      const action = (event.ctrlKey || event.metaKey) && !event.altKey ? SHORTCUTS[String(event.key).toLowerCase()] : null;
      if (action) {
        event.preventDefault();
        run(action);
      }
    });
    // Validation runs before "submit"; a hidden required textarea would block it silently.
    form.addEventListener('click', (event) => {
      if (event.target.closest && event.target.closest('button[type="submit"], input[type="submit"]')) {
        show('write');
      }
    });
    head.hidden = false;
  }

  const api = { applyFormat, init };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).replyEditor = api;
    root.document.addEventListener('DOMContentLoaded', () => {
      root.document.querySelectorAll('[data-ffb-reply-editor]').forEach(init);
    });
  }
}(typeof window !== 'undefined' ? window : globalThis));
