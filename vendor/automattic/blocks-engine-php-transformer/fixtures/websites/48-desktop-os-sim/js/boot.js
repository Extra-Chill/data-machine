/* =========================================================
   AuroraOS 88 — boot / BIOS sequence + login
   Types out a playful POST log, then shows the login.
   Respects prefers-reduced-motion and a "skip boot" pref:
   either jumps straight to the desktop.
   ========================================================= */
'use strict';

AOS.Boot = (function () {

  const LINES = [
    { t: 'AURORA BIOS v8.8.26  —  (c) Tidewave Microsystems', c: 'hi' },
    { t: '' },
    { t: 'CPU: NightShift 4-core @ 3.3GHz ...... <span class="ok">[OK]</span>' },
    { t: 'MEM: 64MB synthwave RAM ............. <span class="ok">[OK]</span>' },
    { t: 'GPU: AuroraVision retro raster ...... <span class="ok">[OK]</span>' },
    { t: 'Tape drive: Tascam-414 .............. <span class="warn">[WOW/FLUTTER]</span>' },
    { t: 'Detecting audio synth ............... <span class="ok">[Juno-106]</span>' },
    { t: '' },
    { t: 'Mounting /home/vega ................. <span class="ok">[OK]</span>' },
    { t: 'Loading window manager <span class="mag">aurora-wm</span> ..... <span class="ok">[OK]</span>' },
    { t: 'Starting desktop services .......... <span class="ok">[OK]</span>' },
    { t: 'Calibrating neon ................... <span class="mag">[VERY ON]</span>' },
    { t: '' },
    { t: 'Welcome to <span class="mag">AuroraOS 88</span>. Press the user to sign in.', c: 'hi' }
  ];

  function go() {
    const bootEl = AOS.$('#boot');
    const loginEl = AOS.$('#login');
    const desktopEl = AOS.$('#desktop');
    const warm = document.body.getAttribute('data-boot') === 'warm';
    const skip = AOS.store.get('skipboot', false) === true;

    function showLogin() {
      bootEl.classList.add('hidden');
      loginEl.classList.remove('hidden');
      const c = AOS.$('#login-bg');
      AOS.startWallpaper(c);
      AOS.$('#login-user').addEventListener('click', signIn, { once: false });
      AOS.$('#login-user').focus();
    }

    function signIn() {
      loginEl.classList.add('hidden');
      desktopEl.classList.remove('hidden');
      AOS.Desktop.start();
    }

    function jumpToDesktop() {
      bootEl.classList.add('hidden');
      loginEl.classList.add('hidden');
      desktopEl.classList.remove('hidden');
      AOS.Desktop.start();
    }

    // Reduced motion OR returning user who chose skip → go straight in.
    if (AOS.REDUCED || skip) { jumpToDesktop(); return; }

    // Deep-link entry pages: skip the BIOS typing, but still show the login.
    if (warm) { showLogin(); return; }

    // Otherwise: type the boot log, then login.
    const log = AOS.$('#boot-log');
    let li = 0, ci = 0, buf = '';
    AOS.store.set('skipboot', true); // after first boot, returning visits skip

    // allow clicking/keypress to skip the typing
    function finish() {
      document.removeEventListener('keydown', onKey);
      bootEl.removeEventListener('click', finish);
      showLogin();
    }
    function onKey() { finish(); }
    document.addEventListener('keydown', onKey);
    bootEl.addEventListener('click', finish);

    function type() {
      if (li >= LINES.length) {
        log.innerHTML = renderAll() + '<span class="boot-cursor">▌</span>';
        setTimeout(() => { if (!bootEl.classList.contains('hidden')) finish(); }, 650);
        return;
      }
      const line = LINES[li];
      const plain = stripTags(line.t);
      if (ci <= plain.length) {
        // type char by char but render with tags once line is done
        buf = plain.slice(0, ci);
        log.innerHTML = renderDone() + escapeKeepTags(buf, li) + '<span class="boot-cursor">▌</span>';
        ci++;
        setTimeout(type, 8 + Math.random() * 14);
      } else {
        li++; ci = 0;
        setTimeout(type, 70);
      }
    }
    function renderDone() {
      return LINES.slice(0, li).map(l => `<div${l.c ? ` class="${l.c}"` : ''}>${l.t || '&nbsp;'}</div>`).join('');
    }
    function escapeKeepTags(text, idx) {
      // during typing show plain text; full markup applied when line completes
      const l = LINES[idx];
      return `<div${l && l.c ? ` class="${l.c}"` : ''}>${text || '&nbsp;'}</div>`;
    }
    function renderAll() {
      return LINES.map(l => `<div${l.c ? ` class="${l.c}"` : ''}>${l.t || '&nbsp;'}</div>`).join('');
    }
    function stripTags(s) { return s.replace(/<[^>]*>/g, ''); }

    type();
  }

  return { go };
})();
