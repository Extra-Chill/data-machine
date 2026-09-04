/* =========================================================
   TUMBLE LAB — Renderer
   Draws the world to a canvas. Handles HiDPI scaling, velocity
   colouring, motion trails, constraint lines, and box fills.
   ========================================================= */

(function (global) {
  'use strict';

  class Renderer {
    constructor(canvas) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.dpr = 1;
      this.trails = true;
      this.colorByVelocity = true;
      this.showConstraints = true;
      this.W = 0; this.H = 0;
    }

    resize(world) {
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      this.dpr = dpr;
      const rect = this.canvas.getBoundingClientRect();
      this.W = Math.max(320, Math.floor(rect.width));
      this.H = Math.max(240, Math.floor(rect.height));
      this.canvas.width = this.W * dpr;
      this.canvas.height = this.H * dpr;
      this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      if (world) { world.width = this.W; world.height = this.H; }
    }

    velColor(p) {
      const sp = Math.hypot(p.vx, p.vy);
      const t = Math.min(1, sp / 10);
      // cool -> warm
      const h = 200 - t * 200;
      const l = 50 + t * 18;
      return `hsl(${h}, 85%, ${l}%)`;
    }

    clear(fade) {
      const ctx = this.ctx;
      if (this.trails && fade) {
        ctx.fillStyle = 'rgba(8, 10, 18, 0.22)';
        ctx.fillRect(0, 0, this.W, this.H);
      } else {
        ctx.clearRect(0, 0, this.W, this.H);
        ctx.fillStyle = '#080a12';
        ctx.fillRect(0, 0, this.W, this.H);
      }
    }

    drawGrid() {
      const ctx = this.ctx;
      ctx.save();
      ctx.strokeStyle = 'rgba(255,255,255,0.03)';
      ctx.lineWidth = 1;
      const step = 48;
      ctx.beginPath();
      for (let x = 0; x < this.W; x += step) { ctx.moveTo(x, 0); ctx.lineTo(x, this.H); }
      for (let y = 0; y < this.H; y += step) { ctx.moveTo(0, y); ctx.lineTo(this.W, y); }
      ctx.stroke();
      ctx.restore();
    }

    render(world, opts = {}) {
      const ctx = this.ctx;
      this.clear(opts.fade);
      if (!this.trails || !opts.fade) this.drawGrid();

      // group boxes for fill
      const boxes = new Map();
      for (const p of world.particles) {
        if (p.kind === 'box' && p.group) {
          let arr = boxes.get(p.group);
          if (!arr) { arr = []; boxes.set(p.group, arr); }
          arr.push(p);
        }
      }
      // fill box quads
      ctx.save();
      for (const [, corners] of boxes) {
        if (corners.length !== 4) continue;
        ctx.beginPath();
        ctx.moveTo(corners[0].x, corners[0].y);
        for (let i = 1; i < 4; i++) ctx.lineTo(corners[i].x, corners[i].y);
        ctx.closePath();
        ctx.fillStyle = corners[0].color + 'cc';
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = 'rgba(255,255,255,0.35)';
        ctx.stroke();
      }
      ctx.restore();

      // constraint lines (skip box edges - already drawn)
      if (this.showConstraints) {
        ctx.save();
        ctx.lineWidth = 2;
        for (const c of world.constraints) {
          if (c.broken || c.visible === false) continue;
          if (c.a.kind === 'box') continue;
          ctx.strokeStyle = c.color;
          ctx.beginPath();
          ctx.moveTo(c.a.x, c.a.y);
          ctx.lineTo(c.b.x, c.b.y);
          ctx.stroke();
        }
        ctx.restore();
      }

      // particles
      ctx.save();
      for (const p of world.particles) {
        if (p.kind === 'box') {
          // small corner node
          ctx.fillStyle = 'rgba(255,255,255,0.5)';
          ctx.beginPath(); ctx.arc(p.x, p.y, 2.5, 0, 7); ctx.fill();
          continue;
        }
        if (p.kind === 'cloth' || p.kind === 'rope') {
          ctx.fillStyle = p.pinned ? '#ffffff' : p.color;
          ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill();
          continue;
        }
        if (p.kind === 'spark') {
          const a = p.life / p.maxLife;
          ctx.globalAlpha = Math.max(0, a);
          ctx.fillStyle = p.color;
          ctx.beginPath(); ctx.arc(p.x, p.y, p.r * (0.4 + a * 0.6), 0, 7); ctx.fill();
          ctx.globalAlpha = 1;
          continue;
        }
        // ball
        let col = this.colorByVelocity ? this.velColor(p) : p.color;
        const grad = ctx.createRadialGradient(
          p.x - p.r * 0.3, p.y - p.r * 0.3, p.r * 0.1, p.x, p.y, p.r);
        grad.addColorStop(0, '#ffffff');
        grad.addColorStop(0.25, col);
        grad.addColorStop(1, this.shade(col, -0.35));
        ctx.fillStyle = grad;
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill();
      }
      ctx.restore();

      // attractor indicator
      if (world.attractor) {
        const a = world.attractor;
        ctx.save();
        ctx.strokeStyle = a.strength > 0 ? 'rgba(108,242,200,0.8)' : 'rgba(255,107,139,0.85)';
        ctx.lineWidth = 2;
        for (let i = 1; i <= 3; i++) {
          ctx.globalAlpha = 0.7 / i;
          ctx.beginPath(); ctx.arc(a.x, a.y, 14 * i + (Date.now() / 40 % 14), 0, 7); ctx.stroke();
        }
        ctx.restore();
      }
    }

    /* lighten/darken a colour string (hsl or hex) crudely */
    shade(col, amt) {
      if (col.startsWith('hsl')) {
        const m = col.match(/hsl\(([\d.]+),\s*([\d.]+)%,\s*([\d.]+)%/);
        if (m) {
          const l = Math.max(0, Math.min(100, parseFloat(m[3]) + amt * 100));
          return `hsl(${m[1]}, ${m[2]}%, ${l}%)`;
        }
      }
      // hex
      let c = col.replace('#', '');
      if (c.length === 3) c = c.split('').map(x => x + x).join('');
      const num = parseInt(c, 16);
      let r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
      r = Math.max(0, Math.min(255, r + amt * 255));
      g = Math.max(0, Math.min(255, g + amt * 255));
      b = Math.max(0, Math.min(255, b + amt * 255));
      return `rgb(${r | 0},${g | 0},${b | 0})`;
    }
  }

  global.TumbleRenderer = Renderer;
})(window);
