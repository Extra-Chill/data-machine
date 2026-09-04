/* =========================================================
   AETHELON — mission page: trajectory scrubber (mission.html)
   A range slider scrubs the probe along its 7-year cruise.
   Readouts (heliocentric distance, velocity, mission elapsed
   time) update live. Pure interpolation over plausible data.
   ========================================================= */
'use strict';

(function () {
  const slider = document.getElementById('traj-slider');
  const probe = document.getElementById('traj-probe');
  const path = document.getElementById('traj-path');
  if (!slider || !probe || !path) return;

  const out = {
    dist: document.querySelector('[data-traj-dist]'),
    vel: document.querySelector('[data-traj-vel]'),
    met: document.querySelector('[data-traj-met]'),
    phase: document.querySelector('[data-traj-phase]')
  };

  // Mission keyframes: t (0..1) → heliocentric distance (AU),
  // velocity (km/s), mission elapsed days, phase label.
  const KEY = [
    { t: 0.00, au: 1.0, v: 11.2, day: 0, phase: 'Launch · Earth departure' },
    { t: 0.18, au: 0.72, v: 38.0, day: 280, phase: 'Inner cruise · Venus assist' },
    { t: 0.36, au: 1.52, v: 27.4, day: 760, phase: 'Earth gravity assist' },
    { t: 0.58, au: 5.2, v: 21.0, day: 1500, phase: 'Outbound cruise · Jupiter assist' },
    { t: 0.82, au: 9.0, v: 9.6, day: 2300, phase: 'Saturn approach' },
    { t: 1.00, au: 9.58, v: 6.3, day: 2555, phase: 'Saturn orbit insertion' }
  ];

  function interp(t) {
    let i = 0;
    while (i < KEY.length - 2 && t > KEY[i + 1].t) i++;
    const a = KEY[i], b = KEY[i + 1];
    const f = (t - a.t) / (b.t - a.t);
    const lerp = (x, y) => x + (y - x) * f;
    return {
      au: lerp(a.au, b.au),
      v: lerp(a.v, b.v),
      day: Math.round(lerp(a.day, b.day)),
      phase: f < 0.5 ? a.phase : b.phase
    };
  }

  const pathLen = path.getTotalLength ? path.getTotalLength() : 0;

  function update() {
    const t = parseFloat(slider.value) / 1000;
    // place probe along the SVG path
    if (pathLen) {
      const pt = path.getPointAtLength(t * pathLen);
      probe.setAttribute('transform', `translate(${pt.x} ${pt.y})`);
    }
    // draw the traversed portion of the path
    path.style.strokeDasharray = pathLen;
    path.style.strokeDashoffset = pathLen * (1 - t);

    const d = interp(t);
    if (out.dist) out.dist.textContent = d.au.toFixed(2) + ' AU';
    if (out.vel) out.vel.textContent = d.v.toFixed(1) + ' km/s';
    if (out.met) out.met.textContent = 'Day ' + d.day.toLocaleString('en-US');
    if (out.phase) out.phase.textContent = d.phase;
  }

  slider.addEventListener('input', update);
  update();
})();
