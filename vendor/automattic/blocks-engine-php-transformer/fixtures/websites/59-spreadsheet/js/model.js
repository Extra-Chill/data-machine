/* =========================================================
   LATTICE — Sheet Model
   Holds cell data, drives the formula engine, maintains the
   dependency graph, recalculates, detects circular refs, and
   persists to localStorage.  Emits events for the view.

   A "cell" stored in model.cells[id] = {
     raw:   the literal user input ("=A1+1", "42", "Rent")
     fmt:   { bold, italic, align, numfmt }   (presentation)
   }
   Derived (not stored): value, formula AST, deps.

   window.Lattice.Model
   ========================================================= */
(function () {
  'use strict';

  const F = window.Lattice.Formula;
  const ROWS = 50;
  const COLS = 26;

  function emptyFmt() { return { bold: false, italic: false, align: '', numfmt: 'auto' }; }

  function Model() {
    this.rows = ROWS;
    this.cols = COLS;
    this.cells = Object.create(null);   // id -> {raw, fmt}
    this.colWidths = Object.create(null);
    this.rowHeights = Object.create(null);
    this.name = 'Untitled sheet';

    // derived
    this._compiled = Object.create(null);  // id -> {deps,run} | {error}
    this._values = Object.create(null);    // id -> computed value
    this._dependents = Object.create(null);// id -> Set of cells that depend on it
    this._listeners = Object.create(null);

    this._today = new Date();
  }

  /* ── events ───────────────────────────────────────────── */
  Model.prototype.on = function (ev, fn) {
    (this._listeners[ev] = this._listeners[ev] || []).push(fn);
  };
  Model.prototype.emit = function (ev, payload) {
    (this._listeners[ev] || []).forEach(fn => fn(payload));
  };

  /* ── cell access ──────────────────────────────────────── */
  Model.prototype.id = function (r, c) { return F.cellId(r, c); };

  Model.prototype.getRaw = function (r, c) {
    const cell = this.cells[F.cellId(r, c)];
    return cell ? cell.raw : '';
  };
  Model.prototype.getFmt = function (r, c) {
    const cell = this.cells[F.cellId(r, c)];
    return cell && cell.fmt ? cell.fmt : emptyFmt();
  };

  // computed display value of a cell
  Model.prototype.getValue = function (r, c) {
    return this._valueById(F.cellId(r, c));
  };
  Model.prototype._valueById = function (id) {
    if (id in this._values) return this._values[id];
    const cell = this.cells[id];
    if (!cell) return '';
    return this._coerceLiteral(cell.raw);
  };

  // numeric value used by selection-stats & functions when no recalc cache
  Model.prototype._coerceLiteral = function (raw) {
    if (raw === '' || raw == null) return '';
    if (raw[0] === '=') return ''; // handled by compiled path
    const n = Number(raw);
    if (raw.trim() !== '' && !isNaN(n)) return n;
    return raw;
  };

  /* ── editing ──────────────────────────────────────────── */
  Model.prototype.setRaw = function (r, c, raw, opts) {
    const id = F.cellId(r, c);
    opts = opts || {};
    if (raw === '' || raw == null) {
      if (this.cells[id]) {
        if (this.cells[id].fmt && hasFmt(this.cells[id].fmt) && !opts.clearFmt) {
          this.cells[id].raw = '';
        } else {
          delete this.cells[id];
        }
      }
    } else {
      const prev = this.cells[id];
      this.cells[id] = { raw: String(raw), fmt: prev && prev.fmt ? prev.fmt : emptyFmt() };
    }
    this._compileCell(id);
    if (!opts.silent) {
      this.recalc();
      this.persist();
      this.emit('change', { ids: [id] });
    }
  };

  // bulk set (paste) — compile all then recalc once
  Model.prototype.setMany = function (entries) {
    const ids = [];
    for (const e of entries) {
      const id = F.cellId(e.r, e.c);
      ids.push(id);
      if (e.raw === '' || e.raw == null) {
        delete this.cells[id];
      } else {
        const prev = this.cells[id];
        this.cells[id] = { raw: String(e.raw), fmt: e.fmt || (prev && prev.fmt) || emptyFmt() };
      }
      this._compileCell(id);
    }
    this.recalc();
    this.persist();
    this.emit('change', { ids });
  };

  Model.prototype.setFmt = function (cellsList, patch) {
    const ids = [];
    for (const [r, c] of cellsList) {
      const id = F.cellId(r, c);
      let cell = this.cells[id];
      if (!cell) { cell = this.cells[id] = { raw: '', fmt: emptyFmt() }; }
      if (!cell.fmt) cell.fmt = emptyFmt();
      Object.assign(cell.fmt, patch);
      if (cell.raw === '' && !hasFmt(cell.fmt)) delete this.cells[id];
      ids.push(id);
    }
    this.persist();
    this.emit('change', { ids });
  };

  function hasFmt(f) {
    return f && (f.bold || f.italic || f.align || (f.numfmt && f.numfmt !== 'auto'));
  }

  /* ── compilation + dependency graph ───────────────────── */
  Model.prototype._compileCell = function (id) {
    // remove old reverse deps
    const old = this._compiled[id];
    if (old && old.deps) {
      old.deps.forEach(dep => {
        if (this._dependents[dep]) this._dependents[dep].delete(id);
      });
    }
    delete this._compiled[id];

    const cell = this.cells[id];
    if (!cell || cell.raw[0] !== '=') return;

    const compiled = F.compile(cell.raw.slice(1));
    this._compiled[id] = compiled;
    if (compiled.deps) {
      compiled.deps.forEach(dep => {
        (this._dependents[dep] = this._dependents[dep] || new Set()).add(id);
      });
    }
  };

  /* ── full recalculation ───────────────────────────────────
     Evaluate every formula cell. We use a per-pass memo +
     recursion stack to detect circular references. Literals
     are resolved lazily inside getCell.
     ───────────────────────────────────────────────────────── */
  Model.prototype.recalc = function () {
    this._values = Object.create(null);
    const memo = this._values;
    const visiting = new Set();
    const self = this;

    function valueOf(id) {
      if (id in memo) return memo[id];
      const compiled = self._compiled[id];
      const cell = self.cells[id];

      // not a formula -> literal
      if (!compiled) {
        const v = cell ? self._coerceLiteral(cell.raw) : '';
        memo[id] = v;
        return v;
      }
      if (compiled.error) { memo[id] = compiled.error; return compiled.error; }

      if (visiting.has(id)) {       // circular!
        memo[id] = F.ERR.CIRC;
        return F.ERR.CIRC;
      }
      visiting.add(id);

      const ctx = {
        getCell(r, c) { return valueOf(F.cellId(r, c)); },
        today() { return self._today; },
      };
      let v;
      try { v = compiled.run(ctx); }
      catch (e) { v = F.ERR.ERROR; }
      visiting.delete(id);

      // propagate circular: if any value we read was CIRC and we're in a cycle
      memo[id] = v;
      return v;
    }

    // evaluate all formula cells
    for (const id in this._compiled) valueOf(id);

    // mark cells that participate in a cycle as #CIRC (catch indirect)
    this._detectCycles();

    this.emit('recalc', {});
  };

  // Tarjan-ish: any node on a cycle in the dep graph -> #CIRC
  Model.prototype._detectCycles = function () {
    const compiled = this._compiled;
    const WHITE = 0, GRAY = 1, BLACK = 2;
    const color = Object.create(null);
    const onCycle = new Set();
    const self = this;

    function dfs(id, stack) {
      color[id] = GRAY;
      stack.push(id);
      const c = compiled[id];
      if (c && c.deps) {
        for (const dep of c.deps) {
          if (!compiled[dep]) continue; // dep is a literal, can't close a cycle
          if (color[dep] === GRAY) {
            // found a back-edge: everything from dep..top of stack is on a cycle
            let k = stack.length - 1;
            while (k >= 0) { onCycle.add(stack[k]); if (stack[k] === dep) break; k--; }
          } else if (color[dep] === WHITE) {
            dfs(dep, stack);
          }
        }
      }
      stack.pop();
      color[id] = BLACK;
    }

    for (const id in compiled) if (color[id] !== BLACK) dfs(id, []);
    onCycle.forEach(id => { self._values[id] = F.ERR.CIRC; });
    this._cycleCells = onCycle;
  };

  Model.prototype.isError = function (v) { return F.isError(v); };

  /* ── display formatting of a computed value ───────────── */
  Model.prototype.display = function (r, c) {
    const id = F.cellId(r, c);
    const cell = this.cells[id];
    if (!cell || cell.raw === '') return '';
    let v;
    if (cell.raw[0] === '=') v = (id in this._values) ? this._values[id] : this._coerceLiteral(cell.raw);
    else v = this._coerceLiteral(cell.raw);

    if (F.isError(v)) return v;
    const fmt = cell.fmt || emptyFmt();
    return this.formatValue(v, fmt.numfmt);
  };

  Model.prototype.formatValue = function (v, numfmt) {
    if (v === '' || v == null) return '';
    if (typeof v === 'boolean') return v ? 'TRUE' : 'FALSE';
    if (typeof v !== 'number') return String(v);

    switch (numfmt) {
      case 'currency':
        return (v < 0 ? '-$' : '$') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      case 'percent':
        return (v * 100).toLocaleString('en-US', { maximumFractionDigits: 2 }) + '%';
      case 'comma':
        return v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
      case 'integer':
        return Math.round(v).toLocaleString('en-US');
      case 'date': {
        const d = F._internals.serialToDate(v);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
      }
      default:
        return F.numToStr(Math.round(v * 1e10) / 1e10);
    }
  };

  /* ── selection statistics (status bar) ────────────────── */
  Model.prototype.stats = function (rect) {
    let sum = 0, count = 0, numCount = 0, min = Infinity, max = -Infinity, filled = 0;
    for (let r = rect.r0; r <= rect.r1; r++) {
      for (let c = rect.c0; c <= rect.c1; c++) {
        const cell = this.cells[F.cellId(r, c)];
        if (!cell || cell.raw === '') continue;
        filled++;
        let v = this.getValue(r, c);
        if (typeof v === 'number' && isFinite(v)) {
          sum += v; numCount++;
          if (v < min) min = v;
          if (v > max) max = v;
        }
        count++;
      }
    }
    return {
      sum, count, numCount, filled,
      avg: numCount ? sum / numCount : 0,
      min: numCount ? min : 0,
      max: numCount ? max : 0,
    };
  };

  /* ── clearing / new ───────────────────────────────────── */
  Model.prototype.clearAll = function () {
    this.cells = Object.create(null);
    this.colWidths = Object.create(null);
    this.rowHeights = Object.create(null);
    this._compiled = Object.create(null);
    this._dependents = Object.create(null);
    this.name = 'Untitled sheet';
    this.recalc();
    this.persist();
    this.emit('reset', {});
  };

  Model.prototype.load = function (data) {
    this.cells = Object.create(null);
    this._compiled = Object.create(null);
    this._dependents = Object.create(null);
    this.colWidths = data.colWidths || Object.create(null);
    this.rowHeights = data.rowHeights || Object.create(null);
    this.name = data.name || 'Untitled sheet';
    for (const id in (data.cells || {})) {
      const cd = data.cells[id];
      const raw = typeof cd === 'string' ? cd : cd.raw;
      const fmt = (typeof cd === 'object' && cd.fmt) ? Object.assign(emptyFmt(), cd.fmt) : emptyFmt();
      if (raw !== '' && raw != null) this.cells[id] = { raw: String(raw), fmt };
    }
    for (const id in this.cells) this._compileCell(id);
    this.recalc();
    this.emit('reset', {});
  };

  Model.prototype.serialize = function () {
    const cells = {};
    for (const id in this.cells) {
      const cell = this.cells[id];
      cells[id] = { raw: cell.raw, fmt: cell.fmt };
    }
    return { name: this.name, cells, colWidths: this.colWidths, rowHeights: this.rowHeights, v: 1 };
  };

  /* ── persistence ──────────────────────────────────────── */
  const STORE_KEY = 'lattice.sheet.v1';
  Model.prototype.persist = function () {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(this.serialize())); } catch (e) {}
  };
  Model.prototype.restore = function () {
    try {
      const raw = localStorage.getItem(STORE_KEY);
      if (!raw) return false;
      this.load(JSON.parse(raw));
      return true;
    } catch (e) { return false; }
  };

  /* ── CSV / JSON import-export ──────────────────────────── */
  Model.prototype.toCSV = function () {
    let maxR = 0, maxC = 0;
    for (const id in this.cells) {
      const r = F.parseRef(id);
      if (r) { if (r.row > maxR) maxR = r.row; if (r.col > maxC) maxC = r.col; }
    }
    const lines = [];
    for (let r = 0; r <= maxR; r++) {
      const row = [];
      for (let c = 0; c <= maxC; c++) {
        // export the *displayed* value for computed cells, raw for inputs
        const cell = this.cells[F.cellId(r, c)];
        let out = '';
        if (cell) out = cell.raw[0] === '=' ? String(this.getValue(r, c)) : cell.raw;
        row.push(csvEscape(out));
      }
      lines.push(row.join(','));
    }
    return lines.join('\n');
  };
  function csvEscape(s) {
    s = String(s);
    if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }
  Model.prototype.fromCSV = function (text) {
    const rows = parseCSV(text);
    this.clearAll();
    const entries = [];
    rows.forEach((row, r) => row.forEach((val, c) => {
      if (val !== '') entries.push({ r, c, raw: val });
    }));
    this.name = 'Imported CSV';
    if (entries.length) this.setMany(entries);
    else this.emit('reset', {});
  };
  function parseCSV(text) {
    const rows = [];
    let row = [], field = '', i = 0, inQ = false;
    const n = text.length;
    while (i < n) {
      const c = text[i];
      if (inQ) {
        if (c === '"') {
          if (text[i + 1] === '"') { field += '"'; i += 2; continue; }
          inQ = false; i++; continue;
        }
        field += c; i++; continue;
      }
      if (c === '"') { inQ = true; i++; continue; }
      if (c === ',') { row.push(field); field = ''; i++; continue; }
      if (c === '\n' || c === '\r') {
        if (c === '\r' && text[i + 1] === '\n') i++;
        row.push(field); rows.push(row); row = []; field = ''; i++; continue;
      }
      field += c; i++;
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows;
  }

  window.Lattice.Model = Model;
  window.Lattice.ROWS = ROWS;
  window.Lattice.COLS = COLS;
})();
