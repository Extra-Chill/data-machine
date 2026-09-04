/* =========================================================
   VOLTA — homepage cinematic scroll choreography
   • Scroll-pinned hero: bike rotates / explodes / colour-shifts
   • Horizontal-scroll colourway gallery driven by vertical scroll
   All transforms driven from scroll progress via rAF.
   Honours prefers-reduced-motion (REDUCED from site.js).
   ========================================================= */
'use strict';

(function () {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---- utilities -------------------------------------------------
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  // progress of a scope element through the viewport: 0 when its top
  // hits viewport top, 1 when its bottom-minus-one-viewport passes.
  function scopeProgress(scope) {
    const rect = scope.getBoundingClientRect();
    const total = rect.height - window.innerHeight;
    if (total <= 0) return 0;
    return clamp(-rect.top / total, 0, 1);
  }
  // map a sub-range [s,e] of p to 0..1
  const sub = (p, s, e) => clamp((p - s) / (e - s), 0, 1);
  const lerp = (a, b, t) => a + (b - a) * t;

  /* ====== HERO ================================================== */
  const heroScope = document.querySelector('.hero-scope');
  const bike = document.querySelector('.hero-bike');
  const headline = document.querySelector('.hero-headline');
  const specs = document.querySelectorAll('.hero-spec');
  const aura = document.querySelector('.hero-aura');
  const ctaRow = document.querySelector('.hero-cta-row');
  const eyebrow = document.querySelector('.hero-eyebrow-fixed');
  const scrollHint = document.querySelector('.scroll-hint');
  // Exploded-part groups inside the SVG.
  const parts = bike ? bike.querySelectorAll('[data-part]') : [];

  // Hero colour stops travelled through during scroll.
  const HERO_COLORS = ['#6f7bff', '#c4ff3d', '#ff6a3d', '#b06bff'];
  function hexToRgb(h) { const n = parseInt(h.slice(1), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function mixHex(a, b, t) {
    const ca = hexToRgb(a), cb = hexToRgb(b);
    return `rgb(${Math.round(lerp(ca[0], cb[0], t))},${Math.round(lerp(ca[1], cb[1], t))},${Math.round(lerp(ca[2], cb[2], t))})`;
  }
  function colorAt(p) {
    const seg = p * (HERO_COLORS.length - 1);
    const i = Math.min(Math.floor(seg), HERO_COLORS.length - 2);
    return mixHex(HERO_COLORS[i], HERO_COLORS[i + 1], seg - i);
  }

  function renderHero() {
    if (!heroScope || !bike) return;
    const p = scopeProgress(heroScope);

    // Phase 1 (0–.35): bike rolls in, slight tilt, scales up.
    const intro = sub(p, 0, 0.32);
    const tilt = lerp(-14, 0, intro);
    const scale = lerp(0.82, 1, intro);
    const baseX = lerp(-14, 0, intro);

    // Phase 2 (.35–.72): slow 3/4 rotation reveal.
    const spin = sub(p, 0.30, 0.72);
    const rotY = lerp(0, 26, spin);     // simulated via skew + scaleX
    const rotZ = lerp(tilt, 4, spin);

    // Phase 3 (.6–1): explode parts outward.
    const blow = sub(p, 0.58, 1);

    bike.style.transform =
      `translateX(${baseX}vw) scale(${scale}) rotate(${rotZ}deg) skewX(${rotY * 0.18}deg) scaleX(${1 - spin * 0.06})`;

    // explode the labelled part groups
    parts.forEach((g) => {
      const dx = parseFloat(g.dataset.dx || '0');
      const dy = parseFloat(g.dataset.dy || '0');
      const rot = parseFloat(g.dataset.rot || '0');
      g.style.transform = `translate(${dx * blow}px, ${dy * blow}px) rotate(${rot * blow}deg)`;
      g.style.opacity = 1;
    });

    // recolour accent strokes/fills via CSS var on the SVG
    bike.style.setProperty('--bike-accent', colorAt(p));

    // aura blob breathes + shifts colour
    if (aura) {
      aura.style.transform = `translateY(${lerp(40, -60, p)}px) scale(${lerp(0.85, 1.25, p)})`;
      aura.style.opacity = lerp(0.55, 0.25, p);
    }

    // headline tracks out then fades
    if (headline) {
      headline.style.letterSpacing = lerp(-0.05, 0.06, sub(p, 0, 0.5)) + 'em';
      headline.style.opacity = lerp(1, 0, sub(p, 0.4, 0.62));
      headline.style.transform = `translateY(${lerp(0, -40, p)}px)`;
    }
    if (eyebrow) eyebrow.style.opacity = lerp(1, 0, sub(p, 0.05, 0.25));
    if (ctaRow) {
      ctaRow.style.opacity = lerp(1, 0, sub(p, 0.05, 0.22));
      ctaRow.style.pointerEvents = p > 0.2 ? 'none' : 'auto';
    }
    if (scrollHint) scrollHint.style.opacity = lerp(1, 0, sub(p, 0, 0.12));

    // floating spec callouts fade/slide in during the rotation phase
    specs.forEach((s, i) => {
      const start = 0.4 + i * 0.07;
      const t = sub(p, start, start + 0.12);
      const out = sub(p, 0.9, 1);
      s.style.opacity = clamp(t - out, 0, 1);
      const dir = s.classList.contains('s2') || s.classList.contains('s4') ? 1 : -1;
      s.style.transform = `translateX(${lerp(dir * 30, 0, t)}px)`;
    });
  }

  /* ====== HORIZONTAL SCROLL ===================================== */
  const hScope = document.querySelector('.hscroll-scope');
  const hTrack = document.querySelector('.hscroll-track');
  const hProg = document.querySelector('.hscroll-progress > i');

  function renderHScroll() {
    if (!hScope || !hTrack) return;
    const p = scopeProgress(hScope);
    const maxShift = hTrack.scrollWidth - window.innerWidth + 80;
    hTrack.style.transform = `translateX(${-p * Math.max(0, maxShift)}px)`;
    if (hProg) hProg.style.width = (p * 100) + '%';

    // subtle parallax inside each card
    hTrack.querySelectorAll('.swatch-orb').forEach((orb, i) => {
      orb.style.transform = `translateY(${Math.sin((p * 6) + i) * 10}px)`;
    });
  }

  /* ====== rAF LOOP ============================================== */
  let scheduled = false;
  function onScroll() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      renderHero();
      renderHScroll();
      scheduled = false;
    });
  }

  if (reduced) {
    // Static, fully-revealed: show spec callouts, no transforms.
    parts.forEach(g => { g.style.transform = 'none'; g.style.opacity = 1; });
    specs.forEach(s => { s.style.opacity = 1; s.style.transform = 'none'; });
    if (hProg) hProg.style.width = '100%';
    return;
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  document.addEventListener('volta:loaded', onScroll);
  // initial paint
  onScroll();
})();
