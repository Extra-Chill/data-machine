/* =========================================================
   TONEBOX — editor.js
   The editor controller. Owns:
     • the SOURCE bitmap (ImageData of the loaded image),
     • the current adjustment STATE,
     • a non-destructive recompute pipeline (source -> point
       adjustments -> convolution -> vignette -> display),
     • a real UNDO/REDO history of committed states,
     • geometry ops (rotate, flip, crop, resize) which actually
       rewrite the source bitmap,
     • live histogram + preset thumbnails,
     • export to PNG/JPEG.

   "Non-destructive" here means slider edits never touch the
   source — every change recomputes the working buffer from the
   pristine source, so any slider can be dragged back to identity.
   Geometry ops are committed into a new source (and pushed to
   history) because they change pixel dimensions.
   ========================================================= */
(function (global) {
  'use strict';

  const F = global.TBFilters;
  const H = global.TBHistogram;

  const PREVIEW_MAX = 1100;   // cap working resolution for responsiveness

  function makeWorkCanvas() {
    const c = document.createElement('canvas');
    return { c, ctx: c.getContext('2d', { willReadFrequently: true }) };
  }

  function Editor(opts) {
    this.view = opts.canvas;                 // visible <canvas>
    this.vctx = this.view.getContext('2d');
    this.histCanvas = opts.histCanvas;
    this.thumbStrip = opts.thumbStrip;
    this.onStateChange = opts.onStateChange || function () {};
    this.onMeta = opts.onMeta || function () {};
    this.onHistory = opts.onHistory || function () {};

    this.source = null;       // ImageData (pristine pixels at working res)
    this.state = F.defaults();
    this.histChannel = 'rgb';

    // history stacks of { state, sourceSnapshot|null }
    this.undoStack = [];
    this.redoStack = [];

    this._raf = null;
    this._work = makeWorkCanvas();   // scratch for processing
    this._thumbSrc = null;           // tiny source for fast preset thumbs
  }

  Editor.prototype = {

    /* ---- load any drawable (canvas/image) as the new source ---- */
    loadImage(drawable, label) {
      let w = drawable.width, h = drawable.height;
      // downscale very large uploads to keep editing snappy
      const scale = Math.min(1, PREVIEW_MAX / Math.max(w, h));
      w = Math.round(w * scale); h = Math.round(h * scale);

      const { c, ctx } = this._work;
      c.width = w; c.height = h;
      ctx.clearRect(0, 0, w, h);
      ctx.drawImage(drawable, 0, 0, w, h);
      this.source = ctx.getImageData(0, 0, w, h);

      this.state = F.defaults();
      this.undoStack = []; this.redoStack = [];
      this.label = label || 'Untitled';
      this._buildThumbSource();
      this._renderThumbs();
      this.render(true);
      this.onMeta(this.meta());
      this.onHistory(this.canUndo(), this.canRedo());
    },

    meta() {
      return {
        w: this.source ? this.source.width : 0,
        h: this.source ? this.source.height : 0,
        label: this.label
      };
    },

    /* ---- the core non-destructive pipeline ---- */
    _process(srcImageData, state) {
      const w = srcImageData.width, h = srcImageData.height;
      // copy source so we never mutate the pristine pixels
      const buf = new Uint8ClampedArray(srcImageData.data); // RGBA copy
      F.applyAdjustments(buf, state);
      let out = F.applyConvolution(buf, w, h, state.convolution);
      F.applyVignette(out, w, h, state.vignette);
      return new ImageData(out, w, h);
    },

    render(immediate) {
      if (!this.source) return;
      const run = () => {
        this._raf = null;
        const result = this._process(this.source, this.state);
        this.view.width = result.width;
        this.view.height = result.height;
        this.vctx.putImageData(result, 0, 0);
        H.render(this.histCanvas, result.data, this.histChannel);
        this.onStateChange(this.state);
      };
      if (immediate) { if (this._raf) cancelAnimationFrame(this._raf); run(); }
      else if (!this._raf) this._raf = requestAnimationFrame(run);
    },

    /* ---- adjustment setters (live, debounced via rAF) ---- */
    set(key, value) {
      if (this.state[key] === value) return;
      this.state[key] = value;
      this.render(false);
    },

    setHistChannel(ch) {
      this.histChannel = ch;
      this.render(true);
    },

    /* ---- commit current point-state into history (for sliders) ---- */
    commit() {
      this.undoStack.push({ state: Object.assign({}, this.state), source: null });
      if (this.undoStack.length > 60) this.undoStack.shift();
      this.redoStack = [];
      this.onHistory(this.canUndo(), this.canRedo());
    },

    /* apply a preset = replace whole state, commit */
    applyPreset(id) {
      this.commitBefore();
      this.state = F.presetState(id);
      this.redoStack = [];
      this.render(true);
      this.onHistory(this.canUndo(), this.canRedo());
    },

    /* snapshot current state BEFORE a discrete change (preset/geom) */
    commitBefore() {
      this.undoStack.push({
        state: Object.assign({}, this.state),
        source: this._cloneSource()
      });
      if (this.undoStack.length > 60) this.undoStack.shift();
    },

    _cloneSource() {
      return new ImageData(
        new Uint8ClampedArray(this.source.data),
        this.source.width, this.source.height
      );
    },

    canUndo() { return this.undoStack.length > 0; },
    canRedo() { return this.redoStack.length > 0; },

    undo() {
      if (!this.undoStack.length) return;
      const cur = { state: Object.assign({}, this.state), source: this._cloneSource() };
      const prev = this.undoStack.pop();
      this.redoStack.push(cur);
      this.state = Object.assign(F.defaults(), prev.state);
      if (prev.source) this.source = prev.source;
      this._buildThumbSource();
      this._renderThumbs();
      this.render(true);
      this.onMeta(this.meta());
      this.onHistory(this.canUndo(), this.canRedo());
    },

    redo() {
      if (!this.redoStack.length) return;
      const cur = { state: Object.assign({}, this.state), source: this._cloneSource() };
      const next = this.redoStack.pop();
      this.undoStack.push(cur);
      this.state = Object.assign(F.defaults(), next.state);
      if (next.source) this.source = next.source;
      this._buildThumbSource();
      this._renderThumbs();
      this.render(true);
      this.onMeta(this.meta());
      this.onHistory(this.canUndo(), this.canRedo());
    },

    reset() {
      this.commitBefore();
      this.state = F.defaults();
      this.render(true);
      this.onHistory(this.canUndo(), this.canRedo());
    },

    /* =========================================================
       GEOMETRY OPS — these BAKE the current look into a new source
       so the operation composes with everything done so far, then
       reset point-state to identity. Pushed to history.
       ========================================================= */
    _bakeCurrent() {
      // returns a canvas holding the fully-processed current image
      const result = this._process(this.source, this.state);
      const c = document.createElement('canvas');
      c.width = result.width; c.height = result.height;
      c.getContext('2d').putImageData(result, 0, 0);
      return c;
    },

    _replaceSourceFrom(canvas) {
      const ctx = canvas.getContext('2d');
      this.source = ctx.getImageData(0, 0, canvas.width, canvas.height);
      this.state = F.defaults();
      this._buildThumbSource();
      this._renderThumbs();
      this.render(true);
      this.onMeta(this.meta());
      this.onHistory(this.canUndo(), this.canRedo());
    },

    rotate(deg) {              // 90 or -90
      this.commitBefore();
      const baked = this._bakeCurrent();
      const c = document.createElement('canvas');
      c.width = baked.height; c.height = baked.width;
      const ctx = c.getContext('2d');
      ctx.translate(c.width / 2, c.height / 2);
      ctx.rotate(deg * Math.PI / 180);
      ctx.drawImage(baked, -baked.width / 2, -baked.height / 2);
      this._replaceSourceFrom(c);
    },

    flip(axis) {               // 'h' or 'v'
      this.commitBefore();
      const baked = this._bakeCurrent();
      const c = document.createElement('canvas');
      c.width = baked.width; c.height = baked.height;
      const ctx = c.getContext('2d');
      if (axis === 'h') { ctx.translate(c.width, 0); ctx.scale(-1, 1); }
      else { ctx.translate(0, c.height); ctx.scale(1, -1); }
      ctx.drawImage(baked, 0, 0);
      this._replaceSourceFrom(c);
    },

    resize(factor) {           // e.g. 0.5, 2
      this.commitBefore();
      const baked = this._bakeCurrent();
      const nw = Math.max(1, Math.round(baked.width * factor));
      const nh = Math.max(1, Math.round(baked.height * factor));
      const c = document.createElement('canvas');
      c.width = nw; c.height = nh;
      const ctx = c.getContext('2d');
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(baked, 0, 0, nw, nh);
      this._replaceSourceFrom(c);
    },

    /* crop given normalized rect {x,y,w,h} (0..1) */
    crop(rect) {
      this.commitBefore();
      const baked = this._bakeCurrent();
      const x = Math.round(rect.x * baked.width);
      const y = Math.round(rect.y * baked.height);
      const w = Math.max(1, Math.round(rect.w * baked.width));
      const h = Math.max(1, Math.round(rect.h * baked.height));
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(baked, x, y, w, h, 0, 0, w, h);
      this._replaceSourceFrom(c);
    },

    /* =========================================================
       PRESET THUMBNAILS — process a tiny copy of the source for
       each preset so the strip is cheap and updates with crops/edits.
       ========================================================= */
    _buildThumbSource() {
      const TW = 132, TH = 92;
      const ratio = this.source.width / this.source.height;
      let tw = TW, th = Math.round(TW / ratio);
      if (th > TH) { th = TH; tw = Math.round(TH * ratio); }
      const tmp = document.createElement('canvas');
      tmp.width = this.source.width; tmp.height = this.source.height;
      tmp.getContext('2d').putImageData(this.source, 0, 0);
      const c = document.createElement('canvas');
      c.width = tw; c.height = th;
      const ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.imageSmoothingQuality = 'medium';
      ctx.drawImage(tmp, 0, 0, tw, th);
      this._thumbSrc = ctx.getImageData(0, 0, tw, th);
    },

    _renderThumbs() {
      if (!this.thumbStrip || !this._thumbSrc) return;
      this.thumbStrip.innerHTML = '';
      F.PRESETS.forEach(p => {
        const result = this._process(this._thumbSrc, F.presetState(p.id));
        const cv = document.createElement('canvas');
        cv.width = result.width; cv.height = result.height;
        cv.getContext('2d').putImageData(result, 0, 0);

        const btn = document.createElement('button');
        btn.className = 'preset';
        btn.type = 'button';
        btn.dataset.preset = p.id;
        btn.title = p.name;
        btn.setAttribute('aria-label', 'Apply ' + p.name + ' look');
        const wrap = document.createElement('span');
        wrap.className = 'preset-thumb';
        wrap.appendChild(cv);
        const cap = document.createElement('span');
        cap.className = 'preset-name';
        cap.textContent = p.name;
        btn.appendChild(wrap);
        btn.appendChild(cap);
        btn.addEventListener('click', () => {
          this.applyPreset(p.id);
          this.onStateChange(this.state, true);
        });
        this.thumbStrip.appendChild(btn);
      });
    },

    /* ---- export ---- */
    export(type, quality, cb) {
      const result = this._process(this.source, this.state);
      const c = document.createElement('canvas');
      c.width = result.width; c.height = result.height;
      const ctx = c.getContext('2d');
      if (type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); }
      ctx.putImageData(result, 0, 0);
      c.toBlob(cb, type, quality);
    }
  };

  global.TBEditor = Editor;
})(window);
