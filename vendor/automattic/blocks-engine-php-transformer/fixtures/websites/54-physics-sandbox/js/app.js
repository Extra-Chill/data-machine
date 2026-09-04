/* =========================================================
   TUMBLE LAB — Sandbox Controller
   Wires the engine + renderer to the DOM controls, input,
   localStorage, the FPS readout, and the scene presets.
   ========================================================= */

(function () {
  'use strict';

  const { World, Particle, Constraint } = window.TumblePhysics;
  const { SCENES, helpers } = window.TumbleScenes;
  const Renderer = window.TumbleRenderer;

  const canvas = document.getElementById('sim');
  if (!canvas) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- persisted settings ---- */
  const STORE_KEY = 'tumblelab.settings.v1';
  const defaults = {
    gravity: 0.55, gravityAngle: 90, restitution: 0.55, friction: 0.015,
    spawnType: 'ball', spawnSize: 14, trails: !reduceMotion,
    colorByVelocity: true, iterations: 4, paused: reduceMotion
  };
  let settings = Object.assign({}, defaults);
  try {
    const saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
    settings = Object.assign(settings, saved);
  } catch (e) { /* ignore */ }
  function save() {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(settings)); } catch (e) {}
  }

  /* ---- world + renderer ---- */
  const world = new World({
    gravity: settings.gravity, gravityAngle: settings.gravityAngle,
    restitution: settings.restitution, friction: settings.friction,
    iterations: settings.iterations,
    maxBodies: reduceMotion ? 300 : 700
  });
  const renderer = new Renderer(canvas);
  renderer.trails = settings.trails;
  renderer.colorByVelocity = settings.colorByVelocity;
  renderer.resize(world);

  let paused = settings.paused;
  let stepOnce = false;
  let currentScene = 'sandbox';

  /* ============================================================
     SCENE LOADING
     ============================================================ */
  function loadScene(name, applySettings = true) {
    const scene = SCENES[name];
    if (!scene) return;
    currentScene = name;
    world.clear();
    world._fireworks = false;
    world.attractor = null;
    if (applySettings && scene.settings) {
      Object.assign(settings, scene.settings);
      syncWorldFromSettings();
      syncUIFromSettings();
      save();
    }
    scene.build(world);
    setSceneLabel(scene.label);
  }

  function syncWorldFromSettings() {
    world.gravity = settings.gravity;
    world.gravityAngle = settings.gravityAngle;
    world.restitution = settings.restitution;
    world.friction = settings.friction;
    world.iterations = settings.iterations;
    renderer.trails = settings.trails;
    renderer.colorByVelocity = settings.colorByVelocity;
  }

  function setSceneLabel(label) {
    const el = document.getElementById('scene-name');
    if (el) el.textContent = label;
  }

  /* ============================================================
     SPAWNING
     ============================================================ */
  function spawnAt(x, y, vx = 0, vy = 0) {
    const type = settings.spawnType;
    const size = settings.spawnSize;
    if (type === 'ball') {
      const p = new Particle(x, y, { r: size, color: helpers.pick(), kind: 'ball' });
      p.setVelocity(vx, vy);
      world.add(p);
    } else if (type === 'box') {
      const b = helpers.box(world, x, y, size * 2.6, { vx, vy });
    } else if (type === 'rope') {
      helpers.rope(world, x, y, x + 4, y + 110, 9, { r: size * 0.5, color: helpers.pick() });
    } else if (type === 'confetti') {
      for (let i = 0; i < 12; i++) {
        const a = Math.random() * Math.PI * 2, sp = Math.random() * 4;
        const p = new Particle(x, y, { r: 3 + Math.random() * 4, color: helpers.pick(), kind: 'ball' });
        p.setVelocity(vx + Math.cos(a) * sp, vy + Math.sin(a) * sp);
        world.add(p);
      }
    }
  }

  function fireworkBurst(x, y) {
    const hue = Math.random() * 360;
    const n = reduceMotion ? 18 : 40;
    for (let i = 0; i < n; i++) {
      const a = (i / n) * Math.PI * 2 + Math.random() * 0.3;
      const sp = 3 + Math.random() * 5;
      const p = new Particle(x, y, {
        r: 2.5 + Math.random() * 2.5,
        color: `hsl(${hue + Math.random() * 40}, 90%, 62%)`,
        kind: 'spark', life: 60 + Math.random() * 40
      });
      p.setVelocity(Math.cos(a) * sp, Math.sin(a) * sp);
      world.add(p);
    }
  }

  /* ============================================================
     INPUT  (mouse + touch)
     ============================================================ */
  let pointer = { x: 0, y: 0, down: false, dragStart: null, lastT: 0, lastX: 0, lastY: 0 };
  let grabbed = null;      // particle held by the cursor
  let grabPin = false;
  let mode = 'spawn';      // spawn | grab | attract | repel

  function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const t = (e.touches && e.touches[0]) || e;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }

  function onDown(e) {
    const p = getPos(e);
    pointer.down = true; pointer.x = p.x; pointer.y = p.y;
    pointer.dragStart = { x: p.x, y: p.y, t: performance.now() };
    pointer.lastX = p.x; pointer.lastY = p.y; pointer.lastT = performance.now();

    if (mode === 'attract' || mode === 'repel') {
      world.attractor = { x: p.x, y: p.y, strength: mode === 'attract' ? 1.2 : -1.4 };
    } else if (mode === 'grab') {
      const hit = world.pick(p.x, p.y, 36);
      if (hit) {
        grabbed = hit;
        grabPin = grabbed.pinned;
        grabbed.pinned = false; grabbed.invMass = 1 / grabbed.mass;
      }
    } else {
      // spawn mode: try to grab an existing body first (feels natural)
      const hit = world.pick(p.x, p.y, 30);
      if (hit && hit.kind !== 'box') {
        grabbed = hit; grabPin = grabbed.pinned;
        grabbed.pinned = false; grabbed.invMass = 1 / grabbed.mass;
      }
    }
    if (e.cancelable) e.preventDefault();
  }

  function onMove(e) {
    const p = getPos(e);
    pointer.x = p.x; pointer.y = p.y;
    if (!pointer.down) return;
    if (world.attractor) { world.attractor.x = p.x; world.attractor.y = p.y; }
    if (grabbed) {
      grabbed.px = grabbed.x; grabbed.py = grabbed.y;
      grabbed.x = p.x; grabbed.y = p.y;
    }
    pointer.lastX = p.x; pointer.lastY = p.y; pointer.lastT = performance.now();
    if (e.cancelable) e.preventDefault();
  }

  function onUp(e) {
    const p = getPos(e);
    const start = pointer.dragStart;
    pointer.down = false;
    world.attractor = null;

    if (grabbed) {
      // impart fling velocity from the last pointer movement (Verlet: set prev pos)
      grabbed.px = grabbed.x - (p.x - pointer.lastX);
      grabbed.py = grabbed.y - (p.y - pointer.lastY);
      grabbed.pinned = grabPin;
      grabbed.invMass = grabPin ? 0 : 1 / grabbed.mass;
      grabbed = null;
      return;
    }

    if (mode === 'spawn' && start) {
      const dx = p.x - start.x, dy = p.y - start.y;
      const dt = Math.max(40, performance.now() - start.t);
      const vx = (dx / dt) * 16, vy = (dy / dt) * 16;
      spawnAt(start.x, start.y, vx, vy);
    }
  }

  canvas.addEventListener('mousedown', onDown);
  window.addEventListener('mousemove', onMove);
  window.addEventListener('mouseup', onUp);
  canvas.addEventListener('touchstart', onDown, { passive: false });
  canvas.addEventListener('touchmove', onMove, { passive: false });
  canvas.addEventListener('touchend', onUp);

  // keyboard: space pause, S step, R reset, 1-4 spawn types
  window.addEventListener('keydown', (e) => {
    if (e.target.matches('input, select, textarea')) return;
    if (e.code === 'Space') { e.preventDefault(); togglePause(); }
    else if (e.key === 's' || e.key === 'S') { stepOnce = true; }
    else if (e.key === 'r' || e.key === 'R') { loadScene(currentScene, false); }
    else if (e.key === '1') setSpawn('ball');
    else if (e.key === '2') setSpawn('box');
    else if (e.key === '3') setSpawn('rope');
    else if (e.key === '4') setSpawn('confetti');
    else if (e.key === 'g' || e.key === 'G') setMode('grab');
    else if (e.key === 'a' || e.key === 'A') setMode('attract');
  });

  /* ============================================================
     CONTROLS BINDING
     ============================================================ */
  function bindRange(id, key, fmt, onset) {
    const el = document.getElementById(id);
    const out = document.getElementById(id + '-val');
    if (!el) return;
    el.value = settings[key];
    if (out) out.textContent = fmt ? fmt(settings[key]) : settings[key];
    el.addEventListener('input', () => {
      const v = parseFloat(el.value);
      settings[key] = v;
      if (out) out.textContent = fmt ? fmt(v) : v;
      syncWorldFromSettings();
      if (onset) onset(v);
      save();
    });
  }

  bindRange('ctl-gravity', 'gravity', v => v.toFixed(2) + ' g');
  bindRange('ctl-angle', 'gravityAngle', v => v + '°');
  bindRange('ctl-restitution', 'restitution', v => Math.round(v * 100) + '%');
  bindRange('ctl-friction', 'friction', v => (v * 100).toFixed(1) + '%');
  bindRange('ctl-size', 'spawnSize', v => v + 'px');
  bindRange('ctl-iterations', 'iterations', v => v + 'x');

  function setSpawn(type) {
    settings.spawnType = type;
    document.querySelectorAll('[data-spawn]').forEach(b =>
      b.classList.toggle('active', b.dataset.spawn === type));
    save();
  }
  document.querySelectorAll('[data-spawn]').forEach(b => {
    b.addEventListener('click', () => setSpawn(b.dataset.spawn));
  });
  setSpawn(settings.spawnType);

  function setMode(m) {
    mode = m;
    document.querySelectorAll('[data-mode]').forEach(b =>
      b.classList.toggle('active', b.dataset.mode === m));
  }
  document.querySelectorAll('[data-mode]').forEach(b => {
    b.addEventListener('click', () => setMode(b.dataset.mode));
  });
  setMode('spawn');

  // toggles
  const trailsToggle = document.getElementById('ctl-trails');
  if (trailsToggle) {
    trailsToggle.checked = settings.trails;
    trailsToggle.addEventListener('change', () => {
      settings.trails = trailsToggle.checked;
      renderer.trails = settings.trails; save();
    });
  }
  const velToggle = document.getElementById('ctl-velcolor');
  if (velToggle) {
    velToggle.checked = settings.colorByVelocity;
    velToggle.addEventListener('change', () => {
      settings.colorByVelocity = velToggle.checked;
      renderer.colorByVelocity = settings.colorByVelocity; save();
    });
  }

  // transport
  function togglePause() {
    paused = !paused;
    settings.paused = paused; save();
    const btn = document.getElementById('btn-pause');
    if (btn) {
      btn.textContent = paused ? '▶ Play' : '❚❚ Pause';
      btn.setAttribute('aria-pressed', String(paused));
    }
  }
  document.getElementById('btn-pause')?.addEventListener('click', togglePause);
  document.getElementById('btn-step')?.addEventListener('click', () => { stepOnce = true; });
  document.getElementById('btn-reset')?.addEventListener('click', () => loadScene(currentScene, false));
  document.getElementById('btn-clear')?.addEventListener('click', () => {
    world.clear(); world._fireworks = false; setSceneLabel('Empty Sandbox'); currentScene = 'sandbox';
  });

  // preset buttons (sidebar)
  document.querySelectorAll('[data-scene]').forEach(b => {
    b.addEventListener('click', () => {
      loadScene(b.dataset.scene, true);
      document.querySelectorAll('[data-scene]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
    });
  });

  /* ============================================================
     UI SYNC HELPERS
     ============================================================ */
  function syncUIFromSettings() {
    const map = {
      'ctl-gravity': ['gravity', v => v.toFixed(2) + ' g'],
      'ctl-angle': ['gravityAngle', v => v + '°'],
      'ctl-restitution': ['restitution', v => Math.round(v * 100) + '%'],
      'ctl-friction': ['friction', v => (v * 100).toFixed(1) + '%'],
      'ctl-size': ['spawnSize', v => v + 'px'],
      'ctl-iterations': ['iterations', v => v + 'x']
    };
    for (const id in map) {
      const [key, fmt] = map[id];
      const el = document.getElementById(id);
      const out = document.getElementById(id + '-val');
      if (el) el.value = settings[key];
      if (out) out.textContent = fmt(settings[key]);
    }
  }

  /* ============================================================
     LOOP + STATS
     ============================================================ */
  let lastTime = performance.now();
  let fpsAccum = 0, fpsFrames = 0, fps = 60;
  const fpsEl = document.getElementById('stat-fps');
  const bodyEl = document.getElementById('stat-bodies');
  const conEl = document.getElementById('stat-constraints');
  const colEl = document.getElementById('stat-collisions');
  let fwTimer = 0;

  function frame(now) {
    const dtMs = now - lastTime;
    lastTime = now;

    // FPS (smoothed)
    fpsAccum += dtMs; fpsFrames++;
    if (fpsAccum >= 400) {
      fps = Math.round(1000 / (fpsAccum / fpsFrames));
      fpsAccum = 0; fpsFrames = 0;
      if (fpsEl) fpsEl.textContent = fps;
    }

    if (!paused || stepOnce) {
      // fixed normalized dt, clamped
      const dt = Math.min(1.6, dtMs / 16.67);
      world.step(Math.max(0.5, dt));

      // continuous fireworks scene
      if (world._fireworks) {
        fwTimer -= dtMs;
        if (fwTimer <= 0) {
          fireworkBurst(60 + Math.random() * (world.width - 120),
                        world.height * 0.3 + Math.random() * world.height * 0.3);
          fwTimer = 420 + Math.random() * 500;
        }
      }
      stepOnce = false;
    }

    renderer.render(world, { fade: !paused && renderer.trails });

    if (bodyEl) bodyEl.textContent = world.bodyCount;
    if (conEl) conEl.textContent = world.constraintCount;
    if (colEl) colEl.textContent = world.collisionsThisStep;

    requestAnimationFrame(frame);
  }

  /* ============================================================
     BOOT
     ============================================================ */
  window.addEventListener('resize', () => {
    renderer.resize(world);
  });

  // initial scene: from ?scene= query (scenes.html links) or sandbox
  const params = new URLSearchParams(location.search);
  const startScene = params.get('scene');
  syncWorldFromSettings();
  if (startScene && SCENES[startScene]) {
    loadScene(startScene, true);
    const btn = document.querySelector(`[data-scene="${startScene}"]`);
    if (btn) btn.classList.add('active');
  } else {
    loadScene('ball-pit', true);
    const btn = document.querySelector('[data-scene="ball-pit"]');
    if (btn) btn.classList.add('active');
  }

  // reflect initial pause state on button
  const pauseBtn = document.getElementById('btn-pause');
  if (pauseBtn && paused) { pauseBtn.textContent = '▶ Play'; pauseBtn.setAttribute('aria-pressed', 'true'); }

  if (reduceMotion) {
    const note = document.getElementById('rm-note');
    if (note) note.hidden = false;
  }

  requestAnimationFrame(frame);

  // expose for debugging
  window.__tumble = { world, renderer, loadScene };
})();
