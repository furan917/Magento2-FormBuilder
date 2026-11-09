(function (root) {
  'use strict';

  // Mirror of Model/Validation/RepeatAnalyzer.php.
  const BACKREF = new Error('back-reference');

  function indexOf(chars, needle, from) {
    for (let j = from; j < chars.length; j++) {
      if (chars[j] === needle) {
        return j;
      }
    }
    return chars.length;
  }

  function charAtom(chars, pos, end) {
    const src = chars.slice(pos.i, Math.max(pos.i + 1, end)).join('');
    pos.i = Math.max(pos.i + 1, end);
    return { type: 'char', src, alts: [] };
  }

  function atom(chars, pos) {
    const i = pos.i;
    const char = chars[i];
    const next = chars[i + 1] || '';
    if (char === '\\') {
      if (/^[1-9]$/.test(next) || (next === 'k' && chars[i + 2] === '<')) {
        throw BACKREF;
      }
      if (next === 'b' || next === 'B') {
        pos.i += 2;
        return { type: 'zero', src: '', alts: [] };
      }
      let end = i + 2;
      if (((next === 'p' || next === 'P') && chars[i + 2] === '{') || (next === 'u' && chars[i + 2] === '{')) {
        end = indexOf(chars, '}', i) + 1;
      } else if (next === 'x') {
        end = i + 4;
      } else if (next === 'u') {
        end = i + 6;
      } else if (next === 'c') {
        end = i + 3;
      }
      return charAtom(chars, pos, end);
    }
    if (char === '[') {
      let j = i + 1;
      if (chars[j] === '^') {
        j++;
      }
      while (j < chars.length && chars[j] !== ']') {
        j += chars[j] === '\\' ? 2 : 1;
      }
      return charAtom(chars, pos, j + 1);
    }
    if (char === '(') {
      let type = 'group';
      let skip = 1;
      if (next === '?') {
        const kind = chars[i + 2] || '';
        const after = chars[i + 3] || '';
        if (kind === '=' || kind === '!' || (kind === '<' && (after === '=' || after === '!'))) {
          type = 'zero';
          skip = kind === '<' ? 4 : 3;
        } else if (kind === '<') {
          skip = indexOf(chars, '>', i) + 1 - i;
        } else {
          skip = 3;
        }
      }
      pos.i += skip;
      const alts = parse(chars, pos);
      pos.i++;
      return { type, src: '', alts };
    }
    if (char === '^' || char === '$') {
      pos.i++;
      return { type: 'zero', src: '', alts: [] };
    }
    return charAtom(chars, pos, i + 1);
  }

  function quantifier(chars, pos) {
    const char = chars[pos.i] || '';
    let range = [1, 1];
    if (char === '*' || char === '+' || char === '?') {
      range = char === '*' ? [0, null] : (char === '+' ? [1, null] : [0, 1]);
      pos.i++;
    } else if (char === '{') {
      const close = indexOf(chars, '}', pos.i);
      const m = /^(\d+)(,(\d*))?$/.exec(chars.slice(pos.i + 1, close).join(''));
      if (!m) {
        return range;
      }
      const max = m[2] !== undefined ? (m[3] === '' ? null : parseInt(m[3], 10)) : parseInt(m[1], 10);
      range = [parseInt(m[1], 10), max];
      pos.i = close + 1;
    } else {
      return range;
    }
    if (chars[pos.i] === '?') {
      pos.i++;
    }
    return range;
  }

  function parse(chars, pos) {
    const alternatives = [];
    let sequence = [];
    while (pos.i < chars.length) {
      const char = chars[pos.i];
      if (char === ')') {
        break;
      }
      if (char === '|') {
        alternatives.push(sequence);
        sequence = [];
        pos.i++;
        continue;
      }
      const a = atom(chars, pos);
      const [min, max] = quantifier(chars, pos);
      a.min = min;
      a.max = max;
      sequence.push(a);
    }
    alternatives.push(sequence);
    return alternatives;
  }

  function flatten(sequence) {
    const out = [];
    sequence.forEach((a) => {
      if (a.type === 'group' && a.min === 1 && a.max === 1 && a.alts.length === 1) {
        out.push(...flatten(a.alts[0]));
      } else {
        out.push(a);
      }
    });
    return out;
  }

  function hasUnbounded(alternatives) {
    return alternatives.some((seq) => seq.some((a) => a.type !== 'zero' && (a.max === null || hasUnbounded(a.alts))));
  }

  function hasAlternation(alternatives) {
    return alternatives.some((seq) => seq.some((a) => a.alts.length > 1 || hasAlternation(a.alts)));
  }

  function charSources(a) {
    if (a.type === 'char') {
      return [a.src];
    }
    const out = [];
    a.alts.forEach((seq) => seq.forEach((child) => out.push(...charSources(child))));
    return out;
  }

  const cache = new Map();
  function matchesOne(source, sample) {
    const key = source + '\u0000' + sample;
    if (!cache.has(key)) {
      let hit = false;
      try {
        hit = new RegExp('^(?:' + source + ')$', 'u').test(sample);
      } catch (e) {
        hit = false;
      }
      cache.set(key, hit);
    }
    return cache.get(key);
  }

  function samples(sources) {
    const out = ['\t', '\n', 'é', 'ß', 'Ж', '中', '😀'];
    for (let c = 0x20; c <= 0x7E; c++) {
      out.push(String.fromCharCode(c));
    }
    out.push(...Array.from(sources));
    return Array.from(new Set(out));
  }

  function overlaps(left, right) {
    return charSources(left).some((a) => charSources(right).some((b) => a === b
      || samples(a + b).some((s) => matchesOne(a, s) && matchesOne(b, s))));
  }

  function checkSequence(sequence) {
    let pending = [];
    for (const a of sequence) {
      if (a.alts.length && !checkAlternatives(a.alts)) {
        return false;
      }
      if (a.type === 'zero') {
        continue;
      }
      const repeats = a.max === null || a.max > 1;
      if (a.type === 'group' && repeats && (a.alts.length > 1 || hasAlternation(a.alts) || hasUnbounded(a.alts))) {
        return false;
      }
      const unbounded = a.max === null || hasUnbounded(a.alts);
      if (unbounded && pending.some((p) => overlaps(p, a))) {
        return false;
      }
      if (a.min === 0) {
        if (unbounded) {
          pending.push(a);
        }
      } else {
        pending = unbounded ? [a] : [];
      }
    }
    return true;
  }

  function checkAlternatives(alternatives) {
    return alternatives.every((seq) => checkSequence(flatten(seq)));
  }

  function isRisky(pattern) {
    try {
      return !checkAlternatives(parse(Array.from(String(pattern)), { i: 0 }));
    } catch (e) {
      return true;
    }
  }

  // What the admin can save: compiles in the browser and cannot backtrack catastrophically.
  function isSafe(pattern) {
    try {
      new RegExp('^(?:' + pattern + ')$', 'u');
    } catch (e) {
      return false;
    }
    return !isRisky(pattern);
  }

  const api = { isRisky, isSafe };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).patternSafety = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
