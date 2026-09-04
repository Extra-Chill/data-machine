/* =========================================================
   AuroraOS 88 — desktop controller + bootstrap
   Wires icons, start menu, taskbar clock, theme, context
   menu, deep-link handling, and kicks off the boot sequence.
   ========================================================= */
'use strict';

AOS.Desktop = (function () {

  // which apps get desktop icons (in placement order)
  const DESKTOP_ICONS = ['readme', 'explorer', 'terminal', 'synth', 'paint', 'guestbook', 'now', 'settings'];
  // start-menu order (a bit fuller)
  const START_ORDER = ['readme', 'explorer', 'terminal', 'synth', 'paint', 'guestbook', 'now', 'settings'];

  let started = false;
  let wallpaperHandle = null;

  function start() {
    if (started) return;
    started = true;

    applyTheme(AOS.store.get('theme', 'night'));
    wallpaperHandle = AOS.startWallpaper(AOS.$('#wall-canvas'));
    buildIcons();
    buildStartMenu();
    initStartButton();
    initClock();
    initTray();
    initContextMenu();
    initShortcuts();

    handleDeepLink();
  }

  /* ── Desktop icons ── */
  function buildIcons() {
    const layer = AOS.$('#icon-layer');
    layer.innerHTML = '';
    const startY = 18, stepY = 100, colX = 18, colStepX = 100;
    const perCol = Math.max(3, Math.floor((window.innerHeight - 80) / stepY));

    DESKTOP_ICONS.forEach((app, i) => {
      const a = AOS.Apps.registry[app];
      if (!a) return;
      const col = Math.floor(i / perCol);
      const row = i % perCol;
      const li = AOS.el('li');
      const btn = AOS.el('button', { class: 'dicon', title: a.title, dataset: { app } },
        AOS.el('span', { class: 'dicon-glyph', html: a.icon }),
        AOS.el('span', { class: 'dicon-label', text: a.title.split(' — ')[0] }));
      btn.style.left = (colX + col * colStepX) + 'px';
      btn.style.top = (startY + row * stepY) + 'px';
      btn.addEventListener('click', () => selectIcon(btn));
      btn.addEventListener('dblclick', () => AOS.Apps.launch(app));
      btn.addEventListener('keydown', e => { if (e.key === 'Enter') AOS.Apps.launch(app); });
      li.append(btn);
      layer.append(li);
    });

    // also drop a "Recycle Bin" icon (cosmetic but interactive)
    const bin = AOS.el('button', { class: 'dicon', title: 'Recycle Bin' },
      AOS.el('span', { class: 'dicon-glyph', html: AOS.icons.trash }),
      AOS.el('span', { class: 'dicon-label', text: 'Recycle Bin' }));
    bin.style.right = '18px'; bin.style.bottom = '18px'; bin.style.left = 'auto'; bin.style.top = 'auto';
    bin.addEventListener('click', () => selectIcon(bin));
    bin.addEventListener('dblclick', () => AOS.Apps.openDoc('readme', 'Recycle Bin', AOS.icons.trash, 420, 260) &&
      flashBin());
    const li = AOS.el('li'); li.append(bin); AOS.$('#icon-layer').append(li);
  }
  function flashBin() { /* tiny easter egg: it just opens the readme */ }

  function selectIcon(btn) {
    AOS.$$('.dicon').forEach(d => d.classList.remove('selected'));
    btn.classList.add('selected');
  }

  /* ── Start menu ── */
  function buildStartMenu() {
    const ul = AOS.$('#start-apps');
    ul.innerHTML = '';
    START_ORDER.forEach(app => {
      const a = AOS.Apps.registry[app];
      if (!a) return;
      const li = AOS.el('li');
      const btn = AOS.el('button', { dataset: { app } },
        AOS.el('span', { class: 'sa-glyph', html: a.icon }),
        AOS.el('span', {}, AOS.el('span', { class: 'sa-name', text: a.title.split(' — ')[0] }), AOS.el('span', { class: 'sa-desc', text: a.desc || '' })));
      btn.addEventListener('click', () => { AOS.Apps.launch(app); closeStart(); });
      li.append(btn);
      ul.append(li);
    });

    AOS.$$('.start-power').forEach(b => b.addEventListener('click', () => {
      const p = b.dataset.power;
      closeStart();
      if (p === 'restart') restart();
      else if (p === 'logoff') logoff();
    }));
  }

  function initStartButton() {
    const btn = AOS.$('#start-btn');
    btn.addEventListener('click', (e) => { e.stopPropagation(); toggleStart(); });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#start-menu') && !e.target.closest('#start-btn')) closeStart();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeStart(); });
  }
  function toggleStart() { AOS.$('#start-menu').classList.contains('hidden') ? openStart() : closeStart(); }
  function openStart() { AOS.$('#start-menu').classList.remove('hidden'); AOS.$('#start-btn').setAttribute('aria-expanded', 'true'); }
  function closeStart() { AOS.$('#start-menu').classList.add('hidden'); AOS.$('#start-btn').setAttribute('aria-expanded', 'false'); }

  /* ── Clock ── */
  function initClock() {
    const clock = AOS.$('#clock');
    function fmt() {
      const d = new Date();
      const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
      const date = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      clock.textContent = `${time}\n${date}`;
      clock.title = d.toLocaleString();
    }
    fmt();
    setInterval(fmt, 1000 * 20);
    // tick the minute boundary a bit tighter
    setTimeout(function tickMin() { fmt(); setTimeout(tickMin, 60000); }, (60 - new Date().getSeconds()) * 1000);
  }

  /* ── Tray (sound + theme) ── */
  function initTray() {
    const sound = AOS.$('#tray-sound');
    sound.addEventListener('click', () => { AOS.Apps.launch('synth'); });
    const theme = AOS.$('#tray-theme');
    theme.addEventListener('click', toggleTheme);
  }

  /* ── Theme ── */
  function applyTheme(name) {
    document.body.classList.toggle('theme-day', name === 'day');
    AOS.store.set('theme', name);
  }
  function toggleTheme() {
    const isDay = document.body.classList.contains('theme-day');
    applyTheme(isDay ? 'night' : 'day');
  }

  /* ── Right-click context menu on the desktop ── */
  function initContextMenu() {
    const menu = AOS.$('#context-menu');
    const desktop = AOS.$('#desktop');
    desktop.addEventListener('contextmenu', (e) => {
      // only when right-clicking empty desktop / wallpaper / icon layer
      if (e.target.closest('.window') || e.target.closest('.taskbar') || e.target.closest('.start-menu')) return;
      e.preventDefault();
      const items = [
        ['📖 Open readme.txt', () => AOS.Apps.launch('readme')],
        ['🖥 Terminal', () => AOS.Apps.launch('terminal')],
        ['🎹 AuroraSynth', () => AOS.Apps.launch('synth')],
        ['__hr'],
        ['🌗 Toggle theme', toggleTheme],
        ['⚙ Settings', () => AOS.Apps.launch('settings')],
        ['__hr'],
        ['↺ Reset window layout', () => AOS.store.del('winpos')]
      ];
      menu.innerHTML = '';
      items.forEach(([label, fn]) => {
        if (label === '__hr') { menu.append(document.createElement('hr')); return; }
        const b = AOS.el('button', { text: label });
        b.addEventListener('click', () => { menu.classList.add('hidden'); fn(); });
        menu.append(b);
      });
      const mw = 180, mh = items.length * 34;
      menu.style.left = Math.min(e.clientX, window.innerWidth - mw) + 'px';
      menu.style.top = Math.min(e.clientY, window.innerHeight - mh - 50) + 'px';
      menu.classList.remove('hidden');
    });
    document.addEventListener('click', () => menu.classList.add('hidden'));
    document.addEventListener('scroll', () => menu.classList.add('hidden'), true);
    // deselect icons on empty click
    desktop.addEventListener('mousedown', (e) => {
      if (!e.target.closest('.dicon') && !e.target.closest('.window') && !e.target.closest('.taskbar') && !e.target.closest('.start-menu'))
        AOS.$$('.dicon').forEach(d => d.classList.remove('selected'));
    });
  }

  /* ── Keyboard shortcuts ── */
  function initShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Alt+T terminal, Alt+P projects — quick launch
      if (e.altKey && !e.ctrlKey && !e.metaKey) {
        if (e.key.toLowerCase() === 't') { e.preventDefault(); AOS.Apps.launch('terminal'); }
        else if (e.key.toLowerCase() === 'e') { e.preventDefault(); AOS.Apps.launch('explorer'); }
      }
    });
  }

  /* ── Power options ── */
  function restart() {
    AOS.store.set('skipboot', false);
    const out = AOS.el('div', { class: 'blackout' });
    document.body.append(out);
    setTimeout(() => location.reload(), 500);
  }
  function logoff() {
    // back to login screen
    const desktopEl = AOS.$('#desktop');
    const loginEl = AOS.$('#login');
    desktopEl.classList.add('hidden');
    loginEl.classList.remove('hidden');
  }

  /* ── Deep links: ?app=terminal  OR  via about.html/projects.html ── */
  function handleDeepLink() {
    let app = null;
    const params = new URLSearchParams(location.search);
    if (params.get('app')) app = params.get('app');
    if (window.AOS_DEEPLINK) app = window.AOS_DEEPLINK;
    const aliases = { about: 'readme', projects: 'explorer', files: 'explorer', music: 'synth', contact: 'guestbook' };
    if (app && aliases[app]) app = aliases[app];
    if (app && AOS.Apps.registry[app]) {
      setTimeout(() => AOS.Apps.launch(app), AOS.REDUCED ? 0 : 200);
    } else if (!AOS.store.get('welcomed', false)) {
      // first-time visitors get the readme to explain the gimmick
      AOS.store.set('welcomed', true);
      setTimeout(() => AOS.Apps.launch('readme'), AOS.REDUCED ? 0 : 350);
    }
  }

  return { start, toggleTheme, restart, logoff };
})();

/* ── Boot the machine ── */
window.addEventListener('DOMContentLoaded', () => AOS.Boot.go());
