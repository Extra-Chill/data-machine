/* hero.js — ambient title-canvas for index.html.
   A calm dusk scene with drifting fireflies (little Pips), a parallax hill
   silhouette and a glowing goal lantern. Purely decorative; honours
   prefers-reduced-motion by freezing the drift and dimming twinkle. */
(function () {
  'use strict';
  const canvas = document.getElementById('hero-sim');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let W = 0, H = 0, dpr = 1;
  function resize() {
    const r = canvas.getBoundingClientRect();
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = r.width; H = r.height;
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    seed();
  }

  let flies = [], stars = [];
  function seed() {
    flies = [];
    const n = reduced ? 10 : 26;
    for (let i = 0; i < n; i++) {
      flies.push({
        x: Math.random() * W, y: Math.random() * H * 0.85,
        vx: (Math.random() - 0.5) * 14, vy: (Math.random() - 0.5) * 10,
        r: 2 + Math.random() * 2.5, ph: Math.random() * Math.PI * 2,
        hue: Math.random() < 0.2 ? 'cyan' : 'gold'
      });
    }
    stars = [];
    for (let i = 0; i < 70; i++) {
      stars.push({ x: Math.random() * W, y: Math.random() * H * 0.6, r: Math.random() * 1.3 + 0.3, tw: Math.random() * 6 });
    }
  }

  let t0 = performance.now();
  function frame(now) {
    requestAnimationFrame(frame);
    const t = now - t0;
    const dt = reduced ? 0 : 1 / 60;

    // sky
    const g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, '#0a0e1f');
    g.addColorStop(0.55, '#1b2350');
    g.addColorStop(1, '#10142b');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);

    // stars
    for (const s of stars) {
      const tw = reduced ? 0.7 : 0.5 + 0.5 * Math.sin(t * 0.002 + s.tw);
      ctx.globalAlpha = 0.4 + 0.5 * tw;
      ctx.fillStyle = '#cfe3ff';
      ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2); ctx.fill();
    }
    ctx.globalAlpha = 1;

    // moon
    const mg = ctx.createRadialGradient(W * 0.82, H * 0.24, 4, W * 0.82, H * 0.24, 70);
    mg.addColorStop(0, 'rgba(255,247,224,0.95)');
    mg.addColorStop(1, 'rgba(255,240,200,0)');
    ctx.fillStyle = mg;
    ctx.beginPath(); ctx.arc(W * 0.82, H * 0.24, 70, 0, Math.PI * 2); ctx.fill();

    // hills
    hill(H * 0.72, '#141d3a', 36, 0.006, 0);
    hill(H * 0.84, '#0e1530', 26, 0.009, 2);
    hill(H * 0.95, '#0a1024', 18, 0.013, 4);

    // goal lantern silhouette on a knoll
    const lx = W * 0.5, ly = H * 0.78;
    ctx.strokeStyle = '#2a2236'; ctx.lineWidth = 5;
    ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(lx, ly - 70); ctx.stroke();
    const pulse = reduced ? 1 : 1 + Math.sin(t * 0.004) * 0.12;
    const lg = ctx.createRadialGradient(lx, ly - 84, 2, lx, ly - 84, 70 * pulse);
    lg.addColorStop(0, 'rgba(255,210,120,0.9)');
    lg.addColorStop(1, 'rgba(255,170,90,0)');
    ctx.fillStyle = lg; ctx.beginPath(); ctx.arc(lx, ly - 84, 70 * pulse, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#ffd27a';
    rr(lx - 13, ly - 100, 26, 30, 6); ctx.fill();

    // fireflies
    for (const f of flies) {
      if (!reduced) {
        f.x += f.vx * dt; f.y += f.vy * dt;
        f.vx += (Math.random() - 0.5) * 4 * dt;
        f.vy += (Math.random() - 0.5) * 4 * dt;
        f.vx = clamp(f.vx, -22, 22); f.vy = clamp(f.vy, -16, 16);
        if (f.x < -10) f.x = W + 10; if (f.x > W + 10) f.x = -10;
        if (f.y < -10) f.y = H * 0.85; if (f.y > H * 0.9) f.y = 0;
      }
      const blink = reduced ? 0.8 : 0.55 + 0.45 * Math.sin(t * 0.006 + f.ph);
      const col = f.hue === 'cyan' ? '126,240,255' : '255,224,130';
      const gg = ctx.createRadialGradient(f.x, f.y, 0, f.x, f.y, f.r * 4);
      gg.addColorStop(0, `rgba(${col},${0.9 * blink})`);
      gg.addColorStop(1, `rgba(${col},0)`);
      ctx.fillStyle = gg;
      ctx.beginPath(); ctx.arc(f.x, f.y, f.r * 4, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = `rgba(${col},${blink})`;
      ctx.beginPath(); ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2); ctx.fill();
    }
  }

  function hill(baseY, color, amp, freq, seedV) {
    ctx.fillStyle = color;
    ctx.beginPath(); ctx.moveTo(0, H);
    for (let x = 0; x <= W; x += 8) {
      const y = baseY + Math.sin(x * freq + seedV) * amp + Math.sin(x * freq * 2.2 + seedV) * amp * 0.4;
      ctx.lineTo(x, y);
    }
    ctx.lineTo(W, H); ctx.closePath(); ctx.fill();
  }
  function rr(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }
  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(frame);
})();
