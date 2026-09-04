/* =========================================================
   TUMBLE LAB — Scene Presets
   Each builder receives the World and populates it. Builders
   assume world.clear() has already been called.
   ========================================================= */

(function (global) {
  'use strict';

  const { Particle, Constraint } = global.TumblePhysics;

  const PALETTE = ['#6cf2c8', '#ff6b8b', '#8a7bff', '#ffd166', '#5cc8ff', '#ff9f59'];
  const pick = () => PALETTE[(Math.random() * PALETTE.length) | 0];

  function rope(world, x1, y1, x2, y2, segments, opts = {}) {
    const group = 'rope-' + Math.random().toString(36).slice(2, 7);
    const prevList = [];
    for (let i = 0; i <= segments; i++) {
      const t = i / segments;
      const p = new Particle(
        x1 + (x2 - x1) * t,
        y1 + (y2 - y1) * t,
        { r: opts.r || 5, pinned: i === 0 && opts.pinStart !== false,
          color: opts.color || '#cbb9ff', kind: 'rope', group }
      );
      p.invMass = p.pinned ? 0 : 1 / p.mass;
      if (opts.pinEnd && i === segments) { p.pinned = true; p.invMass = 0; }
      world.add(p);
      prevList.push(p);
      if (i > 0) {
        world.addConstraint(new Constraint(prevList[i - 1], p,
          { stiffness: opts.stiffness || 0.9, color: opts.lineColor || 'rgba(203,185,255,0.5)' }));
      }
    }
    return prevList;
  }

  function box(world, cx, cy, size, opts = {}) {
    const group = 'box-' + Math.random().toString(36).slice(2, 7);
    const h = size / 2;
    const col = opts.color || pick();
    const corners = [
      [cx - h, cy - h], [cx + h, cy - h], [cx + h, cy + h], [cx - h, cy + h]
    ].map(([x, y]) => {
      const p = new Particle(x, y, { r: opts.r || 7, color: col, kind: 'box', group, mass: 4 });
      if (opts.vx || opts.vy) p.setVelocity(opts.vx || 0, opts.vy || 0);
      world.add(p);
      return p;
    });
    const stiff = 1, line = 'rgba(255,255,255,0.12)';
    // edges
    for (let i = 0; i < 4; i++) {
      world.addConstraint(new Constraint(corners[i], corners[(i + 1) % 4], { stiffness: stiff, color: line }));
    }
    // diagonals (rigidity)
    world.addConstraint(new Constraint(corners[0], corners[2], { stiffness: stiff, visible: false }));
    world.addConstraint(new Constraint(corners[1], corners[3], { stiffness: stiff, visible: false }));
    return { corners, group, color: col };
  }

  function cloth(world, x, y, cols, rows, spacing, opts = {}) {
    const group = 'cloth-' + Math.random().toString(36).slice(2, 7);
    const grid = [];
    const pinTop = opts.pinTop !== false;
    const pinEvery = opts.pinEvery || 1;   // pin every Nth top node
    for (let r = 0; r < rows; r++) {
      grid[r] = [];
      for (let c = 0; c < cols; c++) {
        const pinned = pinTop && r === 0 && (c % pinEvery === 0 || c === cols - 1);
        const p = new Particle(x + c * spacing, y + r * spacing,
          { r: 3, color: opts.color || '#5cc8ff', kind: 'cloth', group, pinned, mass: 1 });
        p.invMass = pinned ? 0 : 1 / p.mass;
        world.add(p);
        grid[r][c] = p;
      }
    }
    const stiff = opts.stiffness || 0.85;
    const tear = opts.tear || Infinity;
    const line = opts.lineColor || 'rgba(92,200,255,0.4)';
    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        if (c < cols - 1) world.addConstraint(new Constraint(grid[r][c], grid[r][c + 1], { stiffness: stiff, tear, color: line }));
        if (r < rows - 1) world.addConstraint(new Constraint(grid[r][c], grid[r + 1][c], { stiffness: stiff, tear, color: line }));
      }
    }
    return grid;
  }

  /* ============================================================
     PRESETS
     ============================================================ */
  const SCENES = {

    'ball-pit': {
      label: 'Ball Pit',
      blurb: 'A few hundred elastic balls tumbling under gravity. Stir them with the mouse.',
      settings: { gravity: 0.6, gravityAngle: 90, restitution: 0.5, friction: 0.01 },
      build(world) {
        const n = 180;
        for (let i = 0; i < n; i++) {
          const r = 7 + Math.random() * 12;
          const p = new Particle(
            40 + Math.random() * (world.width - 80),
            40 + Math.random() * (world.height * 0.5),
            { r, color: pick(), kind: 'ball', mass: r * r * 0.01,
              vx: (Math.random() - 0.5) * 3, vy: 0 }
          );
          world.add(p);
        }
      }
    },

    'rope-bridge': {
      label: 'Rope Bridge',
      blurb: 'A slack suspension bridge pinned at both towers — drop balls to watch it sag and swing.',
      settings: { gravity: 0.6, gravityAngle: 90, restitution: 0.4, friction: 0.015 },
      build(world) {
        const y = world.height * 0.4;
        const margin = world.width * 0.12;
        rope(world, margin, y, world.width - margin, y, 28,
          { r: 8, pinStart: true, pinEnd: true, stiffness: 1,
            color: '#ffd166', lineColor: 'rgba(255,209,102,0.6)' });
        // hang two light ropes from the towers
        rope(world, margin, y, margin, y + 90, 6, { r: 5, color: '#9a99b5' });
        rope(world, world.width - margin, y, world.width - margin, y + 90, 6, { r: 5, color: '#9a99b5' });
        // a few balls to load it
        for (let i = 0; i < 14; i++) {
          world.add(new Particle(margin + 30 + Math.random() * (world.width - margin * 2 - 60),
            60, { r: 11, color: pick(), kind: 'ball' }));
        }
      }
    },

    'cloth-flag': {
      label: 'Cloth Flag',
      blurb: 'A pinned cloth grid solved with distance constraints. It ripples and folds like fabric.',
      settings: { gravity: 0.35, gravityAngle: 90, restitution: 0.2, friction: 0.02 },
      build(world) {
        const cols = 26, rows = 18, sp = Math.min(16, world.width / 40);
        const x = world.width * 0.5 - (cols * sp) / 2;
        cloth(world, x, 60, cols, rows, sp, { pinEvery: 4, stiffness: 0.9, color: '#5cc8ff' });
      }
    },

    'cloth-tear': {
      label: 'Tearable Cloth',
      blurb: 'Same cloth, but the threads snap when overstretched. Grab and rip holes in it.',
      settings: { gravity: 0.5, gravityAngle: 90, restitution: 0.2, friction: 0.02 },
      build(world) {
        const cols = 28, rows = 20, sp = Math.min(15, world.width / 42);
        const x = world.width * 0.5 - (cols * sp) / 2;
        cloth(world, x, 50, cols, rows, sp, { pinEvery: 1, stiffness: 1, tear: 4.0,
          color: '#ff6b8b', lineColor: 'rgba(255,107,139,0.45)' });
      }
    },

    'box-stack': {
      label: 'Box Stack',
      blurb: 'Soft-rigid crates braced by diagonal constraints. Build a tower, then knock it over.',
      settings: { gravity: 0.6, gravityAngle: 90, restitution: 0.1, friction: 0.02 },
      build(world) {
        const size = 46;
        const baseY = world.height - 40;
        const cx = world.width * 0.5;
        let level = 0;
        for (let row = 0; row < 6; row++) {
          const count = 6 - row;
          const startX = cx - (count * size) / 2 + size / 2;
          for (let i = 0; i < count; i++) {
            box(world, startX + i * size, baseY - row * size - size / 2, size - 4, { color: pick() });
          }
          level++;
        }
      }
    },

    'zero-g': {
      label: 'Zero-G Drift',
      blurb: 'Gravity off. Bodies drift and ping-pong off the walls forever. Click to create a gravity well.',
      settings: { gravity: 0.0, gravityAngle: 90, restitution: 0.92, friction: 0.0 },
      build(world) {
        for (let i = 0; i < 90; i++) {
          const r = 6 + Math.random() * 14;
          const a = Math.random() * Math.PI * 2;
          const sp = 2 + Math.random() * 3;
          const p = new Particle(
            60 + Math.random() * (world.width - 120),
            60 + Math.random() * (world.height - 120),
            { r, color: pick(), kind: 'ball',
              vx: Math.cos(a) * sp, vy: Math.sin(a) * sp }
          );
          world.add(p);
        }
      }
    },

    'fireworks': {
      label: 'Fireworks',
      blurb: 'Continuous bursts of short-lived sparks that arc and fade. Pure particle flourish.',
      settings: { gravity: 0.25, gravityAngle: 90, restitution: 0.3, friction: 0.012 },
      build(world, ctx) {
        // marker so the runtime spawns recurring bursts
        world._fireworks = true;
      }
    },

    'pendulum-newton': {
      label: "Newton's Cradle",
      blurb: 'Five pinned pendulums in a row — a classic momentum demo built from ropes and balls.',
      settings: { gravity: 0.6, gravityAngle: 90, restitution: 0.95, friction: 0.004 },
      build(world) {
        const n = 5, gap = 34;
        const startX = world.width * 0.5 - ((n - 1) * gap) / 2;
        const topY = 70, len = 180;
        for (let i = 0; i < n; i++) {
          const x = startX + i * gap;
          const anchor = new Particle(x, topY, { pinned: true, r: 4, color: '#5a5972' });
          anchor.invMass = 0;
          world.add(anchor);
          // pull first one out
          const bx = i === 0 ? x - len * 0.8 : x;
          const by = i === 0 ? topY + len * 0.6 : topY + len;
          const bob = new Particle(bx, by, { r: gap / 2 - 1, color: '#ffd166', kind: 'ball', mass: 8 });
          world.add(bob);
          world.addConstraint(new Constraint(anchor, bob, { stiffness: 1, color: 'rgba(255,255,255,0.25)' }));
        }
      }
    },

    'sandbox': {
      label: 'Empty Sandbox',
      blurb: 'A clean stage. Pick a spawn type and click-drag to fling bodies into the world.',
      settings: { gravity: 0.55, gravityAngle: 90, restitution: 0.55, friction: 0.015 },
      build() { /* empty */ }
    }
  };

  global.TumbleScenes = { SCENES, PALETTE, helpers: { rope, box, cloth, pick } };
})(window);
