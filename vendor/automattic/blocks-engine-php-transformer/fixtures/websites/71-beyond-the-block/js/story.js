/* ============================================================
   BEYOND THE BLOCK — story.js
   Scroll-choreographed sequence. A sticky canvas behind the
   steps reacts to scroll progress: the "scene" morphs as you
   read. Try doing THAT with a stack of paragraph blocks.
   ============================================================ */
(function () {
  'use strict';
  const B = window.BTB;
  if (!B) return;
  const REDUCED = B.reduced, TAU = B.TAU, clamp = B.clamp, lerp = B.lerp;

  document.addEventListener('DOMContentLoaded', () => {
    const story = document.querySelector('.story');
    if (!story) return;
    const canvas = document.getElementById('story-canvas');
    const steps = [...story.querySelectorAll('.story-step')];
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H, dpr;
    let progress = 0;      // 0..1 over the whole story
    let target = 0;

    function reset() { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; }
    reset(); window.addEventListener('resize', reset);

    /* compute scroll progress through the .story block + activate steps */
    function onScroll() {
      const rect = story.getBoundingClientRect();
      const total = rect.height - window.innerHeight;
      const passed = clamp(-rect.top / (total || 1), 0, 1);
      target = passed;

      const mid = window.innerHeight * 0.5;
      let best = 0, bestD = Infinity;
      steps.forEach((s, i) => {
        const r = s.getBoundingClientRect();
        const c = r.top + r.height / 2;
        const d = Math.abs(c - mid);
        if (d < bestD) { bestD = d; best = i; }
      });
      steps.forEach((s, i) => s.classList.toggle('active', i === best));
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* the scene: a constellation that assembles, rotates, and
       resolves into a wireframe "globe" as you scroll */
    const N = REDUCED ? 60 : 150;
    const nodes = [];
    const rnd = B.rng(424242);
    for (let i = 0; i < N; i++) {
      const theta = Math.acos(2 * rnd() - 1), phi = rnd() * TAU;
      nodes.push({
        theta, phi,
        sx: (rnd() - 0.5) * 3, sy: (rnd() - 0.5) * 3, sz: (rnd() - 0.5) * 3, // scattered start
        hue: 180 + rnd() * 160
      });
    }

    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      const cx = W / 2, cy = H / 2;
      const R = Math.min(W, H) * 0.34;
      const p = progress;
      const rot = t * 0.15 + p * 6;            // spins faster as you scroll
      const assemble = clamp((p - 0.05) * 1.6, 0, 1); // 0 = scattered, 1 = sphere
      const proj = [];

      for (const n of nodes) {
        // target sphere position
        const tx = Math.sin(n.theta) * Math.cos(n.phi + rot);
        const ty = Math.cos(n.theta);
        const tz = Math.sin(n.theta) * Math.sin(n.phi + rot);
        // interpolate from scattered start to sphere
        const x = lerp(n.sx, tx, assemble);
        const y = lerp(n.sy, ty, assemble);
        const z = lerp(n.sz, tz, assemble);
        const persp = 2.2 / (2.2 + z);
        const px = cx + x * R * persp;
        const py = cy + y * R * persp;
        proj.push({ px, py, z, hue: n.hue, persp });
      }

      // links between near nodes (only when fairly assembled, for perf)
      if (assemble > 0.35) {
        ctx.lineWidth = 0.6;
        const maxLink = R * 0.42;
        for (let i = 0; i < proj.length; i++) {
          for (let j = i + 1; j < proj.length; j++) {
            const dx = proj[i].px - proj[j].px, dy = proj[i].py - proj[j].py;
            const d = Math.hypot(dx, dy);
            if (d < maxLink) {
              ctx.strokeStyle = `hsla(${proj[i].hue},90%,65%,${(1 - d / maxLink) * 0.22 * assemble})`;
              ctx.beginPath(); ctx.moveTo(proj[i].px, proj[i].py); ctx.lineTo(proj[j].px, proj[j].py); ctx.stroke();
            }
          }
        }
      }
      // nodes
      for (const q of proj) {
        const r = 1.4 + q.persp * 2.4;
        ctx.fillStyle = `hsla(${q.hue},95%,${55 + q.z * 18}%,${0.4 + q.persp * 0.5})`;
        ctx.beginPath(); ctx.arc(q.px, q.py, r, 0, TAU); ctx.fill();
      }

      // progress dial
      ctx.strokeStyle = 'rgba(198,255,58,0.85)'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.arc(W - 46, 46, 18, -Math.PI / 2, -Math.PI / 2 + p * TAU); ctx.stroke();
      ctx.fillStyle = 'rgba(255,255,255,0.55)';
      ctx.font = "600 10px 'JetBrains Mono', monospace"; ctx.textAlign = 'center';
      ctx.fillText(Math.round(p * 100) + '%', W - 46, 50);
    }

    if (REDUCED) { progress = 0.7; draw(2); window.addEventListener('scroll', () => { progress = target; draw(2); }, { passive: true }); return; }
    B.onScreenLoop(canvas, (dt, t) => { progress = lerp(progress, target, 0.08); draw(t); });
  });
})();
