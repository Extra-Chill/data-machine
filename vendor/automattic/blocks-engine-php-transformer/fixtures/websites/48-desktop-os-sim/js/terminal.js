/* =========================================================
   AuroraOS 88 — Terminal app
   A small but real command interpreter with history,
   tab-ish completion, and a few easter eggs.
   ========================================================= */
'use strict';

AOS.Terminal = (function () {

  const FILES = {
    'readme.txt':   'about',
    'now.txt':      'now',
    'the_rig.txt':  'rig',
    'games.txt':    'games',
    'discography.txt': 'disco'
  };

  const FILE_TEXT = {
    about: `vega sato — synthwave musician + indie game dev.
makes music as NEON COASTLINE, games as Tidewave Interactive.
based in a converted radio-repair shop in Kanazawa.
this whole site is a fake OS he built in vanilla JS.`,
    now: `building   DEEP STATIC's signal-translation minigame
writing    album #4 (working title: Harbor Lights Off)
listening  city-pop + rain on a tin roof
avoiding   THE LAST ARCADE, until the solstice`,
    rig: `juno-106 · dx7 · tascam 414 (broken, beloved)
flood-survivor drum machine, est. 1986
games: hand-rolled C engine + lua. tools: vanilla js.`,
    games: `LIGHTHOUSE-KEEPER  2025  slow horror, lying fog. *most played*
CASSETTE PILOT     2023  the level IS the music
DEEP STATIC        2026  early access, submarine SIGINT roguelike
THE LAST ARCADE    ----  will never ship (on purpose)`,
    disco: `Headlight Country    LP  2025
Saltwater Arcade     EP  2024
Low Tide Transmissions LP 2023
Ghost in the FM      single 2022`
  };

  function mount(body) {
    const root = AOS.el('div', { class: 'term' });
    const out = AOS.el('div', { class: 'term-out', tabindex: '0' });
    const line = AOS.el('div', { class: 'term-prompt-line' });
    const ps1 = AOS.el('span', { class: 'term-ps1', text: 'vega@aurora:~$' });
    const input = AOS.el('input', { class: 'term-input', type: 'text', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'Terminal input' });
    line.append(ps1, input);
    root.append(out, line);
    body.append(root);

    const history = [];
    let hIdx = -1;

    function write(text, cls) {
      const div = AOS.el('div', { class: 'term-line' + (cls ? ' ' + cls : '') });
      div.innerHTML = text;
      out.append(div);
      out.scrollTop = out.scrollHeight;
    }
    function echo(cmd) { write(`<span class="term-ps1">vega@aurora:~$</span> <span class="term-cmd">${esc(cmd)}</span>`); }

    const banner = [
      '<span class="term-acc">AuroraOS 88</span> — terminal <span class="term-mut">(build 2026.06)</span>',
      'connected to <span class="term-hi">vega.sato</span> @ localhost',
      'type <span class="term-amb">help</span> for commands, <span class="term-amb">about</span> for the short story.',
      ''
    ];
    banner.forEach(l => write(l));

    const COMMANDS = {
      help() {
        return [
          '<span class="term-acc">available commands</span>',
          '  <span class="term-amb">help</span>            this list',
          '  <span class="term-amb">about</span>           who is vega sato',
          '  <span class="term-amb">whoami</span>          current user',
          '  <span class="term-amb">ls</span>              list files',
          '  <span class="term-amb">cat</span> &lt;file&gt;      print a file (try: readme.txt)',
          '  <span class="term-amb">open</span> &lt;app&gt;      launch an app (terminal, synth, paint…)',
          '  <span class="term-amb">apps</span>            list launchable apps',
          '  <span class="term-amb">music</span>           start AuroraSynth + play',
          '  <span class="term-amb">theme</span>           flip night/day',
          '  <span class="term-amb">date</span>            current date/time',
          '  <span class="term-amb">echo</span> &lt;text&gt;     say something back',
          '  <span class="term-amb">clear</span>           clear the screen',
          '  <span class="term-mut">…and a couple of things i didn\'t list.</span>'
        ].join('\n');
      },
      about() { return FILE_TEXT.about; },
      whoami() { return 'vega.sato <span class="term-mut">(administrator · local account · no password set)</span>'; },
      ls() {
        return Object.keys(FILES).map(f => `<span class="term-hi">${f}</span>`).join('   ')
          + '   <span class="term-acc">Projects/</span>';
      },
      cat(args) {
        const f = (args[0] || '').toLowerCase();
        if (!f) return '<span class="term-err">usage: cat &lt;file&gt;</span>';
        if (FILES[f]) return FILE_TEXT[FILES[f]];
        return `<span class="term-err">cat: ${esc(f)}: no such file</span> <span class="term-mut">(try \`ls\`)</span>`;
      },
      apps() {
        return Object.keys(AOS.Apps.registry).map(a => `<span class="term-amb">${a}</span>`).join('   ');
      },
      open(args) {
        const a = (args[0] || '').toLowerCase();
        if (!a) return '<span class="term-err">usage: open &lt;app&gt;</span> — try `apps`';
        if (a === 'projects' || a === 'files') { AOS.Apps.launch('explorer'); return '<span class="term-mut">opening Projects…</span>'; }
        if (AOS.Apps.registry[a]) { AOS.Apps.launch(a); return `<span class="term-mut">opening ${a}…</span>`; }
        return `<span class="term-err">open: unknown app '${esc(a)}'</span>`;
      },
      music() { AOS.Apps.launch('synth'); setTimeout(() => AOS.Synth && AOS.Synth.play && AOS.Synth.play(), 250); return '<span class="term-acc">♪ booting AuroraSynth…</span>'; },
      theme() { AOS.Desktop.toggleTheme(); return `theme → <span class="term-hi">${document.body.classList.contains('theme-day') ? 'day' : 'night'}</span>`; },
      date() { return new Date().toString(); },
      echo(args) { return esc(args.join(' ')); },
      clear() { out.innerHTML = ''; return null; },

      /* ── easter eggs (not in help) ── */
      sudo(args) {
        if (args.join(' ').includes('make me a sandwich')) return '<span class="term-amb">okay. *makes sandwich*</span> 🥪';
        return '<span class="term-err">vega is not in the sudoers file. this incident will be reported.</span> <span class="term-mut">(to nobody)</span>';
      },
      hello() { return 'oh hey. you found the terminal. <span class="term-acc">good taste.</span>'; },
      hi() { return COMMANDS.hello(); },
      coffee() { return 'brewing... ☕ <span class="term-mut">error: it\'s 3 a.m., switching to tea.</span> 🍵'; },
      matrix() {
        const chars = 'アイウ01ﾊﾐﾋｰ77乱数';
        let s = '';
        for (let i = 0; i < 6; i++) {
          let row = '';
          for (let j = 0; j < 38; j++) row += chars[Math.floor(Math.random() * chars.length)];
          s += `<span class="term-acc">${row}</span>\n`;
        }
        return s + '<span class="term-mut">…wake up, vega.</span>';
      },
      konami() { document.body.animate([{ filter: 'hue-rotate(0)' }, { filter: 'hue-rotate(360deg)' }], { duration: 1200 }); return '<span class="term-acc">🌈 +30 lives</span>'; },
      reboot() { AOS.store.set('skipboot', false); setTimeout(() => location.reload(), 600); return '<span class="term-amb">rebooting AuroraOS…</span>'; },
      exit() { setTimeout(() => { const w = AOS.WM.get('terminal'); if (w) AOS.$('.win-ctl.close', w.node).click(); }, 200); return '<span class="term-mut">logout</span>'; }
    };

    function run(raw) {
      const cmd = raw.trim();
      if (!cmd) return;
      echo(cmd);
      history.unshift(cmd); hIdx = -1;
      const parts = cmd.split(/\s+/);
      const name = parts[0].toLowerCase();
      const args = parts.slice(1);
      if (name in COMMANDS) {
        const res = COMMANDS[name](args);
        if (res != null) write(res);
      } else {
        write(`<span class="term-err">aurora-sh: command not found: ${esc(name)}</span> <span class="term-mut">— type \`help\`</span>`);
      }
      write('');
    }

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { run(input.value); input.value = ''; }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (hIdx < history.length - 1) { hIdx++; input.value = history[hIdx]; setEnd(input); } }
      else if (e.key === 'ArrowDown') { e.preventDefault(); if (hIdx > 0) { hIdx--; input.value = history[hIdx]; } else { hIdx = -1; input.value = ''; } }
      else if (e.key === 'Tab') {
        e.preventDefault();
        const cur = input.value.toLowerCase();
        const pool = [...Object.keys(COMMANDS), ...Object.keys(FILES)];
        const m = pool.filter(c => c.startsWith(cur));
        if (m.length === 1) input.value = m[0];
        else if (m.length > 1) { write(m.map(x => `<span class="term-amb">${x}</span>`).join('  ')); }
      } else if (e.key === 'l' && e.ctrlKey) { e.preventDefault(); out.innerHTML = ''; }
    });

    // focus input when clicking anywhere in the terminal body
    root.addEventListener('mousedown', (e) => { if (!e.target.closest('a') && window.getSelection().isCollapsed) setTimeout(() => input.focus(), 0); });
    setTimeout(() => input.focus(), 60);
  }

  function setEnd(inp) { const v = inp.value; inp.value = ''; inp.value = v; }
  function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  return { mount };
})();
