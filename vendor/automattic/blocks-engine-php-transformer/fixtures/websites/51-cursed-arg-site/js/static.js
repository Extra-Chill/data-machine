/* ============================================================
   static.js — ambient canvas static + CRT decay
   Subtle, gated under prefers-reduced-motion.
   ============================================================ */
(function () {
  'use strict';

  var reduce = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var canvas = document.getElementById('static-canvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');

  var W = 0, H = 0;
  function resize() {
    // render at low res for performance + chunky grain, scale up via CSS
    W = canvas.width = Math.floor(window.innerWidth / 3);
    H = canvas.height = Math.floor(window.innerHeight / 3);
    canvas.style.width = '100%';
    canvas.style.height = '100%';
  }
  resize();
  window.addEventListener('resize', resize);

  // intensity rises slowly the longer you stay (the site "notices" you)
  var startTime = Date.now();

  function drawStatic() {
    if (W <= 0 || H <= 0) return;
    var img = ctx.createImageData(W, H);
    var d = img.data;
    var minutes = (Date.now() - startTime) / 60000;
    // base density 0.10, creeps toward ~0.22 over ~6 min
    var density = Math.min(0.10 + minutes * 0.02, 0.22);
    for (var i = 0; i < d.length; i += 4) {
      if (Math.random() < density) {
        var v = 140 + Math.floor(Math.random() * 115);
        // tint slightly green to match phosphor
        d[i] = Math.floor(v * 0.55);
        d[i + 1] = v;
        d[i + 2] = Math.floor(v * 0.62);
        d[i + 3] = 255;
      } else {
        d[i + 3] = 0;
      }
    }
    ctx.putImageData(img, 0, 0);
  }

  if (reduce) {
    // one calm frame only, no animation loop
    drawStatic();
    return;
  }

  // throttle to ~12fps for a low, analog flicker (not strobing)
  var last = 0;
  function loop(t) {
    if (t - last > 80) {
      drawStatic();
      last = t;
    }
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
})();
