/* =========================================================
   LATTICE — App controller (index.html)
   Wires the model + grid to the toolbar, formula bar, status
   bar, file menu (new / templates / import / export) and a
   little toast system. Boots from localStorage or a seed sheet.

   window.Lattice.App
   ========================================================= */
(function () {
  'use strict';

  const L = window.Lattice;
  const F = L.Formula;

  function App() {
    this.model = new L.Model();
    // boot: restore saved sheet, else seed with the budget template
    if (!this.model.restore()) {
      const tpl = L.Templates.defaultSheet();
      this.model.name = tpl.name;
      this.model.setMany(tpl.cells);
    }

    this.grid = new L.Grid(this.model, {
      root: document.getElementById('gridScroll'),
      formulaInput: document.getElementById('formulaInput'),
      cellAddr: document.getElementById('cellAddr'),
      onSelectionChange: (rect, sel) => this._onSelection(rect, sel),
      onEditState: (editing) => { document.body.classList.toggle('editing', editing); },
    });

    this._initToolbar();
    this._initFileMenu();
    this._initNameField();
    this._onSelection(this.grid.rect(), this.grid.sel);
    this.grid.root.focus();
    this._toast('Loaded "' + this.model.name + '" · autosaving to your browser', 2600);
  }

  /* ── status bar reflects the live selection ───────────── */
  App.prototype._onSelection = function (rect, sel) {
    const s = this.model.stats(rect);
    const fmt = n => this.model.formatValue(n, 'comma');
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

    const single = rect.r0 === rect.r1 && rect.c0 === rect.c1;
    set('sbRange', single ? F.cellId(sel.r, sel.c) : (F.cellId(rect.r0, rect.c0) + ':' + F.cellId(rect.r1, rect.c1) + ' (' + ((rect.r1 - rect.r0 + 1) * (rect.c1 - rect.c0 + 1)) + ' cells)'));
    set('sbSum',   s.numCount ? fmt(s.sum) : '—');
    set('sbAvg',   s.numCount ? fmt(Math.round(s.avg * 1000) / 1000) : '—');
    set('sbCount', s.filled);
    set('sbMin',   s.numCount ? fmt(s.min) : '—');
    set('sbMax',   s.numCount ? fmt(s.max) : '—');

    // reflect format state into toolbar toggles
    const f = this.model.getFmt(sel.r, sel.c);
    this._setToggle('btnBold', f.bold);
    this._setToggle('btnItalic', f.italic);
    document.querySelectorAll('.align-btn').forEach(b => b.classList.toggle('on', b.dataset.align === f.align));
    const numSel = document.getElementById('numFmtSelect');
    if (numSel) numSel.value = f.numfmt || 'auto';

    // raw-formula preview chip
    const raw = this.model.getRaw(sel.r, sel.c);
    const chip = document.getElementById('cellKind');
    if (chip) {
      if (raw === '') chip.textContent = 'empty';
      else if (raw[0] === '=') chip.textContent = 'formula';
      else if (!isNaN(Number(raw)) && raw.trim() !== '') chip.textContent = 'number';
      else chip.textContent = 'text';
    }
  };

  App.prototype._setToggle = function (id, on) {
    const b = document.getElementById(id);
    if (b) b.classList.toggle('on', !!on);
  };

  /* ── toolbar ──────────────────────────────────────────── */
  App.prototype._initToolbar = function () {
    const g = this.grid;
    const click = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };

    click('btnBold',   () => { g.applyFmt({ bold: true }); g.root.focus(); });
    click('btnItalic', () => { g.applyFmt({ italic: true }); g.root.focus(); });
    document.querySelectorAll('.align-btn').forEach(b =>
      b.addEventListener('click', () => { g.applyFmt({ align: b.dataset.align }); g.root.focus(); }));

    const numSel = document.getElementById('numFmtSelect');
    if (numSel) numSel.addEventListener('change', () => { g.applyFmt({ numfmt: numSel.value }); g.root.focus(); });

    // quick-format shortcut buttons
    click('btnCurrency', () => { g.applyFmt({ numfmt: 'currency' }); g.root.focus(); });
    click('btnPercent',  () => { g.applyFmt({ numfmt: 'percent' }); g.root.focus(); });

    click('btnCopy',  () => { g.copy();  g.root.focus(); });
    click('btnCut',   () => { g.cut();   g.root.focus(); });
    click('btnPaste', () => { g.paste(); g.root.focus(); });
    click('btnClear', () => { g.clearSelection(); g.root.focus(); });

    // formula bar fx insert helpers
    document.querySelectorAll('.fx-chip').forEach(chip =>
      chip.addEventListener('click', () => {
        const input = document.getElementById('formulaInput');
        const tpl = chip.dataset.fx;
        input.value = tpl;
        input.focus();
        const paren = tpl.indexOf('(');
        if (paren !== -1) input.setSelectionRange(paren + 1, paren + 1);
      }));

    // paste from system clipboard (best-effort)
    document.addEventListener('paste', e => {
      if (g.editing) return;
      const text = (e.clipboardData || window.clipboardData).getData('text');
      if (text && !g.clipboard) { g.pasteText(text); e.preventDefault(); }
    });
  };

  /* ── file menu: new / templates / import / export ─────── */
  App.prototype._initFileMenu = function () {
    const self = this;
    const click = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };

    // dropdown toggling
    document.querySelectorAll('.menu').forEach(menu => {
      const btn = menu.querySelector('.menu-btn');
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const open = menu.classList.contains('open');
        document.querySelectorAll('.menu.open').forEach(m => m.classList.remove('open'));
        if (!open) menu.classList.add('open');
      });
    });
    document.addEventListener('click', () => document.querySelectorAll('.menu.open').forEach(m => m.classList.remove('open')));

    click('miNew', () => {
      if (confirm('Start a new, empty sheet? Your current sheet is autosaved but will be replaced.')) {
        self.model.clearAll();
        self._toast('New blank sheet', 1800);
      }
    });

    // template quick-load entries inside the menu
    document.querySelectorAll('[data-load-tpl]').forEach(item =>
      item.addEventListener('click', () => {
        const tpl = L.Templates.byId(item.dataset.loadTpl);
        self.model.clearAll();
        self.model.name = tpl.name;
        self.model.setMany(tpl.cells);
        self._syncName();
        self._toast('Loaded template: ' + tpl.name, 2200);
      }));

    click('miExportCsv',  () => self._download(self.model.toCSV(), self._fname('csv'), 'text/csv'));
    click('miExportJson', () => self._download(JSON.stringify(self.model.serialize(), null, 2), self._fname('json'), 'application/json'));

    const fileInput = document.getElementById('fileInput');
    click('miImportCsv',  () => { fileInput.dataset.kind = 'csv';  fileInput.accept = '.csv,text/csv'; fileInput.click(); });
    click('miImportJson', () => { fileInput.dataset.kind = 'json'; fileInput.accept = '.json,application/json'; fileInput.click(); });
    if (fileInput) fileInput.addEventListener('change', e => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        try {
          if (fileInput.dataset.kind === 'json') {
            self.model.load(JSON.parse(reader.result));
            self._toast('Imported JSON sheet', 2000);
          } else {
            self.model.fromCSV(reader.result);
            self._toast('Imported CSV (' + file.name + ')', 2000);
          }
          self._syncName();
        } catch (err) {
          self._toast('Could not read that file', 2400);
        }
        fileInput.value = '';
      };
      reader.readAsText(file);
    });

    // recalc button
    click('miRecalc', () => { self.model.recalc(); self._toast('Recalculated', 1200); });
  };

  App.prototype._fname = function (ext) {
    return this.model.name.replace(/[^\w\- ]+/g, '').replace(/\s+/g, '-').toLowerCase() + '.' + ext;
  };

  App.prototype._download = function (text, name, mime) {
    const blob = new Blob([text], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    this._toast('Exported ' + name, 2000);
  };

  /* ── editable sheet name ──────────────────────────────── */
  App.prototype._initNameField = function () {
    const el = document.getElementById('sheetName');
    if (!el) return;
    this._syncName();
    el.addEventListener('input', () => { this.model.name = el.textContent.trim() || 'Untitled sheet'; this.model.persist(); });
    el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
  };
  App.prototype._syncName = function () {
    const el = document.getElementById('sheetName');
    if (el) el.textContent = this.model.name;
  };

  /* ── toast ────────────────────────────────────────────── */
  App.prototype._toast = function (msg, ms) {
    let t = document.getElementById('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(this._toastTimer);
    this._toastTimer = setTimeout(() => t.classList.remove('show'), ms || 2000);
  };

  document.addEventListener('DOMContentLoaded', () => { window.Lattice.App = new App(); });
})();
