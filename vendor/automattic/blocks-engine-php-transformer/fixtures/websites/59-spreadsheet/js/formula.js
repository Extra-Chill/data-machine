/* =========================================================
   LATTICE — Formula Engine
   A real spreadsheet formula engine, written from scratch.

   Pipeline:   tokenize() -> Parser (recursive descent) -> AST
               -> evaluate(AST, ctx)
   Plus a function library, A1 reference math, and a
   dependency graph with topological recalculation and
   circular-reference detection.

   Exposed as window.Lattice.Formula
   ========================================================= */
(function () {
  'use strict';

  /* ── Error values (spreadsheet-style) ─────────────────── */
  const ERR = {
    DIV0:  '#DIV/0!',
    NAME:  '#NAME?',
    VALUE: '#VALUE!',
    REF:   '#REF!',
    NUM:   '#NUM!',
    NA:    '#N/A',
    CIRC:  '#CIRC!',
    ERROR: '#ERROR!',
    PARSE: '#PARSE!',
  };
  const ALL_ERRORS = Object.values(ERR);
  function isError(v) { return typeof v === 'string' && ALL_ERRORS.indexOf(v) !== -1; }

  /* A small wrapper so we can distinguish a "thrown" spreadsheet error
     from JS exceptions. */
  function FormulaError(code) { this.code = code; }

  /* ─────────────────────────────────────────────────────────
     A1 <-> {row,col} helpers.  Rows/cols are 0-based internally.
     A1 means col 0, row 0.   B3 means col 1, row 2.
     ───────────────────────────────────────────────────────── */
  function colToLetters(col) {
    let s = '';
    col += 1;
    while (col > 0) {
      const rem = (col - 1) % 26;
      s = String.fromCharCode(65 + rem) + s;
      col = Math.floor((col - 1) / 26);
    }
    return s;
  }
  function lettersToCol(letters) {
    let col = 0;
    for (let i = 0; i < letters.length; i++) {
      col = col * 26 + (letters.charCodeAt(i) - 64);
    }
    return col - 1;
  }
  function cellId(row, col) { return colToLetters(col) + (row + 1); }

  // Parse "A1", "$B$2", "C10" -> {row,col,absRow,absCol} or null
  function parseRef(ref) {
    const m = /^(\$?)([A-Za-z]+)(\$?)(\d+)$/.exec(ref);
    if (!m) return null;
    return {
      absCol: m[1] === '$',
      col: lettersToCol(m[2].toUpperCase()),
      absRow: m[3] === '$',
      row: parseInt(m[4], 10) - 1,
    };
  }

  /* =========================================================
     1. TOKENIZER
     ========================================================= */
  const T = {
    NUM: 'NUM', STR: 'STR', REF: 'REF', RANGE_OP: 'RANGE_OP',
    IDENT: 'IDENT', OP: 'OP', LP: 'LP', RP: 'RP',
    COMMA: 'COMMA', BOOL: 'BOOL', EOF: 'EOF',
  };

  function tokenize(src) {
    const toks = [];
    let i = 0;
    const n = src.length;
    const isDigit = c => c >= '0' && c <= '9';
    const isAlpha = c => (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || c === '_';

    while (i < n) {
      const c = src[i];

      if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue; }

      // string literal "..."  (doubled "" -> ")
      if (c === '"') {
        i++; let str = '';
        while (i < n) {
          if (src[i] === '"') {
            if (src[i + 1] === '"') { str += '"'; i += 2; continue; }
            i++; break;
          }
          str += src[i++];
        }
        toks.push({ t: T.STR, v: str });
        continue;
      }

      // number (with optional decimal + exponent, and trailing %)
      if (isDigit(c) || (c === '.' && isDigit(src[i + 1]))) {
        let j = i;
        while (j < n && isDigit(src[j])) j++;
        if (src[j] === '.') { j++; while (j < n && isDigit(src[j])) j++; }
        if (src[j] === 'e' || src[j] === 'E') {
          let k = j + 1;
          if (src[k] === '+' || src[k] === '-') k++;
          if (isDigit(src[k])) { j = k; while (j < n && isDigit(src[j])) j++; }
        }
        let num = parseFloat(src.slice(i, j));
        i = j;
        if (src[i] === '%') { num /= 100; i++; }
        toks.push({ t: T.NUM, v: num });
        continue;
      }

      // identifier / cell ref / boolean / function name
      if (isAlpha(c) || c === '$') {
        let j = i;
        while (j < n && (isAlpha(src[j]) || isDigit(src[j]) || src[j] === '$' || src[j] === '.')) j++;
        const word = src.slice(i, j);
        i = j;
        const up = word.toUpperCase();
        if (up === 'TRUE')  { toks.push({ t: T.BOOL, v: true }); continue; }
        if (up === 'FALSE') { toks.push({ t: T.BOOL, v: false }); continue; }
        // Is this a function call?  next non-space char is '('
        let p = i; while (p < n && src[p] === ' ') p++;
        if (src[p] === '(') { toks.push({ t: T.IDENT, v: up }); continue; }
        // Otherwise a cell reference?
        if (parseRef(word)) { toks.push({ t: T.REF, v: word }); continue; }
        // A bare name we don't understand -> NAME error token (still IDENT, caught later)
        toks.push({ t: T.IDENT, v: up, bare: true });
        continue;
      }

      // operators
      if (c === '<' || c === '>') {
        if (src[i + 1] === '=') { toks.push({ t: T.OP, v: c + '=' }); i += 2; continue; }
        if (c === '<' && src[i + 1] === '>') { toks.push({ t: T.OP, v: '<>' }); i += 2; continue; }
        toks.push({ t: T.OP, v: c }); i++; continue;
      }
      if (c === '=') { toks.push({ t: T.OP, v: '=' }); i++; continue; }
      if ('+-*/^&'.indexOf(c) !== -1) { toks.push({ t: T.OP, v: c }); i++; continue; }
      if (c === '(') { toks.push({ t: T.LP }); i++; continue; }
      if (c === ')') { toks.push({ t: T.RP }); i++; continue; }
      if (c === ':') { toks.push({ t: T.RANGE_OP }); i++; continue; }
      if (c === ',') { toks.push({ t: T.COMMA }); i++; continue; }

      // unrecognised char
      throw new FormulaError(ERR.PARSE);
    }
    toks.push({ t: T.EOF });
    return toks;
  }

  /* =========================================================
     2. PARSER  (recursive descent)

     grammar (lowest precedence first):
       expr      := compare
       compare   := concat ( (= <> < > <= >=) concat )*
       concat    := additive ( & additive )*
       additive  := term ( (+ -) term )*
       term      := power ( (* /) power )*
       power     := unary ( ^ unary )*       (right-assoc)
       unary     := (+|-) unary | postfix
       postfix   := primary ( % )*
       primary   := NUM | STR | BOOL
                  | REF ( : REF )?        (cell or range)
                  | IDENT ( args )        (function call)
                  | ( expr )
     ========================================================= */
  function Parser(tokens) {
    this.toks = tokens;
    this.pos = 0;
  }
  Parser.prototype.peek = function () { return this.toks[this.pos]; };
  Parser.prototype.next = function () { return this.toks[this.pos++]; };
  Parser.prototype.expect = function (type) {
    const tk = this.next();
    if (tk.t !== type) throw new FormulaError(ERR.PARSE);
    return tk;
  };

  Parser.prototype.parse = function () {
    const node = this.parseExpr();
    if (this.peek().t !== T.EOF) throw new FormulaError(ERR.PARSE);
    return node;
  };

  Parser.prototype.parseExpr = function () { return this.parseCompare(); };

  Parser.prototype.parseCompare = function () {
    let left = this.parseConcat();
    while (this.peek().t === T.OP && ['=', '<>', '<', '>', '<=', '>='].indexOf(this.peek().v) !== -1) {
      const op = this.next().v;
      const right = this.parseConcat();
      left = { type: 'binary', op, left, right };
    }
    return left;
  };

  Parser.prototype.parseConcat = function () {
    let left = this.parseAdditive();
    while (this.peek().t === T.OP && this.peek().v === '&') {
      this.next();
      left = { type: 'binary', op: '&', left, right: this.parseAdditive() };
    }
    return left;
  };

  Parser.prototype.parseAdditive = function () {
    let left = this.parseTerm();
    while (this.peek().t === T.OP && (this.peek().v === '+' || this.peek().v === '-')) {
      const op = this.next().v;
      left = { type: 'binary', op, left, right: this.parseTerm() };
    }
    return left;
  };

  Parser.prototype.parseTerm = function () {
    let left = this.parsePower();
    while (this.peek().t === T.OP && (this.peek().v === '*' || this.peek().v === '/')) {
      const op = this.next().v;
      left = { type: 'binary', op, left, right: this.parsePower() };
    }
    return left;
  };

  Parser.prototype.parsePower = function () {
    const left = this.parseUnary();
    if (this.peek().t === T.OP && this.peek().v === '^') {
      this.next();
      return { type: 'binary', op: '^', left, right: this.parsePower() }; // right assoc
    }
    return left;
  };

  Parser.prototype.parseUnary = function () {
    if (this.peek().t === T.OP && (this.peek().v === '+' || this.peek().v === '-')) {
      const op = this.next().v;
      return { type: 'unary', op, arg: this.parseUnary() };
    }
    return this.parsePostfix();
  };

  Parser.prototype.parsePostfix = function () {
    let node = this.parsePrimary();
    while (this.peek().t === T.OP && this.peek().v === '%') {
      this.next();
      node = { type: 'unary', op: '%', arg: node };
    }
    return node;
  };

  Parser.prototype.parsePrimary = function () {
    const tk = this.peek();

    if (tk.t === T.NUM)  { this.next(); return { type: 'num', value: tk.v }; }
    if (tk.t === T.STR)  { this.next(); return { type: 'str', value: tk.v }; }
    if (tk.t === T.BOOL) { this.next(); return { type: 'bool', value: tk.v }; }

    if (tk.t === T.LP) {
      this.next();
      const e = this.parseExpr();
      this.expect(T.RP);
      return e;
    }

    if (tk.t === T.REF) {
      this.next();
      // a range?  REF : REF
      if (this.peek().t === T.RANGE_OP) {
        this.next();
        const end = this.expect(T.REF);
        return { type: 'range', from: tk.v, to: end.v };
      }
      return { type: 'ref', ref: tk.v };
    }

    if (tk.t === T.IDENT) {
      this.next();
      if (tk.bare) {
        // bare identifier not followed by ( -> #NAME?
        throw new FormulaError(ERR.NAME);
      }
      this.expect(T.LP);
      const args = [];
      if (this.peek().t !== T.RP) {
        args.push(this.parseExpr());
        while (this.peek().t === T.COMMA) { this.next(); args.push(this.parseExpr()); }
      }
      this.expect(T.RP);
      return { type: 'call', name: tk.v, args };
    }

    throw new FormulaError(ERR.PARSE);
  };

  /* =========================================================
     3. Coercion helpers
     ========================================================= */
  function toNumber(v) {
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return v;
    if (typeof v === 'boolean') return v ? 1 : 0;
    if (isError(v)) throw new FormulaError(v);
    const n = Number(String(v).trim());
    if (isNaN(n)) throw new FormulaError(ERR.VALUE);
    return n;
  }
  function toText(v) {
    if (v === null || v === undefined) return '';
    if (typeof v === 'boolean') return v ? 'TRUE' : 'FALSE';
    if (isError(v)) throw new FormulaError(v);
    if (typeof v === 'number') return numToStr(v);
    return String(v);
  }
  function toBool(v) {
    if (typeof v === 'boolean') return v;
    if (typeof v === 'number') return v !== 0;
    if (v === null || v === undefined || v === '') return false;
    if (isError(v)) throw new FormulaError(v);
    const s = String(v).trim().toUpperCase();
    if (s === 'TRUE') return true;
    if (s === 'FALSE') return false;
    return Boolean(v);
  }
  function numToStr(n) {
    if (!isFinite(n)) return ERR.NUM;
    // trim float noise
    if (Number.isInteger(n)) return String(n);
    return String(parseFloat(n.toPrecision(12)));
  }

  /* =========================================================
     4. EVALUATOR

     ctx = {
       getCell(row,col)  -> evaluated value of a cell (drives deps)
       today()           -> Date
     }
     ========================================================= */
  function evaluate(node, ctx) {
    switch (node.type) {
      case 'num':  return node.value;
      case 'str':  return node.value;
      case 'bool': return node.value;

      case 'ref': {
        const r = parseRef(node.ref);
        if (!r) throw new FormulaError(ERR.REF);
        return ctx.getCell(r.row, r.col);
      }

      case 'range': {
        // ranges only become meaningful inside function calls; as a
        // bare value a range is a #VALUE! error.
        throw new FormulaError(ERR.VALUE);
      }

      case 'unary': {
        if (node.op === '%') return toNumber(evaluate(node.arg, ctx)) / 100;
        const v = toNumber(evaluate(node.arg, ctx));
        return node.op === '-' ? -v : v;
      }

      case 'binary': return evalBinary(node, ctx);

      case 'call': return evalCall(node, ctx);
    }
    throw new FormulaError(ERR.ERROR);
  }

  function evalBinary(node, ctx) {
    const op = node.op;

    if (op === '&') {
      return toText(evaluate(node.left, ctx)) + toText(evaluate(node.right, ctx));
    }

    if (['=', '<>', '<', '>', '<=', '>='].indexOf(op) !== -1) {
      return compare(evaluate(node.left, ctx), evaluate(node.right, ctx), op);
    }

    const a = toNumber(evaluate(node.left, ctx));
    const b = toNumber(evaluate(node.right, ctx));
    switch (op) {
      case '+': return a + b;
      case '-': return a - b;
      case '*': return a * b;
      case '/':
        if (b === 0) throw new FormulaError(ERR.DIV0);
        return a / b;
      case '^': {
        const r = Math.pow(a, b);
        if (isNaN(r)) throw new FormulaError(ERR.NUM);
        return r;
      }
    }
    throw new FormulaError(ERR.ERROR);
  }

  function compare(a, b, op) {
    // numbers compare numerically; otherwise compare as text (case-insensitive)
    let x = a, y = b;
    if (typeof a === 'number' && typeof b === 'number') { /* numeric */ }
    else if (typeof a === 'boolean' || typeof b === 'boolean') { x = toBool(a) ? 1 : 0; y = toBool(b) ? 1 : 0; }
    else if (typeof a === 'number' || typeof b === 'number') {
      // mixed: try to coerce the text side; if it isn't numeric, compare as text
      const an = typeof a === 'number' ? a : Number(String(a));
      const bn = typeof b === 'number' ? b : Number(String(b));
      if (!isNaN(an) && !isNaN(bn)) { x = an; y = bn; }
      else { x = toText(a).toUpperCase(); y = toText(b).toUpperCase(); }
    } else {
      x = toText(a).toUpperCase(); y = toText(b).toUpperCase();
    }
    switch (op) {
      case '=':  return x === y;
      case '<>': return x !== y;
      case '<':  return x < y;
      case '>':  return x > y;
      case '<=': return x <= y;
      case '>=': return x >= y;
    }
    return false;
  }

  /* Flatten an argument node into a list of *values*.
     Ranges expand to all their cell values. */
  function argValues(node, ctx) {
    if (node.type === 'range') {
      const out = [];
      const f = parseRef(node.from), t = parseRef(node.to);
      if (!f || !t) throw new FormulaError(ERR.REF);
      const r0 = Math.min(f.row, t.row), r1 = Math.max(f.row, t.row);
      const c0 = Math.min(f.col, t.col), c1 = Math.max(f.col, t.col);
      for (let r = r0; r <= r1; r++)
        for (let c = c0; c <= c1; c++)
          out.push(ctx.getCell(r, c));
      return out;
    }
    return [evaluate(node, ctx)];
  }

  // numeric-only values from args (ignores blanks/text, like real SUM)
  function numericArgs(args, ctx) {
    const nums = [];
    for (const a of args) {
      for (const v of argValues(a, ctx)) {
        if (v === null || v === undefined || v === '') continue;
        if (typeof v === 'boolean') { nums.push(v ? 1 : 0); continue; }
        if (typeof v === 'number') { nums.push(v); continue; }
        if (isError(v)) throw new FormulaError(v);
        const n = Number(String(v).trim());
        if (!isNaN(n) && String(v).trim() !== '') nums.push(n);
      }
    }
    return nums;
  }

  /* =========================================================
     5. FUNCTION LIBRARY
     Each fn receives (args[], ctx).  args are AST nodes so a
     function can decide whether to evaluate lazily (IF) or to
     expand ranges (SUM).
     ========================================================= */
  const FUNCS = {
    SUM(args, ctx)     { return numericArgs(args, ctx).reduce((a, b) => a + b, 0); },
    AVERAGE(args, ctx) { const n = numericArgs(args, ctx); if (!n.length) throw new FormulaError(ERR.DIV0); return n.reduce((a, b) => a + b, 0) / n.length; },
    MIN(args, ctx)     { const n = numericArgs(args, ctx); return n.length ? Math.min.apply(null, n) : 0; },
    MAX(args, ctx)     { const n = numericArgs(args, ctx); return n.length ? Math.max.apply(null, n) : 0; },
    PRODUCT(args, ctx) { const n = numericArgs(args, ctx); return n.length ? n.reduce((a, b) => a * b, 1) : 0; },
    MEDIAN(args, ctx)  {
      const n = numericArgs(args, ctx).sort((a, b) => a - b);
      if (!n.length) throw new FormulaError(ERR.NUM);
      const m = Math.floor(n.length / 2);
      return n.length % 2 ? n[m] : (n[m - 1] + n[m]) / 2;
    },

    COUNT(args, ctx) {
      // count numbers only
      let c = 0;
      for (const a of args) for (const v of argValues(a, ctx)) {
        if (typeof v === 'number') c++;
        else if (typeof v === 'string' && v.trim() !== '' && !isNaN(Number(v))) c++;
      }
      return c;
    },
    COUNTA(args, ctx) {
      let c = 0;
      for (const a of args) for (const v of argValues(a, ctx))
        if (v !== null && v !== undefined && v !== '') c++;
      return c;
    },
    COUNTIF(args, ctx) {
      if (args.length !== 2) throw new FormulaError(ERR.VALUE);
      const vals = argValues(args[0], ctx);
      const crit = evaluate(args[1], ctx);
      let c = 0;
      for (const v of vals) if (matchCriterion(v, crit)) c++;
      return c;
    },
    SUMIF(args, ctx) {
      if (args.length < 2) throw new FormulaError(ERR.VALUE);
      const range = argValues(args[0], ctx);
      const crit = evaluate(args[1], ctx);
      const sumRange = args.length >= 3 ? argValues(args[2], ctx) : range;
      let total = 0;
      for (let i = 0; i < range.length; i++) {
        if (matchCriterion(range[i], crit)) {
          const v = sumRange[i];
          if (typeof v === 'number') total += v;
          else if (typeof v === 'string' && v.trim() !== '' && !isNaN(Number(v))) total += Number(v);
        }
      }
      return total;
    },

    IF(args, ctx) {
      if (args.length < 2) throw new FormulaError(ERR.VALUE);
      const cond = toBool(evaluate(args[0], ctx));
      if (cond) return evaluate(args[1], ctx);
      return args.length >= 3 ? evaluate(args[2], ctx) : false;
    },
    IFERROR(args, ctx) {
      if (args.length < 2) throw new FormulaError(ERR.VALUE);
      try {
        const v = evaluate(args[0], ctx);
        if (isError(v)) return evaluate(args[1], ctx);
        return v;
      } catch (e) {
        if (e instanceof FormulaError) return evaluate(args[1], ctx);
        throw e;
      }
    },
    AND(args, ctx) {
      let any = false;
      for (const a of args) for (const v of argValues(a, ctx)) { any = true; if (!toBool(v)) return false; }
      return any;
    },
    OR(args, ctx) {
      for (const a of args) for (const v of argValues(a, ctx)) if (toBool(v)) return true;
      return false;
    },
    NOT(args, ctx) { return !toBool(evaluate(args[0], ctx)); },
    TRUE() { return true; },
    FALSE() { return false; },

    ROUND(args, ctx) {
      const x = toNumber(evaluate(args[0], ctx));
      const d = args.length > 1 ? toNumber(evaluate(args[1], ctx)) : 0;
      const f = Math.pow(10, d);
      return Math.round((x + Number.EPSILON * Math.sign(x)) * f) / f;
    },
    ROUNDUP(args, ctx) {
      const x = toNumber(evaluate(args[0], ctx));
      const d = args.length > 1 ? toNumber(evaluate(args[1], ctx)) : 0;
      const f = Math.pow(10, d);
      return Math.ceil(Math.abs(x) * f) / f * Math.sign(x);
    },
    ROUNDDOWN(args, ctx) {
      const x = toNumber(evaluate(args[0], ctx));
      const d = args.length > 1 ? toNumber(evaluate(args[1], ctx)) : 0;
      const f = Math.pow(10, d);
      return Math.floor(Math.abs(x) * f) / f * Math.sign(x);
    },
    INT(args, ctx)  { return Math.floor(toNumber(evaluate(args[0], ctx))); },
    ABS(args, ctx)  { return Math.abs(toNumber(evaluate(args[0], ctx))); },
    SQRT(args, ctx) { const x = toNumber(evaluate(args[0], ctx)); if (x < 0) throw new FormulaError(ERR.NUM); return Math.sqrt(x); },
    POWER(args, ctx){ return Math.pow(toNumber(evaluate(args[0], ctx)), toNumber(evaluate(args[1], ctx))); },
    MOD(args, ctx)  {
      const a = toNumber(evaluate(args[0], ctx)), b = toNumber(evaluate(args[1], ctx));
      if (b === 0) throw new FormulaError(ERR.DIV0);
      return a - b * Math.floor(a / b);
    },
    CEILING(args, ctx) { return Math.ceil(toNumber(evaluate(args[0], ctx))); },
    FLOOR(args, ctx)   { return Math.floor(toNumber(evaluate(args[0], ctx))); },
    PI() { return Math.PI; },
    SIGN(args, ctx)   { return Math.sign(toNumber(evaluate(args[0], ctx))); },

    CONCAT(args, ctx)        { let s = ''; for (const a of args) for (const v of argValues(a, ctx)) s += toText(v); return s; },
    CONCATENATE(args, ctx)   { return FUNCS.CONCAT(args, ctx); },
    LEN(args, ctx)    { return toText(evaluate(args[0], ctx)).length; },
    LOWER(args, ctx)  { return toText(evaluate(args[0], ctx)).toLowerCase(); },
    UPPER(args, ctx)  { return toText(evaluate(args[0], ctx)).toUpperCase(); },
    TRIM(args, ctx)   { return toText(evaluate(args[0], ctx)).replace(/\s+/g, ' ').trim(); },
    LEFT(args, ctx)   { const s = toText(evaluate(args[0], ctx)); const n = args.length > 1 ? toNumber(evaluate(args[1], ctx)) : 1; return s.slice(0, Math.max(0, n)); },
    RIGHT(args, ctx)  { const s = toText(evaluate(args[0], ctx)); const n = args.length > 1 ? toNumber(evaluate(args[1], ctx)) : 1; return n <= 0 ? '' : s.slice(-n); },
    MID(args, ctx)    {
      const s = toText(evaluate(args[0], ctx));
      const start = toNumber(evaluate(args[1], ctx));
      const len = toNumber(evaluate(args[2], ctx));
      return s.substr(Math.max(0, start - 1), Math.max(0, len));
    },
    REPT(args, ctx)   { const s = toText(evaluate(args[0], ctx)); const n = toNumber(evaluate(args[1], ctx)); return n > 0 ? s.repeat(Math.floor(n)) : ''; },
    TEXT(args, ctx)   {
      const v = toNumber(evaluate(args[0], ctx));
      const fmt = toText(evaluate(args[1], ctx));
      const dec = (fmt.split('.')[1] || '').length;
      return v.toFixed(dec);
    },
    VALUE(args, ctx)  { return toNumber(evaluate(args[0], ctx)); },

    TODAY(args, ctx)  { const d = ctx.today(); return dateToSerial(new Date(d.getFullYear(), d.getMonth(), d.getDate())); },
    NOW(args, ctx)    { return dateToSerial(ctx.today()); },
    YEAR(args, ctx)   { return serialToDate(toNumber(evaluate(args[0], ctx))).getFullYear(); },
    MONTH(args, ctx)  { return serialToDate(toNumber(evaluate(args[0], ctx))).getMonth() + 1; },
    DAY(args, ctx)    { return serialToDate(toNumber(evaluate(args[0], ctx))).getDate(); },
    DATE(args, ctx)   {
      const y = toNumber(evaluate(args[0], ctx));
      const m = toNumber(evaluate(args[1], ctx));
      const d = toNumber(evaluate(args[2], ctx));
      return dateToSerial(new Date(y, m - 1, d));
    },
  };

  // Excel-style serial dates (day 1 = 1900-01-01, matching the common epoch enough for demo)
  const DAY_MS = 86400000;
  const EPOCH = Date.UTC(1899, 11, 30);
  function dateToSerial(d) { return Math.floor((Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) - EPOCH) / DAY_MS); }
  function serialToDate(s) { return new Date(EPOCH + s * DAY_MS); }

  // criterion: number => equality; ">5","<=3","<>x" => operator; text => case-insensitive equality
  function matchCriterion(value, crit) {
    if (typeof crit === 'number') {
      return typeof value === 'number' ? value === crit : Number(value) === crit;
    }
    const s = String(crit).trim();
    const m = /^(<=|>=|<>|<|>|=)?\s*(.*)$/.exec(s);
    const op = m[1] || '=';
    let target = m[2];
    const tn = Number(target);
    const numericTarget = target !== '' && !isNaN(tn);
    let vNum = typeof value === 'number' ? value : Number(value);
    if (numericTarget && !isNaN(vNum)) {
      switch (op) {
        case '=':  return vNum === tn;
        case '<>': return vNum !== tn;
        case '<':  return vNum < tn;
        case '>':  return vNum > tn;
        case '<=': return vNum <= tn;
        case '>=': return vNum >= tn;
      }
    }
    const vs = String(value).toUpperCase();
    const ts = target.toUpperCase();
    if (op === '<>') return vs !== ts;
    // simple wildcard support * ?
    if (ts.indexOf('*') !== -1 || ts.indexOf('?') !== -1) {
      const re = new RegExp('^' + ts.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$');
      return re.test(vs);
    }
    return vs === ts;
  }

  function evalCall(node, ctx) {
    const fn = FUNCS[node.name];
    if (!fn) throw new FormulaError(ERR.NAME);
    return fn(node.args, ctx);
  }

  /* =========================================================
     6. Dependency extraction (for the graph)
     Walk an AST and collect every cell it reads.
     ========================================================= */
  function collectRefs(node, set) {
    if (!node) return;
    switch (node.type) {
      case 'ref': {
        const r = parseRef(node.ref);
        if (r) set.add(cellId(r.row, r.col));
        break;
      }
      case 'range': {
        const f = parseRef(node.from), t = parseRef(node.to);
        if (f && t) {
          const r0 = Math.min(f.row, t.row), r1 = Math.max(f.row, t.row);
          const c0 = Math.min(f.col, t.col), c1 = Math.max(f.col, t.col);
          for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) set.add(cellId(r, c));
        }
        break;
      }
      case 'binary': collectRefs(node.left, set); collectRefs(node.right, set); break;
      case 'unary': collectRefs(node.arg, set); break;
      case 'call': node.args.forEach(a => collectRefs(a, set)); break;
    }
  }

  /* =========================================================
     7. Relative reference adjustment (copy/paste of formulas)
     Shift every non-absolute ref in a formula string by (dr,dc).
     ========================================================= */
  function adjustFormula(formula, dr, dc) {
    // tokenize-ish: find REF tokens not inside strings
    let out = '';
    let i = 0;
    const n = formula.length;
    while (i < n) {
      const c = formula[i];
      if (c === '"') {
        out += c; i++;
        while (i < n) { out += formula[i]; if (formula[i] === '"') { i++; break; } i++; }
        continue;
      }
      // potential ref:  optional $, letters, optional $, digits
      const m = /^(\$?)([A-Za-z]{1,3})(\$?)(\d{1,7})/.exec(formula.slice(i));
      if (m && parseRef(m[0])) {
        // make sure it's not part of a longer identifier (function name etc.)
        const prev = formula[i - 1];
        const after = formula[i + m[0].length];
        const isWordBefore = prev && /[A-Za-z0-9_$]/.test(prev);
        const isWordAfter = after && /[A-Za-z0-9_(]/.test(after);
        if (!isWordBefore && !isWordAfter) {
          const r = parseRef(m[0]);
          const newCol = r.absCol ? r.col : r.col + dc;
          const newRow = r.absRow ? r.row : r.row + dr;
          if (newCol < 0 || newRow < 0) { out += ERR.REF; i += m[0].length; continue; }
          out += (r.absCol ? '$' : '') + colToLetters(newCol) + (r.absRow ? '$' : '') + (newRow + 1);
          i += m[0].length;
          continue;
        }
      }
      out += c; i++;
    }
    return out;
  }

  /* =========================================================
     Public API:  compile a formula string into an AST + deps.
     compile("=A1+SUM(B1:B3)") ->
       { ast, deps:Set, evaluate(ctx) }
     ========================================================= */
  function compile(formulaBody) {
    let ast;
    try {
      ast = new Parser(tokenize(formulaBody)).parse();
    } catch (e) {
      if (e instanceof FormulaError) return { error: e.code };
      return { error: ERR.PARSE };
    }
    const deps = new Set();
    collectRefs(ast, deps);
    return {
      ast,
      deps,
      run(ctx) {
        try {
          const v = evaluate(ast, ctx);
          if (typeof v === 'number' && !isFinite(v)) return ERR.NUM;
          return v;
        } catch (e) {
          if (e instanceof FormulaError) return e.code;
          throw e;
        }
      },
    };
  }

  window.Lattice = window.Lattice || {};
  window.Lattice.Formula = {
    compile, tokenize, Parser, evaluate, adjustFormula,
    colToLetters, lettersToCol, cellId, parseRef,
    isError, ERR, numToStr,
    FUNC_NAMES: Object.keys(FUNCS).sort(),
    _internals: { toNumber, toText, toBool, dateToSerial, serialToDate },
  };
})();
