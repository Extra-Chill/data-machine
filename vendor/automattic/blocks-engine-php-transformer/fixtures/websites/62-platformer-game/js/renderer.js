/* renderer.js — all drawing for Lumen Leap, done procedurally on a 2D canvas.
   Nothing here uses image files: tiles, sprites, the firefly hero "Pip",
   beetles, spikes, lanterns, the parallax dusk sky and particles are all drawn
   with paths and gradients. The renderer is camera-aware (world -> screen). */
(function () {
  'use strict';

  function Renderer(canvas) {
    const ctx = canvas.getContext('2d');
    let stars = null;
    // Logical viewport size (CSS px). play.js sets viewW/viewH after DPR scaling;
    // fall back to the raw attributes when run without that shim.
    const view = {
      get w() { return canvas.viewW || canvas.width; },
      get h() { return canvas.viewH || canvas.height; }
    };

    function buildStars(w, h) {
      stars = [];
      for (let i = 0; i < 90; i++) {
        stars.push({
          x: Math.random(), y: Math.random() * 0.7,
          r: Math.random() * 1.4 + 0.3,
          tw: Math.random() * Math.PI * 2
        });
      }
    }

    /* ── Parallax dusk background ── */
    function drawBackground(cam, level, t, reduced) {
      const w = view.w, h = view.h;
      if (!stars) buildStars(w, h);

      // sky gradient, tinted per level
      const g = ctx.createLinearGradient(0, 0, 0, h);
      const tint = level.tint || '#1b2a4a';
      g.addColorStop(0, '#0a0e1f');
      g.addColorStop(0.55, tint);
      g.addColorStop(1, '#06121a');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, w, h);

      // twinkling stars (far)
      const px = reduced ? 0 : cam.x * 0.04;
      ctx.save();
      for (const s of stars) {
        const sx = (s.x * w - px) % w;
        const x = sx < 0 ? sx + w : sx;
        const tw = reduced ? 0.7 : 0.5 + 0.5 * Math.sin(t * 0.002 + s.tw);
        ctx.globalAlpha = 0.5 + 0.5 * tw;
        ctx.fillStyle = '#cfe3ff';
        ctx.beginPath();
        ctx.arc(x, s.y * h, s.r, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.restore();

      // a big soft moon
      const moonX = w * 0.78 - (reduced ? 0 : cam.x * 0.03);
      const mg = ctx.createRadialGradient(moonX, h * 0.22, 4, moonX, h * 0.22, 60);
      mg.addColorStop(0, 'rgba(255,247,224,0.95)');
      mg.addColorStop(0.7, 'rgba(255,240,200,0.35)');
      mg.addColorStop(1, 'rgba(255,240,200,0)');
      ctx.fillStyle = mg;
      ctx.beginPath();
      ctx.arc(moonX, h * 0.22, 60, 0, Math.PI * 2);
      ctx.fill();

      // distant rolling hills (3 parallax layers)
      drawHills(cam.x * 0.12, h * 0.62, h, '#13203a', 120, 0.0008, reduced);
      drawHills(cam.x * 0.28, h * 0.74, h, '#101a2e', 90, 0.0013, reduced, 1.7);
      drawHills(cam.x * 0.5, h * 0.86, h, '#0b1322', 70, 0.0019, reduced, 3.1);
    }

    function drawHills(offset, baseY, h, color, amp, freq, reduced, seed) {
      const w = view.w;
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.moveTo(0, h);
      for (let x = 0; x <= w; x += 8) {
        const wx = x + offset;
        const y = baseY + Math.sin(wx * freq + (seed || 0)) * amp * 0.5
                       + Math.sin(wx * freq * 2.3 + (seed || 0)) * amp * 0.25;
        ctx.lineTo(x, y);
      }
      ctx.lineTo(w, h);
      ctx.closePath();
      ctx.fill();
    }

    /* ── Tilemap ── */
    function drawLevel(cam, level, t) {
      const T = level.tile;
      const x0 = Math.max(0, Math.floor(cam.x / T) - 1);
      const y0 = Math.max(0, Math.floor(cam.y / T) - 1);
      const x1 = Math.min(level.cols, Math.ceil((cam.x + view.w) / T) + 1);
      const y1 = Math.min(level.rows, Math.ceil((cam.y + view.h) / T) + 1);

      // decorative tufts behind tiles
      for (const d of level.decor) {
        const sx = d.x - cam.x, sy = d.y - cam.y;
        if (sx < -T || sx > view.w || sy < -T || sy > view.h) continue;
        drawDecor(sx, sy, T, d.type, t);
      }

      for (let y = y0; y < y1; y++) {
        for (let x = x0; x < x1; x++) {
          const s = level.solids[y][x];
          if (!s) continue;
          const sx = x * T - cam.x, sy = y * T - cam.y;
          if (s === 1) drawSolid(sx, sy, T, level.solids, x, y);
          else if (s === 2) drawPlatform(sx, sy, T);
        }
      }
    }

    function drawSolid(x, y, T, solids, gx, gy) {
      const top = !(solids[gy - 1] && solids[gy - 1][gx]);
      // earthy block
      ctx.fillStyle = '#2c3344';
      ctx.fillRect(x, y, T, T);
      ctx.fillStyle = '#242a39';
      ctx.fillRect(x + 2, y + 2, T - 4, T - 4);
      // subtle inner speckle
      ctx.fillStyle = 'rgba(255,255,255,0.04)';
      ctx.fillRect(x + 5, y + 6, 3, 3);
      ctx.fillRect(x + T - 10, y + T - 12, 4, 4);
      if (top) {
        // glowing moss cap on exposed top edges
        const g = ctx.createLinearGradient(0, y, 0, y + 8);
        g.addColorStop(0, '#5be0a0');
        g.addColorStop(1, '#2c8f63');
        ctx.fillStyle = g;
        ctx.fillRect(x, y, T, 6);
        ctx.fillStyle = 'rgba(180,255,220,0.5)';
        for (let i = 2; i < T; i += 7) ctx.fillRect(x + i, y - 2, 2, 4);
      }
    }

    function drawPlatform(x, y, T) {
      const g = ctx.createLinearGradient(0, y, 0, y + 10);
      g.addColorStop(0, '#8a7bff');
      g.addColorStop(1, '#4b3fa0');
      ctx.fillStyle = g;
      roundRect(x + 1, y + 2, T - 2, 9, 4);
      ctx.fill();
      ctx.fillStyle = 'rgba(220,210,255,0.6)';
      ctx.fillRect(x + 3, y + 3, T - 6, 1.5);
    }

    function drawDecor(x, y, T, type, t) {
      if (type === 'bark') {
        ctx.fillStyle = '#3a2c22';
        ctx.fillRect(x, y, T, T);
        return;
      }
      const base = y + T;
      ctx.strokeStyle = '#3aa06a';
      ctx.lineWidth = 2;
      const blades = type === 'grass' ? 4 : 2;
      for (let i = 0; i < blades; i++) {
        const bx = x + 6 + i * 7;
        const sway = Math.sin(t * 0.003 + bx) * 2;
        ctx.beginPath();
        ctx.moveTo(bx, base);
        ctx.quadraticCurveTo(bx + sway, base - 9, bx + sway * 1.6, base - 16);
        ctx.stroke();
      }
    }

    /* ── Collectibles ── */
    function drawLumen(x, y, t, cam) {
      const sx = x - cam.x, sy = y - cam.y + Math.sin(t * 0.004 + x) * 3;
      const r = 7;
      const glow = ctx.createRadialGradient(sx, sy, 1, sx, sy, r * 2.4);
      glow.addColorStop(0, 'rgba(255,224,130,0.9)');
      glow.addColorStop(1, 'rgba(255,224,130,0)');
      ctx.fillStyle = glow;
      ctx.beginPath(); ctx.arc(sx, sy, r * 2.4, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#ffe27a';
      ctx.beginPath(); ctx.arc(sx, sy, r, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#fff6cf';
      ctx.beginPath(); ctx.arc(sx - 2, sy - 2, r * 0.4, 0, Math.PI * 2); ctx.fill();
    }

    function drawGem(x, y, t, cam) {
      const sx = x - cam.x, sy = y - cam.y + Math.sin(t * 0.003 + x) * 4;
      const s = 9 + Math.sin(t * 0.006) * 0.6;
      const glow = ctx.createRadialGradient(sx, sy, 1, sx, sy, s * 2.6);
      glow.addColorStop(0, 'rgba(120,240,255,0.8)');
      glow.addColorStop(1, 'rgba(120,240,255,0)');
      ctx.fillStyle = glow;
      ctx.beginPath(); ctx.arc(sx, sy, s * 2.6, 0, Math.PI * 2); ctx.fill();
      ctx.save();
      ctx.translate(sx, sy);
      ctx.fillStyle = '#7ef0ff';
      ctx.beginPath();
      ctx.moveTo(0, -s); ctx.lineTo(s * 0.8, -s * 0.2);
      ctx.lineTo(0, s); ctx.lineTo(-s * 0.8, -s * 0.2);
      ctx.closePath(); ctx.fill();
      ctx.fillStyle = 'rgba(255,255,255,0.7)';
      ctx.beginPath();
      ctx.moveTo(0, -s); ctx.lineTo(s * 0.35, -s * 0.2);
      ctx.lineTo(0, 0); ctx.lineTo(-s * 0.35, -s * 0.2);
      ctx.closePath(); ctx.fill();
      ctx.restore();
    }

    function drawCheckpoint(c, t, cam) {
      const sx = c.x - cam.x, sy = c.y - cam.y;
      // post
      ctx.fillStyle = '#5a4632';
      ctx.fillRect(sx - 2, sy - 30, 4, 30);
      // lantern
      const lit = c.reached;
      const r = 8;
      if (lit) {
        const glow = ctx.createRadialGradient(sx, sy - 36, 1, sx, sy - 36, r * 3);
        glow.addColorStop(0, 'rgba(120,255,200,0.9)');
        glow.addColorStop(1, 'rgba(120,255,200,0)');
        ctx.fillStyle = glow;
        ctx.beginPath(); ctx.arc(sx, sy - 36, r * 3, 0, Math.PI * 2); ctx.fill();
      }
      ctx.fillStyle = lit ? '#7dffc8' : '#3a4a55';
      roundRect(sx - 7, sy - 44, 14, 16, 3); ctx.fill();
      ctx.strokeStyle = '#22303a'; ctx.lineWidth = 2;
      ctx.strokeRect(sx - 7, sy - 44, 14, 16);
    }

    function drawGoal(g, t, cam) {
      const sx = g.x - cam.x + 16, sy = g.y - cam.y + 32;
      // tall lantern pole
      ctx.fillStyle = '#4a3a28';
      ctx.fillRect(sx - 3, sy - 70, 6, 70);
      // big glowing lantern
      const pulse = 1 + Math.sin(t * 0.005) * 0.12;
      const cy = sy - 78;
      const glow = ctx.createRadialGradient(sx, cy, 2, sx, cy, 46 * pulse);
      glow.addColorStop(0, 'rgba(255,210,120,0.95)');
      glow.addColorStop(0.6, 'rgba(255,170,90,0.4)');
      glow.addColorStop(1, 'rgba(255,170,90,0)');
      ctx.fillStyle = glow;
      ctx.beginPath(); ctx.arc(sx, cy, 46 * pulse, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#ffd27a';
      roundRect(sx - 13, cy - 16, 26, 30, 6); ctx.fill();
      ctx.strokeStyle = '#7a5a30'; ctx.lineWidth = 3;
      ctx.strokeRect(sx - 13, cy - 16, 26, 30);
      ctx.fillStyle = '#fff4d0';
      ctx.beginPath(); ctx.arc(sx - 3, cy - 4, 5, 0, Math.PI * 2); ctx.fill();
    }

    /* ── Spikes ── */
    function drawSpike(s, T, cam) {
      const sx = s.x - cam.x, sy = s.y - cam.y;
      ctx.fillStyle = '#b9c2d0';
      const n = 4, w = T / n;
      ctx.beginPath();
      for (let i = 0; i < n; i++) {
        if (s.dir === 'up') {
          ctx.moveTo(sx + i * w, sy + T);
          ctx.lineTo(sx + i * w + w / 2, sy + T - 16);
          ctx.lineTo(sx + (i + 1) * w, sy + T);
        } else {
          ctx.moveTo(sx + i * w, sy);
          ctx.lineTo(sx + i * w + w / 2, sy + 16);
          ctx.lineTo(sx + (i + 1) * w, sy);
        }
      }
      ctx.fill();
      ctx.fillStyle = '#7d8696';
      ctx.fillRect(sx, s.dir === 'up' ? sy + T - 3 : sy, T, 3);
    }

    /* ── Moving platform ── */
    function drawMover(m, cam) {
      const sx = m.x - cam.x, sy = m.y - cam.y;
      const g = ctx.createLinearGradient(0, sy, 0, sy + m.h);
      g.addColorStop(0, '#9b8bff');
      g.addColorStop(1, '#5044a8');
      ctx.fillStyle = g;
      roundRect(sx, sy, m.w, m.h, 5); ctx.fill();
      ctx.fillStyle = 'rgba(230,222,255,0.7)';
      ctx.fillRect(sx + 4, sy + 3, m.w - 8, 2);
      // little rune dots
      ctx.fillStyle = 'rgba(255,240,180,0.8)';
      for (let i = 8; i < m.w - 6; i += 16) {
        ctx.beginPath(); ctx.arc(sx + i, sy + m.h / 2, 1.6, 0, Math.PI * 2); ctx.fill();
      }
    }

    /* ── Beetle enemy ── */
    function drawBeetle(b, cam, t) {
      if (b.dead) return;
      const sx = b.x - cam.x, sy = b.y - cam.y;
      const cx = sx + b.w / 2, cy = sy + b.h / 2;
      const legPhase = Math.sin(t * 0.02) * 2;
      // legs
      ctx.strokeStyle = '#20140f'; ctx.lineWidth = 2;
      for (let i = -1; i <= 1; i++) {
        ctx.beginPath();
        ctx.moveTo(cx + i * 7, cy + 4);
        ctx.lineTo(cx + i * 7 + legPhase * (i || 1), cy + b.h / 2 + 4);
        ctx.stroke();
      }
      // body
      ctx.fillStyle = '#7a3f2e';
      ctx.beginPath();
      ctx.ellipse(cx, cy, b.w / 2, b.h / 2, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = '#a85a3f';
      ctx.beginPath();
      ctx.ellipse(cx, cy - 2, b.w / 2 - 2, b.h / 2 - 3, 0, 0, Math.PI * 2);
      ctx.fill();
      // shell split line
      ctx.strokeStyle = '#3a201a'; ctx.lineWidth = 1.5;
      ctx.beginPath(); ctx.moveTo(cx, cy - b.h / 2 + 2); ctx.lineTo(cx, cy + b.h / 2 - 2); ctx.stroke();
      // grumpy eyes facing travel direction
      const dir = b.vx >= 0 ? 1 : -1;
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc(cx + dir * 5, cy - 3, 2.6, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#000';
      ctx.beginPath(); ctx.arc(cx + dir * 6, cy - 3, 1.3, 0, Math.PI * 2); ctx.fill();
    }

    /* ── Pip, the firefly hero ── */
    function drawPlayer(p, cam, t) {
      const sx = p.x - cam.x + p.w / 2;
      const sy = p.y - cam.y + p.h / 2;
      const face = p.facing;

      // squash/stretch from vertical velocity
      const stretch = clamp(1 + p.vy * 0.012, 0.78, 1.25);
      const squash = 1 / stretch;

      // ambient glow (Pip is a living lantern)
      const flick = 1 + Math.sin(t * 0.02) * 0.06;
      const glow = ctx.createRadialGradient(sx, sy, 2, sx, sy, 30 * flick);
      glow.addColorStop(0, p.invuln > 0 && Math.floor(t / 60) % 2 ? 'rgba(255,120,120,0.7)' : 'rgba(255,236,160,0.8)');
      glow.addColorStop(1, 'rgba(255,236,160,0)');
      ctx.fillStyle = glow;
      ctx.beginPath(); ctx.arc(sx, sy, 30 * flick, 0, Math.PI * 2); ctx.fill();

      ctx.save();
      ctx.translate(sx, sy);
      ctx.scale(face, 1);

      // body
      ctx.scale(squash, stretch);
      const blink = (Math.floor(t / 1400) % 6 === 0) ? 0.15 : 1;
      ctx.fillStyle = '#ffd15a';
      ctx.beginPath();
      ctx.ellipse(0, 0, p.w / 2, p.h / 2, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = '#ffe79a';
      ctx.beginPath();
      ctx.ellipse(-2, -2, p.w / 2 - 3, p.h / 2 - 4, 0, 0, Math.PI * 2);
      ctx.fill();

      // little antennae
      ctx.strokeStyle = '#caa23a'; ctx.lineWidth = 1.6;
      ctx.beginPath();
      ctx.moveTo(2, -p.h / 2 + 2);
      ctx.quadraticCurveTo(7, -p.h / 2 - 6, 9, -p.h / 2 - 9);
      ctx.stroke();
      ctx.fillStyle = '#fff0a0';
      ctx.beginPath(); ctx.arc(9, -p.h / 2 - 9, 2, 0, Math.PI * 2); ctx.fill();

      // eyes
      ctx.fillStyle = '#2a2010';
      ctx.beginPath(); ctx.ellipse(4, -2, 2.4, 3 * blink, 0, 0, Math.PI * 2); ctx.fill();
      ctx.beginPath(); ctx.ellipse(-3, -2, 2.2, 2.6 * blink, 0, 0, Math.PI * 2); ctx.fill();
      // smile
      ctx.strokeStyle = '#2a2010'; ctx.lineWidth = 1.4;
      ctx.beginPath(); ctx.arc(1, 3, 3.5, 0.1 * Math.PI, 0.9 * Math.PI); ctx.stroke();

      ctx.restore();

      // wings (drawn unscaled, flutter when airborne)
      if (!p.grounded) {
        const flap = Math.sin(t * 0.06) * 0.5 + 0.5;
        ctx.save();
        ctx.translate(sx, sy - 2);
        ctx.globalAlpha = 0.5;
        ctx.fillStyle = 'rgba(200,230,255,0.7)';
        for (const dir of [-1, 1]) {
          ctx.beginPath();
          ctx.ellipse(dir * (8 + flap * 3), -4, 6, 11 * (0.6 + flap * 0.4), dir * 0.5, 0, Math.PI * 2);
          ctx.fill();
        }
        ctx.restore();
      }
    }

    /* ── Particles ── */
    function drawParticles(parts, cam) {
      for (const pt of parts) {
        const sx = pt.x - cam.x, sy = pt.y - cam.y;
        ctx.globalAlpha = clamp(pt.life / pt.max, 0, 1);
        ctx.fillStyle = pt.color;
        if (pt.shape === 'spark') {
          ctx.fillRect(sx - pt.r / 2, sy - pt.r / 2, pt.r, pt.r);
        } else {
          ctx.beginPath(); ctx.arc(sx, sy, pt.r, 0, Math.PI * 2); ctx.fill();
        }
      }
      ctx.globalAlpha = 1;
    }

    function clear() {
      ctx.clearRect(0, 0, view.w, view.h);
    }

    function roundRect(x, y, w, h, r) {
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }
    function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }

    return {
      ctx,
      clear, drawBackground, drawLevel, drawLumen, drawGem, drawCheckpoint,
      drawGoal, drawSpike, drawMover, drawBeetle, drawPlayer, drawParticles
    };
  }

  window.LumenRenderer = Renderer;
})();
