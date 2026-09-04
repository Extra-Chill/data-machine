/* =========================================================
   HELIX GRID — App Shell
   Shared across all pages: theme, persisted prefs, clock,
   sidebar nav, mobile menu, pause/speed controls, the
   incident-trigger + alert-banner plumbing.
   ========================================================= */
(function () {
  'use strict';

  const PREFS_KEY = 'helix.prefs.v1';
  const defaults = {
    theme: 'dark', paused: false, speed: 1,
    window: 60, region: 'all',
    logFilters: { crit: true, warn: true, info: true, ok: true },
  };

  function loadPrefs() {
    try { return Object.assign({}, defaults, JSON.parse(localStorage.getItem(PREFS_KEY) || '{}')); }
    catch (e) { return Object.assign({}, defaults); }
  }
  function savePrefs(p) {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(p)); } catch (e) {}
  }

  const prefs = loadPrefs();
  const E = window.HelixEngine;

  /* ---- theme ---- */
  function applyTheme(t) {
    document.body.setAttribute('data-theme', t);
    prefs.theme = t; savePrefs(prefs);
    const btn = document.getElementById('themeToggle');
    if (btn) btn.querySelector('.tlabel') && (btn.querySelector('.tlabel').textContent = t === 'dark' ? 'Dark' : 'Light');
  }
  applyTheme(prefs.theme);

  /* ---- clock ---- */
  function startClock() {
    const el = document.getElementById('clock');
    if (!el) return;
    function tick() {
      const now = new Date();
      const hh = String(now.getUTCHours()).padStart(2, '0');
      const mm = String(now.getUTCMinutes()).padStart(2, '0');
      const ss = String(now.getUTCSeconds()).padStart(2, '0');
      el.innerHTML = `<b>${hh}:${mm}:${ss}</b> UTC<br><small>NOC · grid time synced</small>`;
    }
    tick(); setInterval(tick, 1000);
  }

  /* ---- mobile menu ---- */
  function initMenu() {
    const t = document.getElementById('menuToggle');
    const scrim = document.querySelector('.nav-scrim');
    if (t) t.addEventListener('click', () => document.body.classList.toggle('nav-open'));
    if (scrim) scrim.addEventListener('click', () => document.body.classList.remove('nav-open'));
    document.querySelectorAll('.side-link').forEach(l =>
      l.addEventListener('click', () => document.body.classList.remove('nav-open')));
  }

  /* ---- highlight active nav by filename ---- */
  function highlightNav() {
    const file = (location.pathname.split('/').pop() || 'index.html');
    document.querySelectorAll('.side-link').forEach(l => {
      const href = l.getAttribute('href');
      if (href === file || (file === '' && href === 'index.html')) l.classList.add('active');
    });
  }

  /* ---- feed status indicator + pause control ---- */
  function initFeedControls() {
    if (!E) return;
    E.pause(prefs.paused);
    E.setSpeed(prefs.speed);

    const status = document.getElementById('feedStatus');
    const pauseBtn = document.getElementById('pauseBtn');
    function syncStatus() {
      const paused = E.isPaused();
      if (status) {
        status.classList.toggle('paused', paused);
        status.querySelector('.fs-text').textContent = paused ? 'FEED PAUSED' : 'LIVE · streaming';
      }
      if (pauseBtn) {
        pauseBtn.classList.toggle('active', !paused);
        pauseBtn.querySelector('.plabel').textContent = paused ? 'Resume' : 'Pause';
        pauseBtn.querySelector('.picon').innerHTML = paused
          ? '<polygon points="5,3 19,12 5,21"/>'
          : '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>';
      }
    }
    if (pauseBtn) pauseBtn.addEventListener('click', () => {
      E.pause(); prefs.paused = E.isPaused(); savePrefs(prefs); syncStatus();
    });
    E.on('pause', syncStatus);
    syncStatus();

    // keyboard: space pauses (unless typing)
    document.addEventListener('keydown', e => {
      if (e.target.matches('input,select,textarea')) return;
      if (e.code === 'Space') { e.preventDefault(); E.pause(); prefs.paused = E.isPaused(); savePrefs(prefs); syncStatus(); }
      if (e.key === 'i' || e.key === 'I') { E.triggerIncident(); }
    });
  }

  /* ---- speed slider (present on dashboard) ---- */
  function initSpeed() {
    const slider = document.getElementById('speedSlider');
    const val = document.getElementById('speedVal');
    if (!slider || !E) return;
    slider.value = prefs.speed;
    function show() { if (val) val.textContent = E.getSpeed().toFixed(2) + '×'; }
    slider.addEventListener('input', () => {
      E.setSpeed(parseFloat(slider.value)); prefs.speed = E.getSpeed(); savePrefs(prefs); show();
    });
    show();
  }

  /* ---- theme toggle button ---- */
  function initTheme() {
    const btn = document.getElementById('themeToggle');
    if (btn) btn.addEventListener('click', () =>
      applyTheme(document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));
  }

  /* ---- incident trigger + alert banner (global) ---- */
  function initIncidents() {
    if (!E) return;
    const banner = document.getElementById('alertBanner');
    const trig = document.getElementById('incidentBtn');
    if (trig) trig.addEventListener('click', () => E.triggerIncident());

    function showBanner(inc) {
      if (!banner) return;
      banner.classList.toggle('warn', inc.severity !== 'crit');
      banner.querySelector('.ab-title').textContent =
        `${inc.severity === 'crit' ? 'SEV-1' : 'SEV-2'} · ${inc.kind} — ${inc.service.name}`;
      banner.querySelector('.ab-desc').textContent =
        `${inc.id} · ${inc.region.name} control area · operators paged · auto-mitigation engaged`;
      banner.classList.add('show');
    }
    function hideBanner() { if (banner) banner.classList.remove('show'); }
    if (banner) banner.querySelector('.ab-dismiss').addEventListener('click', hideBanner);

    E.on('incident:start', showBanner);
    E.on('incident:end', inc => { if (!E.state.activeIncident) hideBanner(); });
    if (E.state.activeIncident) showBanner(E.state.activeIncident);
  }

  /* ---- sidebar live incident badge ---- */
  function initBadge() {
    if (!E) return;
    const badge = document.getElementById('alertsBadge');
    function upd() {
      if (!badge) return;
      const active = E.state.incidents.filter(i => i.status === 'active').length;
      badge.textContent = active;
      badge.classList.toggle('crit', active > 0);
      badge.style.display = active > 0 ? '' : 'none';
    }
    E.on('tick', upd); upd();
  }

  /* ---- public for pages ---- */
  window.HelixShell = {
    prefs, savePrefs,
    init() {
      highlightNav(); startClock(); initMenu(); initTheme();
      initFeedControls(); initSpeed(); initIncidents(); initBadge();
      if (E) E.start();
    },
  };

  document.addEventListener('DOMContentLoaded', () => window.HelixShell.init());
})();
