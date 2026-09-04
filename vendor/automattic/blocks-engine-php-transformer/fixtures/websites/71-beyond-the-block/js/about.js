/* ============================================================
   BEYOND THE BLOCK — about.js
   The flex: fetch this site's own hand-written source files,
   add up their bytes, and animate the total. If fetch is
   blocked (some file:// setups), fall back to a known figure
   so the counter never just sits there blank.
   ============================================================ */
(function () {
  'use strict';
  const B = window.BTB || {};
  const REDUCED = B.reduced;
  const el = document.getElementById('byte-counter');
  if (!el) return;

  const FILES = [
    'index.html', 'possible.html', 'about.html',
    'css/style.css',
    'js/engine.js', 'js/demos.js', 'js/story.js', 'js/about.js'
  ];

  /* sensible fallback (approximate hand-source size in KB) */
  const FALLBACK_BYTES = 78 * 1024;

  function fmt(bytes) {
    const kb = bytes / 1024;
    return kb < 1000 ? kb.toFixed(1) + ' KB' : (kb / 1024).toFixed(2) + ' MB';
  }

  function animateTo(target) {
    if (REDUCED) { el.textContent = fmt(target); return; }
    const start = performance.now(), dur = 1400, from = 0;
    function tick(now) {
      const t = Math.min((now - start) / dur, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = fmt(from + (target - from) * eased);
      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = fmt(target) + ' of hand-written source';
    }
    requestAnimationFrame(tick);
  }

  function run() {
    Promise.all(FILES.map(f =>
      fetch(f, { cache: 'no-store' })
        .then(r => r.ok ? r.text() : '')
        .then(txt => new Blob([txt]).size)
        .catch(() => 0)
    )).then(sizes => {
      const total = sizes.reduce((a, b) => a + b, 0);
      animateTo(total > 0 ? total : FALLBACK_BYTES);
    }).catch(() => animateTo(FALLBACK_BYTES));
  }

  /* run when the counter scrolls into view */
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => {
      if (es.some(e => e.isIntersecting)) { io.disconnect(); run(); }
    }, { threshold: 0.4 });
    io.observe(el);
  } else {
    run();
  }
})();
