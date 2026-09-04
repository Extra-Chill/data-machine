/* =========================================================
   VOLTA — live colourway switcher
   Recolours the inline SVG bike by setting CSS custom
   properties + updates name/price/glow. Used on index &
   features pages anywhere a [data-configurator] block exists.
   ========================================================= */
'use strict';

(function () {
  // Shared colourway catalogue (also referenced by reserve.js).
  window.VOLTA_COLORWAYS = [
    { id: 'midnight', name: 'Midnight Indigo', body: '#27305c', accent: '#6f7bff', glow: 'rgba(111,123,255,.42)', priceAdj: 0 },
    { id: 'acid',     name: 'Acid Volt',       body: '#1c2410', accent: '#c4ff3d', glow: 'rgba(196,255,61,.40)',  priceAdj: 0 },
    { id: 'ember',    name: 'Ember Copper',    body: '#3a1d12', accent: '#ff6a3d', glow: 'rgba(255,106,61,.40)',  priceAdj: 600 },
    { id: 'nova',     name: 'Nova Magenta',    body: '#321542', accent: '#b06bff', glow: 'rgba(176,107,255,.42)', priceAdj: 600 },
    { id: 'glacier',  name: 'Glacier Silver',  body: '#3b4150', accent: '#cfe6ff', glow: 'rgba(207,230,255,.38)', priceAdj: 1200 },
  ];

  function applyColorway(svg, cw) {
    if (!svg) return;
    svg.style.setProperty('--bike-body', cw.body);
    svg.style.setProperty('--bike-accent', cw.accent);
    svg.style.setProperty('--bike-body-2', shade(cw.body, 22));
    svg.style.setProperty('--bike-body-d', shade(cw.body, -28));
  }
  function shade(hex, amt) {
    const n = parseInt(hex.slice(1), 16);
    const r = clamp((n >> 16 & 255) + amt), g = clamp((n >> 8 & 255) + amt), b = clamp((n & 255) + amt);
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }
  function clamp(v) { return Math.max(0, Math.min(255, v)); }

  document.querySelectorAll('[data-configurator]').forEach(block => {
    const svg = block.querySelector('.config-bike');
    const stage = block.querySelector('.config-stage');
    const nameEl = block.querySelector('[data-cfg-name]');
    const priceEl = block.querySelector('[data-cfg-price]');
    const row = block.querySelector('.swatch-row');
    if (!row) return;

    const base = parseInt(block.dataset.basePrice || '14900', 10);

    window.VOLTA_COLORWAYS.forEach((cw, i) => {
      const b = document.createElement('button');
      b.className = 'swatch';
      b.style.background = `radial-gradient(circle at 32% 28%, ${shade(cw.accent, 40)}, ${cw.body})`;
      b.setAttribute('aria-label', cw.name);
      b.setAttribute('aria-pressed', i === 0 ? 'true' : 'false');
      b.dataset.id = cw.id;
      b.addEventListener('click', () => select(cw, b));
      row.appendChild(b);
    });

    function select(cw, btn) {
      row.querySelectorAll('.swatch').forEach(s => s.setAttribute('aria-pressed', 'false'));
      btn.setAttribute('aria-pressed', 'true');
      applyColorway(svg, cw);
      if (stage) stage.style.setProperty('--cfg-glow', cw.glow);
      if (nameEl) nameEl.innerHTML = `Finish &mdash; <b>${cw.name}</b>`;
      if (priceEl) {
        const total = base + cw.priceAdj;
        priceEl.textContent = '$' + total.toLocaleString('en-US') + (cw.priceAdj ? '  (+$' + cw.priceAdj + ')' : '');
      }
    }

    // initialise with first colourway
    select(window.VOLTA_COLORWAYS[0], row.querySelector('.swatch'));
  });

  // expose for other modules
  window.VOLTA_applyColorway = applyColorway;
})();
