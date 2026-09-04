/* game.js — the Lumen Leap engine: fixed-timestep loop, player controller,
   tile/AABB collision, entities, camera, particles, screen shake, game states
   and HUD wiring. This is the file that makes the platforming FEEL GOOD:
   gravity + run accel/friction, variable jump height, coyote time and jump
   buffering, stomp-on-enemy, moving platforms that carry you.

   It is instantiated by play.js once the DOM is ready. */
(function () {
  'use strict';

  // ── Tuning (in pixels / second unless noted) ──
  const FIXED = 1 / 120;            // physics tick (seconds) — fixed timestep
  const MAX_FRAME = 0.25;           // clamp huge frame gaps (tab switch)
  const GRAVITY = 2200;
  const MAX_FALL = 980;
  const RUN_ACCEL = 2600;
  const AIR_ACCEL = 1700;
  const RUN_MAX = 250;
  const GROUND_FRICTION = 2200;
  const AIR_FRICTION = 350;
  const JUMP_VELOCITY = 620;        // initial upward speed
  const JUMP_CUT = 0.45;            // multiply vy when jump released early (variable height)
  const COYOTE = 0.10;              // seconds you can still jump after leaving a ledge
  const JUMP_BUFFER = 0.12;         // seconds a jump press is remembered
  const BOUNCE = 430;               // bounce after stomping an enemy

  function Game(opts) {
    const canvas = opts.canvas;
    const renderer = LumenRenderer(canvas);
    const ctx = renderer.ctx;
    // Logical viewport (CSS px). play.js sets viewW/viewH; fall back otherwise.
    const view = {
      get w() { return canvas.viewW || canvas.width; },
      get h() { return canvas.viewH || canvas.height; }
    };
    const hud = opts.hud;          // { score, lumens, lives, level, time }
    const overlays = opts.overlays; // map of state -> element
    const on = opts.on || {};

    let reducedMotion = resolveReduced();
    let raf = null, running = false;
    let acc = 0, last = 0, animTime = 0;

    let state = 'title';           // title | playing | paused | levelclear | gameover | win
    let level = null;
    let levelIndex = 1;
    let lives = 3;
    let score = 0;
    let lumens = 0;                // collected this level
    let runStart = 0;              // performance.now at level start
    let elapsed = 0;               // ms elapsed in current level
    let lastCheckpoint = null;

    const cam = { x: 0, y: 0, tx: 0, ty: 0, shake: 0 };
    const particles = [];

    // player entity
    const player = {
      x: 0, y: 0, w: 22, h: 26,
      vx: 0, vy: 0,
      grounded: false, facing: 1,
      coyote: 0, buffer: 0, jumpHeld: false,
      invuln: 0, dead: false,
      onMover: null
    };

    /* ─────────── Public API ─────────── */
    function setReduced(v) { reducedMotion = v; }

    function startLevel(idx) {
      levelIndex = idx;
      level = LumenLevels.get(idx);
      if (!level) return;
      resetLevelEntities();
      lumens = 0;
      elapsed = 0;
      runStart = performance.now();
      lastCheckpoint = null;
      spawnPlayer(level.entities.spawn);
      cam.x = cam.tx = clampCamX(player.x);
      cam.y = cam.ty = clampCamY(player.y);
      particles.length = 0;
      setState('playing');
      LumenAudio.startMusic();
      ensureLoop();
      updateHud();
    }

    function resetLevelEntities() {
      // deep-ish reset of mutable entity flags by re-parsing
      level = LumenLevels.get(levelIndex);
      level.entities.beetles.forEach(b => { b.vx = 38; b.dead = false; b.deadT = 0; });
      level.entities.movers.forEach(m => { m.x = m.baseX; m.y = m.baseY; m.lastDX = 0; m.lastDY = 0; });
    }

    function spawnPlayer(at) {
      const sp = lastCheckpoint || at;
      player.x = sp.x + 5; player.y = sp.y;
      player.vx = 0; player.vy = 0;
      player.grounded = false; player.dead = false;
      player.invuln = 1.0; player.facing = 1;
      player.coyote = 0; player.buffer = 0;
    }

    function setState(s) {
      state = s;
      for (const k in overlays) {
        if (overlays[k]) overlays[k].classList.toggle('show', k === s);
      }
      if (on.state) on.state(s, { levelIndex, lumens, score, elapsed, lives, level });
    }

    function getState() { return state; }

    function pause() {
      if (state !== 'playing') return;
      setState('paused');
    }
    function resume() {
      if (state !== 'paused') return;
      runStart = performance.now() - elapsed; // keep timer honest
      setState('playing');
    }
    function togglePause() {
      if (state === 'playing') pause();
      else if (state === 'paused') resume();
    }

    function restartLevel() {
      lastCheckpoint = null;
      startLevel(levelIndex);
    }

    function quitToTitle() {
      setState('title');
      LumenAudio.stopMusic();
    }

    /* ─────────── Loop ─────────── */
    function ensureLoop() {
      if (running) return;
      running = true;
      last = performance.now();
      raf = requestAnimationFrame(frame);
    }

    function frame(now) {
      raf = requestAnimationFrame(frame);
      let dt = (now - last) / 1000;
      last = now;
      if (dt > MAX_FRAME) dt = MAX_FRAME;
      animTime = now;

      // edge inputs handled regardless of state
      if (LumenInput.consume('pause')) togglePause();
      if (LumenInput.consume('mute') && on.mute) on.mute();
      if (LumenInput.consume('restart') && (state === 'playing' || state === 'paused')) restartLevel();
      if (LumenInput.consume('confirm') && on.confirm) on.confirm(state);

      if (state === 'playing') {
        acc += dt;
        let steps = 0;
        while (acc >= FIXED && steps < 8) {
          step(FIXED);
          acc -= FIXED;
          steps++;
        }
      } else {
        // drain buffered jump edge so it doesn't fire on resume
        LumenInput.clearJumpEdge();
      }

      render(now);
    }

    /* ─────────── Fixed-step simulation ─────────── */
    function step(dt) {
      elapsed = performance.now() - runStart;

      updateMovers(dt);
      updatePlayer(dt);
      updateBeetles(dt);
      updateParticles(dt);
      updateCamera(dt);

      // timers
      if (cam.shake > 0) cam.shake = Math.max(0, cam.shake - dt * 60);

      checkCollectibles();
      checkHazards();
      checkGoal();
    }

    function updatePlayer(dt) {
      const in_ = LumenInput.actions;
      const p = player;

      // ── horizontal: accel toward input, friction otherwise ──
      let dir = (in_.right ? 1 : 0) - (in_.left ? 1 : 0);
      const accel = p.grounded ? RUN_ACCEL : AIR_ACCEL;
      if (dir !== 0) {
        p.vx += dir * accel * dt;
        p.facing = dir;
        if (p.vx > RUN_MAX) p.vx = RUN_MAX;
        if (p.vx < -RUN_MAX) p.vx = -RUN_MAX;
      } else {
        const fr = (p.grounded ? GROUND_FRICTION : AIR_FRICTION) * dt;
        if (p.vx > 0) p.vx = Math.max(0, p.vx - fr);
        else if (p.vx < 0) p.vx = Math.min(0, p.vx + fr);
      }

      // ── jump buffering + coyote time ──
      if (LumenInput.peekJump()) { p.buffer = JUMP_BUFFER; LumenInput.clearJumpEdge(); }
      p.buffer = Math.max(0, p.buffer - dt);
      p.coyote = p.grounded ? COYOTE : Math.max(0, p.coyote - dt);

      const wantJump = p.buffer > 0;
      if (wantJump && p.coyote > 0) {
        p.vy = -JUMP_VELOCITY;
        p.grounded = false;
        p.coyote = 0; p.buffer = 0;
        p.jumpHeld = true;
        if (on.jump) on.jump();
        LumenAudio.sfx.jump();
      }

      // variable jump height: release early -> cut upward velocity
      if (!in_.jump && p.jumpHeld && p.vy < 0) {
        p.vy *= JUMP_CUT;
        p.jumpHeld = false;
      }
      if (!in_.jump) p.jumpHeld = false;

      // ── gravity ──
      p.vy += GRAVITY * dt;
      if (p.vy > MAX_FALL) p.vy = MAX_FALL;

      const wasGrounded = p.grounded;
      p.grounded = false;
      p.onMover = null;

      // ── integrate + resolve against tiles, axis-separated ──
      moveAndCollide(p, dt);

      // ── moving platform carry ──
      carryOnMovers(p);

      // landing feedback
      if (p.grounded && !wasGrounded && p.vy >= 0) {
        if (p.landVy > 220 && !reducedMotion) addShake(2);
        LumenAudio.sfx.land();
      }
      p.invuln = Math.max(0, p.invuln - dt);

      // fell off the world -> die
      if (p.y > level.height + 80) hurtPlayer(true);
    }

    /* AABB vs tilemap, separated by axis so corners resolve cleanly.
       EPS shrinks the box by a hair when computing which tiles it spans, so an
       edge resting exactly on a tile boundary (e.g. feet flush on the floor)
       doesn't falsely register as overlapping the neighbouring tile — that
       would otherwise wall you off horizontally while standing on flat ground. */
    const EPS = 0.02;
    function moveAndCollide(p, dt) {
      const T = level.tile;

      // ----- X axis -----
      p.x += p.vx * dt;
      let box = aabb(p);
      let minX = Math.floor(box.left / T), maxX = Math.floor((box.right - EPS) / T);
      let minY = Math.floor(box.top / T), maxY = Math.floor((box.bottom - EPS) / T);
      for (let ty = minY; ty <= maxY; ty++) {
        for (let tx = minX; tx <= maxX; tx++) {
          if (solidAt(tx, ty) === 1) {
            if (p.vx > 0) p.x = tx * T - p.w;
            else if (p.vx < 0) p.x = (tx + 1) * T;
            p.vx = 0;
            box = aabb(p);
          }
        }
      }

      // ----- Y axis -----
      p.landVy = p.vy;
      p.y += p.vy * dt;
      box = aabb(p);
      minX = Math.floor(box.left / T); maxX = Math.floor((box.right - EPS) / T);
      minY = Math.floor(box.top / T); maxY = Math.floor(box.bottom / T);
      for (let ty = minY; ty <= maxY; ty++) {
        for (let tx = minX; tx <= maxX; tx++) {
          const s = solidAt(tx, ty);
          if (s === 1) {
            if (p.vy > 0) { p.y = ty * T - p.h; p.grounded = true; }
            else if (p.vy < 0) { p.y = (ty + 1) * T; }
            p.vy = 0;
            box = aabb(p);
          } else if (s === 2 && p.vy > 0) {
            // one-way platform: only collide when feet were above it last frame
            const platTop = ty * T;
            const prevBottom = box.bottom - p.vy * dt;
            if (prevBottom <= platTop + 2) {
              p.y = platTop - p.h;
              p.vy = 0; p.grounded = true;
              box = aabb(p);
            }
          }
        }
      }
    }

    function carryOnMovers(p) {
      const feet = p.y + p.h;
      for (const m of level.entities.movers) {
        const overlapX = p.x + p.w > m.x + 2 && p.x < m.x + m.w - 2;
        const onTop = Math.abs(feet - m.y) <= 6 && p.vy >= 0;
        if (overlapX && onTop) {
          p.y = m.y - p.h;
          p.grounded = true;
          if (p.vy > 0) p.vy = 0;
          p.x += m.lastDX || 0;
          p.y += m.lastDY || 0;
          p.onMover = m;
        }
      }
    }

    function updateMovers(dt) {
      animTimeSec = (animTimeSec || 0) + dt;
      for (const m of level.entities.movers) {
        const prevX = m.x, prevY = m.y;
        const wave = Math.sin(animTimeSec * m.speed + m.phase);
        if (m.axis === 'x') {
          m.x = m.baseX + wave * m.range;
        } else {
          m.y = m.baseY + wave * m.range;
        }
        m.lastDX = m.x - prevX;
        m.lastDY = m.y - prevY;
      }
    }
    let animTimeSec = 0;

    function updateBeetles(dt) {
      const T = level.tile;
      for (const b of level.entities.beetles) {
        if (b.dead) { b.deadT += dt; continue; }
        b.x += b.vx * dt;
        // turn at ledges or walls: probe the tile ahead and below
        const aheadX = b.vx > 0 ? b.x + b.w + 2 : b.x - 2;
        const footY = b.y + b.h + 2;
        const wallTile = solidAt(Math.floor(aheadX / T), Math.floor((b.y + b.h / 2) / T));
        const groundTile = solidAt(Math.floor(aheadX / T), Math.floor(footY / T));
        if (wallTile === 1 || groundTile === 0) b.vx = -b.vx;
        // keep beetles resting on their platform
        const underY = Math.floor((b.y + b.h + 1) / T);
        const bx = Math.floor((b.x + b.w / 2) / T);
        if (solidAt(bx, underY) === 0 && solidAt(bx, underY + 1) === 1) {
          b.y = (underY + 1) * T - b.h;
        }
      }
      // remove long-dead beetles
      for (let i = level.entities.beetles.length - 1; i >= 0; i--) {
        if (level.entities.beetles[i].dead && level.entities.beetles[i].deadT > 0.6)
          level.entities.beetles.splice(i, 1);
      }
      // player vs beetles
      if (player.dead) return;
      for (const b of level.entities.beetles) {
        if (b.dead) continue;
        if (overlap(aabb(player), { left: b.x, right: b.x + b.w, top: b.y, bottom: b.y + b.h })) {
          const stomping = player.vy > 60 && (player.y + player.h) - b.y < 16;
          if (stomping) {
            b.dead = true; b.deadT = 0;
            player.vy = -BOUNCE;
            player.buffer = 0;
            score += 50;
            burst(b.x + b.w / 2, b.y, '#a85a3f', 14, 'spark');
            if (!reducedMotion) addShake(4);
            LumenAudio.sfx.stomp();
            updateHud();
          } else if (player.invuln <= 0) {
            hurtPlayer(false);
          }
        }
      }
    }

    function checkCollectibles() {
      const pb = aabb(player);
      for (const c of level.entities.lumens) {
        if (c.taken) continue;
        if (dist2(player.x + player.w / 2, player.y + player.h / 2, c.x, c.y) < 22 * 22) {
          c.taken = true; lumens++; score += 10;
          burst(c.x, c.y, '#ffe27a', 8, 'dot');
          LumenAudio.sfx.coin();
          updateHud();
        }
      }
      for (const g of level.entities.gems) {
        if (g.taken) continue;
        if (dist2(player.x + player.w / 2, player.y + player.h / 2, g.x, g.y) < 24 * 24) {
          g.taken = true; lumens++; score += 100;
          burst(g.x, g.y, '#7ef0ff', 18, 'spark');
          if (!reducedMotion) addShake(3);
          LumenAudio.sfx.gem();
          updateHud();
        }
      }
      for (const cp of level.entities.checkpoints) {
        if (cp.reached) continue;
        if (Math.abs((player.x + player.w / 2) - cp.x) < 24 && Math.abs((player.y + player.h) - cp.y) < 48) {
          cp.reached = true;
          lastCheckpoint = { x: cp.x - 5, y: cp.y - level.tile };
          burst(cp.x, cp.y - 36, '#7dffc8', 12, 'dot');
          LumenAudio.sfx.checkpoint();
        }
      }
    }

    function checkHazards() {
      if (player.dead || player.invuln > 0) return;
      const T = level.tile;
      const pb = aabb(player);
      for (const s of level.entities.spikes) {
        const box = s.dir === 'up'
          ? { left: s.x + 3, right: s.x + T - 3, top: s.y + T - 14, bottom: s.y + T }
          : { left: s.x + 3, right: s.x + T - 3, top: s.y, bottom: s.y + 14 };
        if (overlap(pb, box)) { hurtPlayer(false); return; }
      }
    }

    function checkGoal() {
      const g = level.entities.goal;
      if (!g) return;
      const gx = g.x, gy = g.y;
      if (Math.abs((player.x) - gx) < 30 && Math.abs((player.y) - gy) < 60) {
        completeLevel();
      }
    }

    function hurtPlayer(fell) {
      if (player.dead) return;
      lives--;
      player.invuln = 1.2;
      burst(player.x + player.w / 2, player.y + player.h / 2, '#ff7a7a', 16, 'spark');
      if (!reducedMotion) addShake(7);
      LumenAudio.sfx.hurt();
      updateHud();
      if (lives <= 0) {
        gameOver();
      } else {
        // respawn at last checkpoint
        spawnPlayer(level.entities.spawn);
      }
    }

    function completeLevel() {
      const result = LumenSave.recordResult(level.id, {
        timeMs: elapsed, lumens, maxLumens: level.maxLumens
      });
      // time + completion bonus
      const timeBonus = Math.max(0, 30000 - elapsed) / 100 | 0;
      score += 200 + timeBonus + lumens * 5;
      LumenSave.unlockUpTo(Math.min(levelIndex + 1, LumenLevels.count));
      LumenAudio.stopMusic();
      LumenAudio.sfx.win();
      if (levelIndex >= LumenLevels.count) {
        setState('win');
      } else {
        setState('levelclear');
      }
      if (on.levelComplete) on.levelComplete({
        levelIndex, level, lumens, elapsed, score,
        stars: result.stars, newBest: result.newBest, isFinal: levelIndex >= LumenLevels.count
      });
    }

    function nextLevel() {
      if (levelIndex < LumenLevels.count) startLevel(levelIndex + 1);
    }

    function gameOver() {
      player.dead = true;
      LumenAudio.stopMusic();
      LumenAudio.sfx.lose();
      setState('gameover');
      if (on.gameOver) on.gameOver({ score, levelIndex });
    }

    function newGame(idx) {
      lives = 3; score = 0;
      startLevel(idx || 1);
    }

    /* ─────────── Camera ─────────── */
    function updateCamera(dt) {
      const lookahead = player.facing * 70 * (reducedMotion ? 0.4 : 1);
      cam.tx = clampCamX(player.x + player.w / 2 - view.w / 2 + lookahead);
      cam.ty = clampCamY(player.y + player.h / 2 - view.h / 2 + 40);
      const smooth = reducedMotion ? 0.25 : 0.12;
      cam.x += (cam.tx - cam.x) * smooth;
      cam.y += (cam.ty - cam.y) * smooth;
    }
    function clampCamX(x) { return clamp(x, 0, Math.max(0, level.width - view.w)); }
    function clampCamY(y) { return clamp(y, 0, Math.max(0, level.height - view.h)); }

    function addShake(n) { cam.shake = Math.min(12, cam.shake + n); }

    /* ─────────── Particles ─────────── */
    function burst(x, y, color, n, shape) {
      const count = reducedMotion ? Math.ceil(n / 3) : n;
      for (let i = 0; i < count; i++) {
        const a = Math.random() * Math.PI * 2;
        const sp = 40 + Math.random() * 160;
        particles.push({
          x, y,
          vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 40,
          life: 0.5 + Math.random() * 0.4, max: 0.9,
          r: 2 + Math.random() * 2.5, color, shape: shape || 'dot'
        });
      }
    }
    function updateParticles(dt) {
      for (let i = particles.length - 1; i >= 0; i--) {
        const p = particles[i];
        p.vy += 320 * dt;
        p.x += p.vx * dt; p.y += p.vy * dt;
        p.life -= dt;
        if (p.life <= 0) particles.splice(i, 1);
      }
    }

    /* ─────────── Render ─────────── */
    function render(now) {
      // apply screen shake offset to the camera used for drawing only
      let ox = 0, oy = 0;
      if (cam.shake > 0 && !reducedMotion) {
        ox = (Math.random() - 0.5) * cam.shake;
        oy = (Math.random() - 0.5) * cam.shake;
      }
      const dc = { x: cam.x + ox, y: cam.y + oy };

      renderer.clear();
      if (!level) return;
      renderer.drawBackground(dc, level, now, reducedMotion);
      renderer.drawLevel(dc, level, now);

      for (const m of level.entities.movers) renderer.drawMover(m, dc);
      for (const s of level.entities.spikes) renderer.drawSpike(s, level.tile, dc);
      for (const c of level.entities.checkpoints) renderer.drawCheckpoint(c, now, dc);
      if (level.entities.goal) renderer.drawGoal(level.entities.goal, now, dc);
      for (const c of level.entities.lumens) if (!c.taken) renderer.drawLumen(c.x, c.y, now, dc);
      for (const g of level.entities.gems) if (!g.taken) renderer.drawGem(g.x, g.y, now, dc);
      for (const b of level.entities.beetles) renderer.drawBeetle(b, dc, now);
      renderer.drawParticles(particles, dc);
      if (!player.dead || state === 'playing') renderer.drawPlayer(player, dc, now);

      // a soft vignette to focus the scene
      const vg = ctx.createRadialGradient(
        view.w / 2, view.h / 2, view.h * 0.4,
        view.w / 2, view.h / 2, view.h * 0.85);
      vg.addColorStop(0, 'rgba(0,0,0,0)');
      vg.addColorStop(1, 'rgba(0,0,0,0.45)');
      ctx.fillStyle = vg;
      ctx.fillRect(0, 0, view.w, view.h);
    }

    /* ─────────── HUD ─────────── */
    function updateHud() {
      if (!hud) return;
      if (hud.score) hud.score.textContent = String(score).padStart(6, '0');
      if (hud.lumens) hud.lumens.textContent = `${lumens}/${level ? level.maxLumens : 0}`;
      if (hud.lives) hud.lives.textContent = '✦'.repeat(Math.max(0, lives));
      if (hud.level && level) hud.level.textContent = `${levelIndex}. ${level.name}`;
    }
    function tickHudTime() {
      if (hud && hud.time) hud.time.textContent = fmtTime(elapsed);
    }

    /* ─────────── helpers ─────────── */
    function aabb(p) { return { left: p.x, right: p.x + p.w, top: p.y, bottom: p.y + p.h }; }
    function solidAt(tx, ty) {
      if (ty < 0 || ty >= level.rows || tx < 0 || tx >= level.cols) {
        return tx < 0 || tx >= level.cols ? 1 : 0; // walls on left/right edges
      }
      return level.solids[ty][tx];
    }
    function overlap(a, b) {
      return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
    }
    function dist2(ax, ay, bx, by) { const dx = ax - bx, dy = ay - by; return dx * dx + dy * dy; }
    function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }

    // keep the HUD timer ticking smoothly even between physics steps
    setInterval(() => { if (state === 'playing') tickHudTime(); }, 100);

    return {
      newGame, startLevel, nextLevel, restartLevel,
      pause, resume, togglePause, quitToTitle,
      getState, setState, setReduced,
      get level() { return level; },
      get levelIndex() { return levelIndex; },
      get score() { return score; },
      get lumens() { return lumens; },
      get lives() { return lives; },
      get elapsed() { return elapsed; },
      /* Read-only snapshot used by automated tests to verify a level can be
         walked from spawn to goal. Has no effect on gameplay. */
      get _debug() { return { player, cam, particles, state }; }
    };
  }

  function fmtTime(ms) {
    const s = Math.floor(ms / 1000);
    const m = Math.floor(s / 60);
    const cs = Math.floor((ms % 1000) / 10);
    return `${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}.${String(cs).padStart(2, '0')}`;
  }

  function resolveReduced() {
    const pref = window.LumenSave && LumenSave.get('reducedMotion');
    if (pref === true) return true;
    if (pref === false) return false;
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  window.LumenGame = Game;
  window.LumenFmtTime = fmtTime;
})();
