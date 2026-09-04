/* =========================================================
   LATTICE — Grid View
   Renders the cell grid (capped 50×26 -> 1300 cells, well
   within DOM budget) with a frozen header row + column, cell
   selection (single + range), inline editing, keyboard
   navigation, copy/paste with relative-ref adjustment, and a
   formula bar.

   window.Lattice.Grid
   ========================================================= */
(function () {
  'use strict';

  const F = window.Lattice.Formula;

  function Grid(model, opts) {
    this.model = model;
    this.root = opts.root;
    this.formulaBar = opts.formulaInput;
    this.cellAddr = opts.cellAddr;
    this.onSelectionChange = opts.onSelectionChange || function () {};
    this.onEditState = opts.onEditState || function () {};

    this.sel = { r: 0, c: 0 };          // active cell
    this.anchor = { r: 0, c: 0 };       // range anchor
    this.editing = false;
    this.clipboard = null;              // {rect, cells:[[{raw,fmt}]]}
    this.cellEls = Object.create(null); // id -> td element

    this._build();
    this._bindEvents();
    model.on('change', () => this.refreshAll());
    model.on('recalc', () => this.refreshAll());
    model.on('reset', () => { this.sel = { r: 0, c: 0 }; this.anchor = { r: 0, c: 0 }; this.refreshAll(); this._syncFormulaBar(); this._emitSel(); });
  }

  /* ── build the table DOM once ─────────────────────────── */
  Grid.prototype._build = function () {
    const m = this.model;
    const table = document.createElement('table');
    table.className = 'grid-table';
    table.setAttribute('role', 'grid');
    table.setAttribute('aria-label', 'Spreadsheet grid');

    // header row
    const thead = document.createElement('thead');
    const htr = document.createElement('tr');
    const corner = document.createElement('th');
    corner.className = 'gh corner';
    corner.setAttribute('aria-hidden', 'true');
    htr.appendChild(corner);
    for (let c = 0; c < m.cols; c++) {
      const th = document.createElement('th');
      th.className = 'gh col-head';
      th.dataset.c = c;
      th.scope = 'col';
      th.textContent = F.colToLetters(c);
      const res = document.createElement('span');
      res.className = 'col-resizer';
      res.dataset.c = c;
      th.appendChild(res);
      if (m.colWidths[c]) th.style.width = m.colWidths[c] + 'px';
      htr.appendChild(th);
    }
    thead.appendChild(htr);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    for (let r = 0; r < m.rows; r++) {
      const tr = document.createElement('tr');
      const rh = document.createElement('th');
      rh.className = 'gh row-head';
      rh.dataset.r = r;
      rh.scope = 'row';
      rh.textContent = r + 1;
      tr.appendChild(rh);
      for (let c = 0; c < m.cols; c++) {
        const td = document.createElement('td');
        td.className = 'cell';
        td.dataset.r = r;
        td.dataset.c = c;
        td.tabIndex = -1;
        if (m.colWidths[c]) td.style.minWidth = m.colWidths[c] + 'px';
        tr.appendChild(td);
        this.cellEls[F.cellId(r, c)] = td;
      }
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    this.root.appendChild(table);
    this.table = table;

    this.refreshAll();
    this._highlightSelection();
    this._syncFormulaBar();
  };

  /* ── render a single cell's content + format ──────────── */
  Grid.prototype.refreshCell = function (r, c) {
    const td = this.cellEls[F.cellId(r, c)];
    if (!td) return;
    const m = this.model;
    const disp = m.display(r, c);
    const fmt = m.getFmt(r, c);
    td.textContent = disp;
    td.classList.toggle('bold', !!fmt.bold);
    td.classList.toggle('italic', !!fmt.italic);
    td.classList.remove('al-left', 'al-center', 'al-right', 'cell-error', 'cell-num');
    const v = m.getValue(r, c);
    const isNum = typeof v === 'number';
    if (fmt.align) td.classList.add('al-' + fmt.align);
    else if (isNum) td.classList.add('al-right');     // numbers right-align by default
    if (m.isError(disp)) td.classList.add('cell-error');
    if (isNum) td.classList.add('cell-num');
    td.title = m.isError(disp) ? errorHelp(disp) : '';
  };

  function errorHelp(code) {
    const map = {
      '#DIV/0!': 'Division by zero',
      '#NAME?':  'Unknown function or name',
      '#VALUE!': 'Wrong type of value',
      '#REF!':   'Invalid cell reference',
      '#NUM!':   'Invalid number',
      '#CIRC!':  'Circular reference — a cell refers back to itself',
      '#ERROR!': 'Could not evaluate',
      '#PARSE!': 'Formula syntax error',
      '#N/A':    'Value not available',
    };
    return map[code] || 'Error';
  }

  Grid.prototype.refreshAll = function () {
    const m = this.model;
    for (let r = 0; r < m.rows; r++)
      for (let c = 0; c < m.cols; c++)
        this.refreshCell(r, c);
    this._highlightSelection();
  };

  /* ── selection ────────────────────────────────────────── */
  Grid.prototype.rect = function () {
    return {
      r0: Math.min(this.sel.r, this.anchor.r),
      r1: Math.max(this.sel.r, this.anchor.r),
      c0: Math.min(this.sel.c, this.anchor.c),
      c1: Math.max(this.sel.c, this.anchor.c),
    };
  };

  Grid.prototype._highlightSelection = function () {
    this.root.querySelectorAll('.cell.sel, .cell.active, .gh.hl')
      .forEach(el => el.classList.remove('sel', 'active', 'hl'));
    const rc = this.rect();
    for (let r = rc.r0; r <= rc.r1; r++)
      for (let c = rc.c0; c <= rc.c1; c++) {
        const td = this.cellEls[F.cellId(r, c)];
        if (td) td.classList.add('sel');
      }
    const active = this.cellEls[F.cellId(this.sel.r, this.sel.c)];
    if (active) active.classList.add('active');
    // highlight headers
    const colH = this.table.querySelector(`.col-head[data-c="${this.sel.c}"]`);
    const rowH = this.table.querySelector(`.row-head[data-r="${this.sel.r}"]`);
    if (colH) colH.classList.add('hl');
    if (rowH) rowH.classList.add('hl');
  };

  Grid.prototype.select = function (r, c, extend) {
    r = Math.max(0, Math.min(this.model.rows - 1, r));
    c = Math.max(0, Math.min(this.model.cols - 1, c));
    this.sel = { r, c };
    if (!extend) this.anchor = { r, c };
    this._highlightSelection();
    this._syncFormulaBar();
    this._scrollIntoView();
    this._emitSel();
  };

  Grid.prototype._emitSel = function () {
    this.onSelectionChange(this.rect(), this.sel);
  };

  Grid.prototype._scrollIntoView = function () {
    const td = this.cellEls[F.cellId(this.sel.r, this.sel.c)];
    if (!td) return;
    const wrap = this.root;
    const r = td.getBoundingClientRect();
    const w = wrap.getBoundingClientRect();
    const headH = 34, headW = 48;
    if (r.bottom > w.bottom) wrap.scrollTop += r.bottom - w.bottom + 4;
    if (r.top < w.top + headH) wrap.scrollTop -= (w.top + headH - r.top) + 4;
    if (r.right > w.right) wrap.scrollLeft += r.right - w.right + 4;
    if (r.left < w.left + headW) wrap.scrollLeft -= (w.left + headW - r.left) + 4;
  };

  /* ── formula bar sync ─────────────────────────────────── */
  Grid.prototype._syncFormulaBar = function () {
    if (this.cellAddr) this.cellAddr.textContent = F.cellId(this.sel.r, this.sel.c);
    if (this.formulaBar && !this.editing)
      this.formulaBar.value = this.model.getRaw(this.sel.r, this.sel.c);
  };

  /* ── editing ──────────────────────────────────────────── */
  Grid.prototype.beginEdit = function (initial) {
    if (this.editing) return;
    const td = this.cellEls[F.cellId(this.sel.r, this.sel.c)];
    if (!td) return;
    this.editing = true;
    this.onEditState(true);
    const raw = initial != null ? initial : this.model.getRaw(this.sel.r, this.sel.c);
    const input = document.createElement('input');
    input.className = 'cell-editor';
    input.value = raw;
    input.spellcheck = false;
    td.textContent = '';
    td.classList.add('editing');
    td.appendChild(input);
    input.focus();
    if (initial != null) input.setSelectionRange(raw.length, raw.length);
    else input.select();
    this._editor = input;
    if (this.formulaBar) this.formulaBar.value = raw;

    input.addEventListener('input', () => { if (this.formulaBar) this.formulaBar.value = input.value; });
    input.addEventListener('keydown', e => this._editorKey(e));
    input.addEventListener('blur', () => { if (this.editing) this.commitEdit(0, 0); });
  };

  Grid.prototype._editorKey = function (e) {
    if (e.key === 'Enter')      { e.preventDefault(); this.commitEdit(e.shiftKey ? -1 : 1, 0); }
    else if (e.key === 'Tab')   { e.preventDefault(); this.commitEdit(0, e.shiftKey ? -1 : 1); }
    else if (e.key === 'Escape'){ e.preventDefault(); this.cancelEdit(); }
    e.stopPropagation();
  };

  Grid.prototype.commitEdit = function (dr, dc) {
    if (!this.editing) return;
    const val = this._editor.value;
    const r = this.sel.r, c = this.sel.c;
    this.editing = false;
    this._editor = null;
    this.onEditState(false);
    const td = this.cellEls[F.cellId(r, c)];
    td.classList.remove('editing');
    this.model.setRaw(r, c, val);   // triggers refresh
    if (dr || dc) this.select(r + dr, c + dc, false);
    else { this._highlightSelection(); this._syncFormulaBar(); }
    this._emitSel();
  };

  Grid.prototype.cancelEdit = function () {
    if (!this.editing) return;
    const r = this.sel.r, c = this.sel.c;
    this.editing = false;
    this._editor = null;
    this.onEditState(false);
    const td = this.cellEls[F.cellId(r, c)];
    td.classList.remove('editing');
    this.refreshCell(r, c);
    this._highlightSelection();
    this._syncFormulaBar();
  };

  // commit from the external formula bar
  Grid.prototype.commitFormulaBar = function (dr) {
    const val = this.formulaBar.value;
    this.model.setRaw(this.sel.r, this.sel.c, val);
    if (dr) this.select(this.sel.r + dr, this.sel.c, false);
    else { this.refreshAll(); this._emitSel(); }
  };

  /* ── clipboard ────────────────────────────────────────── */
  Grid.prototype.copy = function () {
    const rc = this.rect();
    const cells = [];
    for (let r = rc.r0; r <= rc.r1; r++) {
      const row = [];
      for (let c = rc.c0; c <= rc.c1; c++)
        row.push({ raw: this.model.getRaw(r, c), fmt: Object.assign({}, this.model.getFmt(r, c)) });
      cells.push(row);
    }
    this.clipboard = { rect: rc, cells, origin: { r: rc.r0, c: rc.c0 } };
    this._flashClipboard();
    // also try the system clipboard with TSV of displayed values
    try {
      const tsv = cells.map((row, ri) =>
        row.map((cell, ci) => {
          const rr = rc.r0 + ri, cc = rc.c0 + ci;
          return cell.raw[0] === '=' ? String(this.model.getValue(rr, cc)) : cell.raw;
        }).join('\t')).join('\n');
      navigator.clipboard && navigator.clipboard.writeText(tsv).catch(() => {});
    } catch (e) {}
  };

  Grid.prototype.cut = function () {
    this.copy();
    const rc = this.rect();
    const entries = [];
    for (let r = rc.r0; r <= rc.r1; r++)
      for (let c = rc.c0; c <= rc.c1; c++)
        entries.push({ r, c, raw: '' });
    this.model.setMany(entries);
    this._cutPending = true;
  };

  Grid.prototype.paste = function () {
    if (!this.clipboard) return;
    const cb = this.clipboard;
    const baseR = this.sel.r, baseC = this.sel.c;
    const entries = [];
    cb.cells.forEach((row, ri) => row.forEach((cell, ci) => {
      const r = baseR + ri, c = baseC + ci;
      if (r >= this.model.rows || c >= this.model.cols) return;
      let raw = cell.raw;
      if (raw && raw[0] === '=') {
        const dr = baseR - cb.origin.r;
        const dc = baseC - cb.origin.c;
        raw = '=' + F.adjustFormula(raw.slice(1), dr, dc);
      }
      entries.push({ r, c, raw, fmt: Object.assign({}, cell.fmt) });
    }));
    this.model.setMany(entries);
    // select the pasted block
    const h = cb.cells.length - 1, w = cb.cells[0].length - 1;
    this.anchor = { r: baseR, c: baseC };
    this.select(Math.min(baseR + h, this.model.rows - 1), Math.min(baseC + w, this.model.cols - 1), true);
    this._clearClipboardFlash();
  };

  // paste plain text (e.g. from system clipboard) as TSV/CSV
  Grid.prototype.pasteText = function (text) {
    const rows = text.indexOf('\t') !== -1
      ? text.replace(/\r/g, '').split('\n').map(l => l.split('\t'))
      : text.replace(/\r/g, '').split('\n').map(l => l.split(','));
    const entries = [];
    rows.forEach((row, ri) => row.forEach((val, ci) => {
      const r = this.sel.r + ri, c = this.sel.c + ci;
      if (r < this.model.rows && c < this.model.cols && val !== '')
        entries.push({ r, c, raw: val });
    }));
    if (entries.length) this.model.setMany(entries);
  };

  Grid.prototype._flashClipboard = function () {
    this._clearClipboardFlash();
    const rc = this.rect();
    for (let r = rc.r0; r <= rc.r1; r++)
      for (let c = rc.c0; c <= rc.c1; c++) {
        const td = this.cellEls[F.cellId(r, c)];
        if (td) td.classList.add('copied');
      }
  };
  Grid.prototype._clearClipboardFlash = function () {
    this.root.querySelectorAll('.cell.copied').forEach(el => el.classList.remove('copied'));
  };

  Grid.prototype.clearSelection = function () {
    const rc = this.rect();
    const entries = [];
    for (let r = rc.r0; r <= rc.r1; r++)
      for (let c = rc.c0; c <= rc.c1; c++)
        entries.push({ r, c, raw: '' });
    this.model.setMany(entries);
    this._emitSel();
  };

  /* ── apply a format to the current selection ──────────── */
  Grid.prototype.applyFmt = function (patch) {
    const rc = this.rect();
    const list = [];
    for (let r = rc.r0; r <= rc.r1; r++)
      for (let c = rc.c0; c <= rc.c1; c++)
        list.push([r, c]);
    // toggle behaviour for booleans: if all already set, unset
    if ('bold' in patch || 'italic' in patch) {
      const key = 'bold' in patch ? 'bold' : 'italic';
      const allSet = list.every(([r, c]) => this.model.getFmt(r, c)[key]);
      patch[key] = !allSet;
    }
    this.model.setFmt(list, patch);
  };

  Grid.prototype.currentFmt = function () {
    return this.model.getFmt(this.sel.r, this.sel.c);
  };

  /* ── events ───────────────────────────────────────────── */
  Grid.prototype._bindEvents = function () {
    const self = this;
    let dragging = false;

    this.table.addEventListener('mousedown', e => {
      const res = e.target.closest('.col-resizer');
      if (res) { self._startResize(e, parseInt(res.dataset.c, 10)); e.preventDefault(); return; }
      const td = e.target.closest('td.cell');
      if (td) {
        if (self.editing) self.commitEdit(0, 0);
        const r = +td.dataset.r, c = +td.dataset.c;
        self.select(r, c, e.shiftKey);
        dragging = true;
        e.preventDefault();
        return;
      }
      // header click selects whole col/row
      const ch = e.target.closest('.col-head');
      if (ch) { const c = +ch.dataset.c; self.anchor = { r: 0, c }; self.select(self.model.rows - 1, c, true); self.sel = { r: 0, c }; self._highlightSelection(); self._emitSel(); return; }
      const rh = e.target.closest('.row-head');
      if (rh) { const r = +rh.dataset.r; self.anchor = { r, c: 0 }; self.select(r, self.model.cols - 1, true); self.sel = { r, c: 0 }; self._highlightSelection(); self._emitSel(); return; }
    });

    this.table.addEventListener('mousemove', e => {
      if (!dragging) return;
      const td = e.target.closest('td.cell');
      if (td) self.select(+td.dataset.r, +td.dataset.c, true);
    });
    window.addEventListener('mouseup', () => { dragging = false; });

    this.table.addEventListener('dblclick', e => {
      const td = e.target.closest('td.cell');
      if (td) { self.select(+td.dataset.r, +td.dataset.c, false); self.beginEdit(); }
    });

    // keyboard on the grid container
    this.root.tabIndex = 0;
    this.root.addEventListener('keydown', e => self._gridKey(e));

    // formula bar
    if (this.formulaBar) {
      this.formulaBar.addEventListener('focus', () => { self.editing = false; });
      this.formulaBar.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); self.commitFormulaBar(1); self.root.focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); self._syncFormulaBar(); self.root.focus(); }
      });
    }
  };

  Grid.prototype._gridKey = function (e) {
    if (this.editing) return;
    const k = e.key;
    const mod = e.ctrlKey || e.metaKey;

    if (mod) {
      if (k === 'c' || k === 'C') { e.preventDefault(); this.copy(); return; }
      if (k === 'x' || k === 'X') { e.preventDefault(); this.cut(); return; }
      if (k === 'v' || k === 'V') { e.preventDefault(); if (this.clipboard) this.paste(); return; }
      if (k === 'b' || k === 'B') { e.preventDefault(); this.applyFmt({ bold: true }); return; }
      if (k === 'i' || k === 'I') { e.preventDefault(); this.applyFmt({ italic: true }); return; }
      if (k === 'a' || k === 'A') { e.preventDefault(); this.anchor = { r: 0, c: 0 }; this.sel = { r: this.model.rows - 1, c: this.model.cols - 1 }; this._highlightSelection(); this.sel = { r: 0, c: 0 }; this._highlightSelection(); this._emitSel(); return; }
      // jump to edges
      if (k === 'ArrowDown')  { e.preventDefault(); this.select(this.model.rows - 1, this.sel.c, e.shiftKey); return; }
      if (k === 'ArrowUp')    { e.preventDefault(); this.select(0, this.sel.c, e.shiftKey); return; }
      if (k === 'ArrowRight') { e.preventDefault(); this.select(this.sel.r, this.model.cols - 1, e.shiftKey); return; }
      if (k === 'ArrowLeft')  { e.preventDefault(); this.select(this.sel.r, 0, e.shiftKey); return; }
      return;
    }

    switch (k) {
      case 'ArrowUp':    e.preventDefault(); this.select(this.sel.r - 1, this.sel.c, e.shiftKey); break;
      case 'ArrowDown':  e.preventDefault(); this.select(this.sel.r + 1, this.sel.c, e.shiftKey); break;
      case 'ArrowLeft':  e.preventDefault(); this.select(this.sel.r, this.sel.c - 1, e.shiftKey); break;
      case 'ArrowRight': e.preventDefault(); this.select(this.sel.r, this.sel.c + 1, e.shiftKey); break;
      case 'Tab':        e.preventDefault(); this.select(this.sel.r, this.sel.c + (e.shiftKey ? -1 : 1), false); break;
      case 'Enter':      e.preventDefault(); this.beginEdit(); break;
      case 'F2':         e.preventDefault(); this.beginEdit(); break;
      case 'Backspace':
      case 'Delete':     e.preventDefault(); this.clearSelection(); break;
      case 'Home':       e.preventDefault(); this.select(this.sel.r, 0, e.shiftKey); break;
      case 'End':        e.preventDefault(); this.select(this.sel.r, this.model.cols - 1, e.shiftKey); break;
      case 'PageDown':   e.preventDefault(); this.select(this.sel.r + 10, this.sel.c, e.shiftKey); break;
      case 'PageUp':     e.preventDefault(); this.select(this.sel.r - 10, this.sel.c, e.shiftKey); break;
      case 'Escape':     this._clearClipboardFlash(); break;
      default:
        // printable char -> start editing
        if (k.length === 1 && !e.altKey) { this.beginEdit(k); }
    }
  };

  /* ── column resize ────────────────────────────────────── */
  Grid.prototype._startResize = function (e, col) {
    const self = this;
    const th = this.table.querySelector(`.col-head[data-c="${col}"]`);
    const startX = e.clientX;
    const startW = th.getBoundingClientRect().width;
    document.body.classList.add('resizing-col');
    function move(ev) {
      const w = Math.max(48, startW + (ev.clientX - startX));
      th.style.width = w + 'px';
      self.model.colWidths[col] = Math.round(w);
      // sync cell min-widths in this column
      for (let r = 0; r < self.model.rows; r++) {
        const td = self.cellEls[F.cellId(r, col)];
        if (td) td.style.minWidth = w + 'px';
      }
    }
    function up() {
      document.removeEventListener('mousemove', move);
      document.removeEventListener('mouseup', up);
      document.body.classList.remove('resizing-col');
      self.model.persist();
    }
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
  };

  window.Lattice.Grid = Grid;
})();
