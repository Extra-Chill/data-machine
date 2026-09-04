/* =========================================================
   DRIFTLANE — Shared shell (theme + sidebar) for every page
   ========================================================= */
(function () {
  'use strict';
  const THEME_KEY = 'driftlane.theme';

  /* theme */
  const saved = (() => { try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; } })();
  if (saved) document.body.setAttribute('data-theme', saved);

  function syncThemeLabel() {
    const t = document.body.getAttribute('data-theme') || 'dark';
    document.querySelectorAll('[data-theme-label]').forEach(el => {
      el.textContent = t === 'dark' ? 'Dark' : 'Light';
    });
  }
  syncThemeLabel();

  document.addEventListener('click', (e) => {
    if (e.target.closest('#themeToggle')) {
      const cur = document.body.getAttribute('data-theme') || 'dark';
      const next = cur === 'dark' ? 'light' : 'dark';
      document.body.setAttribute('data-theme', next);
      try { localStorage.setItem(THEME_KEY, next); } catch (e2) {}
      syncThemeLabel();
    }
    if (e.target.closest('#menuToggle')) {
      document.body.classList.toggle('nav-open');
    }
    if (e.target.closest('.nav-scrim')) {
      document.body.classList.remove('nav-open');
    }
  });

  /* mark active nav link */
  const here = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.side-link').forEach(a => {
    const href = a.getAttribute('href');
    if (href === here || (here === '' && href === 'index.html')) a.classList.add('active');
  });

  /* live clock in topbar if present */
  const clock = document.getElementById('clock');
  if (clock) {
    const tick = () => {
      const d = new Date();
      clock.textContent = d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) +
        ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    };
    tick(); setInterval(tick, 30000);
  }

  /* keyboard shortcut help overlay (press ?) */
  document.addEventListener('keydown', (e) => {
    if (e.key === '?' && !e.target.matches('input,textarea,select')) {
      toggleHelp();
    } else if (e.key === 'Escape') {
      const h = document.getElementById('shortcutHelp');
      if (h && !h.hidden) h.hidden = true;
    } else if (e.key === '/' && !e.target.matches('input,textarea,select')) {
      const s = document.getElementById('searchInput');
      if (s) { e.preventDefault(); s.focus(); }
    } else if ((e.key === 'n' || e.key === 'N') && !e.target.matches('input,textarea,select')) {
      // quick add into first column
      const first = document.querySelector('[data-add-card]');
      if (first) { e.preventDefault(); first.click(); }
    }
  });

  function toggleHelp() {
    let h = document.getElementById('shortcutHelp');
    if (h) { h.hidden = !h.hidden; return; }
    h = document.createElement('div');
    h.id = 'shortcutHelp';
    h.className = 'help-overlay';
    h.innerHTML =
      '<div class="help-card" role="dialog" aria-label="Keyboard shortcuts">' +
      '<h3>Keyboard shortcuts</h3>' +
      '<dl>' +
      '<div><dt>/</dt><dd>Focus search</dd></div>' +
      '<div><dt>N</dt><dd>New card in first column</dd></div>' +
      '<div><dt>Tab</dt><dd>Move focus between cards</dd></div>' +
      '<div><dt>Enter</dt><dd>Open focused card</dd></div>' +
      '<div><dt>← →</dt><dd>Move focused card between columns</dd></div>' +
      '<div><dt>↑ ↓</dt><dd>Reorder focused card in column</dd></div>' +
      '<div><dt>F2 / dbl-click</dt><dd>Rename a column</dd></div>' +
      '<div><dt>Esc</dt><dd>Close dialog / cancel</dd></div>' +
      '<div><dt>?</dt><dd>Toggle this help</dd></div>' +
      '</dl>' +
      '<button class="btn btn-primary" id="helpClose">Got it</button>' +
      '</div><div class="help-scrim"></div>';
    document.body.appendChild(h);
    h.addEventListener('click', (ev) => {
      if (ev.target.closest('#helpClose') || ev.target.closest('.help-scrim')) h.hidden = true;
    });
  }

  // open focused card with Enter
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const c = document.activeElement;
      if (c && c.classList && c.classList.contains('card')) {
        e.preventDefault();
        c.click();
      }
    }
  });
})();
