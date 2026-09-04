/* =========================================================
   LATTICE — Shared shell
   Header nav active-state, theme toggle + persistence.
   Used on every page.
   window.Lattice.Shell
   ========================================================= */
(function () {
  'use strict';
  const THEME_KEY = 'lattice.theme.v1';

  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem(THEME_KEY, t); } catch (e) {}
    const lbl = document.querySelector('#themeToggle .t-label');
    if (lbl) lbl.textContent = t === 'dark' ? 'Dark' : 'Light';
  }

  function initTheme() {
    let t = 'light';
    try { t = localStorage.getItem(THEME_KEY) || 'light'; } catch (e) {}
    applyTheme(t);
    const btn = document.getElementById('themeToggle');
    if (btn) btn.addEventListener('click', () =>
      applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));
  }

  function highlightNav() {
    const file = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.top-nav a').forEach(a => {
      const href = a.getAttribute('href');
      if (href === file || (file === '' && href === 'index.html')) a.classList.add('active');
    });
  }

  window.Lattice = window.Lattice || {};
  window.Lattice.Shell = {
    init() { initTheme(); highlightNav(); },
  };
  document.addEventListener('DOMContentLoaded', () => window.Lattice.Shell.init());
})();
