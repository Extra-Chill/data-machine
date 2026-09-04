/* =========================================================
   TUMBLE LAB — Home hero demo
   A small self-contained physics teaser using the same engine:
   a swinging chain + a pile of bouncing balls. Click to drop.
   ========================================================= */
(function () {
  'use strict';
  const canvas = document.getElementById('hero-sim');
  if (!canvas || !window.TumblePhysics) return;
  const { World, Particle } = window.TumblePhysics;
  const { helpers } = window.TumbleScenes;
  const Renderer = window.TumbleRenderer;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const world = new World({ gravity: 0.5, restitution: 0.6, friction: 0.012, iterations: 4, maxBodies: 160 });
  const renderer = new Renderer(canvas);
  renderer.trails = !reduceMotion;
  renderer.colorByVelocity = true;

  function build() {
    world.clear();
    const W = world.width, H = world.height;
    // a couple of hanging chains pinned at the top
    helpers.rope(world, W * 0.22, 0, W * 0.22, H * 0.5, 10, { r: 6, color: '#8a7bff' });
    helpers.rope(world, W * 0.78, 0, W * 0.78, H * 0.5, 10, { r: 6, color: '#6cf2c8' });
    // a slack cord between them
    helpers.rope(world, W * 0.3, H * 0.25, W * 0.7, H * 0.25, 16,
      { r: 5, pinStart: true, pinEnd: true, color: '#ffd166', lineColor: 'rgba(255,209,102,0.5)' });
    // a pile of balls
    const n = reduceMotion ? 30 : 70;
    for (let i = 0; i < n; i++) {
      const r = 7 + Math.random() * 12;
      world.add(new Particle(40 + Math.random() * (W - 80), Math.random() * H * 0.4,
        { r, color: helpers.pick(), kind: 'ball', vx: (Math.random() - 0.5) * 2 }));
    }
  }

  function resize() { renderer.resize(world); build(); }
  resize();
  window.addEventListener('resize', resize);

  // click drops a burst of balls
  canvas.addEventListener('pointerdown', (e) => {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left, y = e.clientY - rect.top;
    for (let i = 0; i < 8; i++) {
      const p = new Particle(x, y, { r: 7 + Math.random() * 9, color: helpers.pick(), kind: 'ball' });
      p.setVelocity((Math.random() - 0.5) * 4, -Math.random() * 3);
      world.add(p);
    }
  });

  let paused = reduceMotion;
  // gentle ambient breeze that nudges the chains
  let t = 0;
  function frame() {
    if (!paused) {
      t += 0.02;
      world.gravityAngle = 90 + Math.sin(t) * 8;   // sway
      world.step(1);
    }
    renderer.render(world, { fade: !paused && renderer.trails });
    requestAnimationFrame(frame);
  }
  // if reduced motion, render one settled frame after a quick settle
  if (reduceMotion) {
    for (let i = 0; i < 120; i++) world.step(1);
    renderer.render(world, { fade: false });
  } else {
    requestAnimationFrame(frame);
  }
})();
