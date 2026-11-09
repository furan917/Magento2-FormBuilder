(function (root) {
  'use strict';

  // Port of MiniMarkdown::renderMessage(); Test/fixtures/reply-markdown.json keeps both identical.
  // Explicit ASCII whitespace (not \s) because PHP matches bytes and JS \s also matches NBSP.
  const WS = ' \\t\\n\\x0B\\f\\r';
  const S = '[^' + WS + ']';
  const T = '[^' + WS + '*]';
  const LINK = new RegExp('\\[([^\\[\\]]+)\\]\\(([^()' + WS + ']*)\\)', 'g');
  const HTTP = new RegExp('^https?://[A-Za-z0-9][^' + WS + '\\\\<>"\'`\\x00-\\x1F\\x7F]*$', 'i');
  const MAILTO = /^mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,24}$/;
  const BULLET = new RegExp('^- (' + S + '[^\\n]*)$');
  const NUMBER = new RegExp('^\\d{1,3}\\. (' + S + '[^\\n]*)$');
  const STRONG_EM = new RegExp('\\*\\*\\*(?=' + S + ')([^\\n]+?)(?<=' + S + ')\\*\\*\\*', 'g');
  const STRONG = new RegExp('\\*\\*(?=' + S + ')([^\\n]+?)(?<=' + S + ')\\*\\*', 'g');
  const EM = new RegExp('\\*(?=' + T + ')([^*]+?)(?<=' + T + ')\\*', 'g');
  const REL = 'noopener noreferrer nofollow';
  const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  const ENTITIES = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'" };
  const TAG = /<(\/?)(p|br|ul|ol|li|strong|em|a)(?: href="([^"<>]*)" rel="noopener noreferrer nofollow"(?: target="_blank")?)?>/g;

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

  function emphasis(escaped) {
    return escaped.replace(STRONG_EM, '<strong><em>$1</em></strong>')
      .replace(STRONG, '<strong>$1</strong>')
      .replace(EM, '<em>$1</em>');
  }

  function inline(escapedLine) {
    const anchors = [];
    const line = escapedLine.replace(LINK, (whole, label, escapedUrl) => {
      const url = unescape(escapedUrl);
      const http = HTTP.test(url);
      if (!http && !MAILTO.test(url)) {
        return whole;
      }
      anchors.push('<a href="' + escape(url) + '" rel="' + REL + '"' + (http ? ' target="_blank"' : '') + '>'
        + emphasis(label) + '</a>');
      return '\0' + (anchors.length - 1) + '\0';
    });
    return emphasis(line).replace(/\x00(\d+)\x00/g, (m, i) => anchors[Number(i)]);
  }

  function block(kind, lines) {
    return kind === 'p'
      ? '<p>' + lines.join('<br>\n') + '</p>'
      : '<' + kind + '><li>' + lines.join('</li><li>') + '</li></' + kind + '>';
  }

  /** Same string as the server renders; only ever contains the fixed tag set, all text escaped. */
  function toHtml(text) {
    const clean = phpTrim(String(text).split('\0').join('').split('\r\n').join('\n').split('\r').join('\n'));
    if (clean === '') {
      return '';
    }
    const blocks = [];
    for (const chunk of escape(clean).split(/\n{2,}/)) {
      let kind = null;
      let lines = [];
      for (let line of chunk.split('\n')) {
        let lineKind = 'p';
        let match = BULLET.exec(line);
        if (match) {
          lineKind = 'ul';
          line = match[1];
        } else if ((match = NUMBER.exec(line))) {
          lineKind = 'ol';
          line = match[1];
        }
        if (kind !== null && lineKind !== kind) {
          blocks.push(block(kind, lines));
          lines = [];
        }
        kind = lineKind;
        lines.push(inline(line));
      }
      blocks.push(block(kind, lines));
    }
    return blocks.join('\n');
  }

  /** DocumentFragment built with createElement/createTextNode only (no innerHTML). */
  function render(text, doc) {
    const fragment = doc.createDocumentFragment();
    const html = toHtml(text);
    const stack = [fragment];
    let last = 0;
    const appendText = (raw) => {
      if (raw !== '') {
        stack[stack.length - 1].appendChild(doc.createTextNode(unescape(raw)));
      }
    };
    TAG.lastIndex = 0;
    let match;
    while ((match = TAG.exec(html)) !== null) {
      appendText(html.slice(last, match.index));
      last = TAG.lastIndex;
      const [, closing, name, href] = match;
      if (closing) {
        const at = stack.map((node) => node.nodeName.toLowerCase()).lastIndexOf(name);
        if (at > 0) {
          stack.length = at;
        }
        continue;
      }
      const el = doc.createElement(name);
      if (name === 'a') {
        const url = unescape(href || '');
        if (HTTP.test(url) || MAILTO.test(url)) {
          el.setAttribute('href', url);
        }
        el.setAttribute('rel', REL);
        if (HTTP.test(url)) {
          el.setAttribute('target', '_blank');
        }
      }
      stack[stack.length - 1].appendChild(el);
      if (name !== 'br') {
        stack.push(el);
      }
    }
    appendText(html.slice(last));
    return fragment;
  }

  const api = { toHtml, render };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).replyMarkdown = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
