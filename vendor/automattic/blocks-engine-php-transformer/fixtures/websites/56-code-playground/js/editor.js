/* =========================================================
   FORKBENCH — Code editor component
   A textarea with a synced, syntax-highlighted <pre> overlay,
   a line-number gutter, tab-to-indent, bracket/quote
   auto-pairing, current-line highlight and perfect scroll sync.
   ========================================================= */
(function (global) {
  'use strict';

  var PAIRS = { '(': ')', '[': ']', '{': '}', '"': '"', "'": "'", '`': '`' };
  var CLOSERS = { ')': '(', ']': '[', '}': '{' };
  var INDENT = '  '; // two spaces

  function CodeEditor(host, opts) {
    this.lang = opts.lang || 'html';
    this.onChange = opts.onChange || function () {};
    this.onCursor = opts.onCursor || function () {};
    this._build(host);
    this._bind();
    this.setValue(opts.value || '');
  }

  CodeEditor.prototype._build = function (host) {
    host.classList.add('ce');
    host.innerHTML =
      '<div class="ce-gutter" aria-hidden="true"></div>' +
      '<div class="ce-scroll">' +
        '<div class="ce-curline" aria-hidden="true"></div>' +
        '<pre class="ce-highlight" aria-hidden="true"><code></code></pre>' +
        '<textarea class="ce-input" spellcheck="false" autocapitalize="off" ' +
          'autocomplete="off" autocorrect="off" wrap="off"></textarea>' +
      '</div>';
    this.host = host;
    this.gutter = host.querySelector('.ce-gutter');
    this.scroll = host.querySelector('.ce-scroll');
    this.curline = host.querySelector('.ce-curline');
    this.pre = host.querySelector('.ce-highlight');
    this.code = host.querySelector('.ce-highlight code');
    this.ta = host.querySelector('.ce-input');
    this.ta.setAttribute('aria-label', this.lang.toUpperCase() + ' source');
  };

  CodeEditor.prototype._bind = function () {
    var self = this;

    this.ta.addEventListener('input', function () {
      self._render();
      self.onChange(self.ta.value);
      self._cursor();
    });

    this.ta.addEventListener('scroll', function () {
      self.pre.style.transform =
        'translate(' + -self.ta.scrollLeft + 'px,' + -self.ta.scrollTop + 'px)';
      self.gutter.scrollTop = self.ta.scrollTop;
      self._positionCurline();
    });

    this.ta.addEventListener('keydown', function (e) { self._keydown(e); });

    var cur = function () { self._cursor(); };
    this.ta.addEventListener('keyup', cur);
    this.ta.addEventListener('click', cur);
    this.ta.addEventListener('select', cur);
    this.ta.addEventListener('focus', function () { self.host.classList.add('is-focus'); cur(); });
    this.ta.addEventListener('blur', function () { self.host.classList.remove('is-focus'); });
  };

  CodeEditor.prototype._keydown = function (e) {
    var ta = this.ta;
    var v = ta.value, s = ta.selectionStart, eN = ta.selectionEnd;

    // Tab / Shift+Tab indentation
    if (e.key === 'Tab') {
      e.preventDefault();
      if (s !== eN || e.shiftKey) {
        // line-based (de)indent
        var lineStart = v.lastIndexOf('\n', s - 1) + 1;
        var block = v.slice(lineStart, eN);
        var lines = block.split('\n');
        var delta = 0, first = 0;
        if (e.shiftKey) {
          lines = lines.map(function (ln, idx) {
            var rm = ln.match(/^( {1,2}|\t)/);
            if (rm) { if (idx === 0) first = rm[0].length; delta += rm[0].length; return ln.slice(rm[0].length); }
            return ln;
          });
        } else {
          lines = lines.map(function (ln, idx) { if (idx === 0) first = INDENT.length; delta += INDENT.length; return INDENT + ln; });
        }
        var rebuilt = lines.join('\n');
        ta.value = v.slice(0, lineStart) + rebuilt + v.slice(eN);
        ta.selectionStart = Math.max(lineStart, s + (e.shiftKey ? -first : first));
        ta.selectionEnd = lineStart + rebuilt.length;
      } else {
        this._insert(INDENT);
      }
      this._after();
      return;
    }

    // Enter: keep indentation, and expand {| } / (| ) / [| ]
    if (e.key === 'Enter') {
      var ls = v.lastIndexOf('\n', s - 1) + 1;
      var indentMatch = v.slice(ls, s).match(/^[ \t]*/);
      var indent = indentMatch ? indentMatch[0] : '';
      var before = v[s - 1], after = v[s];
      if (PAIRS[before] && after === PAIRS[before] && before !== '"' && before !== "'" && before !== '`') {
        e.preventDefault();
        var ins = '\n' + indent + INDENT + '\n' + indent;
        ta.value = v.slice(0, s) + ins + v.slice(s);
        ta.selectionStart = ta.selectionEnd = s + 1 + indent.length + INDENT.length;
        this._after(); return;
      }
      if (indent) {
        e.preventDefault();
        this._insert('\n' + indent);
        return;
      }
      return; // default newline
    }

    // Auto-pair opening brackets/quotes
    if (PAIRS[e.key] && e.key !== ')' && e.key !== ']' && e.key !== '}') {
      var sel = v.slice(s, eN);
      e.preventDefault();
      if (sel) {
        // wrap selection
        ta.value = v.slice(0, s) + e.key + sel + PAIRS[e.key] + v.slice(eN);
        ta.selectionStart = s + 1; ta.selectionEnd = eN + 1;
      } else {
        // don't double-quote if next char is same quote (typing through)
        if ((e.key === '"' || e.key === "'" || e.key === '`') && v[s] === e.key) {
          ta.selectionStart = ta.selectionEnd = s + 1;
        } else {
          ta.value = v.slice(0, s) + e.key + PAIRS[e.key] + v.slice(s);
          ta.selectionStart = ta.selectionEnd = s + 1;
        }
      }
      this._after(); return;
    }

    // Type-through closing bracket
    if (CLOSERS[e.key] && v[s] === e.key && s === eN) {
      e.preventDefault();
      ta.selectionStart = ta.selectionEnd = s + 1;
      this._after(); return;
    }

    // Backspace removes empty pair
    if (e.key === 'Backspace' && s === eN && s > 0) {
      var bc = v[s - 1], ac = v[s];
      if (PAIRS[bc] && ac === PAIRS[bc]) {
        e.preventDefault();
        ta.value = v.slice(0, s - 1) + v.slice(s + 1);
        ta.selectionStart = ta.selectionEnd = s - 1;
        this._after(); return;
      }
    }
  };

  CodeEditor.prototype._insert = function (text) {
    var ta = this.ta, s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + text.length;
    this._after();
  };

  CodeEditor.prototype._after = function () {
    this._render();
    this.onChange(this.ta.value);
    this._cursor();
  };

  CodeEditor.prototype._render = function () {
    var val = this.ta.value;
    this.code.innerHTML = global.ForkbenchHighlight(val, this.lang);
    // line numbers
    var count = val.split('\n').length;
    if (count !== this._lineCount) {
      this._lineCount = count;
      var g = '';
      for (var i = 1; i <= count; i++) g += i + '\n';
      this.gutter.textContent = g;
    }
    this._positionCurline();
  };

  CodeEditor.prototype._positionCurline = function () {
    var val = this.ta.value;
    var pos = this.ta.selectionStart;
    var line = val.slice(0, pos).split('\n').length - 1;
    var lh = 21;     // must match CSS line-height (1.5 * 14px)
    var padTop = 8;  // must match CSS --ce-pad-y
    this.curline.style.top = (padTop + line * lh - this.ta.scrollTop) + 'px';
  };

  CodeEditor.prototype._cursor = function () {
    var val = this.ta.value;
    var pos = this.ta.selectionStart;
    var upto = val.slice(0, pos);
    var line = upto.split('\n').length;
    var col = pos - (upto.lastIndexOf('\n') + 1) + 1;
    this._positionCurline();
    this.onCursor({ line: line, col: col, lang: this.lang });
  };

  CodeEditor.prototype.setValue = function (v) {
    this.ta.value = v || '';
    this._lineCount = -1;
    this._render();
    this._cursor();
  };

  CodeEditor.prototype.getValue = function () { return this.ta.value; };
  CodeEditor.prototype.focus = function () { this.ta.focus(); };

  global.ForkbenchEditor = CodeEditor;
})(window);
