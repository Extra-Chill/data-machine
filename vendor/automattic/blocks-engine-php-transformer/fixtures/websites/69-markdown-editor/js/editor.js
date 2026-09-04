/* ============================================================
   Inkwell — editor behaviours bound to the <textarea>
   Selection-aware formatting, smart Tab, auto-continue lists,
   keyboard shortcuts, and document statistics.
   ============================================================ */
(function (global) {
  'use strict';

  function Editor(textarea) {
    this.ta = textarea;
    this._bindKeys();
  }

  Editor.prototype.value = function (v) {
    if (v === undefined) return this.ta.value;
    this.ta.value = v;
    return v;
  };

  Editor.prototype.focus = function () { this.ta.focus(); };

  // Replace the current selection (or insert) and fire input so listeners update.
  Editor.prototype._replaceSel = function (text, selStart, selEnd) {
    var ta = this.ta;
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.setRangeText(text, s, e, 'end');
    if (selStart != null) {
      ta.selectionStart = selStart;
      ta.selectionEnd = selEnd != null ? selEnd : selStart;
    }
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  };

  Editor.prototype.getSelection = function () {
    var ta = this.ta;
    return ta.value.slice(ta.selectionStart, ta.selectionEnd);
  };

  /* Wrap selection with before/after markers (toggle off if already wrapped). */
  Editor.prototype.wrap = function (before, after, placeholder) {
    after = after == null ? before : after;
    var ta = this.ta;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e);
    var outer = ta.value.slice(s - before.length, s) + sel + ta.value.slice(e, e + after.length);

    // toggle off
    if (sel && ta.value.slice(s - before.length, s) === before &&
        ta.value.slice(e, e + after.length) === after) {
      ta.setRangeText(sel, s - before.length, e + after.length, 'end');
      ta.selectionStart = s - before.length;
      ta.selectionEnd = e - before.length;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    var body = sel || placeholder || '';
    var ins = before + body + after;
    ta.setRangeText(ins, s, e, 'end');
    if (sel) { ta.selectionStart = s + before.length; ta.selectionEnd = s + before.length + body.length; }
    else { ta.selectionStart = ta.selectionEnd = s + before.length + body.length; }
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.focus();
  };

  /* Prefix each selected line (e.g. headings, blockquotes, lists). */
  Editor.prototype.prefixLines = function (makePrefix) {
    var ta = this.ta;
    var s = ta.selectionStart, e = ta.selectionEnd;
    var val = ta.value;
    var lineStart = val.lastIndexOf('\n', s - 1) + 1;
    var lineEnd = val.indexOf('\n', e);
    if (lineEnd === -1) lineEnd = val.length;
    var block = val.slice(lineStart, lineEnd);
    var lines = block.split('\n');
    var n = 0;
    var out = lines.map(function (l) { n++; return makePrefix(l, n); }).join('\n');
    ta.setRangeText(out, lineStart, lineEnd, 'end');
    ta.selectionStart = lineStart;
    ta.selectionEnd = lineStart + out.length;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.focus();
  };

  Editor.prototype.heading = function (level) {
    var hashes = new Array(level + 1).join('#') + ' ';
    this.prefixLines(function (l) {
      var stripped = l.replace(/^#{1,6}\s+/, '');
      return /^#{1,6}\s/.test(l) && l.indexOf(hashes) === 0 ? stripped : hashes + stripped;
    });
  };

  Editor.prototype.bulletList = function () {
    this.prefixLines(function (l) {
      if (/^\s*-\s+/.test(l)) return l.replace(/^(\s*)-\s+/, '$1');
      return '- ' + l.replace(/^\s*/, '');
    });
  };
  Editor.prototype.numberList = function () {
    this.prefixLines(function (l, n) {
      if (/^\s*\d+[.)]\s+/.test(l)) return l.replace(/^(\s*)\d+[.)]\s+/, '$1');
      return n + '. ' + l.replace(/^\s*/, '');
    });
  };
  Editor.prototype.taskList = function () {
    this.prefixLines(function (l) {
      if (/^\s*- \[[ xX]\]\s+/.test(l)) return l.replace(/^(\s*)- \[[ xX]\]\s+/, '$1');
      return '- [ ] ' + l.replace(/^\s*/, '');
    });
  };
  Editor.prototype.quote = function () {
    this.prefixLines(function (l) {
      if (/^>\s?/.test(l)) return l.replace(/^>\s?/, '');
      return '> ' + l;
    });
  };

  Editor.prototype.link = function () {
    var sel = this.getSelection();
    if (sel) this.wrap('[' + sel + '](', ')', '');
    else this._replaceSel('[text](https://)', this.ta.selectionStart + 1, this.ta.selectionStart + 5);
  };
  Editor.prototype.image = function () {
    var s = this.ta.selectionStart;
    this._replaceSel('![alt text](image.png)', s + 2, s + 10);
  };
  Editor.prototype.codeBlock = function () {
    var sel = this.getSelection();
    if (sel) this.wrap('```\n', '\n```');
    else {
      var s = this.ta.selectionStart;
      this._replaceSel('```js\n\n```', s + 6, s + 6);
    }
  };
  Editor.prototype.table = function () {
    var t = '| Column A | Column B | Column C |\n' +
            '| -------- | -------- | -------- |\n' +
            '| Cell 1   | Cell 2   | Cell 3   |\n' +
            '| Cell 4   | Cell 5   | Cell 6   |\n';
    this._replaceSel(t);
  };
  Editor.prototype.hr = function () { this._replaceSel('\n---\n'); };
  Editor.prototype.slideBreak = function () { this._replaceSel('\n\n---\n\n'); };

  /* ---- key handling: Tab indent, list auto-continue, shortcuts ---- */
  Editor.prototype._bindKeys = function () {
    var self = this;
    var ta = this.ta;

    ta.addEventListener('keydown', function (ev) {
      var mod = ev.metaKey || ev.ctrlKey;

      // Shortcuts
      if (mod && !ev.altKey) {
        var k = ev.key.toLowerCase();
        if (k === 'b') { ev.preventDefault(); self.wrap('**', '**', 'bold'); return; }
        if (k === 'i') { ev.preventDefault(); self.wrap('*', '*', 'italic'); return; }
        if (k === 'e') { ev.preventDefault(); self.wrap('`', '`', 'code'); return; }
        if (k === 'k') { ev.preventDefault(); self.link(); return; }
        if (ev.shiftKey && k === 'x') { ev.preventDefault(); self.wrap('~~', '~~', 'strikethrough'); return; }
        // Ctrl/Cmd + 1..6 headings
        if (/^[1-6]$/.test(ev.key)) { ev.preventDefault(); self.heading(+ev.key); return; }
      }

      // Tab / Shift+Tab indentation
      if (ev.key === 'Tab') {
        ev.preventDefault();
        var s = ta.selectionStart, e = ta.selectionEnd;
        if (s !== e || ev.shiftKey) {
          // multi-line (or outdent) — operate on whole lines
          var val = ta.value;
          var ls = val.lastIndexOf('\n', s - 1) + 1;
          var le = val.indexOf('\n', e); if (le === -1) le = val.length;
          var block = val.slice(ls, le);
          var out;
          if (ev.shiftKey) {
            out = block.replace(/^( {1,2}|\t)/gm, '');
          } else {
            out = block.replace(/^/gm, '  ');
          }
          ta.setRangeText(out, ls, le, 'preserve');
          ta.selectionStart = ls; ta.selectionEnd = ls + out.length;
        } else {
          ta.setRangeText('  ', s, e, 'end');
        }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }

      // Enter — auto-continue lists / blockquotes
      if (ev.key === 'Enter' && !ev.shiftKey && !mod) {
        var v = ta.value, p = ta.selectionStart;
        var lineStart = v.lastIndexOf('\n', p - 1) + 1;
        var line = v.slice(lineStart, p);
        var m = line.match(/^(\s*)(-\s\[[ xX]\]\s|[-*+]\s|\d+[.)]\s|>\s?)(.*)$/);
        if (m) {
          var indent = m[1], marker = m[2], rest = m[3];
          if (rest.trim() === '') {
            // empty item -> end the list, remove the marker
            ev.preventDefault();
            ta.setRangeText('', lineStart, p, 'end');
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            return;
          }
          ev.preventDefault();
          var nextMarker = marker;
          // reset task checkbox state, and increment ordered numbers
          nextMarker = nextMarker.replace(/\[[xX]\]/, '[ ]');
          var num = marker.match(/^(\d+)([.)])\s$/);
          if (num) nextMarker = (parseInt(num[1], 10) + 1) + num[2] + ' ';
          self._replaceSel('\n' + indent + nextMarker);
          return;
        }
      }
    });
  };

  /* ---- statistics ---- */
  Editor.computeStats = function (text) {
    var t = text || '';
    var words = (t.trim().match(/\S+/g) || []).length;
    var chars = t.length;
    var lines = t === '' ? 0 : t.split('\n').length;
    var sentences = (t.match(/[.!?](\s|$)/g) || []).length;
    var minutes = words / 220; // ~220 wpm
    var readMin = Math.max(1, Math.round(minutes));
    var readLabel = words === 0 ? '0 min' : (minutes < 1 ? '< 1 min' : readMin + ' min');
    return {
      words: words, chars: chars, lines: lines, sentences: sentences,
      reading: readLabel
    };
  };

  global.InkwellEditor = Editor;

})(typeof window !== 'undefined' ? window : this);
