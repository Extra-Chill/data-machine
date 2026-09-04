/* =========================================================
   TONEBOX — filters.js
   The actual image processing. Everything operates on a flat
   Uint8ClampedArray (RGBA, channel order R,G,B,A) so the same
   routines work on ImageData from any canvas.

   Two families:
     • POINT adjustments — per-pixel value mapping (brightness,
       contrast, saturation, hue, gamma, temperature, invert,
       grayscale, sepia, threshold).
     • CONVOLUTION — a correct NxN kernel pass with edge clamping
       (blur, gaussian, sharpen, Sobel edge-detect, emboss).

   The editor applies POINT adjustments in a fixed, sensible order
   on every recompute from the ORIGINAL pixels, so every slider is
   fully non-destructive and reversible.
   ========================================================= */
(function (global) {
  'use strict';

  const clamp = (v) => (v < 0 ? 0 : v > 255 ? 255 : v);

  /* default (identity) state for all adjustments */
  const DEFAULTS = {
    brightness: 0,    // -100..100  (additive, scaled)
    contrast:   0,    // -100..100
    saturation: 0,    // -100..100
    hue:        0,    // -180..180 degrees
    gamma:      100,  // 10..300  (100 = 1.0, exposure/gamma)
    temperature:0,    // -100..100 (warm/cool)
    tint:       0,    // -100..100 (green/magenta)
    grayscale:  0,    // 0..100   (mix)
    sepia:      0,    // 0..100
    invert:     0,    // 0..100
    threshold:  0,    // 0..100   (0 = off, else level mix)
    vignette:   0,    // 0..100
    convolution:'none'// none|blurBox|blurGauss|sharpen|edge|emboss
  };

  function defaults() { return Object.assign({}, DEFAULTS); }

  /* ---- RGB <-> HSL helpers (operate on 0..255 in, 0..1 out) ---- */
  function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    let h = 0, s = 0; const l = (max + min) / 2;
    const dx = max - min;
    if (dx !== 0) {
      s = l > 0.5 ? dx / (2 - max - min) : dx / (max + min);
      if (max === r) h = ((g - b) / dx) % 6;
      else if (max === g) h = (b - r) / dx + 2;
      else h = (r - g) / dx + 4;
      h *= 60; if (h < 0) h += 360;
    }
    return [h, s, l];
  }
  function hue2rgb(p, q, t) {
    if (t < 0) t += 1; if (t > 1) t -= 1;
    if (t < 1 / 6) return p + (q - p) * 6 * t;
    if (t < 1 / 2) return q;
    if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
    return p;
  }
  function hslToRgb(h, s, l) {
    h /= 360;
    let r, g, b;
    if (s === 0) { r = g = b = l; }
    else {
      const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      const p = 2 * l - q;
      r = hue2rgb(p, q, h + 1 / 3);
      g = hue2rgb(p, q, h);
      b = hue2rgb(p, q, h - 1 / 3);
    }
    return [r * 255, g * 255, b * 255];
  }

  /* =========================================================
     POINT ADJUSTMENTS — applied in place to a pixel buffer.
     `a` is a state object (see DEFAULTS).
     ========================================================= */
  function applyAdjustments(data, a) {
    const bright = a.brightness * 1.28;                  // -128..128
    const cFactor = (259 * (a.contrast + 255)) / (255 * (259 - a.contrast)); // classic contrast
    const gamma = 100 / Math.max(10, a.gamma);           // inverse so >100 brightens
    const satMul = 1 + a.saturation / 100;               // 0..2
    const hueShift = a.hue;
    const needHsl = a.saturation !== 0 || a.hue !== 0;
    const tempR = a.temperature * 0.6;                   // push red up / blue down
    const tempB = -a.temperature * 0.6;
    const tintG = a.tint * 0.5;                           // green vs magenta
    const gray = a.grayscale / 100;
    const sepia = a.sepia / 100;
    const inv = a.invert / 100;
    const thr = a.threshold / 100;

    // precompute gamma LUT (0..255) — gamma is expensive per-pixel
    const gLut = new Uint8ClampedArray(256);
    for (let i = 0; i < 256; i++) gLut[i] = clamp(255 * Math.pow(i / 255, gamma));

    for (let i = 0; i < data.length; i += 4) {
      let r = data[i], g = data[i + 1], b = data[i + 2];

      // 1. exposure / gamma (via LUT)
      r = gLut[r]; g = gLut[g]; b = gLut[b];

      // 2. brightness (additive)
      r += bright; g += bright; b += bright;

      // 3. contrast (pivot around 128)
      r = cFactor * (r - 128) + 128;
      g = cFactor * (g - 128) + 128;
      b = cFactor * (b - 128) + 128;

      // 4. white-balance: temperature + tint
      r += tempR; b += tempB; g += tintG;

      r = clamp(r); g = clamp(g); b = clamp(b);

      // 5. saturation + hue rotation in HSL
      if (needHsl) {
        let [h, s, l] = rgbToHsl(r, g, b);
        h = (h + hueShift) % 360; if (h < 0) h += 360;
        s = Math.max(0, Math.min(1, s * satMul));
        const rgb = hslToRgb(h, s, l);
        r = rgb[0]; g = rgb[1]; b = rgb[2];
      }

      // 6. grayscale mix (luma-weighted)
      if (gray > 0) {
        const y = 0.299 * r + 0.587 * g + 0.114 * b;
        r += (y - r) * gray; g += (y - g) * gray; b += (y - b) * gray;
      }

      // 7. sepia mix
      if (sepia > 0) {
        const sr = 0.393 * r + 0.769 * g + 0.189 * b;
        const sg = 0.349 * r + 0.686 * g + 0.168 * b;
        const sb = 0.272 * r + 0.534 * g + 0.131 * b;
        r += (sr - r) * sepia; g += (sg - g) * sepia; b += (sb - b) * sepia;
      }

      // 8. invert mix
      if (inv > 0) {
        r += ((255 - r) - r) * inv;
        g += ((255 - g) - g) * inv;
        b += ((255 - b) - b) * inv;
      }

      // 9. threshold (binary on luma, mixable)
      if (thr > 0) {
        const y = 0.299 * r + 0.587 * g + 0.114 * b;
        const t = y >= 128 ? 255 : 0;
        r += (t - r) * thr; g += (t - g) * thr; b += (t - b) * thr;
      }

      data[i] = clamp(r); data[i + 1] = clamp(g); data[i + 2] = clamp(b);
      // alpha untouched
    }
  }

  /* vignette is applied separately because it needs geometry (w,h) */
  function applyVignette(data, w, h, amount) {
    if (amount <= 0) return;
    const k = amount / 100;
    const cx = w / 2, cy = h / 2;
    const maxD = Math.sqrt(cx * cx + cy * cy);
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const dx = x - cx, dy = y - cy;
        const d = Math.sqrt(dx * dx + dy * dy) / maxD; // 0 center .. 1 corner
        // smooth falloff, only darkens outer ~45%
        const f = 1 - k * Math.pow(Math.max(0, d - 0.55) / 0.45, 2);
        if (f < 1) {
          const i = (y * w + x) * 4;
          data[i] *= f; data[i + 1] *= f; data[i + 2] *= f;
        }
      }
    }
  }

  /* =========================================================
     CONVOLUTION — a correct separable-agnostic NxN kernel.
     Reads from a copy of the source so neighbours aren't polluted,
     CLAMPS edge coordinates (extend), and supports a post-bias for
     emboss/edge kernels. Returns a NEW buffer.
     ========================================================= */
  const KERNELS = {
    blurBox: {
      label: 'Box blur',
      k: [1, 1, 1, 1, 1, 1, 1, 1, 1], w: 3, divisor: 9, bias: 0
    },
    blurGauss: {
      label: 'Gaussian blur',
      k: [1, 2, 1, 2, 4, 2, 1, 2, 1], w: 3, divisor: 16, bias: 0
    },
    sharpen: {
      label: 'Sharpen',
      k: [0, -1, 0, -1, 5, -1, 0, -1, 0], w: 3, divisor: 1, bias: 0
    },
    emboss: {
      label: 'Emboss',
      k: [-2, -1, 0, -1, 1, 1, 0, 1, 2], w: 3, divisor: 1, bias: 128, gray: true
    }
  };

  function convolve(src, w, h, kernel) {
    const { k, w: kw, divisor, bias } = kernel;
    const half = (kw - 1) / 2;
    const out = new Uint8ClampedArray(src.length);
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        let r = 0, g = 0, b = 0;
        for (let ky = 0; ky < kw; ky++) {
          for (let kx = 0; kx < kw; kx++) {
            // clamp neighbour coordinates to the edge (extend)
            let sx = x + kx - half;
            let sy = y + ky - half;
            if (sx < 0) sx = 0; else if (sx >= w) sx = w - 1;
            if (sy < 0) sy = 0; else if (sy >= h) sy = h - 1;
            const si = (sy * w + sx) * 4;
            const kv = k[ky * kw + kx];
            r += src[si] * kv;
            g += src[si + 1] * kv;
            b += src[si + 2] * kv;
          }
        }
        const di = (y * w + x) * 4;
        let rr = r / divisor + bias;
        let gg = g / divisor + bias;
        let bb = b / divisor + bias;
        if (kernel.gray) { const v = clamp(rr); rr = gg = bb = v; }
        out[di] = clamp(rr);
        out[di + 1] = clamp(gg);
        out[di + 2] = clamp(bb);
        out[di + 3] = src[di + 3];
      }
    }
    return out;
  }

  /* Sobel edge-detect: combine horizontal + vertical gradients on luma. */
  function sobel(src, w, h) {
    const gxK = [-1, 0, 1, -2, 0, 2, -1, 0, 1];
    const gyK = [-1, -2, -1, 0, 0, 0, 1, 2, 1];
    // luma buffer first
    const lum = new Float32Array(w * h);
    for (let i = 0, p = 0; i < src.length; i += 4, p++) {
      lum[p] = 0.299 * src[i] + 0.587 * src[i + 1] + 0.114 * src[i + 2];
    }
    const out = new Uint8ClampedArray(src.length);
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        let gx = 0, gy = 0, idx = 0;
        for (let ky = -1; ky <= 1; ky++) {
          for (let kx = -1; kx <= 1; kx++, idx++) {
            let sx = x + kx, sy = y + ky;
            if (sx < 0) sx = 0; else if (sx >= w) sx = w - 1;
            if (sy < 0) sy = 0; else if (sy >= h) sy = h - 1;
            const v = lum[sy * w + sx];
            gx += v * gxK[idx];
            gy += v * gyK[idx];
          }
        }
        const mag = clamp(Math.sqrt(gx * gx + gy * gy));
        const di = (y * w + x) * 4;
        out[di] = out[di + 1] = out[di + 2] = mag;
        out[di + 3] = src[(y * w + x) * 4 + 3];
      }
    }
    return out;
  }

  function applyConvolution(data, w, h, type) {
    if (!type || type === 'none') return data;
    if (type === 'edge') return sobel(data, w, h);
    const kernel = KERNELS[type];
    if (!kernel) return data;
    return convolve(data, w, h, kernel);
  }

  /* =========================================================
     PRESETS — named "looks" that set a stack of adjustments.
     Each preset returns a full state object (merged on defaults).
     ========================================================= */
  const PRESETS = [
    { id: 'original', name: 'Original', state: {} },
    { id: 'vintage',  name: 'Vintage',
      state: { contrast: -12, saturation: -22, sepia: 38, temperature: 26, gamma: 112, vignette: 35 } },
    { id: 'noir',     name: 'Noir',
      state: { grayscale: 100, contrast: 34, brightness: -6, gamma: 92, vignette: 45 } },
    { id: 'cyanotype',name: 'Cyanotype',
      state: { grayscale: 100, temperature: -70, tint: -18, contrast: 16, brightness: 8 } },
    { id: 'lomo',     name: 'Lomo',
      state: { saturation: 46, contrast: 28, temperature: 14, vignette: 60, gamma: 96 } },
    { id: 'crisp',    name: 'Crisp Pop',
      state: { saturation: 26, contrast: 14, brightness: 4, convolution: 'sharpen' } },
    { id: 'dreamy',   name: 'Dream Haze',
      state: { brightness: 14, contrast: -16, saturation: 12, gamma: 118, convolution: 'blurGauss' } },
    { id: 'ink',      name: 'Inkline',
      state: { convolution: 'edge', invert: 100 } }
  ];

  function presetState(id) {
    const p = PRESETS.find(x => x.id === id);
    return Object.assign(defaults(), p ? p.state : {});
  }

  global.TBFilters = {
    defaults, applyAdjustments, applyVignette,
    applyConvolution, convolve, sobel,
    rgbToHsl, hslToRgb, clamp,
    KERNELS, PRESETS, presetState, DEFAULTS
  };
})(window);
