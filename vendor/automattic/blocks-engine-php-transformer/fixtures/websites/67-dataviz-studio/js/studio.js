/* =========================================================
   PLOTWEAVER — Studio controller
   Wires the data editor, config panel, chart renderer, exports,
   localStorage persistence and URL-hash sharing together.
   ========================================================= */
(function () {
  'use strict';
  const PW = window.PW;
  const Data = PW.Data;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const STORE_KEY = 'plotweaver.state.v1';

  /* ---------- application state ---------- */
  let ds = null;            // current dataset {columns, rows}
  let cfg = null;           // current chart config
  let chart = null;

  const canvas = $('#chart');
  const tooltip = $('#chartTooltip');

  /* ================================================================
     STATE INIT — from hash, then localStorage, else first dataset
     ================================================================ */
  function init() {
    chart = new PW.Chart(canvas, tooltip);

    const fromHash = readHash();
    const stored = readStore();
    if (fromHash) { ds = fromHash.ds; cfg = fromHash.cfg; }
    else if (stored) { ds = stored.ds; cfg = stored.cfg; }
    else { loadDataset('quarterly_revenue', /*silent*/true); }

    if (ds && !cfg) cfg = PW.defaultConfig(ds);

    buildDatasetMenu();
    renderGrid();
    buildConfigPanel();
    syncConfigInputs();
    update();
    bindGlobal();
    window.addEventListener('resize', debounce(() => chart.redraw(), 120));
  }

  /* ================================================================
     DATASET HANDLING
     ================================================================ */
  function loadDataset(key, silent) {
    const d = PW.DATASETS[key];
    if (!d) return;
    ds = Data.parseCSV(d.csv);
    cfg = PW.defaultConfig(ds);
    if (!silent) { renderGrid(); buildConfigPanel(); syncConfigInputs(); update(); toast('Loaded “' + d.label + '”'); }
  }

  function buildDatasetMenu() {
    const sel = $('#datasetSelect');
    sel.innerHTML = '<option value="">— built-in datasets —</option>' +
      Object.entries(PW.DATASETS).map(([k, d]) => `<option value="${k}">${d.label}</option>`).join('');
    sel.onchange = () => { if (sel.value) { loadDataset(sel.value); sel.value = ''; } };
  }

  /* ================================================================
     EDITABLE GRID
     ================================================================ */
  function renderGrid() {
    const wrap = $('#gridWrap');
    if (!ds || !ds.columns.length) { wrap.innerHTML = '<div class="empty-note">No data. Load a dataset or paste CSV below.</div>'; return; }
    let html = '<table class="dtable"><thead><tr><th class="rownum"></th>';
    ds.columns.forEach((c, ci) => {
      html += `<th><div class="col-head">
        <span class="col-type ${c.type}" data-ci="${ci}" title="Click to cycle type">${c.type}</span>
        <input class="col-name" data-ci="${ci}" value="${escAttr(c.name)}" aria-label="Column name">
        <button class="col-del" data-ci="${ci}" aria-label="Delete column" title="Delete column">×</button>
      </div></th>`;
    });
    html += '</tr></thead><tbody>';
    ds.rows.forEach((r, ri) => {
      html += `<tr><td class="rownum"><span class="rn">${ri + 1}</span><button class="row-del" data-ri="${ri}" aria-label="Delete row" title="Delete row">×</button></td>`;
      ds.columns.forEach((c, ci) => {
        html += `<td class="${c.type === 'num' ? 'num' : ''}"><input data-ri="${ri}" data-ci="${ci}" value="${escAttr(r[ci] != null ? r[ci] : '')}" aria-label="Cell ${ri + 1},${ci + 1}"></td>`;
      });
      html += '</tr>';
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
    bindGrid(wrap);
  }

  function bindGrid(wrap) {
    // cell edits
    wrap.addEventListener('input', (e) => {
      const t = e.target;
      if (t.classList.contains('col-name')) {
        const ci = +t.dataset.ci; const old = ds.columns[ci].name;
        ds.columns[ci].name = t.value;
        // keep config column references in sync
        renameInConfig(old, t.value);
        persistAndRender();
      } else if (t.dataset.ri != null) {
        ds.rows[+t.dataset.ri][+t.dataset.ci] = t.value;
        scheduleUpdate();
      }
    });
    // type cycle
    wrap.addEventListener('click', (e) => {
      const tp = e.target.closest('.col-type');
      if (tp) { const ci = +tp.dataset.ci; const order = ['str', 'num', 'date']; const cur = ds.columns[ci].type; ds.columns[ci].type = order[(order.indexOf(cur) + 1) % 3]; renderGrid(); persistAndRender(); return; }
      const cd = e.target.closest('.col-del');
      if (cd) { delColumn(+cd.dataset.ci); return; }
      const rd = e.target.closest('.row-del');
      if (rd) { ds.rows.splice(+rd.dataset.ri, 1); renderGrid(); persistAndRender(); return; }
    });
    // keyboard nav between cells
    wrap.addEventListener('keydown', (e) => {
      const t = e.target; if (t.dataset.ri == null) return;
      const ri = +t.dataset.ri, ci = +t.dataset.ci;
      let nr = ri, nc = ci;
      if (e.key === 'Enter' || (e.key === 'ArrowDown')) nr++;
      else if (e.key === 'ArrowUp') nr--;
      else if (e.key === 'Tab') { e.preventDefault(); nc += e.shiftKey ? -1 : 1; if (nc >= ds.columns.length) { nc = 0; nr++; } if (nc < 0) { nc = ds.columns.length - 1; nr--; } }
      else return;
      const next = wrap.querySelector(`input[data-ri="${nr}"][data-ci="${nc}"]`);
      if (next) { e.preventDefault(); next.focus(); next.select(); }
    });
  }

  function delColumn(ci) {
    const name = ds.columns[ci].name;
    ds.columns.splice(ci, 1);
    ds.rows.forEach(r => r.splice(ci, 1));
    // drop from config
    if (cfg.x === name) cfg.x = ds.columns[0] ? ds.columns[0].name : '';
    cfg.series = cfg.series.filter(s => s !== name);
    if (cfg.group === name) cfg.group = '';
    renderGrid(); buildConfigPanel(); syncConfigInputs(); persistAndRender();
  }

  function renameInConfig(oldN, newN) {
    if (cfg.x === oldN) cfg.x = newN;
    cfg.series = cfg.series.map(s => s === oldN ? newN : s);
    if (cfg.group === oldN) cfg.group = newN;
  }

  function addRow() {
    ds.rows.push(ds.columns.map(() => ''));
    renderGrid(); persistAndRender();
    const last = $$('#gridWrap input[data-ci="0"]').pop(); if (last) last.focus();
  }
  function addColumn() {
    const name = 'Column ' + (ds.columns.length + 1);
    ds.columns.push({ name, type: 'num' });
    ds.rows.forEach(r => r.push(''));
    renderGrid(); buildConfigPanel(); syncConfigInputs(); persistAndRender();
  }

  /* ---- paste CSV ---- */
  function applyPaste() {
    const txt = $('#csvInput').value.trim();
    if (!txt) { toast('Paste some CSV/TSV first'); return; }
    const header = $('#csvHeader').checked;
    const parsed = Data.parseCSV(txt, { header });
    if (!parsed.columns.length) { toast('Could not parse that data'); return; }
    ds = parsed; cfg = PW.defaultConfig(ds);
    renderGrid(); buildConfigPanel(); syncConfigInputs(); update();
    $('#csvInput').value = '';
    toast(`Imported ${ds.rows.length} rows × ${ds.columns.length} cols`);
  }

  /* ================================================================
     CONFIG PANEL
     ================================================================ */
  const CHART_TYPES = [
    ['bar', 'Bar', '<rect x="3" y="11" width="4" height="10"/><rect x="10" y="6" width="4" height="15"/><rect x="17" y="13" width="4" height="8"/>'],
    ['line', 'Line', '<path d="M3 17 L9 11 L14 14 L21 5" fill="none" stroke="currentColor" stroke-width="2"/>'],
    ['area', 'Area', '<path d="M3 17 L9 11 L14 14 L21 5 V21 H3 Z" fill="currentColor" opacity="0.7"/>'],
    ['scatter', 'Scatter', '<circle cx="6" cy="16" r="2"/><circle cx="11" cy="9" r="2"/><circle cx="15" cy="14" r="2"/><circle cx="19" cy="6" r="2"/>'],
    ['pie', 'Pie', '<path d="M12 12 L12 3 A9 9 0 0 1 21 12 Z"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>'],
    ['donut', 'Donut', '<path d="M12 3 A9 9 0 0 1 20 16 L14 13 A4 4 0 0 0 12 8 Z"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/>'],
    ['radar', 'Radar', '<path d="M12 3 L20 9 L17 19 L7 19 L4 9 Z" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M12 7 L16 10 L14 16 L9 15 L7 10 Z" fill="currentColor" opacity="0.6"/>'],
    ['heatmap', 'Heatmap', '<rect x="3" y="3" width="5" height="5" opacity="0.4"/><rect x="10" y="3" width="5" height="5" opacity="0.9"/><rect x="3" y="10" width="5" height="5" opacity="0.7"/><rect x="10" y="10" width="5" height="5" opacity="0.3"/><rect x="17" y="3" width="4" height="5" opacity="0.6"/><rect x="17" y="10" width="4" height="5" opacity="1"/>'],
    ['histogram', 'Histogram', '<rect x="3" y="14" width="4" height="7"/><rect x="8" y="8" width="4" height="13"/><rect x="13" y="11" width="4" height="10"/><rect x="18" y="16" width="3" height="5"/>'],
  ];

  function buildConfigPanel() {
    // chart-type grid
    const tg = $('#typeGrid');
    tg.innerHTML = CHART_TYPES.map(([id, lab, svg]) =>
      `<button class="type-btn ${cfg.type === id ? 'active' : ''}" data-type="${id}" aria-label="${lab} chart" aria-pressed="${cfg.type === id}">
        <svg viewBox="0 0 24 24" fill="currentColor">${svg}</svg><span>${lab}</span></button>`).join('');
    tg.querySelectorAll('.type-btn').forEach(b => b.onclick = () => { cfg.type = b.dataset.type; buildConfigPanel(); syncConfigInputs(); update(); });

    // palettes
    const pg = $('#paletteGrid');
    pg.innerHTML = Object.entries(PW.PALETTES).map(([name, cols]) =>
      `<button class="palette-opt ${cfg.palette === name ? 'active' : ''}" data-pal="${name}">
        <span>${name}</span><div class="palette-swatches">${cols.slice(0, 6).map(c => `<i style="background:${c}"></i>`).join('')}</div></button>`).join('');
    pg.querySelectorAll('.palette-opt').forEach(b => b.onclick = () => { cfg.palette = b.dataset.pal; pg.querySelectorAll('.palette-opt').forEach(x => x.classList.toggle('active', x === b)); update(); });

    buildColumnMapping();
    updateTypeDependentUI();
  }

  function colOptions(selected, includeBlank) {
    return (includeBlank ? '<option value="">(none)</option>' : '') +
      ds.columns.map(c => `<option value="${escAttr(c.name)}" ${c.name === selected ? 'selected' : ''}>${escHtml(c.name)} · ${c.type}</option>`).join('');
  }

  function buildColumnMapping() {
    const x = $('#mapX'), grp = $('#mapGroupRow');
    const t = cfg.type;
    const xLabel = (t === 'scatter') ? 'X column' : (t === 'pie' || t === 'donut') ? 'Label col' : (t === 'radar') ? 'Axis col' : (t === 'histogram') ? '—' : 'X axis';
    $('#mapXLabel').textContent = xLabel;
    x.disabled = (t === 'histogram');
    x.innerHTML = colOptions(cfg.x, t !== 'bar' && t !== 'line' && t !== 'area' ? false : false);
    x.value = cfg.x;
    x.onchange = () => { cfg.x = x.value; update(); };

    // series / values chips
    const sLabel = (t === 'scatter') ? 'Y column' : (t === 'pie' || t === 'donut' || t === 'histogram') ? 'Value col' : (t === 'radar') ? 'Series' : 'Series';
    $('#mapSeriesLabel').textContent = sLabel;
    const single = (t === 'pie' || t === 'donut' || t === 'histogram' || t === 'scatter');
    const cont = $('#mapSeries');
    if (single) {
      // single-select dropdown for value/y
      const cur = cfg.series[0] || (ds.columns.find(c => c.type === 'num') || {}).name || '';
      cont.innerHTML = `<select class="select full" id="mapSeriesSel">${colOptions(cur, false)}</select>`;
      const sel = $('#mapSeriesSel'); sel.value = cur; cfg.series = [sel.value];
      sel.onchange = () => { cfg.series = [sel.value]; update(); };
    } else {
      const pal = PW.PALETTES[cfg.palette] || PW.PALETTES.aurora;
      cont.innerHTML = '<div class="map-cols">' + ds.columns.map((c, i) => {
        if (c.type !== 'num') return '';
        const on = cfg.series.includes(c.name);
        const ci = cfg.series.indexOf(c.name);
        const color = on ? pal[ci % pal.length] : 'var(--ink-faint)';
        return `<button class="map-chip ${on ? '' : 'off'}" data-col="${escAttr(c.name)}"><i style="background:${color}"></i>${escHtml(c.name)}</button>`;
      }).join('') + '</div>';
      cont.querySelectorAll('.map-chip').forEach(b => b.onclick = () => {
        const n = b.dataset.col;
        if (cfg.series.includes(n)) { if (cfg.series.length > 1) cfg.series = cfg.series.filter(s => s !== n); }
        else cfg.series.push(n);
        buildColumnMapping(); update();
      });
    }

    // group column (scatter only)
    if (t === 'scatter') {
      grp.style.display = '';
      const g = $('#mapGroup');
      g.innerHTML = colOptions(cfg.group, true); g.value = cfg.group || '';
      g.onchange = () => { cfg.group = g.value; update(); };
    } else grp.style.display = 'none';
  }

  function updateTypeDependentUI() {
    const t = cfg.type;
    const cartesian = (t === 'bar' || t === 'line' || t === 'area');
    $('#stackedRow').style.display = (t === 'bar' || t === 'area') ? '' : 'none';
    $('#gridRow').style.display = (cartesian || t === 'scatter' || t === 'histogram') ? '' : 'none';
    $('#axisRows').style.display = (cartesian || t === 'scatter' || t === 'histogram') ? '' : 'none';
  }

  function syncConfigInputs() {
    $('#cfgTitle').value = cfg.title || '';
    $('#cfgXLabel').value = cfg.xLabel || '';
    $('#cfgYLabel').value = cfg.yLabel || '';
    $('#cfgStacked').checked = !!cfg.stacked;
    $('#cfgGrid').checked = cfg.grid !== false;
    $('#cfgAnimate').checked = !!cfg.animate;
    $('#cfgFormat').value = cfg.valueFormat || 'number';
    $$('#sortSeg button').forEach(b => b.classList.toggle('active', b.dataset.sort === (cfg.sort || 'none')));
    $$('#legendSeg button').forEach(b => b.classList.toggle('active', b.dataset.leg === (cfg.legendPos || 'top')));
  }

  function bindConfigInputs() {
    $('#cfgTitle').oninput = e => { cfg.title = e.target.value; update(); };
    $('#cfgXLabel').oninput = e => { cfg.xLabel = e.target.value; update(); };
    $('#cfgYLabel').oninput = e => { cfg.yLabel = e.target.value; update(); };
    $('#cfgStacked').onchange = e => { cfg.stacked = e.target.checked; update(); };
    $('#cfgGrid').onchange = e => { cfg.grid = e.target.checked; update(); };
    $('#cfgAnimate').onchange = e => { cfg.animate = e.target.checked; if (cfg.animate) chart.render(model(), cfg); };
    $('#cfgFormat').onchange = e => { cfg.valueFormat = e.target.value; update(); };
    $$('#sortSeg button').forEach(b => b.onclick = () => { cfg.sort = b.dataset.sort; syncConfigInputs(); update(); });
    $$('#legendSeg button').forEach(b => b.onclick = () => { cfg.legendPos = b.dataset.leg; syncConfigInputs(); update(); });
  }

  /* ================================================================
     RENDER + PERSIST
     ================================================================ */
  function model() { return PW.buildModel(ds, cfg); }

  function update(animate) {
    const m = model();
    chart.render(m, Object.assign({}, cfg, animate === false ? { animate: false } : {}));
    updateStatbar(m);
    persist();
  }
  // redraw without re-running entrance animation (used on rapid edits)
  function quickUpdate() { chart.render(model(), Object.assign({}, cfg, { animate: false })); updateStatbar(); persist(); }

  function persistAndRender() { quickUpdate(); }
  let _upTimer = null;
  function scheduleUpdate() { clearTimeout(_upTimer); _upTimer = setTimeout(quickUpdate, 120); }

  function updateStatbar(m) {
    m = m || model();
    const stat = $('#statbar');
    let seriesN, pts;
    if (m.rowsHM) { seriesN = m.cols ? m.cols.length : 0; pts = m.rowsHM.length * (m.cols ? m.cols.length : 0); }
    else if (m.points) { seriesN = m.groups ? m.groups.length : 1; pts = m.points.length; }
    else if (m.values) { seriesN = 1; pts = m.values.length; }
    else if (m.series) { seriesN = m.series.length; pts = m.series[0] ? m.series[0].values.length : 0; }
    else { seriesN = 0; pts = 0; }
    stat.innerHTML =
      `<span><b>${ds.rows.length}</b> rows</span><span class="dot-sep">·</span>` +
      `<span><b>${ds.columns.length}</b> cols</span><span class="dot-sep">·</span>` +
      `<span>type <b>${cfg.type}</b></span><span class="dot-sep">·</span>` +
      `<span><b>${seriesN}</b> series</span><span class="dot-sep">·</span>` +
      `<span><b>${pts}</b> points plotted</span>`;
  }

  function snapshot() { return { ds, cfg }; }

  function persist() {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(snapshot())); } catch (e) {}
    writeHash();
  }
  function readStore() {
    try { const s = JSON.parse(localStorage.getItem(STORE_KEY)); if (s && s.ds && s.ds.columns) return s; } catch (e) {}
    return null;
  }

  /* ---- URL hash sharing (base64 of {ds,cfg}) ---- */
  function writeHash() {
    try {
      const json = JSON.stringify(snapshot());
      const b64 = btoa(unescape(encodeURIComponent(json)));
      history.replaceState(null, '', '#d=' + b64);
    } catch (e) {}
  }
  function readHash() {
    const h = location.hash;
    const m = h.match(/[#&]d=([^&]+)/);
    if (!m) {
      // preset deep-link  #preset=id
      const pm = h.match(/[#&]preset=([^&]+)/);
      if (pm) { const p = (PW.PRESETS || []).find(x => x.id === pm[1]); if (p) { const d = Data.parseCSV(PW.DATASETS[p.dataset].csv); return { ds: d, cfg: Object.assign(PW.defaultConfig(d), p.config) }; } }
      return null;
    }
    try { const json = decodeURIComponent(escape(atob(m[1]))); const s = JSON.parse(json); if (s.ds && s.ds.columns) return s; } catch (e) {}
    return null;
  }

  /* ================================================================
     EXPORTS
     ================================================================ */
  function download(name, blob) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function exportPNG() {
    // re-render at high res into an offscreen canvas with solid bg
    const scale = 2;
    const off = document.createElement('canvas');
    const r = canvas.getBoundingClientRect();
    off.width = r.width * scale; off.height = r.height * scale;
    const octx = off.getContext('2d');
    octx.fillStyle = getComputedStyle(document.body).getPropertyValue('--panel').trim() || '#11161f';
    octx.fillRect(0, 0, off.width, off.height);
    const tmp = new PW.Chart(off, null);
    octx.scale(scale, scale);
    // temporarily disable HiDPI re-setup by drawing through a wrapper:
    tmp._setup = function () { this.W = r.width; this.H = r.height; this.ctx.setTransform(scale, 0, 0, scale, 0, 0); this.ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--panel').trim() || '#11161f'; this.ctx.fillRect(0, 0, this.W, this.H); };
    tmp.hidden = chart.hidden;
    tmp.anim = 1; tmp._drawAnim = 1;
    tmp.render(model(), Object.assign({}, cfg, { animate: false }));
    off.toBlob(b => { download((cfg.title || 'plotweaver-chart').replace(/\W+/g, '-').toLowerCase() + '.png', b); toast('Exported PNG'); });
  }

  function exportSVG() {
    const svg = PW.toSVG(model(), cfg, chart);
    download((cfg.title || 'plotweaver-chart').replace(/\W+/g, '-').toLowerCase() + '.svg', new Blob([svg], { type: 'image/svg+xml' }));
    toast('Exported standalone SVG');
  }

  function exportJSON() {
    const out = { tool: 'Plotweaver', version: 1, exportedAt: new Date().toISOString(), config: cfg, data: ds };
    download((cfg.title || 'plotweaver').replace(/\W+/g, '-').toLowerCase() + '.json', new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' }));
    toast('Exported config + data JSON');
  }

  function copyEmbed() {
    writeHash();
    const url = location.href;
    const snippet = `<iframe src="${url}" width="720" height="460" frameborder="0" title="${escAttr(cfg.title || 'Plotweaver chart')}"></iframe>`;
    navigator.clipboard.writeText(snippet).then(() => toast('Embed snippet + share link copied'), () => {
      // fallback
      const ta = document.createElement('textarea'); ta.value = snippet; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast('Embed snippet copied');
    });
  }

  function importJSONFile(file) {
    const fr = new FileReader();
    fr.onload = () => {
      try {
        const obj = JSON.parse(fr.result);
        if (obj.data && obj.data.columns) { ds = obj.data; cfg = Object.assign(PW.defaultConfig(ds), obj.config || {}); }
        else if (obj.columns) { ds = obj; cfg = PW.defaultConfig(ds); }
        else throw 0;
        renderGrid(); buildConfigPanel(); syncConfigInputs(); update(); toast('Imported chart from JSON');
      } catch (e) { toast('Not a valid Plotweaver JSON'); }
    };
    fr.readAsText(file);
  }

  /* ================================================================
     GLOBAL UI: toolbar buttons, theme, mobile tabs, toast
     ================================================================ */
  function bindGlobal() {
    bindConfigInputs();
    $('#addRowBtn').onclick = addRow;
    $('#addColBtn').onclick = addColumn;
    $('#applyPasteBtn').onclick = applyPaste;
    $('#clearPasteBtn').onclick = () => { $('#csvInput').value = ''; };
    $('#exportPngBtn').onclick = exportPNG;
    $('#exportSvgBtn').onclick = exportSVG;
    $('#exportJsonBtn').onclick = exportJSON;
    $('#copyEmbedBtn').onclick = copyEmbed;
    $('#importJsonInput').onchange = e => { if (e.target.files[0]) importJSONFile(e.target.files[0]); e.target.value = ''; };

    // theme toggle
    const tg = $('#themeToggle');
    const savedTheme = localStorage.getItem('plotweaver.theme');
    if (savedTheme) document.body.dataset.theme = savedTheme;
    syncThemeLabel();
    tg.onclick = () => { document.body.dataset.theme = document.body.dataset.theme === 'light' ? 'dark' : 'light'; localStorage.setItem('plotweaver.theme', document.body.dataset.theme); syncThemeLabel(); chart.redraw(); };

    // mobile pane tabs
    $$('.mobtab button').forEach(b => b.onclick = () => {
      $$('.mobtab button').forEach(x => x.classList.remove('active')); b.classList.add('active');
      $$('.pane').forEach(p => p.classList.remove('active'));
      $('#pane-' + b.dataset.pane).classList.add('active');
      if (b.dataset.pane === 'canvas') chart.redraw();
    });

    // shortcuts
    document.addEventListener('keydown', (e) => {
      if (e.target.matches('input, textarea, select')) return;
      if ((e.key === 'e' || e.key === 'E') && (e.metaKey || e.ctrlKey)) { e.preventDefault(); exportPNG(); }
    });
  }

  function syncThemeLabel() {
    const lab = $('#themeToggle .tlabel'); if (lab) lab.textContent = document.body.dataset.theme === 'light' ? 'Light' : 'Dark';
  }

  let _toastTimer = null;
  function toast(msg) {
    const t = $('#toast'); $('#toastMsg').textContent = msg; t.classList.add('show');
    clearTimeout(_toastTimer); _toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
  }

  function debounce(fn, ms) { let t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }
  function escAttr(s) { return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function escHtml(s) { return String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }

  // expose toast for inline use
  PW.studioToast = toast;

  document.addEventListener('DOMContentLoaded', init);
})();
