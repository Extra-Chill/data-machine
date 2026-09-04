/* =========================================================
   AuroraOS 88 — animated wallpaper + login backdrop
   Synthwave: drifting stars, scanning aurora ribbons.
   Respects prefers-reduced-motion (static frame).
   ========================================================= */
'use strict';

AOS.startWallpaper = function (canvas) {
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let w, h, dpr, raf = 0, t = 0;
  let stars = [];

  function size() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = canvas.clientWidth; h = canvas.clientHeight;
    canvas.width = Math.max(1, w * dpr);
    canvas.height = Math.max(1, h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    seed();
  }

  function seed() {
    const r = AOS.rng(0x20880088);
    stars = [];
    const n = Math.round((w * h) / 7000);
    for (let i = 0; i < n; i++) {
      stars.push({
        x: r() * w, y: r() * h * 0.62,
        z: 0.3 + r() * 0.9,
        tw: r() * Math.PI * 2
      });
    }
  }

  function ribbon(yBase, hue, amp, speed, phase) {
    ctx.beginPath();
    for (let x = -20; x <= w + 20; x += 10) {
      const y = yBase
        + Math.sin(x * 0.006 + t * speed + phase) * amp
        + Math.sin(x * 0.013 - t * speed * 0.6) * amp * 0.4;
      x === -20 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    }
    const g = ctx.createLinearGradient(0, yBase - amp, 0, yBase + amp);
    g.addColorStop(0, `hsla(${hue},100%,68%,0)`);
    g.addColorStop(0.5, `hsla(${hue},100%,68%,0.5)`);
    g.addColorStop(1, `hsla(${hue},100%,68%,0)`);
    ctx.strokeStyle = g;
    ctx.lineWidth = 2.2;
    ctx.shadowBlur = 18; ctx.shadowColor = `hsla(${hue},100%,60%,.7)`;
    ctx.stroke();
    ctx.shadowBlur = 0;
  }

  function frame(now) {
    t = now / 1000;
    ctx.clearRect(0, 0, w, h);

    // stars
    for (const s of stars) {
      const flick = 0.55 + 0.45 * Math.sin(t * 1.5 * s.z + s.tw);
      ctx.fillStyle = `rgba(234,252,255,${0.25 + 0.55 * flick * s.z})`;
      const r = s.z * 1.3;
      ctx.fillRect(s.x, s.y, r, r);
      if (!AOS.REDUCED) { s.y += s.z * 0.05; if (s.y > h * 0.62) s.y = 0; }
    }

    // aurora ribbons across upper sky
    ribbon(h * 0.22, 305, 26, 0.25, 0);          // magenta
    ribbon(h * 0.30, 190, 20, 0.32, 1.5);        // cyan
    ribbon(h * 0.16, 270, 16, 0.20, 3.0);        // violet

    if (!AOS.REDUCED) raf = requestAnimationFrame(frame);
  }

  size();
  window.addEventListener('resize', size);
  if (AOS.REDUCED) { t = 4; frame(0); }
  else raf = requestAnimationFrame(frame);

  return { stop() { cancelAnimationFrame(raf); window.removeEventListener('resize', size); } };
};
