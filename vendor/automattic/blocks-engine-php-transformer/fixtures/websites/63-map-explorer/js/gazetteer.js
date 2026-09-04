/* =========================================================
   VAELORA ATLAS — Gazetteer page
   Builds grouped cards from the world data; each links to
   index.html?focus=<kind>:<id> to fly the map to it.
   Shares theme + nav behaviour with the map page.
   ========================================================= */
(function () {
  'use strict';
  const D = window.VAELORA;
  const STORE = 'vaelora.state.v1';

  /* theme (shared key with the map app) */
  function readTheme() {
    try { return (JSON.parse(localStorage.getItem(STORE) || '{}').theme) || 'dark'; }
    catch (e) { return 'dark'; }
  }
  function writeTheme(t) {
    try { const s = JSON.parse(localStorage.getItem(STORE) || '{}'); s.theme = t; localStorage.setItem(STORE, JSON.stringify(s)); } catch (e) {}
  }
  function applyTheme(t) {
    document.body.setAttribute('data-theme', t); writeTheme(t);
    const l = document.querySelector('#themeToggle .tlabel'); if (l) l.textContent = t === 'dark' ? 'Dark' : 'Light';
  }
  applyTheme(readTheme());
  const tb = document.getElementById('themeToggle');
  if (tb) tb.addEventListener('click', () => applyTheme(document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));

  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="13 6 19 12 13 18"/></svg>';

  function regionCard(r) {
    return card('region', r.id, r.name, r.color, r.island + ' Island · capital ' + r.capital,
      r.area, r.blurb + ' Population ' + r.pop.toLocaleString() + '.');
  }
  function poiCard(p) {
    const cat = D.poiCats[p.cat];
    return card('poi', p.id, p.name, cat.color, cat.label, p.stat, p.blurb);
  }
  function stationCard(s) {
    const lines = D.linesForStation(s.id);
    const color = lines[0] ? lines[0].color : '#9b6fe0';
    return card('station', s.id, s.name, color,
      (lines.length > 1 ? 'Interchange' : 'Station'),
      lines.map(l => l.name).join(' · '),
      'Tramway station served by ' + lines.map(l => l.name).join(', ') + '.');
  }
  function card(kind, id, name, color, kicker, sub, blurb) {
    return `<a class="gaz-card" data-kind="${kind}" data-name="${esc(name)}" data-sub="${esc(sub)}" href="index.html?focus=${kind}:${id}">
      <span class="gc-kicker" style="color:${color}">${esc(kicker)}</span>
      <h3>${esc(name)}</h3>
      <p class="gc-sub">${esc(sub)}</p>
      <p>${esc(blurb)}</p>
      <span class="gc-cta">Open in map ${arrow}</span>
    </a>`;
  }

  const groups = [
    { kind: 'region',  title: 'Regions', html: D.regions.map(regionCard) },
    { kind: 'poi',     title: 'Landmarks & places of interest', html: D.pois.slice().sort((a,b)=>a.name.localeCompare(b.name)).map(poiCard) },
    { kind: 'station', title: 'Tramway stations', html: D.stations.slice().sort((a,b)=>a.name.localeCompare(b.name)).map(stationCard) }
  ];

  const container = document.getElementById('gazContent');
  function build() {
    container.innerHTML = groups.map(g =>
      `<section class="gaz-group" data-kind="${g.kind}">
        <h2>${g.title} <span style="color:var(--ink-faint);font-weight:500">(${g.html.length})</span></h2>
        <div class="gaz-grid">${g.html.join('')}</div>
      </section>`).join('');
  }
  build();

  /* filter + search */
  let activeFilter = 'all', q = '';
  document.querySelectorAll('.filter-chip').forEach(c => c.addEventListener('click', () => {
    document.querySelectorAll('.filter-chip').forEach(x => x.classList.remove('active'));
    c.classList.add('active'); activeFilter = c.dataset.filter; apply();
  }));
  document.getElementById('gazSearch').addEventListener('input', e => { q = e.target.value.trim().toLowerCase(); apply();});

  function apply() {
    container.querySelectorAll('.gaz-group').forEach(sec => {
      const kindOk = activeFilter === 'all' || sec.dataset.kind === activeFilter;
      let visible = 0;
      sec.querySelectorAll('.gaz-card').forEach(card => {
        const text = (card.dataset.name + ' ' + card.dataset.sub + ' ' + card.textContent).toLowerCase();
        const show = kindOk && (!q || text.includes(q));
        card.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      sec.style.display = (kindOk && visible) ? '' : 'none';
    });
    const anyVisible = [...container.querySelectorAll('.gaz-group')].some(s => s.style.display !== 'none');
    let empty = container.querySelector('.gaz-empty');
    if (!anyVisible) {
      if (!empty) { empty = document.createElement('p'); empty.className = 'gaz-empty'; container.appendChild(empty); }
      empty.textContent = 'No places match “' + q + '”.';
      empty.style.display = '';
    } else if (empty) empty.style.display = 'none';
  }
})();
