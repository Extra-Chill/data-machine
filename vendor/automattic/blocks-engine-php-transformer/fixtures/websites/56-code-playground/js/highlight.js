/* =========================================================
   FORKBENCH — Syntax highlighter
   A small, dependency-free tokenizer for HTML, CSS, and JS.
   It returns an HTML string of <span class="tok-*"> wrappers
   that is layered behind a transparent <textarea>.
   Whitespace and length are preserved exactly so the overlay
   and the textarea stay perfectly aligned.
   ========================================================= */
(function (global) {
  'use strict';

  function esc(s) {
    return s.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
  }

  function span(cls, text) {
    return '<span class="tok-' + cls + '">' + esc(text) + '</span>';
  }

  var JS_KEYWORDS = new Set((
    'break case catch class const continue debugger default delete do else export ' +
    'extends finally for function if import in instanceof let new return super switch ' +
    'this throw try typeof var void while with yield async await static get set of as from'
  ).split(' '));

  var JS_LITERALS = new Set(['true', 'false', 'null', 'undefined', 'NaN', 'Infinity']);
  var JS_BUILTINS = new Set((
    'console document window Math JSON Array Object String Number Boolean Date ' +
    'Promise Map Set Symbol RegExp Error parseInt parseFloat setTimeout setInterval ' +
    'requestAnimationFrame fetch localStorage navigator'
  ).split(' '));

  /* ---------- JavaScript ---------- */
  function highlightJS(src) {
    var out = '';
    var i = 0, n = src.length;
    // track previous meaningful token to disambiguate regex vs divide
    var prevType = null;

    function peek(k) { return src[i + (k || 0)]; }

    while (i < n) {
      var c = src[i];

      // line comment
      if (c === '/' && src[i + 1] === '/') {
        var j = i;
        while (j < n && src[j] !== '\n') j++;
        out += span('comment', src.slice(i, j));
        i = j; prevType = 'comment'; continue;
      }
      // block comment
      if (c === '/' && src[i + 1] === '*') {
        var k = src.indexOf('*/', i + 2);
        k = k === -1 ? n : k + 2;
        out += span('comment', src.slice(i, k));
        i = k; prevType = 'comment'; continue;
      }
      // strings & template literals
      if (c === '"' || c === "'" || c === '`') {
        var q = c, p = i + 1;
        while (p < n) {
          if (src[p] === '\\') { p += 2; continue; }
          if (src[p] === q) { p++; break; }
          p++;
        }
        out += span('string', src.slice(i, p));
        i = p; prevType = 'value'; continue;
      }
      // regex literal (heuristic: only when a value can't precede it)
      if (c === '/' && prevType !== 'value' && prevType !== 'name') {
        var r = i + 1, ok = false, inClass = false;
        while (r < n) {
          var rc = src[r];
          if (rc === '\\') { r += 2; continue; }
          if (rc === '\n') break;
          if (rc === '[') inClass = true;
          else if (rc === ']') inClass = false;
          else if (rc === '/' && !inClass) { ok = true; r++; break; }
          r++;
        }
        if (ok) {
          while (r < n && /[a-z]/i.test(src[r])) r++; // flags
          out += span('regex', src.slice(i, r));
          i = r; prevType = 'value'; continue;
        }
      }
      // numbers
      if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1]))) {
        var m = src.slice(i).match(/^(0[xX][0-9a-fA-F]+|0[bB][01]+|(\d[\d_]*)?\.?\d[\d_]*([eE][+-]?\d+)?)/);
        var num = m ? m[0] : c;
        out += span('number', num);
        i += num.length; prevType = 'value'; continue;
      }
      // identifiers / keywords
      if (/[A-Za-z_$]/.test(c)) {
        var s = i + 1;
        while (s < n && /[A-Za-z0-9_$]/.test(src[s])) s++;
        var word = src.slice(i, s);
        var after = src.slice(s).match(/^\s*\(/);
        if (JS_KEYWORDS.has(word)) { out += span('keyword', word); prevType = 'keyword'; }
        else if (JS_LITERALS.has(word)) { out += span('literal', word); prevType = 'value'; }
        else if (JS_BUILTINS.has(word)) { out += span('builtin', word); prevType = 'name'; }
        else if (after) { out += span('func', word); prevType = 'name'; }
        else { out += esc(word); prevType = 'name'; }
        i = s; continue;
      }
      // punctuation / operators
      if (/[{}()\[\];,]/.test(c)) {
        out += span('punc', c); i++; prevType = (c === ')' || c === ']') ? 'value' : 'punc'; continue;
      }
      if (/[+\-*/%=<>!&|?:.~^]/.test(c)) {
        out += span('op', c); i++; prevType = 'op'; continue;
      }
      // whitespace / fallthrough
      out += esc(c); i++;
      if (!/\s/.test(c)) prevType = 'other';
    }
    return out;
  }

  /* ---------- CSS ---------- */
  function highlightCSS(src) {
    var out = '';
    var i = 0, n = src.length;
    var inBlock = false; // inside { } => property/value context

    while (i < n) {
      var c = src[i];
      // comment
      if (c === '/' && src[i + 1] === '*') {
        var k = src.indexOf('*/', i + 2);
        k = k === -1 ? n : k + 2;
        out += span('comment', src.slice(i, k));
        i = k; continue;
      }
      // string
      if (c === '"' || c === "'") {
        var q = c, p = i + 1;
        while (p < n) { if (src[p] === '\\') { p += 2; continue; } if (src[p] === q) { p++; break; } p++; }
        out += span('string', src.slice(i, p));
        i = p; continue;
      }
      if (c === '{') { inBlock = true; out += span('punc', c); i++; continue; }
      if (c === '}') { inBlock = false; out += span('punc', c); i++; continue; }

      if (!inBlock) {
        // selector context: at-rules, classes, ids, elements
        if (c === '@') {
          var s = i + 1; while (s < n && /[a-z-]/i.test(src[s])) s++;
          out += span('keyword', src.slice(i, s)); i = s; continue;
        }
        if (/[.#&:]/.test(c)) {
          var s2 = i + 1; while (s2 < n && /[A-Za-z0-9_-]/.test(src[s2])) s2++;
          out += span('selector', src.slice(i, s2)); i = s2; continue;
        }
        if (/[A-Za-z]/.test(c)) {
          var s3 = i; while (s3 < n && /[A-Za-z0-9_-]/.test(src[s3])) s3++;
          out += span('tag', src.slice(i, s3)); i = s3; continue;
        }
        out += esc(c); i++; continue;
      } else {
        // declaration context
        if (/[A-Za-z-]/.test(c)) {
          var w = i; while (w < n && /[A-Za-z0-9_-]/.test(src[w])) w++;
          var word = src.slice(i, w);
          var rest = src.slice(w).match(/^\s*:/);
          if (rest) out += span('property', word);
          else out += span('value', word);
          i = w; continue;
        }
        if (c === '#') { // hex color
          var h = i + 1; while (h < n && /[0-9a-fA-F]/.test(src[h])) h++;
          out += span('number', src.slice(i, h)); i = h; continue;
        }
        if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1]))) {
          var m = src.slice(i).match(/^\d*\.?\d+(px|em|rem|vh|vw|vmin|vmax|%|s|ms|deg|fr|ch|pt)?/);
          var num = m ? m[0] : c; out += span('number', num); i += num.length; continue;
        }
        if (/[:;,]/.test(c)) { out += span('punc', c); i++; continue; }
        out += esc(c); i++; continue;
      }
    }
    return out;
  }

  /* ---------- HTML ---------- */
  function highlightHTML(src) {
    var out = '';
    var i = 0, n = src.length;
    while (i < n) {
      var c = src[i];
      // comment
      if (src.startsWith('<!--', i)) {
        var end = src.indexOf('-->', i + 4);
        end = end === -1 ? n : end + 3;
        out += span('comment', src.slice(i, end)); i = end; continue;
      }
      // doctype
      if (/^<!doctype/i.test(src.slice(i))) {
        var d = src.indexOf('>', i); d = d === -1 ? n : d + 1;
        out += span('keyword', src.slice(i, d)); i = d; continue;
      }
      if (c === '<') {
        var gt = src.indexOf('>', i);
        if (gt === -1) { out += esc(src.slice(i)); break; }
        var raw = src.slice(i, gt + 1);
        out += highlightTag(raw);
        i = gt + 1; continue;
      }
      // text content up to next <
      var lt = src.indexOf('<', i);
      lt = lt === -1 ? n : lt;
      out += esc(src.slice(i, lt));
      i = lt;
    }
    return out;
  }

  function highlightTag(raw) {
    // raw includes the surrounding < ... >
    var out = '';
    var m = raw.match(/^<\/?[A-Za-z0-9-]*/);
    if (!m) return esc(raw);
    var head = m[0];
    out += span('punc', head[0] + (head[1] === '/' ? '/' : ''));
    var name = head.replace(/^<\/?/, '');
    out += span('tag', name);
    var rest = raw.slice(head.length);
    // attributes
    var re = /([A-Za-z_][A-Za-z0-9-:@.]*)(\s*=\s*)("(?:[^"]*)"|'(?:[^']*)'|[^\s>]+)?|(\/?>)|(\s+)|([^\s=>]+)/g;
    var mm;
    while ((mm = re.exec(rest)) !== null) {
      if (mm[1]) {
        out += span('attr', mm[1]);
        if (mm[2]) out += span('op', mm[2]);
        if (mm[3]) out += span('string', mm[3]);
      } else if (mm[4]) {
        out += span('punc', mm[4]);
      } else if (mm[5]) {
        out += esc(mm[5]);
      } else if (mm[6]) {
        out += esc(mm[6]);
      }
    }
    return out;
  }

  global.ForkbenchHighlight = function (code, lang) {
    if (lang === 'css') return highlightCSS(code);
    if (lang === 'js') return highlightJS(code);
    return highlightHTML(code);
  };
})(window);
