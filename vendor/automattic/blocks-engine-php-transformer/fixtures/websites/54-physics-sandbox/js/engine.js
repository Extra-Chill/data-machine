/* =========================================================
   TUMBLE LAB — Physics Engine
   A hand-written 2D Verlet integrator.

   Model:
     - Particles store current + previous position. Velocity is
       implicit (pos - prev). This is position-Verlet, which is
       very stable for constraint-based structures (ropes, cloth).
     - Constraints are solved iteratively (relaxation). More
       iterations = stiffer structures.
     - Walls are handled as positional bounce with restitution +
       tangential friction.
     - Boxes are rigid quads built from 4 corner particles braced
       by edge + diagonal distance constraints — a poor-man's
       rigid body that still stacks and tumbles.

   Everything here is framework-free vanilla JS.
   ========================================================= */

(function (global) {
  'use strict';

  /* ---- small vector helpers (operate on plain {x,y}) ---- */
  const V = {
    dist(ax, ay, bx, by) {
      const dx = bx - ax, dy = by - ay;
      return Math.hypot(dx, dy);
    }
  };

  let _id = 0;

  /* ============================================================
     PARTICLE
     ============================================================ */
  class Particle {
    constructor(x, y, opts = {}) {
      this.id = ++_id;
      this.x = x; this.y = y;
      this.px = x - (opts.vx || 0);
      this.py = y - (opts.vy || 0);
      this.r = opts.r || 8;
      this.mass = opts.mass != null ? opts.mass : this.r * this.r * 0.01;
      this.pinned = !!opts.pinned;
      this.invMass = this.pinned ? 0 : 1 / this.mass;
      this.color = opts.color || '#6cf2c8';
      this.kind = opts.kind || 'ball';   // ball | rope | cloth | box | spark
      this.life = opts.life != null ? opts.life : Infinity;
      this.maxLife = this.life;
      this.group = opts.group || null;   // shared id for box/cloth
      // velocity readout (computed each step)
      this.vx = 0; this.vy = 0;
    }
    setVelocity(vx, vy) { this.px = this.x - vx; this.py = this.y - vy; }
  }

  /* ============================================================
     DISTANCE CONSTRAINT — keeps two particles at `rest` apart.
     stiffness in [0,1]; tear breaks it when stretched too far.
     ============================================================ */
  class Constraint {
    constructor(a, b, opts = {}) {
      this.a = a; this.b = b;
      this.rest = opts.rest != null ? opts.rest : V.dist(a.x, a.y, b.x, b.y);
      this.stiffness = opts.stiffness != null ? opts.stiffness : 1;
      this.tear = opts.tear || Infinity;   // ratio of rest at which it snaps
      this.visible = opts.visible !== false;
      this.broken = false;
      this.color = opts.color || 'rgba(150,200,255,0.55)';
    }
    solve() {
      if (this.broken) return;
      const a = this.a, b = this.b;
      const dx = b.x - a.x, dy = b.y - a.y;
      const d = Math.hypot(dx, dy) || 0.0001;
      if (this.tear !== Infinity && d > this.rest * this.tear) {
        this.broken = true; return;
      }
      const diff = (d - this.rest) / d;       // signed fractional error
      const im = a.invMass + b.invMass;
      if (im === 0) return;                    // both ends pinned
      const k = this.stiffness;
      // distribute the correction by inverse mass so heavy/pinned ends move less
      const ka = k * diff * (a.invMass / im);
      const kb = k * diff * (b.invMass / im);
      a.x += dx * ka; a.y += dy * ka;
      b.x -= dx * kb; b.y -= dy * kb;
    }
  }

  /* ============================================================
     WORLD
     ============================================================ */
  class World {
    constructor(opts = {}) {
      this.particles = [];
      this.constraints = [];
      this.width = opts.width || 800;
      this.height = opts.height || 600;

      // tunables (mutated live by the UI)
      this.gravity = opts.gravity != null ? opts.gravity : 0.55;
      this.gravityAngle = opts.gravityAngle != null ? opts.gravityAngle : 90; // degrees, 90 = down
      this.restitution = opts.restitution != null ? opts.restitution : 0.55;
      this.friction = opts.friction != null ? opts.friction : 0.02;     // air drag
      this.wallFriction = opts.wallFriction != null ? opts.wallFriction : 0.18;
      this.iterations = opts.iterations || 4;
      this.maxBodies = opts.maxBodies || 600;

      // interaction
      this.attractor = null;   // {x,y,strength}  + = pull, - = push

      // stats
      this.collisionsThisStep = 0;
    }

    clear() {
      this.particles.length = 0;
      this.constraints.length = 0;
    }

    add(p) {
      this.particles.push(p);
      if (this.particles.length > this.maxBodies) {
        // retire oldest non-pinned, non-grouped particle
        for (let i = 0; i < this.particles.length; i++) {
          if (!this.particles[i].pinned && !this.particles[i].group) {
            this.particles.splice(i, 1); break;
          }
        }
      }
      return p;
    }

    addConstraint(c) { this.constraints.push(c); return c; }

    gravityVector() {
      const rad = this.gravityAngle * Math.PI / 180;
      return { x: Math.cos(rad) * this.gravity, y: Math.sin(rad) * this.gravity };
    }

    /* ---- main update ----
       dt is a normalized factor (1 = baseline). We use a fixed
       sub-step internally so the sim stays stable regardless of
       frame timing. */
    step(dt = 1) {
      this.collisionsThisStep = 0;
      const g = this.gravityVector();
      const drag = 1 - this.friction;

      // 1. integrate
      for (const p of this.particles) {
        if (p.pinned) { p.vx = 0; p.vy = 0; continue; }
        // life
        if (p.life !== Infinity) p.life -= dt;

        // attractor / repeller
        let ax = g.x, ay = g.y;
        if (this.attractor) {
          const dx = this.attractor.x - p.x;
          const dy = this.attractor.y - p.y;
          const d2 = dx * dx + dy * dy + 400;
          const f = this.attractor.strength / d2 * 800;
          ax += dx * f; ay += dy * f;
        }

        const vx = (p.x - p.px) * drag;
        const vy = (p.y - p.py) * drag;
        p.px = p.x; p.py = p.y;
        p.x += vx + ax * dt * dt;
        p.y += vy + ay * dt * dt;
        p.vx = p.x - p.px;
        p.vy = p.y - p.py;
      }

      // 2. solve constraints (relaxation) + walls each pass
      for (let it = 0; it < this.iterations; it++) {
        for (const c of this.constraints) c.solve();
        this.solveCollisions();
        for (const p of this.particles) this.constrainWalls(p);
      }

      // 3. retire dead / out-of-bounds loose particles
      this.cull();
    }

    constrainWalls(p) {
      if (p.pinned) return;
      const r = p.r;
      const e = this.restitution;
      const wf = 1 - this.wallFriction;
      // x
      if (p.x < r) {
        const vx = p.x - p.px, vy = p.y - p.py;
        p.x = r;
        p.px = p.x + vx * e;        // reflect normal (x) velocity, scaled by restitution
        p.py = p.y - vy * wf;       // damp tangential (y) velocity by wall friction
        this.collisionsThisStep++;
      } else if (p.x > this.width - r) {
        const vx = p.x - p.px, vy = p.y - p.py;
        p.x = this.width - r;
        p.px = p.x + vx * e;
        p.py = p.y - vy * wf;
        this.collisionsThisStep++;
      }
      // y
      if (p.y < r) {
        const vx = p.x - p.px, vy = p.y - p.py;
        p.y = r;
        p.py = p.y + vy * e;
        p.px = p.x - vx * wf;
        this.collisionsThisStep++;
      } else if (p.y > this.height - r) {
        const vx = p.x - p.px, vy = p.y - p.py;
        p.y = this.height - r;
        p.py = p.y + vy * e;
        p.px = p.x - vx * wf;
        this.collisionsThisStep++;
      }
    }

    /* ---- particle-particle collision (uniform-grid broadphase) ---- */
    solveCollisions() {
      const parts = this.particles;
      const n = parts.length;
      if (n < 2) return;

      // build a spatial hash sized to the largest plausible radius
      const cell = 48;
      const grid = new Map();
      const key = (cx, cy) => cx + ',' + cy;
      for (let i = 0; i < n; i++) {
        const p = parts[i];
        const cx = Math.floor(p.x / cell);
        const cy = Math.floor(p.y / cell);
        const k = key(cx, cy);
        let bucket = grid.get(k);
        if (!bucket) { bucket = []; grid.set(k, bucket); }
        bucket.push(p);
      }

      for (let i = 0; i < n; i++) {
        const a = parts[i];
        const cx = Math.floor(a.x / cell);
        const cy = Math.floor(a.y / cell);
        for (let ox = -1; ox <= 1; ox++) {
          for (let oy = -1; oy <= 1; oy++) {
            const bucket = grid.get(key(cx + ox, cy + oy));
            if (!bucket) continue;
            for (let j = 0; j < bucket.length; j++) {
              const b = bucket[j];
              if (b.id <= a.id) continue;           // each pair once
              if (a.group && a.group === b.group) continue; // same body
              this.resolvePair(a, b);
            }
          }
        }
      }
    }

    resolvePair(a, b) {
      const dx = b.x - a.x, dy = b.y - a.y;
      const minD = a.r + b.r;
      const d2 = dx * dx + dy * dy;
      if (d2 >= minD * minD || d2 === 0) return;
      const d = Math.sqrt(d2);
      const overlap = (minD - d);
      const nx = dx / d, ny = dy / d;
      const im = a.invMass + b.invMass;
      if (im === 0) return;
      const aMove = (a.invMass / im) * overlap * 0.5;
      const bMove = (b.invMass / im) * overlap * 0.5;
      a.x -= nx * aMove; a.y -= ny * aMove;
      b.x += nx * bMove; b.y += ny * bMove;
      this.collisionsThisStep++;
    }

    cull() {
      for (let i = this.particles.length - 1; i >= 0; i--) {
        const p = this.particles[i];
        if (p.life !== Infinity && p.life <= 0) {
          this.removeParticle(p, i);
        } else if (p.y > this.height + 400 || p.x < -400 || p.x > this.width + 400) {
          if (!p.pinned && !p.group) this.removeParticle(p, i);
        }
      }
    }

    removeParticle(p, idx) {
      this.particles.splice(idx, 1);
      // drop any constraints touching it
      for (let i = this.constraints.length - 1; i >= 0; i--) {
        const c = this.constraints[i];
        if (c.a === p || c.b === p) this.constraints.splice(i, 1);
      }
    }

    /* ---- pick nearest particle to a point (for grab) ---- */
    pick(x, y, maxDist = 40) {
      let best = null, bestD = Infinity;
      for (const p of this.particles) {
        const dx = p.x - x, dy = p.y - y;
        const d2 = dx * dx + dy * dy;
        // pickable if within maxDist OR inside the body's own radius
        const reach = Math.max(maxDist, p.r + 6);
        if (d2 <= reach * reach && d2 < bestD) { bestD = d2; best = p; }
      }
      return best;
    }

    get bodyCount() { return this.particles.length; }
    get constraintCount() { return this.constraints.length; }
  }

  global.TumblePhysics = { World, Particle, Constraint, V };
})(window);
