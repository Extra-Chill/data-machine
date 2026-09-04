/* =========================================================
   TONEBOX — app.js
   Glue between the DOM (index.html) and the Editor. Builds the
   adjustment panel from a schema, wires sliders (debounced via
   the editor's rAF, committed to history on release), the toolbar
   (rotate/flip/resize/crop), the interactive crop overlay, sample
   loading, file upload, export, the histogram channel toggle,
   keyboard shortcuts and the shortcuts modal.
   ========================================================= */
(function () {
  'use strict';

  const F = window.TBFilters;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  /* adjustment slider schema (label, key, min, max, step, suffix) */
  const SLIDERS = [
    { group: 'Light', items: [
      { key: 'brightness', label: 'Brightness', min: -100, max: 100, def: 0 },
      { key: 'contrast',   label: 'Contrast',   min: -100, max: 100, def: 0 },
      { key: 'gamma',      label: 'Exposure / Gamma', min: 10, max: 300, def: 100, suffix: '%' }
    ]},
    { group: 'Color', items: [
      { key: 'saturation', label: 'Saturation', min: -100, max: 100, def: 0 },
      { key: 'hue',        label: 'Hue rotate', min: -180, max: 180, def: 0, suffix: '°' },
      { key: 'temperature',label: 'Temperature', min: -100, max: 100, def: 0 },
      { key: 'tint',       label: 'Tint (G–M)',  min: -100, max: 100, def: 0 }
    ]},
    { group: 'Style', items: [
      { key: 'grayscale', label: 'Grayscale', min: 0, max: 100, def: 0, suffix: '%' },
      { key: 'sepia',     label: 'Sepia',     min: 0, max: 100, def: 0, suffix: '%' },
      { key: 'invert',    label: 'Invert',    min: 0, max: 100, def: 0, suffix: '%' },
      { key: 'threshold', label: 'Threshold', min: 0, max: 100, def: 0, suffix: '%' },
      { key: 'vignette',  label: 'Vignette',  min: 0, max: 100, def: 0, suffix: '%' }
    ]}
  ];

  const CONV = [
    { v: 'none',      l: 'None' },
    { v: 'blurBox',   l: 'Box blur' },
    { v: 'blurGauss', l: 'Gaussian blur' },
    { v: 'sharpen',   l: 'Sharpen' },
    { v: 'edge',      l: 'Edge detect (Sobel)' },
    { v: 'emboss',    l: 'Emboss' }
  ];

  let editor;
  const sliderEls = {};   // key -> {input, valEl}

  function fmtVal(it, v) {
    const suffix = it.suffix || '';
    return (it.key === 'gamma' ? v : v) + suffix;
  }

  function buildPanel() {
    const host = $('#adjust-panel');
    SLIDERS.forEach(group => {
      const g = document.createElement('div');
      g.className = 'adj-group';
      const h = document.createElement('h3');
      h.textContent = group.group;
      g.appendChild(h);
      group.items.forEach(it => {
        const row = document.createElement('div');
        row.className = 'adj-row';
        const lab = document.createElement('label');
        lab.textContent = it.label;
        lab.htmlFor = 'sl-' + it.key;
        const val = document.createElement('output');
        val.className = 'adj-val';
        val.textContent = fmtVal(it, it.def);
        const head = document.createElement('div');
        head.className = 'adj-head';
        head.appendChild(lab); head.appendChild(val);

        const input = document.createElement('input');
        input.type = 'range';
        input.id = 'sl-' + it.key;
        input.min = it.min; input.max = it.max; input.step = it.step || 1;
        input.value = it.def;
        input.dataset.key = it.key;

        // live update on input, commit to history on change (release)
        input.addEventListener('input', () => {
          const v = Number(input.value);
          editor.set(it.key, v);
          val.textContent = fmtVal(it, v);
        });
        input.addEventListener('change', () => editor.commit());
        // double-click resets the single slider
        val.addEventListener('dblclick', () => {
          input.value = it.def;
          editor.commit();
          editor.set(it.key, it.def);
          val.textContent = fmtVal(it, it.def);
        });

        row.appendChild(head);
        row.appendChild(input);
        g.appendChild(row);
        sliderEls[it.key] = { input, val, it };
      });
      host.appendChild(g);
    });

    // convolution select lives in its own group
    const cg = document.createElement('div');
    cg.className = 'adj-group';
    const ch = document.createElement('h3');
    ch.textContent = 'Convolution kernel';
    cg.appendChild(ch);
    const sel = document.createElement('select');
    sel.id = 'conv-select';
    sel.className = 'conv-select';
    CONV.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.v; opt.textContent = o.l;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', () => {
      editor.commit();
      editor.set('convolution', sel.value);
    });
    cg.appendChild(sel);
    host.appendChild(cg);
  }

  /* reflect editor state back into the controls (after preset/undo) */
  function syncControls(state) {
    Object.keys(sliderEls).forEach(k => {
      const { input, val, it } = sliderEls[k];
      input.value = state[k];
      val.textContent = fmtVal(it, state[k]);
    });
    const sel = $('#conv-select');
    if (sel) sel.value = state.convolution;
  }

  /* ---- sample images ---- */
  function loadSample(id) {
    const cv = window.TBSamples.build(id);
    const s = window.TBSamples.byId(id);
    editor.loadImage(cv, s.name);
    syncControls(editor.state);
  }

  /* ---- file upload ---- */
  function handleFile(file) {
    if (!file || !/^image\//.test(file.type)) return;
    const reader = new FileReader();
    reader.onload = e => {
      const img = new Image();
      img.onload = () => { editor.loadImage(img, file.name); syncControls(editor.state); };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }

  /* =========================================================
     CROP OVERLAY — a draggable/resizable rectangle over the
     canvas. Coordinates are stored normalized (0..1) so they map
     onto whatever the current image size is.
     ========================================================= */
  const Crop = {
    active: false,
    rect: { x: 0.15, y: 0.15, w: 0.7, h: 0.7 },
    el: null, drag: null,
    init() {
      this.el = $('#crop-overlay');
      this.box = $('#crop-box');
      const stage = $('#canvas-stage');
      this.stage = stage;
      // pointer handling on the crop box + handles
      this.box.addEventListener('pointerdown', e => this.start(e, 'move'));
      $$('.crop-handle', this.box).forEach(hd => {
        hd.addEventListener('pointerdown', e => { e.stopPropagation(); this.start(e, hd.dataset.h); });
      });
      window.addEventListener('pointermove', e => this.move(e));
      window.addEventListener('pointerup', () => this.drag = null);
    },
    canvasRect() { return $('#view').getBoundingClientRect(); },
    show() {
      this.active = true;
      this.el.hidden = false;
      this.rect = { x: 0.12, y: 0.12, w: 0.76, h: 0.76 };
      this.layout();
      $('#crop-bar').hidden = false;
    },
    hide() { this.active = false; this.el.hidden = true; $('#crop-bar').hidden = true; },
    layout() {
      const cr = this.canvasRect();
      const sr = this.stage.getBoundingClientRect();
      const left = cr.left - sr.left, top = cr.top - sr.top;
      this.box.style.left = (left + this.rect.x * cr.width) + 'px';
      this.box.style.top = (top + this.rect.y * cr.height) + 'px';
      this.box.style.width = (this.rect.w * cr.width) + 'px';
      this.box.style.height = (this.rect.h * cr.height) + 'px';
      // darken outside via clip-path on the mask
      const x = this.rect.x * 100, y = this.rect.y * 100;
      const w = this.rect.w * 100, h = this.rect.h * 100;
      $('#crop-mask').style.clipPath =
        `polygon(0 0, 100% 0, 100% 100%, 0 100%, 0 ${y}%, ${x}% ${y}%, ${x}% ${y + h}%, ${x + w}% ${y + h}%, ${x + w}% ${y}%, 0 ${y}%)`;
    },
    start(e, mode) {
      e.preventDefault();
      const cr = this.canvasRect();
      this.drag = { mode, sx: e.clientX, sy: e.clientY, r: Object.assign({}, this.rect), cw: cr.width, ch: cr.height };
    },
    move(e) {
      if (!this.drag) return;
      const d = this.drag;
      const dx = (e.clientX - d.sx) / d.cw;
      const dy = (e.clientY - d.sy) / d.ch;
      let r = Object.assign({}, d.r);
      const min = 0.05;
      if (d.mode === 'move') {
        r.x = clampN(d.r.x + dx, 0, 1 - r.w);
        r.y = clampN(d.r.y + dy, 0, 1 - r.h);
      } else {
        if (d.mode.includes('e')) r.w = clampN(d.r.w + dx, min, 1 - r.x);
        if (d.mode.includes('s')) r.h = clampN(d.r.h + dy, min, 1 - r.y);
        if (d.mode.includes('w')) { const nx = clampN(d.r.x + dx, 0, d.r.x + d.r.w - min); r.w = d.r.w + (d.r.x - nx); r.x = nx; }
        if (d.mode.includes('n')) { const ny = clampN(d.r.y + dy, 0, d.r.y + d.r.h - min); r.h = d.r.h + (d.r.y - ny); r.y = ny; }
      }
      this.rect = r;
      this.layout();
    },
    apply() { editor.crop(this.rect); this.hide(); }
  };
  function clampN(v, a, b) { return v < a ? a : v > b ? b : v; }

  /* ---- export modal ---- */
  function doExport(type) {
    const q = type === 'image/jpeg' ? 0.92 : undefined;
    editor.export(type, q, blob => {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      const ext = type === 'image/jpeg' ? 'jpg' : 'png';
      a.href = url;
      a.download = 'tonebox-' + Date.now() + '.' + ext;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
    });
  }

  /* ---- modal helpers ---- */
  function openModal(id) { $(id).classList.add('open'); }
  function closeModals() { $$('.modal.open').forEach(m => m.classList.remove('open')); }

  /* =========================================================
     INIT
     ========================================================= */
  document.addEventListener('DOMContentLoaded', () => {
    buildPanel();

    editor = new window.TBEditor({
      canvas: $('#view'),
      histCanvas: $('#histogram'),
      thumbStrip: $('#preset-strip'),
      onStateChange: (state) => syncControls(state),
      onMeta: (m) => {
        $('#meta-dims').textContent = m.w + ' × ' + m.h + ' px';
        $('#meta-name').textContent = m.label;
        if (Crop.active) Crop.layout();
      },
      onHistory: (canU, canR) => {
        $('#act-undo').disabled = !canU;
        $('#act-redo').disabled = !canR;
      }
    });
    Crop.init();

    // build sample chooser buttons
    const chooser = $('#sample-list');
    window.TBSamples.list.forEach((s, i) => {
      const cv = s.build();
      const t = document.createElement('canvas');
      t.width = 120; t.height = 80;
      const tctx = t.getContext('2d');
      tctx.drawImage(cv, 0, 0, 120, 80);
      const btn = document.createElement('button');
      btn.className = 'sample-btn';
      btn.type = 'button';
      btn.appendChild(t);
      const cap = document.createElement('span');
      cap.innerHTML = '<strong>' + s.name + '</strong><em>' + s.sub + '</em>';
      btn.appendChild(cap);
      btn.addEventListener('click', () => loadSample(s.id));
      chooser.appendChild(btn);
    });

    // load first sample by default
    loadSample('coast');

    // sample dropdown toggle
    $('#open-samples').addEventListener('click', () => $('#sample-pop').classList.toggle('open'));
    document.addEventListener('click', e => {
      if (!e.target.closest('.sample-wrap')) $('#sample-pop').classList.remove('open');
    });
    $('#sample-list').addEventListener('click', () => $('#sample-pop').classList.remove('open'));

    // file upload (button + drag-drop)
    $('#upload-input').addEventListener('change', e => handleFile(e.target.files[0]));
    const stage = $('#canvas-stage');
    ['dragover', 'dragenter'].forEach(ev =>
      stage.addEventListener(ev, e => { e.preventDefault(); stage.classList.add('dropping'); }));
    ['dragleave', 'drop'].forEach(ev =>
      stage.addEventListener(ev, e => { e.preventDefault(); stage.classList.remove('dropping'); }));
    stage.addEventListener('drop', e => { if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]); });

    // toolbar
    $('#act-undo').addEventListener('click', () => editor.undo());
    $('#act-redo').addEventListener('click', () => editor.redo());
    $('#act-reset').addEventListener('click', () => editor.reset());
    $('#act-rot-l').addEventListener('click', () => editor.rotate(-90));
    $('#act-rot-r').addEventListener('click', () => editor.rotate(90));
    $('#act-flip-h').addEventListener('click', () => editor.flip('h'));
    $('#act-flip-v').addEventListener('click', () => editor.flip('v'));
    $('#act-half').addEventListener('click', () => editor.resize(0.5));
    $('#act-double').addEventListener('click', () => editor.resize(2));
    $('#act-crop').addEventListener('click', () => Crop.show());
    $('#crop-apply').addEventListener('click', () => Crop.apply());
    $('#crop-cancel').addEventListener('click', () => Crop.hide());

    // histogram channel toggle
    $$('.hist-tab').forEach(t => t.addEventListener('click', () => {
      $$('.hist-tab').forEach(x => x.classList.remove('is-active'));
      t.classList.add('is-active');
      editor.setHistChannel(t.dataset.ch);
    }));

    // export
    $('#export-png').addEventListener('click', () => doExport('image/png'));
    $('#export-jpg').addEventListener('click', () => doExport('image/jpeg'));

    // help modal
    $('#help-btn').addEventListener('click', () => openModal('#shortcuts'));
    $$('[data-close]').forEach(b => b.addEventListener('click', closeModals));
    $$('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) closeModals(); }));

    // keyboard shortcuts
    document.addEventListener('keydown', e => {
      const tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'select' || tag === 'textarea') {
        if (e.key === 'Escape') e.target.blur();
        return;
      }
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 'z') {
        e.preventDefault(); e.shiftKey ? editor.redo() : editor.undo(); return;
      }
      if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); editor.redo(); return; }
      if (mod && e.key.toLowerCase() === 's') {
        e.preventDefault(); doExport('image/png'); return;
      }
      switch (e.key) {
        case 'Escape': if (Crop.active) Crop.hide(); else closeModals(); break;
        case 'r': editor.rotate(90); break;
        case 'R': editor.rotate(-90); break;
        case 'f': editor.flip('h'); break;
        case 'v': editor.flip('v'); break;
        case 'c': Crop.active ? Crop.apply() : Crop.show(); break;
        case '0': editor.reset(); break;
        case '?': openModal('#shortcuts'); break;
        default:
          // 1..8 apply presets
          if (/^[1-8]$/.test(e.key)) {
            const p = F.PRESETS[Number(e.key) - 1];
            if (p) { editor.applyPreset(p.id); syncControls(editor.state); }
          }
      }
    });

    window.addEventListener('resize', () => { if (Crop.active) Crop.layout(); });
  });
})();
