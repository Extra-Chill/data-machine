/* =========================================================
   AETHELON — homepage scroll choreography (scrollytelling)
   • Pinned hero: solar-system / trajectory stage advances with
     scroll progress — the probe travels Earth → Saturn, the
     view pans, captions and HUD readouts cross-fade per stage.
   • Multi-layer parallax band (bg / mid / fg move at rates).
   • Live orbital-mechanics SVG diagram (rAF, Enceladus + Saturn).
   Honours prefers-reduced-motion.
   ========================================================= */
'use strict';

(function () {
  const { clamp, lerp, sub, reduced } = window.AETH;

  // progress of a scope through the viewport: 0 at top-aligned, 1 at end.
  function scopeProgress(scope) {
    const rect = scope.getBoundingClientRect();
    const total = rect.height - window.innerHeight;
    if (total <= 0) return 0;
    return clamp(-rect.top / total, 0, 1);
  }

  /* ====== PINNED HERO STAGE ===================================== */
  const heroScope = document.querySelector('.hero-scope');
  const stageSvg = document.querySelector('.hero-stage svg');
  const probe = document.getElementById('probe');
  const probeTrail = document.getElementById('probe-trail');
  const camera = document.getElementById('stage-camera');
  const heroCopy = document.querySelector('.hero-copy');
  const captions = document.querySelectorAll('.story-caption');
  const huds = document.querySelectorAll('.hud-readout');
  const scrollHint = document.querySelector('.scroll-hint');
  const nebula = document.querySelector('.hero-nebula');

  // trajectory waypoints in SVG coords (Earth → gravity assist → Saturn)
  const PATH = [
    { x: 120, y: 430 },  // Earth
    { x: 320, y: 250 },
    { x: 520, y: 360 },  // Venus flyby
    { x: 700, y: 170 },
    { x: 860, y: 300 }   // Saturn / Enceladus
  ];

  // Catmull-Rom-ish sampling for a smooth curve through the waypoints.
  function pointAt(t) {
    const seg = t * (PATH.length - 1);
    const i = Math.min(Math.floor(seg), PATH.length - 2);
    const f = seg - i;
    const a = PATH[i], b = PATH[i + 1];
    const ease = f * f * (3 - 2 * f); // smoothstep
    return { x: lerp(a.x, b.x, ease), y: lerp(a.y, b.y, ease) };
  }

  function renderHero() {
    if (!heroScope) return;
    const p = scopeProgress(heroScope);

    // hero title fades out as the journey begins
    if (heroCopy) {
      heroCopy.style.opacity = lerp(1, 0, sub(p, 0.04, 0.18));
      heroCopy.style.transform = `translateY(${lerp(0, -50, sub(p, 0, 0.2))}px)`;
      heroCopy.style.pointerEvents = p > 0.16 ? 'none' : 'auto';
    }
    if (scrollHint) scrollHint.style.opacity = lerp(1, 0, sub(p, 0, 0.08));

    // probe travels its trajectory; the camera pans/zooms to follow
    const journey = sub(p, 0.12, 0.95);
    if (probe) {
      const pt = pointAt(journey);
      // orient probe along the path
      const ahead = pointAt(Math.min(journey + 0.02, 1));
      const ang = Math.atan2(ahead.y - pt.y, ahead.x - pt.x) * 180 / Math.PI;
      probe.setAttribute('transform', `translate(${pt.x} ${pt.y}) rotate(${ang})`);
    }
    if (probeTrail) {
      // draw trail as a dash that grows with journey
      const len = probeTrail.getTotalLength ? probeTrail.getTotalLength() : 1000;
      probeTrail.style.strokeDasharray = len;
      probeTrail.style.strokeDashoffset = len * (1 - journey);
    }
    if (camera) {
      // pan from Earth side toward Saturn + slight zoom
      const panX = lerp(0, -180, journey);
      const zoom = lerp(1, 1.18, journey);
      camera.setAttribute('transform', `translate(${panX} ${lerp(0,-20,journey)}) scale(${zoom})`);
    }
    if (nebula) nebula.style.transform = `translateX(${lerp(0, -60, p)}px) scale(${lerp(1, 1.15, p)})`;

    // four narrative stages cross-fade across the scroll
    captions.forEach((cap, i) => {
      const start = 0.14 + i * 0.2;
      const inT = sub(p, start, start + 0.07);
      const outT = sub(p, start + 0.14, start + 0.2);
      cap.style.opacity = clamp(inT - outT, 0, 1);
      cap.style.transform = `translateX(-50%) translateY(${lerp(24, 0, inT)}px)`;
    });

    // HUD readouts pop in during the cruise phase
    huds.forEach((hud, i) => {
      const start = 0.2 + i * 0.06;
      const t = sub(p, start, start + 0.1);
      const out = sub(p, 0.92, 1);
      hud.style.opacity = clamp(t - out, 0, 1);
      const dir = i % 2 === 0 ? -1 : 1;
      hud.style.transform = `translateX(${lerp(dir * 24, 0, t)}px)`;
    });
  }

  /* ====== PARALLAX BAND ======================================== */
  const pbands = document.querySelectorAll('.parallax-band');
  function renderParallax() {
    pbands.forEach(band => {
      const rect = band.getBoundingClientRect();
      const center = rect.top + rect.height / 2 - window.innerHeight / 2;
      const norm = center / window.innerHeight; // ~ -1..1 across viewport
      band.querySelector('.pl-bg') && (band.querySelector('.pl-bg').style.transform = `translateY(${norm * 60}px)`);
      band.querySelector('.pl-mid') && (band.querySelector('.pl-mid').style.transform = `translateY(${norm * -90}px)`);
      band.querySelector('.pl-fg') && (band.querySelector('.pl-fg').style.transform = `translateY(${norm * -150}px)`);
    });
  }

  /* ====== rAF scroll loop ====================================== */
  let scheduled = false;
  function onScroll() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { renderHero(); renderParallax(); scheduled = false; });
  }

  /* ====== LIVE ORBITAL DIAGRAM (independent rAF) =============== */
  function initOrbits() {
    const svg = document.getElementById('orbit-diagram');
    if (!svg) return;
    const bodies = [
      { el: svg.querySelector('#orb-enceladus'), r: 90, speed: 1.0, a: 0 },
      { el: svg.querySelector('#orb-tethys'), r: 130, speed: 0.62, a: 1.6 },
      { el: svg.querySelector('#orb-dione'), r: 168, speed: 0.44, a: 3.2 },
      { el: svg.querySelector('#orb-titan'), r: 210, speed: 0.22, a: 5.0 }
    ].filter(b => b.el);
    const cx = 250, cy = 250;

    function place(b) {
      const x = cx + Math.cos(b.a) * b.r;
      const y = cy + Math.sin(b.a) * b.r * 0.42; // tilted (perspective)
      b.el.setAttribute('transform', `translate(${x} ${y})`);
    }

    if (reduced) { bodies.forEach(place); return; }

    let last = performance.now();
    function loop(now) {
      const dt = Math.min((now - last) / 1000, 0.05);
      last = now;
      bodies.forEach(b => { b.a += b.speed * dt * 0.6; place(b); });
      requestAnimationFrame(loop);
    }
    requestAnimationFrame(loop);
  }

  /* ====== boot ================================================= */
  initOrbits();

  if (reduced) {
    // fully revealed, no pinning transforms
    captions.forEach(c => { c.style.opacity = 1; });
    huds.forEach(h => { h.style.opacity = 1; });
    if (probe) probe.setAttribute('transform', `translate(${PATH[4].x} ${PATH[4].y})`);
    if (probeTrail) { const len = probeTrail.getTotalLength ? probeTrail.getTotalLength() : 0; probeTrail.style.strokeDasharray = 'none'; probeTrail.style.strokeDashoffset = 0; }
    return;
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  document.addEventListener('aeth:loaded', onScroll);
  onScroll();
})();
