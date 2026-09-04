/* levelselect.js — builds the level-select grid on levels.html.
   Reads unlock state + best results from localStorage and draws a tiny
   procedural thumbnail of each level (a zoomed-out tilemap snapshot). Locked
   levels are dimmed and not clickable. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const grid = document.querySelector('.level-grid');
    if (!grid) return;

    const save = LumenSave.all;

    LumenLevels.defs.forEach((def) => {
      const level = LumenLevels.parse(def);
      const unlocked = LumenSave.isUnlocked(def.index);
      const best = LumenSave.best(def.id);

      const card = document.createElement(unlocked ? 'a' : 'div');
      card.className = 'level-card' + (unlocked ? '' : ' locked');
      if (unlocked) {
        card.href = `play.html?level=${def.index}&autostart=1`;
        card.setAttribute('aria-label', `Play level ${def.index}: ${def.name}`);
      }

      const stars = best ? best.stars : 0;
      const starHtml = '★'.repeat(stars) + `<span class="dim">${'★'.repeat(3 - stars)}</span>`;
      const bestHtml = best
        ? `best ${LumenFmtTime(best.timeMs)} · ${best.lumens}/${level.maxLumens}✦`
        : (unlocked ? 'not cleared yet' : '');

      card.innerHTML = `
        ${unlocked ? '' : '<span class="lock-tag">🔒 Locked</span>'}
        <div class="level-thumb"><canvas></canvas></div>
        <span class="level-no">Level ${def.index}${unlocked ? '' : ' · finish the previous lantern'}</span>
        <h3>${def.name}</h3>
        <p>${def.hint}</p>
        <div class="level-meta">
          <span class="stars">${starHtml}</span>
          <span class="best">${bestHtml}</span>
        </div>`;

      grid.appendChild(card);
      drawThumb(card.querySelector('canvas'), level, unlocked);
    });

    // progress summary line
    const summary = document.querySelector('[data-progress]');
    if (summary) {
      const cleared = save.completed.length;
      const total = LumenLevels.count;
      summary.innerHTML =
        `Lanterns relit: <b>${cleared}/${total}</b> &nbsp;·&nbsp; ` +
        `Lifetime lumens: <b>${save.totalLumens}</b> &nbsp;·&nbsp; ` +
        `Highest unlocked: <b>Level ${save.unlocked}</b>`;
    }

    const resetBtn = document.querySelector('[data-action="reset-progress"]');
    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (confirm('Reset all progress, best times and unlocks? This cannot be undone.')) {
          LumenSave.reset();
          location.reload();
        }
      });
    }
  });

  /* Draw a zoomed-out snapshot of a level into a small canvas. */
  function drawThumb(canvas, level, unlocked) {
    if (!canvas) return;
    const r = canvas.parentElement.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const W = Math.max(120, r.width), Hh = Math.max(60, r.height);
    canvas.width = W * dpr; canvas.height = Hh * dpr;
    canvas.style.width = W + 'px'; canvas.style.height = Hh + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    // background tint
    const g = ctx.createLinearGradient(0, 0, 0, Hh);
    g.addColorStop(0, '#0a0e1f');
    g.addColorStop(1, level.tint || '#1b2a4a');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, Hh);

    const sx = W / level.width, sy = Hh / level.height;
    const s = Math.min(sx, sy);
    const offX = (W - level.width * s) / 2;
    const offY = (Hh - level.height * s) / 2;
    const T = level.tile;

    // tiles
    for (let y = 0; y < level.rows; y++) {
      for (let x = 0; x < level.cols; x++) {
        const v = level.solids[y][x];
        if (!v) continue;
        ctx.fillStyle = v === 1 ? '#2c3344' : '#6a5cc0';
        ctx.fillRect(offX + x * T * s, offY + y * T * s, T * s + 0.6, T * s + 0.6);
      }
    }
    // collectibles
    ctx.fillStyle = '#ffe27a';
    level.entities.lumens.forEach(c => dot(c.x, c.y, 1.4));
    ctx.fillStyle = '#7ef0ff';
    level.entities.gems.forEach(c => dot(c.x, c.y, 2));
    // hazards
    ctx.fillStyle = '#b9c2d0';
    level.entities.spikes.forEach(sp => dot(sp.x + T / 2, sp.y + T / 2, 1.4));
    // beetles
    ctx.fillStyle = '#a85a3f';
    level.entities.beetles.forEach(b => dot(b.x + b.w / 2, b.y + b.h / 2, 1.8));
    // spawn + goal
    if (level.entities.spawn) { ctx.fillStyle = '#ffd15a'; dot(level.entities.spawn.x + T / 2, level.entities.spawn.y + T / 2, 2.4); }
    if (level.entities.goal) { ctx.fillStyle = '#ffb05a'; dot(level.entities.goal.x + T / 2, level.entities.goal.y, 3); }

    if (!unlocked) { ctx.fillStyle = 'rgba(6,10,24,0.55)'; ctx.fillRect(0, 0, W, Hh); }

    function dot(wx, wy, rad) {
      ctx.beginPath();
      ctx.arc(offX + wx * s, offY + wy * s, rad, 0, Math.PI * 2);
      ctx.fill();
    }
  }
})();
