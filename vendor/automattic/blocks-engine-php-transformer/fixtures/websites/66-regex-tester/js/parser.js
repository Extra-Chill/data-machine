/* =========================================================
   RELAY — Regex parser
   A hand-written recursive-descent parser that turns a
   JavaScript regex source string into an AST. The AST is the
   single source of truth for the Explain panel and the
   railroad diagram. It does NOT execute the regex; matching
   is done by the real RegExp engine elsewhere.

   Node shapes (the .type field):
     alternation { options: [seq, seq, ...] }
     sequence    { items: [node, ...] }
     literal     { value, raw }            single char
     charclass   { negated, parts:[...], raw }
     any         { raw:"." }
     anchor      { kind:"start|end|wordB|nonWordB", raw }
     group       { kind, name?, body:node, raw }
       kind: "capturing" | "noncapturing" | "named"
             | "lookahead" | "neglookahead"
             | "lookbehind" | "neglookbehind"
     backref     { ref, byName, raw }
     escape      { kind, raw }             \d \w \s \b etc.
     quantifier  { child, min, max, greedy, raw }
   ========================================================= */

(function (global) {
  'use strict';

  function ParseError(message, index) {
    this.name = 'ParseError';
    this.message = message;
    this.index = index;
  }
  ParseError.prototype = Object.create(Error.prototype);

  function Parser(src, flags) {
    this.src = src;
    this.flags = flags || '';
    this.pos = 0;
    this.groupIndex = 0;       // running capture-group counter
    this.groupNames = [];      // collected named groups (for backref validation)
  }

  Parser.prototype.peek = function (o) { return this.src[this.pos + (o || 0)]; };
  Parser.prototype.eof = function () { return this.pos >= this.src.length; };
  Parser.prototype.error = function (msg) { throw new ParseError(msg, this.pos); };

  /* Pre-scan named groups so forward \k<name> references validate. */
  Parser.prototype.prescanNames = function () {
    var re = /\(\?<([A-Za-z_$][\w$]*)>/g, m;
    while ((m = re.exec(this.src))) {
      // skip lookbehind (?<= (?<!  which also start with (?<
      var after = this.src[m.index + 2];
      if (after === '=' || after === '!') continue;
      this.groupNames.push(m[1]);
    }
  };

  Parser.prototype.parse = function () {
    this.prescanNames();
    if (this.src === '') {
      return { type: 'sequence', items: [], raw: '' };
    }
    var node = this.parseAlternation();
    if (!this.eof()) {
      if (this.peek() === ')') this.error('Unmatched closing parenthesis ")"');
      this.error('Unexpected character "' + this.peek() + '"');
    }
    return node;
  };

  Parser.prototype.parseAlternation = function () {
    var start = this.pos;
    var options = [this.parseSequence()];
    while (this.peek() === '|') {
      this.pos++;
      options.push(this.parseSequence());
    }
    if (options.length === 1) return options[0];
    return { type: 'alternation', options: options, raw: this.src.slice(start, this.pos) };
  };

  Parser.prototype.parseSequence = function () {
    var start = this.pos;
    var items = [];
    while (!this.eof() && this.peek() !== '|' && this.peek() !== ')') {
      var atom = this.parseAtom();
      if (!atom) break;
      atom = this.maybeQuantifier(atom);
      items.push(atom);
    }
    return { type: 'sequence', items: items, raw: this.src.slice(start, this.pos) };
  };

  Parser.prototype.maybeQuantifier = function (atom) {
    var c = this.peek();
    if (c !== '*' && c !== '+' && c !== '?' && c !== '{') return atom;

    var qStart = this.pos;
    var min, max;

    if (c === '*') { min = 0; max = Infinity; this.pos++; }
    else if (c === '+') { min = 1; max = Infinity; this.pos++; }
    else if (c === '?') { min = 0; max = 1; this.pos++; }
    else {
      // {n} {n,} {n,m}  — must be a valid interval or it's a literal "{"
      var saved = this.pos;
      this.pos++; // consume {
      var n1 = this.readDigits();
      if (n1 === '') { this.pos = saved; return atom; }
      if (this.peek() === '}') {
        this.pos++; min = +n1; max = +n1;
      } else if (this.peek() === ',') {
        this.pos++;
        var n2 = this.readDigits();
        if (this.peek() !== '}') { this.pos = saved; return atom; }
        this.pos++;
        min = +n1; max = n2 === '' ? Infinity : +n2;
      } else {
        this.pos = saved; return atom;
      }
      if (max !== Infinity && max < min) this.error('Quantifier {' + n1 + ',' + n2 + '} is out of order (max < min)');
    }

    var greedy = true;
    if (this.peek() === '?') { greedy = false; this.pos++; }
    else if (this.peek() === '+') { greedy = true; this.pos++; } // possessive (treat as greedy)

    return {
      type: 'quantifier',
      child: atom, min: min, max: max, greedy: greedy,
      raw: this.src.slice(qStart, this.pos)
    };
  };

  Parser.prototype.readDigits = function () {
    var s = '';
    while (/[0-9]/.test(this.peek() || '')) { s += this.peek(); this.pos++; }
    return s;
  };

  Parser.prototype.parseAtom = function () {
    var c = this.peek();
    var start = this.pos;

    if (c === '(') return this.parseGroup();
    if (c === '[') return this.parseCharClass();
    if (c === '^') { this.pos++; return { type: 'anchor', kind: 'start', raw: '^' }; }
    if (c === '$') { this.pos++; return { type: 'anchor', kind: 'end', raw: '$' }; }
    if (c === '.') { this.pos++; return { type: 'any', raw: '.' }; }
    if (c === '\\') return this.parseEscape();

    if (c === '*' || c === '+' || c === '?') this.error('Nothing to repeat (dangling "' + c + '")');

    // plain literal
    this.pos++;
    return { type: 'literal', value: c, raw: c };
  };

  Parser.prototype.parseGroup = function () {
    var start = this.pos;
    this.pos++; // (
    var kind = 'capturing';
    var name = null;
    var captureNum = null;

    if (this.peek() === '?') {
      var n = this.peek(1);
      if (n === ':') { kind = 'noncapturing'; this.pos += 2; }
      else if (n === '=') { kind = 'lookahead'; this.pos += 2; }
      else if (n === '!') { kind = 'neglookahead'; this.pos += 2; }
      else if (n === '<' && this.peek(2) === '=') { kind = 'lookbehind'; this.pos += 3; }
      else if (n === '<' && this.peek(2) === '!') { kind = 'neglookbehind'; this.pos += 3; }
      else if (n === '<') {
        kind = 'named'; this.pos += 2; // consume ?<
        name = this.readGroupName();
        if (this.peek() !== '>') this.error('Invalid named group — expected ">"');
        this.pos++;
        captureNum = ++this.groupIndex;
      } else {
        this.error('Invalid group (unknown "(?' + (n || '') + '")');
      }
    } else {
      captureNum = ++this.groupIndex;
    }

    var body = this.parseAlternation();
    if (this.peek() !== ')') this.error('Unterminated group — expected ")"');
    this.pos++;

    return {
      type: 'group', kind: kind, name: name, captureNum: captureNum,
      body: body, raw: this.src.slice(start, this.pos)
    };
  };

  Parser.prototype.readGroupName = function () {
    var s = '';
    while (/[\w$]/.test(this.peek() || '')) { s += this.peek(); this.pos++; }
    if (s === '') this.error('Empty group name');
    return s;
  };

  Parser.prototype.parseCharClass = function () {
    var start = this.pos;
    this.pos++; // [
    var negated = false;
    if (this.peek() === '^') { negated = true; this.pos++; }

    var parts = [];
    // allow a literal ] as the first member
    if (this.peek() === ']') { parts.push({ kind: 'char', value: ']' }); this.pos++; }

    while (!this.eof() && this.peek() !== ']') {
      var lo = this.readClassAtom();
      if (this.peek() === '-' && this.peek(1) !== ']' && this.peek(1) !== undefined) {
        this.pos++; // -
        var hi = this.readClassAtom();
        if (lo.kind !== 'char' || hi.kind !== 'char') {
          // range with a class shorthand -> treat parts as separate plus a literal dash
          parts.push(lo, { kind: 'char', value: '-' }, hi);
        } else {
          parts.push({ kind: 'range', from: lo.value, to: hi.value });
        }
      } else {
        parts.push(lo);
      }
    }
    if (this.peek() !== ']') this.error('Unterminated character class — expected "]"');
    this.pos++;
    return { type: 'charclass', negated: negated, parts: parts, raw: this.src.slice(start, this.pos) };
  };

  var CLASS_ESC = {
    d: 'digit', D: 'non-digit', w: 'word char', W: 'non-word char',
    s: 'whitespace', S: 'non-whitespace'
  };
  var CTRL_ESC = { n: '\n', r: '\r', t: '\t', f: '\f', v: '\v', '0': '\0' };

  Parser.prototype.readClassAtom = function () {
    var c = this.peek();
    if (c === '\\') {
      this.pos++;
      var e = this.peek();
      if (e === undefined) this.error('Trailing backslash in character class');
      this.pos++;
      if (CLASS_ESC[e]) return { kind: 'class', shorthand: e, label: CLASS_ESC[e] };
      if (e === 'x') { var hx = this.readHex(2); return { kind: 'char', value: String.fromCharCode(parseInt(hx, 16)), raw: '\\x' + hx }; }
      if (e === 'u') { var u = this.readUnicode(); return { kind: 'char', value: u.value, raw: u.raw }; }
      if (CTRL_ESC[e] !== undefined) return { kind: 'char', value: CTRL_ESC[e], raw: '\\' + e };
      if (e === 'b') return { kind: 'char', value: '\b', raw: '\\b' }; // backspace inside class
      return { kind: 'char', value: e, raw: '\\' + e };
    }
    this.pos++;
    return { kind: 'char', value: c };
  };

  Parser.prototype.readHex = function (n) {
    var s = '';
    for (var i = 0; i < n; i++) {
      if (/[0-9a-fA-F]/.test(this.peek() || '')) { s += this.peek(); this.pos++; }
      else this.error('Invalid \\x escape — expected ' + n + ' hex digits');
    }
    return s;
  };

  Parser.prototype.readUnicode = function () {
    if (this.peek() === '{') {
      this.pos++;
      var s = '';
      while (/[0-9a-fA-F]/.test(this.peek() || '')) { s += this.peek(); this.pos++; }
      if (this.peek() !== '}') this.error('Invalid \\u{...} escape');
      this.pos++;
      return { value: String.fromCodePoint(parseInt(s, 16)), raw: '\\u{' + s + '}' };
    }
    var h = this.readHex(4);
    return { value: String.fromCharCode(parseInt(h, 16)), raw: '\\u' + h };
  };

  Parser.prototype.parseEscape = function () {
    var start = this.pos;
    this.pos++; // backslash
    var c = this.peek();
    if (c === undefined) this.error('Trailing backslash "\\" at end of pattern');
    this.pos++;

    // anchors that are escapes
    if (c === 'b') return { type: 'anchor', kind: 'wordB', raw: '\\b' };
    if (c === 'B') return { type: 'anchor', kind: 'nonWordB', raw: '\\B' };

    // character-class shorthands
    if (CLASS_ESC[c]) return { type: 'escape', kind: 'class', shorthand: c, label: CLASS_ESC[c], raw: '\\' + c };

    // control chars
    if (CTRL_ESC[c] !== undefined && c !== '0') {
      return { type: 'escape', kind: 'control', value: CTRL_ESC[c], char: c, raw: '\\' + c };
    }
    if (c === '0') return { type: 'escape', kind: 'control', value: '\0', char: '0', raw: '\\0' };

    // named backref \k<name>
    if (c === 'k' && this.peek() === '<') {
      this.pos++;
      var name = this.readGroupName();
      if (this.peek() !== '>') this.error('Invalid named backreference — expected ">"');
      this.pos++;
      if (this.groupNames.indexOf(name) === -1) this.error('Backreference to unknown group name "' + name + '"');
      return { type: 'backref', ref: name, byName: true, raw: this.src.slice(start, this.pos) };
    }

    // numeric backref \1 .. \99
    if (/[1-9]/.test(c)) {
      var num = c;
      while (/[0-9]/.test(this.peek() || '')) { num += this.peek(); this.pos++; }
      return { type: 'backref', ref: +num, byName: false, raw: '\\' + num };
    }

    // hex / unicode literal escapes
    if (c === 'x') { var hx = this.readHex(2); return { type: 'literal', value: String.fromCharCode(parseInt(hx, 16)), raw: '\\x' + hx, escaped: true }; }
    if (c === 'u') { var u = this.readUnicode(); return { type: 'literal', value: u.value, raw: u.raw, escaped: true }; }

    // \p{...} unicode property (u flag) — keep as escape token
    if ((c === 'p' || c === 'P') && this.peek() === '{') {
      var prop = '';
      this.pos++;
      while (this.peek() !== '}' && !this.eof()) { prop += this.peek(); this.pos++; }
      if (this.peek() === '}') this.pos++;
      return { type: 'escape', kind: 'unicodeprop', negated: c === 'P', prop: prop, raw: this.src.slice(start, this.pos) };
    }

    // escaped metacharacter / literal
    return { type: 'literal', value: c, raw: '\\' + c, escaped: true };
  };

  /* ---- public API ---- */
  function parse(src, flags) {
    var p = new Parser(src, flags);
    var ast = p.parse();
    return { ast: ast, groupCount: p.groupIndex, groupNames: p.groupNames };
  }

  global.RelayParser = { parse: parse, ParseError: ParseError };

})(typeof window !== 'undefined' ? window : this);
