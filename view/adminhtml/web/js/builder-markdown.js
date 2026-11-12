(function (root) {
  'use strict';

  // Port of MiniMarkdown::render(); Test/fixtures/rich-text.json keeps both identical.
  // ASCII whitespace only: PHP matches bytes, JS \s also matches NBSP.
  const WS = ' \\t\\n\\x0B\\f\\r';
  const S = '[^' + WS + ']';
  const T = '[^' + WS + '*]';
  const URL_TAIL = '[^' + WS + '\\\\<>"\'`\\x00-\\x1F\\x7F]*';
  const LINK = new RegExp('\\[([^\\[\\]]+)\\]\\(([^()' + WS + ']*)\\)', 'g');
  const HTTP = new RegExp('^https?://[A-Za-z0-9]' + URL_TAIL + '$', 'i');
  const RELATIVE = new RegExp('^/(?![/\\\\])' + URL_TAIL + '$');
  const MAILTO = /^mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,24}$/;
  const BULLET = new RegExp('^- (' + S + '[^\\n]*)$');
  const NUMBER = new RegExp('^\\d{1,3}\\. (' + S + '[^\\n]*)$');
  const HEADING = new RegExp('^(#{1,4}) (' + S + '[^\\n]*)$');
  const STRONG_EM = new RegExp('\\*\\*\\*(?=' + S + ')([^\\n]+?)(?<=' + S + ')\\*\\*\\*', 'g');
  const STRONG = new RegExp('\\*\\*(?=' + S + ')([^\\n]+?)(?<=' + S + ')\\*\\*', 'g');
  const EM = new RegExp('\\*(?=' + T + ')([^*]+?)(?<=' + T + ')\\*', 'g');
  const NEW_TAB = ' rel="noopener noreferrer" target="_blank"';
  const CUE_CLASS = 'ffb-sr-only';
  const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  const ENTITIES = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'" };
  const TAGS = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'h3', 'h4', 'span'];

  function tr(text) {
    const api = typeof module === 'object' && module.exports
      ? require('./builder-i18n.js')
      : (root.FuranFormBuilder || {}).builderI18n;
    return api ? api.t(text) : text;
  }

  function escape(text) {
    return text.replace(/[&<>"']/g, (c) => ESCAPES[c]);
  }

  function unescape(text) {
    return text.replace(/&(?:amp|lt|gt|quot|#039);/g, (e) => ENTITIES[e]);
  }

  // PHP trim(): JS String#trim would also strip NBSP and other Unicode spaces.
  function phpTrim(text) {
    return text.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
  }

  function allowed(url) {
    return HTTP.test(url) || MAILTO.test(url) || RELATIVE.test(url);
  }

  function emphasis(escaped) {
    return escaped.replace(STRONG_EM, '<strong><em>$1</em></strong>')
      .replace(STRONG, '<strong>$1</strong>')
      .replace(EM, '<em>$1</em>');
  }

  function inline(escapedLine) {
    const anchors = [];
    const line = escapedLine.replace(LINK, (whole, label, escapedUrl) => {
      const url = unescape(escapedUrl);
      if (!allowed(url)) {
        return whole;
      }
      const http = HTTP.test(url);
      anchors.push('<a href="' + escape(url) + '"' + (http ? NEW_TAB : '') + '>' + emphasis(label)
        + (http ? '<span class="' + CUE_CLASS + '"> ' + escape(tr('(opens in a new tab)')) + '</span>' : '') + '</a>');
      return '\0' + (anchors.length - 1) + '\0';
    });
    return emphasis(line).replace(/\x00(\d+)\x00/g, (m, i) => anchors[Number(i)]);
  }

  function lineKind(line) {
    let match = BULLET.exec(line);
    if (match) {
      return ['ul', match[1]];
    }
    if ((match = NUMBER.exec(line))) {
      return ['ol', match[1]];
    }
    if ((match = HEADING.exec(line))) {
      return [match[1].length === 4 ? 'h4' : 'h3', match[2]];
    }
    return ['p', line];
  }

  function block(kind, lines) {
    if (kind === 'p') {
      return '<p>' + lines.join('<br>\n') + '</p>';
    }
    if (kind[0] === 'h') {
      return '<' + kind + '>' + lines[0] + '</' + kind + '>';
    }
    return '<' + kind + '><li>' + lines.join('</li><li>') + '</li></' + kind + '>';
  }

  // Only the fixed tag set, all text escaped.
  function toHtml(text) {
    const clean = phpTrim(String(text).split('\0').join('').split('\r\n').join('\n').split('\r').join('\n'));
    if (clean === '') {
      return '';
    }
    const blocks = [];
    for (const chunk of escape(clean).split(/\n{2,}/)) {
      let kind = null;
      let lines = [];
      for (const raw of chunk.split('\n')) {
        const [nextKind, line] = lineKind(raw);
        if (kind !== null && (nextKind !== kind || kind[0] === 'h')) {
          blocks.push(block(kind, lines));
          lines = [];
        }
        kind = nextKind;
        lines.push(inline(line));
      }
      blocks.push(block(kind, lines));
    }
    return blocks.join('\n');
  }

  // Copies only allowlisted tags and attributes from the detached parse into nodes owned by doc.
  function copy(doc, source, target) {
    for (const node of Array.from(source.childNodes)) {
      if (node.nodeType === 3) {
        target.appendChild(doc.createTextNode(node.nodeValue));
        continue;
      }
      if (node.nodeType !== 1) {
        continue;
      }
      const name = node.nodeName.toLowerCase();
      if (!TAGS.includes(name)) {
        copy(doc, node, target);
        continue;
      }
      const el = doc.createElement(name);
      if (name === 'a') {
        const url = node.getAttribute('href') || '';
        if (allowed(url)) {
          el.setAttribute('href', url);
        }
        if (HTTP.test(url)) {
          el.setAttribute('rel', 'noopener noreferrer');
          el.setAttribute('target', '_blank');
        }
      } else if (name === 'span' && node.getAttribute('class') === CUE_CLASS) {
        el.className = CUE_CLASS;
      }
      target.appendChild(el);
      copy(doc, node, el);
    }
  }

  /** DocumentFragment of nodes created with createElement/createTextNode; the markup is parsed in a detached document. */
  function render(doc, text) {
    const fragment = doc.createDocumentFragment();
    const html = toHtml(text);
    if (html !== '') {
      const parsed = new doc.defaultView.DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
      copy(doc, parsed.body, fragment);
    }
    return fragment;
  }

  const api = { render };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderMarkdown = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
