/* =========================================================
   AuroraOS 88 — application registry + launchers
   Desktop icons, start menu, and the non-canvas apps:
   ReadMe, Explorer, Guestbook/Contact, Settings, viewers.
   ========================================================= */
'use strict';

AOS.Apps = (function () {

  /* read a <template data-content="..."> from the page */
  function content(key) {
    const tpl = AOS.$(`#content-store template[data-content="${key}"]`);
    return tpl ? tpl.innerHTML : `<div class="doc"><p>File not found.</p></div>`;
  }

  /* ── App registry ── */
  const registry = {
    terminal: {
      title: 'Terminal — vega@aurora',
      icon: AOS.icons.terminal,
      desc: 'A real little shell',
      width: 560, height: 360,
      open: () => AOS.WM.open({
        app: 'terminal', title: 'Terminal — vega@aurora', icon: AOS.icons.terminal,
        width: 560, height: 360, render: (body) => AOS.Terminal.mount(body)
      })
    },
    readme: {
      title: 'readme.txt',
      icon: AOS.icons.readme,
      desc: 'Start here — about Vega',
      width: 540, height: 460,
      open: () => openDoc('readme', 'readme.txt', AOS.icons.readme, 540, 460)
    },
    explorer: {
      title: 'Projects',
      icon: AOS.icons.explorer,
      desc: 'Games, records & files',
      width: 540, height: 380,
      open: () => openExplorer()
    },
    synth: {
      title: 'AuroraSynth',
      icon: AOS.icons.synth,
      desc: 'Play + visualizer',
      width: 480, height: 380,
      open: () => AOS.WM.open({
        app: 'synth', title: 'AuroraSynth', icon: AOS.icons.synth,
        width: 480, height: 380,
        render: (b, api) => AOS.Synth.mount(b, api),
        onClose: () => AOS.Synth && AOS.Synth.stop()
      })
    },
    paint: {
      title: 'NeonPaint',
      icon: AOS.icons.paint,
      desc: 'Tiny pixel doodle pad',
      width: 460, height: 400,
      open: () => AOS.WM.open({
        app: 'paint', title: 'NeonPaint', icon: AOS.icons.paint,
        width: 460, height: 400, render: (b, api) => AOS.Paint.mount(b, api)
      })
    },
    guestbook: {
      title: 'Guestbook & Contact',
      icon: AOS.icons.guestbook,
      desc: 'Say hi / get in touch',
      width: 520, height: 460,
      open: () => openGuestbook()
    },
    now: {
      title: 'now.txt',
      icon: AOS.icons.now,
      desc: 'What I\'m doing now',
      width: 460, height: 360,
      open: () => openDoc('now', 'now.txt', AOS.icons.now, 460, 360)
    },
    settings: {
      title: 'Settings',
      icon: AOS.icons.settings,
      desc: 'Theme, motion, reset',
      width: 440, height: 380,
      open: () => openSettings()
    }
  };

  function launch(app) {
    const a = registry[app];
    if (a) a.open();
    return a;
  }

  /* ── Generic document viewer ── */
  function openDoc(key, title, icon, w, h) {
    return AOS.WM.open({
      app: 'doc:' + key, title, icon, width: w, height: h, single: false,
      render: (body) => { body.innerHTML = content(key); }
    });
  }

  /* ── Explorer (Projects drive) ── */
  const FS = {
    name: '~/Projects', kind: 'root',
    children: [
      { name: 'NEON COASTLINE', kind: 'folder', icon: AOS.icons.audio, meta: 'music', children: [
        { name: 'discography.txt', kind: 'doc', icon: AOS.icons.doc, file: 'file-coastline', meta: '4 releases', title: 'discography.txt — NEON COASTLINE' },
        { name: 'Open AuroraSynth', kind: 'app', icon: AOS.icons.synth, app: 'synth', meta: 'player' }
      ]},
      { name: 'Tidewave Games', kind: 'folder', icon: AOS.icons.game, meta: 'games', children: [
        { name: 'games.txt', kind: 'doc', icon: AOS.icons.doc, file: 'file-tidewave', meta: '4 titles', title: 'games.txt — Tidewave Interactive' },
        { name: 'LIGHTHOUSE-KEEPER', kind: 'doc', icon: AOS.icons.game, file: 'file-tidewave', meta: '2025', title: 'Tidewave Interactive — games' }
      ]},
      { name: 'the_rig.txt', kind: 'doc', icon: AOS.icons.doc, file: 'file-rig', meta: 'gear & tools', title: 'the_rig.txt — gear & tools' },
      { name: 'readme.txt', kind: 'doc', icon: AOS.icons.readme, file: 'readme', meta: 'about me', title: 'readme.txt — about Vega Sato' },
      { name: 'now.txt', kind: 'doc', icon: AOS.icons.now, file: 'now', meta: 'current', title: 'now.txt' }
    ]
  };

  function openExplorer() {
    return AOS.WM.open({
      app: 'explorer', title: 'Projects', icon: AOS.icons.explorer,
      width: 540, height: 380,
      render: (body, api) => {
        const stack = [FS];
        const bar = AOS.el('div', { class: 'exp-bar' });
        const back = AOS.el('button', { class: 'exp-back', disabled: 'true' }, '← Back');
        const path = AOS.el('span', { class: 'exp-path' });
        bar.append(back, path);
        const grid = AOS.el('div', { class: 'exp-grid' });
        const status = AOS.el('div', { class: 'exp-status' });
        body.append(bar, grid, status);

        function render() {
          const cur = stack[stack.length - 1];
          path.innerHTML = stack.map(s => s === FS ? '<b>~/Projects</b>' : s.name).join(' / ');
          back.disabled = stack.length < 2;
          grid.innerHTML = '';
          (cur.children || []).forEach(node => {
            const item = AOS.el('button', { class: 'exp-item', title: node.name },
              AOS.el('span', { class: 'exp-glyph', html: node.icon || AOS.icons.doc }),
              AOS.el('span', { class: 'exp-name', text: node.name }),
              AOS.el('span', { class: 'exp-meta', text: node.meta || node.kind }));
            item.addEventListener('dblclick', () => activate(node));
            item.addEventListener('keydown', e => { if (e.key === 'Enter') activate(node); });
            item.addEventListener('click', () => { grid.querySelectorAll('.exp-item').forEach(i => i.classList.remove('sel')); item.classList.add('sel'); });
            grid.append(item);
          });
          status.textContent = `${(cur.children || []).length} items · double-click to open`;
        }
        function activate(node) {
          if (node.kind === 'folder') { stack.push(node); render(); }
          else if (node.kind === 'app') { launch(node.app); }
          else if (node.kind === 'doc') { openDoc(node.file, node.title || node.name, node.icon || AOS.icons.doc, 540, 460); }
        }
        back.addEventListener('click', () => { if (stack.length > 1) { stack.pop(); render(); } });
        render();
      }
    });
  }

  /* ── Guestbook + Contact ── */
  const MOODS = ['🌊', '🌃', '🎹', '🕹️', '📼', '✨', '🌙'];
  function defaultGuestbook() {
    return [
      { who: 'tape_ghost', mood: '📼', msg: 'Headlight Country got me through a 6-hour night drive. The tape-loop outro is everything.', when: 'May 2026' },
      { who: 'pixelmari', mood: '🕹️', msg: 'LIGHTHOUSE-KEEPER ruined my sleep schedule. The fog LIES. 11/10.', when: 'Apr 2026' },
      { who: 'kanazawa_rain', mood: '🌙', msg: 'this whole site is a fake OS?? you absolute menace. love it.', when: 'Mar 2026' }
    ];
  }

  function openGuestbook() {
    return AOS.WM.open({
      app: 'guestbook', title: 'Guestbook & Contact', icon: AOS.icons.guestbook,
      width: 520, height: 460,
      render: (body) => {
        const wrap = AOS.el('div', { class: 'gb' });
        wrap.innerHTML = `
          <h1>Guestbook & Contact</h1>
          <p class="gb-lead">Leave a note, or use it as a contact form — everything you post is stored only in <b>your</b> browser (this is a demo OS, after all). For real business: <a href="mailto:vega@neoncoastline.fm">vega@neoncoastline.fm</a>.</p>
          <form class="gb-form" novalidate>
            <div class="gb-row">
              <div class="gb-field" data-f="name">
                <label for="gb-name">Handle / name</label>
                <input id="gb-name" name="name" type="text" autocomplete="off" maxlength="32" placeholder="tape_ghost">
                <div class="gb-err" data-err="name"></div>
              </div>
              <div class="gb-field" data-f="mood">
                <label for="gb-mood">Mood</label>
                <select id="gb-mood" name="mood">${MOODS.map(m => `<option>${m}</option>`).join('')}</select>
                <div class="gb-err"></div>
              </div>
            </div>
            <div class="gb-field" data-f="email">
              <label for="gb-email">Email (optional — only if you want a reply)</label>
              <input id="gb-email" name="email" type="text" autocomplete="off" placeholder="you@somewhere.net">
              <div class="gb-err" data-err="email"></div>
            </div>
            <div class="gb-field" data-f="msg">
              <label for="gb-msg">Message</label>
              <textarea id="gb-msg" name="msg" maxlength="500" placeholder="Say something nice (or weird)…"></textarea>
              <div class="gb-err" data-err="msg"></div>
            </div>
            <button class="gb-submit" type="submit">Sign the book →</button>
            <div class="gb-toast" role="status" aria-live="polite"></div>
          </form>
          <div class="gb-entries">
            <h2>// recent signatures</h2>
            <div class="gb-list"></div>
          </div>`;
        body.append(wrap);

        const form = AOS.$('form', wrap);
        const listEl = AOS.$('.gb-list', wrap);
        const toast = AOS.$('.gb-toast', wrap);

        let entries = AOS.store.get('guestbook', null);
        if (!entries) { entries = defaultGuestbook(); AOS.store.set('guestbook', entries); }

        function renderList() {
          listEl.innerHTML = '';
          if (!entries.length) { listEl.append(AOS.el('p', { class: 'gb-empty', text: 'No signatures yet. Be the first.' })); return; }
          entries.forEach(e => {
            const card = AOS.el('div', { class: 'gb-entry' });
            card.innerHTML = `
              <div class="gb-meta"><span class="gb-who">${esc(e.who)} <span class="gb-mood">${e.mood || ''}</span></span><span>${esc(e.when)}</span></div>
              <div class="gb-msg">${esc(e.msg)}</div>`;
            listEl.append(card);
          });
        }
        renderList();

        function err(field, msg) {
          const f = AOS.$(`[data-f="${field}"]`, wrap);
          const e = AOS.$(`[data-err="${field}"]`, wrap);
          f.classList.toggle('invalid', !!msg);
          if (e) e.textContent = msg || '';
          return !msg;
        }

        form.addEventListener('submit', (ev) => {
          ev.preventDefault();
          const name = form.name.value.trim();
          const email = form.email.value.trim();
          const msg = form.msg.value.trim();
          let ok = true;
          ok = err('name', name.length < 2 ? 'Give me at least 2 characters.' : '') && ok;
          ok = err('msg', msg.length < 4 ? 'A little more than that?' : '') && ok;
          if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) ok = err('email', 'That email looks off.') && ok;
          else err('email', '');
          if (!ok) { toast.textContent = ''; return; }

          entries.unshift({
            who: name, mood: form.mood.value, msg,
            when: new Date().toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
          });
          entries = entries.slice(0, 40);
          AOS.store.set('guestbook', entries);
          renderList();
          form.reset();
          toast.textContent = email ? '✔ Signed! I\'ll reply if you left an email.' : '✔ Thanks for signing the book.';
          setTimeout(() => { toast.textContent = ''; }, 4000);
        });

        ['name', 'msg', 'email'].forEach(f => {
          form[f].addEventListener('input', () => err(f, ''));
        });
      }
    });
  }

  /* ── Settings ── */
  function openSettings() {
    return AOS.WM.open({
      app: 'settings', title: 'Settings', icon: AOS.icons.settings,
      width: 440, height: 380,
      render: (body) => {
        const wrap = AOS.el('div', { class: 'settings' });
        const day = document.body.classList.contains('theme-day');
        wrap.innerHTML = `
          <h1>System Settings</h1>
          <div class="set-row">
            <span class="set-label">Theme<span class="set-sub">Night (synthwave) / Day (pastel)</span></span>
            <button class="set-toggle" data-act="theme">${day ? '☀ Day mode' : '🌙 Night mode'}</button>
          </div>
          <div class="set-row">
            <span class="set-label">Boot screen<span class="set-sub">Replay BIOS + login next time</span></span>
            <button class="set-toggle" data-act="reboot">⟳ Replay boot</button>
          </div>
          <div class="set-row">
            <span class="set-label">Window positions<span class="set-sub">Reset all remembered window layouts</span></span>
            <button class="set-toggle" data-act="resetwin">↺ Reset layout</button>
          </div>
          <div class="set-row">
            <span class="set-label">Guestbook<span class="set-sub">Clear signatures stored in this browser</span></span>
            <button class="set-toggle" data-act="resetgb">🗑 Clear guestbook</button>
          </div>
          <p class="set-note">AuroraOS 88 · build 2026.06 · everything persists in localStorage on this device only.</p>`;
        body.append(wrap);
        wrap.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-act]'); if (!btn) return;
          const act = btn.dataset.act;
          if (act === 'theme') { AOS.Desktop.toggleTheme(); btn.textContent = document.body.classList.contains('theme-day') ? '☀ Day mode' : '🌙 Night mode'; }
          else if (act === 'reboot') { AOS.store.set('skipboot', false); location.reload(); }
          else if (act === 'resetwin') { AOS.store.del('winpos'); btn.textContent = '✔ Reset'; setTimeout(() => btn.textContent = '↺ Reset layout', 1500); }
          else if (act === 'resetgb') { AOS.store.del('guestbook'); btn.textContent = '✔ Cleared'; setTimeout(() => btn.textContent = '🗑 Clear guestbook', 1500); }
        });
      }
    });
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  return { registry, launch, openDoc, content, esc };
})();
