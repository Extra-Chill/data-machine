/* =========================================================
   PLOTWEAVER — Gallery
   Renders each preset into a live thumbnail canvas and links
   into the studio via a #preset deep-link.
   ========================================================= */
(function () {
  'use strict';
  const PW = window.PW;
  document.addEventListener('DOMContentLoaded', () => {
    // theme persistence (shared header)
    const saved = localStorage.getItem('plotweaver.theme');
    if (saved) document.body.dataset.theme = saved;
    const tg = document.getElementById('themeToggle');
    if (tg) tg.onclick = () => { document.body.dataset.theme = document.body.dataset.theme === 'light' ? 'dark' : 'light'; localStorage.setItem('plotweaver.theme', document.body.dataset.theme); renderAll(); };

    const grid = document.getElementById('galGrid');
    grid.innerHTML = PW.PRESETS.map(p => `
      <article class="gal-card fade-up">
        <div class="gal-thumb"><canvas data-id="${p.id}"></canvas></div>
        <div class="gal-meta">
          <span class="tag">${p.tag}</span>
          <h3>${esc(p.title)}</h3>
          <p>${esc(p.desc)}</p>
          <a class="btn accent" href="index.html#preset=${p.id}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
            Open in studio
          </a>
        </div>
      </article>`).join('');

    function renderAll() {
      PW.PRESETS.forEach(p => {
        const cv = grid.querySelector(`canvas[data-id="${p.id}"]`);
        if (!cv) return;
        const ds = PW.Data.parseCSV(PW.DATASETS[p.dataset].csv);
        const cfg = Object.assign(PW.defaultConfig(ds), p.config);
        const model = PW.buildModel(ds, cfg);
        const ch = new PW.Chart(cv, null);
        ch.render(model, Object.assign({}, cfg, { animate: false }));
      });
    }
    renderAll();
    window.addEventListener('resize', debounce(renderAll, 150));
  });

  function debounce(fn, ms) { let t; return () => { clearTimeout(t); t = setTimeout(fn, ms); }; }
  function esc(s) { return String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
})();
