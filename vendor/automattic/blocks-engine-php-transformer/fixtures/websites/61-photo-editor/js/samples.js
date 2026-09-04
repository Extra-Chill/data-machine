/* =========================================================
   TONEBOX — samples.js
   Procedurally generates the built-in editable sample images.
   Everything is drawn on an offscreen canvas with gradients,
   shapes and value noise — NO remote images, ever. Each sample
   returns an HTMLCanvasElement that the editor treats exactly
   like an uploaded photo.
   ========================================================= */
(function (global) {
  'use strict';

  /* ---- tiny seeded PRNG (mulberry32) so samples are stable ---- */
  function rng(seed) {
    let s = seed >>> 0;
    return function () {
      s |= 0; s = (s + 0x6D2B79F5) | 0;
      let t = Math.imul(s ^ (s >>> 15), 1 | s);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  /* smooth value noise via bilinear interpolation over a lattice */
  function makeNoise(seed, cells) {
    const r = rng(seed);
    const grid = new Float32Array((cells + 1) * (cells + 1));
    for (let i = 0; i < grid.length; i++) grid[i] = r();
    return function (x, y) {
      const gx = x * cells, gy = y * cells;
      const x0 = Math.floor(gx), y0 = Math.floor(gy);
      const fx = gx - x0, fy = gy - y0;
      const sx = fx * fx * (3 - 2 * fx);
      const sy = fy * fy * (3 - 2 * fy);
      const i00 = grid[y0 * (cells + 1) + x0];
      const i10 = grid[y0 * (cells + 1) + x0 + 1];
      const i01 = grid[(y0 + 1) * (cells + 1) + x0];
      const i11 = grid[(y0 + 1) * (cells + 1) + x0 + 1];
      const top = i00 + (i10 - i00) * sx;
      const bot = i01 + (i11 - i01) * sx;
      return top + (bot - top) * sy;
    };
  }

  function fractalNoise(noises, x, y) {
    let v = 0, amp = 0.5, sum = 0;
    for (let i = 0; i < noises.length; i++) {
      v += noises[i](x, y) * amp;
      sum += amp; amp *= 0.5;
    }
    return v / sum;
  }

  function newCanvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    return c;
  }

  /* lerp two hex-ish rgb arrays */
  function mix(a, b, t) {
    return [
      a[0] + (b[0] - a[0]) * t,
      a[1] + (b[1] - a[1]) * t,
      a[2] + (b[2] - a[2]) * t
    ];
  }

  /* ========== Sample 1: "Cypress Coast" — a layered landscape ========== */
  function landscape(W, H) {
    const c = newCanvas(W, H), ctx = c.getContext('2d');
    // sky gradient
    const sky = ctx.createLinearGradient(0, 0, 0, H * 0.7);
    sky.addColorStop(0, '#fcd9a8');
    sky.addColorStop(0.45, '#f6a77c');
    sky.addColorStop(1, '#b96a8e');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, W, H);

    // sun glow
    const sx = W * 0.72, sy = H * 0.34, sr = W * 0.10;
    const glow = ctx.createRadialGradient(sx, sy, 0, sx, sy, sr * 4);
    glow.addColorStop(0, 'rgba(255,243,210,0.95)');
    glow.addColorStop(0.18, 'rgba(255,228,170,0.65)');
    glow.addColorStop(1, 'rgba(255,228,170,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#fff6df';
    ctx.beginPath(); ctx.arc(sx, sy, sr, 0, Math.PI * 2); ctx.fill();

    // soft cloud bands with noise
    const cn = makeNoise(91, 6);
    const img = ctx.getImageData(0, 0, W, H);
    const d = img.data;
    for (let y = 0; y < H * 0.55; y++) {
      for (let x = 0; x < W; x++) {
        const n = cn(x / W * 2.4, y / H * 1.4);
        if (n > 0.62 && y > H * 0.1) {
          const a = Math.min(1, (n - 0.62) * 2.6) * (1 - y / (H * 0.55)) * 0.5;
          const i = (y * W + x) * 4;
          d[i] = d[i] + (255 - d[i]) * a;
          d[i + 1] = d[i + 1] + (250 - d[i + 1]) * a;
          d[i + 2] = d[i + 2] + (245 - d[i + 2]) * a;
        }
      }
    }
    ctx.putImageData(img, 0, 0);

    // layered hills (back to front, getting darker/cooler)
    const noises = [makeNoise(11, 8), makeNoise(22, 16), makeNoise(33, 32)];
    const layers = [
      { base: H * 0.62, amp: H * 0.05, col: '#8a6f93' },
      { base: H * 0.70, amp: H * 0.07, col: '#5d5379' },
      { base: H * 0.80, amp: H * 0.09, col: '#36304f' },
      { base: H * 0.90, amp: H * 0.07, col: '#1c1930' }
    ];
    layers.forEach((L, li) => {
      ctx.fillStyle = L.col;
      ctx.beginPath();
      ctx.moveTo(0, H);
      for (let x = 0; x <= W; x += 4) {
        const n = fractalNoise(noises, x / W * (1.5 + li), li * 0.7);
        ctx.lineTo(x, L.base + (n - 0.5) * 2 * L.amp);
      }
      ctx.lineTo(W, H); ctx.closePath(); ctx.fill();
    });

    // water reflection sheen
    const wr = ctx.createLinearGradient(0, H * 0.9, 0, H);
    wr.addColorStop(0, 'rgba(255,200,150,0.10)');
    wr.addColorStop(1, 'rgba(255,200,150,0)');
    ctx.fillStyle = wr; ctx.fillRect(0, H * 0.9, W, H);

    // a couple of birds
    ctx.strokeStyle = 'rgba(30,25,45,0.7)';
    ctx.lineWidth = 2; ctx.lineCap = 'round';
    [[0.3, 0.22], [0.36, 0.26], [0.42, 0.20]].forEach(([px, py]) => {
      const bx = px * W, by = py * H, s = W * 0.018;
      ctx.beginPath();
      ctx.moveTo(bx - s, by); ctx.quadraticCurveTo(bx, by - s * 0.7, bx, by);
      ctx.quadraticCurveTo(bx, by - s * 0.7, bx + s, by);
      ctx.stroke();
    });
    return c;
  }

  /* ========== Sample 2: "Citrus Studio" — still-life on a table ========== */
  function stillLife(W, H) {
    const c = newCanvas(W, H), ctx = c.getContext('2d');
    // wall
    const wall = ctx.createLinearGradient(0, 0, W, H);
    wall.addColorStop(0, '#e9e2d4');
    wall.addColorStop(1, '#cfc4b0');
    ctx.fillStyle = wall; ctx.fillRect(0, 0, W, H);
    // window light wash
    const lw = ctx.createRadialGradient(W * 0.28, H * 0.2, 0, W * 0.28, H * 0.2, W * 0.8);
    lw.addColorStop(0, 'rgba(255,250,235,0.55)');
    lw.addColorStop(1, 'rgba(255,250,235,0)');
    ctx.fillStyle = lw; ctx.fillRect(0, 0, W, H);

    // table
    ctx.fillStyle = '#8a5a36';
    ctx.fillRect(0, H * 0.66, W, H * 0.34);
    const tg = ctx.createLinearGradient(0, H * 0.66, 0, H);
    tg.addColorStop(0, 'rgba(255,230,200,0.25)');
    tg.addColorStop(1, 'rgba(40,20,10,0.35)');
    ctx.fillStyle = tg; ctx.fillRect(0, H * 0.66, W, H * 0.34);
    // wood grain
    const wn = makeNoise(77, 40);
    ctx.globalAlpha = 0.12; ctx.strokeStyle = '#3a2414';
    for (let y = H * 0.68; y < H; y += 5) {
      ctx.beginPath();
      for (let x = 0; x <= W; x += 6) {
        const yy = y + (wn(x / W * 3, y / H) - 0.5) * 10;
        x === 0 ? ctx.moveTo(x, yy) : ctx.lineTo(x, yy);
      }
      ctx.stroke();
    }
    ctx.globalAlpha = 1;

    // a soft shadow ellipse under the fruit
    ctx.fillStyle = 'rgba(20,10,5,0.30)';
    ctx.beginPath();
    ctx.ellipse(W * 0.5, H * 0.74, W * 0.34, H * 0.05, 0, 0, Math.PI * 2);
    ctx.fill();

    // draw a few spheres (oranges + a lemon) with radial shading
    function sphere(cx, cy, r, light, dark, hi) {
      const g = ctx.createRadialGradient(cx - r * 0.35, cy - r * 0.4, r * 0.1, cx, cy, r);
      g.addColorStop(0, hi);
      g.addColorStop(0.4, light);
      g.addColorStop(1, dark);
      ctx.fillStyle = g;
      ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fill();
      // specular dot
      ctx.fillStyle = 'rgba(255,255,255,0.7)';
      ctx.beginPath();
      ctx.ellipse(cx - r * 0.32, cy - r * 0.36, r * 0.16, r * 0.10, -0.5, 0, Math.PI * 2);
      ctx.fill();
    }
    sphere(W * 0.36, H * 0.60, W * 0.10, '#f08a1d', '#9c4a06', '#ffc25e');
    sphere(W * 0.56, H * 0.62, W * 0.11, '#ef851a', '#8c3f04', '#ffbd55');
    sphere(W * 0.69, H * 0.58, W * 0.085, '#f4d22a', '#b08907', '#fff08a'); // lemon
    sphere(W * 0.46, H * 0.50, W * 0.075, '#f59324', '#9a4806', '#ffce6b');

    // a tall bottle on the left
    ctx.fillStyle = 'rgba(60,90,70,0.85)';
    const bx = W * 0.16, bw = W * 0.07;
    ctx.fillRect(bx, H * 0.30, bw, H * 0.38);
    ctx.fillRect(bx + bw * 0.32, H * 0.20, bw * 0.36, H * 0.12);
    const bgl = ctx.createLinearGradient(bx, 0, bx + bw, 0);
    bgl.addColorStop(0, 'rgba(255,255,255,0.05)');
    bgl.addColorStop(0.4, 'rgba(255,255,255,0.35)');
    bgl.addColorStop(0.5, 'rgba(255,255,255,0.05)');
    ctx.fillStyle = bgl;
    ctx.fillRect(bx, H * 0.20, bw, H * 0.48);
    return c;
  }

  /* ========== Sample 3: "Night Avenue" — neon city street ========== */
  function cityNight(W, H) {
    const c = newCanvas(W, H), ctx = c.getContext('2d');
    const sky = ctx.createLinearGradient(0, 0, 0, H);
    sky.addColorStop(0, '#0a0f2c');
    sky.addColorStop(0.5, '#1a1146');
    sky.addColorStop(1, '#37123f');
    ctx.fillStyle = sky; ctx.fillRect(0, 0, W, H);

    // stars
    const sr = rng(5);
    ctx.fillStyle = '#ffffff';
    for (let i = 0; i < 140; i++) {
      const x = sr() * W, y = sr() * H * 0.5, a = sr() * 0.7 + 0.1;
      ctx.globalAlpha = a;
      ctx.fillRect(x, y, sr() > 0.85 ? 2 : 1, 1);
    }
    ctx.globalAlpha = 1;

    // moon
    const mx = W * 0.8, my = H * 0.2, mr = W * 0.05;
    const mg = ctx.createRadialGradient(mx, my, 0, mx, my, mr * 3);
    mg.addColorStop(0, 'rgba(220,230,255,0.6)');
    mg.addColorStop(1, 'rgba(220,230,255,0)');
    ctx.fillStyle = mg; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#e8eeff';
    ctx.beginPath(); ctx.arc(mx, my, mr, 0, Math.PI * 2); ctx.fill();

    // buildings with lit windows, fading toward a vanishing point
    const br = rng(8);
    const skyline = [];
    let x = -20;
    while (x < W) {
      const bw = 30 + br() * 70;
      const bh = H * (0.25 + br() * 0.45);
      skyline.push({ x, bw, bh, hue: br() });
      x += bw + 6;
    }
    skyline.forEach(b => {
      const top = H * 0.62 - b.bh;
      const shade = mix([20, 16, 40], [50, 30, 70], b.hue);
      ctx.fillStyle = `rgb(${shade[0]|0},${shade[1]|0},${shade[2]|0})`;
      ctx.fillRect(b.x, top, b.bw, b.bh);
      // windows
      for (let wy = top + 8; wy < H * 0.62 - 6; wy += 12) {
        for (let wx = b.x + 5; wx < b.x + b.bw - 5; wx += 10) {
          if (br() > 0.55) {
            ctx.fillStyle = br() > 0.5 ? 'rgba(255,214,120,0.9)' : 'rgba(140,200,255,0.85)';
            ctx.fillRect(wx, wy, 5, 6);
          }
        }
      }
    });

    // wet street with neon reflections
    const street = ctx.createLinearGradient(0, H * 0.62, 0, H);
    street.addColorStop(0, '#0c0a1f');
    street.addColorStop(1, '#241a33');
    ctx.fillStyle = street; ctx.fillRect(0, H * 0.62, W, H * 0.38);
    // perspective center line
    ctx.strokeStyle = 'rgba(255,210,90,0.5)'; ctx.lineWidth = 3;
    ctx.setLineDash([14, 12]);
    ctx.beginPath(); ctx.moveTo(W * 0.5, H * 0.62); ctx.lineTo(W * 0.5, H); ctx.stroke();
    ctx.setLineDash([]);
    // neon sign
    ctx.shadowColor = '#ff3aa8'; ctx.shadowBlur = 18;
    ctx.strokeStyle = '#ff7ad0'; ctx.lineWidth = 4;
    ctx.strokeRect(W * 0.08, H * 0.40, W * 0.14, H * 0.08);
    ctx.shadowColor = '#35e1ff'; ctx.strokeStyle = '#8af0ff';
    ctx.beginPath(); ctx.arc(W * 0.30, H * 0.30, W * 0.035, 0, Math.PI * 2); ctx.stroke();
    ctx.shadowBlur = 0;
    return c;
  }

  /* ========== Sample 4: "Test Pattern" — high-detail chart ========== */
  function testChart(W, H) {
    const c = newCanvas(W, H), ctx = c.getContext('2d');
    ctx.fillStyle = '#202020'; ctx.fillRect(0, 0, W, H);
    // SMPTE-ish color bars
    const bars = ['#c0c0c0', '#c0c000', '#00c0c0', '#00c000', '#c000c0', '#c00000', '#0000c0'];
    const bw = W / bars.length;
    bars.forEach((col, i) => { ctx.fillStyle = col; ctx.fillRect(i * bw, 0, bw + 1, H * 0.55); });
    // grayscale ramp (true linear)
    for (let x = 0; x < W; x++) {
      const v = Math.round(x / W * 255);
      ctx.fillStyle = `rgb(${v},${v},${v})`;
      ctx.fillRect(x, H * 0.55, 1, H * 0.18);
    }
    // resolution gratings for sharpen/edge tests
    ctx.fillStyle = '#000'; ctx.fillRect(0, H * 0.73, W, H * 0.27);
    ctx.fillStyle = '#fff';
    let freq = 2;
    for (let bx = 0; bx < W; bx += W / 6) {
      for (let x = 0; x < W / 6; x += freq * 2) {
        ctx.fillRect(bx + x, H * 0.73, freq, H * 0.27);
      }
      freq++;
    }
    // center crosshair + circles
    ctx.strokeStyle = '#fff'; ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(W / 2, H * 0.55); ctx.lineTo(W / 2, H);
    ctx.moveTo(0, H * 0.64); ctx.lineTo(W, H * 0.64);
    ctx.stroke();
    return c;
  }

  /* ========== Sample 5: "Aurora Mesh" — colorful gradient + grain ====== */
  function auroraMesh(W, H) {
    const c = newCanvas(W, H), ctx = c.getContext('2d');
    const blobs = [
      { x: 0.2, y: 0.25, r: 0.6, col: [88, 28, 135] },
      { x: 0.8, y: 0.2, r: 0.55, col: [6, 95, 132] },
      { x: 0.75, y: 0.85, r: 0.6, col: [190, 24, 93] },
      { x: 0.15, y: 0.9, r: 0.5, col: [16, 122, 87] },
      { x: 0.5, y: 0.5, r: 0.4, col: [234, 88, 12] }
    ];
    ctx.fillStyle = '#0b0b16'; ctx.fillRect(0, 0, W, H);
    ctx.globalCompositeOperation = 'lighter';
    blobs.forEach(b => {
      const g = ctx.createRadialGradient(b.x * W, b.y * H, 0, b.x * W, b.y * H, b.r * W);
      g.addColorStop(0, `rgba(${b.col[0]},${b.col[1]},${b.col[2]},0.9)`);
      g.addColorStop(1, `rgba(${b.col[0]},${b.col[1]},${b.col[2]},0)`);
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    });
    ctx.globalCompositeOperation = 'source-over';
    // film grain so filters have texture to bite into
    const img = ctx.getImageData(0, 0, W, H), d = img.data, gr = rng(404);
    for (let i = 0; i < d.length; i += 4) {
      const n = (gr() - 0.5) * 22;
      d[i] = clamp8(d[i] + n);
      d[i + 1] = clamp8(d[i + 1] + n);
      d[i + 2] = clamp8(d[i + 2] + n);
    }
    ctx.putImageData(img, 0, 0);
    return c;
  }

  function clamp8(v) { return v < 0 ? 0 : v > 255 ? 255 : v; }

  /* Each sample is generated at a comfortable editing resolution. */
  const DEF_W = 960, DEF_H = 640;

  const SAMPLES = [
    { id: 'coast',  name: 'Cypress Coast', sub: 'Sunset landscape',  build: () => landscape(DEF_W, DEF_H) },
    { id: 'citrus', name: 'Citrus Studio', sub: 'Window-lit still life', build: () => stillLife(DEF_W, DEF_H) },
    { id: 'avenue', name: 'Night Avenue',  sub: 'Neon city street',   build: () => cityNight(DEF_W, DEF_H) },
    { id: 'aurora', name: 'Aurora Mesh',   sub: 'Gradient + film grain', build: () => auroraMesh(DEF_W, DEF_H) },
    { id: 'chart',  name: 'Test Pattern',  sub: 'Calibration chart',   build: () => testChart(DEF_W, DEF_H) }
  ];

  global.TBSamples = {
    list: SAMPLES,
    byId(id) { return SAMPLES.find(s => s.id === id); },
    build(id) { const s = this.byId(id); return s ? s.build() : null; }
  };
})(window);
