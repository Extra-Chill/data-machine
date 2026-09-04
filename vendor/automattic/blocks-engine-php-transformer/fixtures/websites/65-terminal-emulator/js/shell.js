/* =========================================================
   Tideglass Shell — Terminal UI, line editing, runner
   Wires the DOM terminal to the parser, FS, and commands.
   Implements: prompt + blinking cursor, scrollback,
   history (Up/Down + Ctrl+R), TAB completion, line editing
   (Ctrl+A/E/U/K/W, Left/Right/Home/End), pipes & redirects,
   themes, CRT toggle, font size, tour, matrix.
   ========================================================= */
'use strict';

// Global HTML-escape used across modules (commands emit safe HTML).
function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

window.TG = window.TG || {};

(function () {
  const FS = TG.FS;
  const Parser = TG.Parser;
  const Commands = TG.Commands;

  const LS_ENV   = 'tideglass.env.v1';
  const LS_HIST  = 'tideglass.history.v1';
  const LS_PREFS = 'tideglass.prefs.v1';

  // ---- DOM refs -----------------------------------------------------
  const screen   = document.getElementById('screen');
  const output   = document.getElementById('output');
  const inputLine= document.getElementById('input-line');
  const promptEl = document.getElementById('prompt');
  const editEl   = document.getElementById('edit');     // contenteditable-ish span
  const cursorEl = document.getElementById('cursor');
  const term     = document.getElementById('terminal');

  // ---- Shell state --------------------------------------------------
  const shell = {
    cwd: '/home/mara',
    prevCwd: '/home/mara',
    history: [],
    scheme: 'harbor',
    bootTime: Date.now(),
    env: {},
    setCwd(p) { this.prevCwd = this.cwd; this.cwd = p || '/'; },
    clearScreen() { output.innerHTML = ''; },
    saveEnv() { try { localStorage.setItem(LS_ENV, JSON.stringify(this.env)); } catch (e) {} },
    uptime() {
      const s = Math.floor((Date.now() - this.bootTime) / 1000);
      const m = Math.floor(s / 60), sec = s % 60;
      return m ? `${m} min ${sec} sec` : `${sec} sec`;
    },
    startTour, runMatrix
  };

  // line-editor state
  let buffer = '';
  let caret = 0;          // index into buffer
  let histIdx = -1;       // -1 = editing fresh line
  let histDraft = '';
  let reverseSearch = null; // {query, results, idx}

  // ---- Defaults & prefs ---------------------------------------------
  function defaultEnv() {
    return {
      USER: 'mara', HOME: '/home/mara', HOSTNAME: 'harborlight',
      SHELL: '/bin/glass', PATH: '/bin:/usr/bin', PWD: '/home/mara',
      EDITOR: 'glass', PAGER: 'less', LANG: 'en_US.UTF-8',
      TERM: 'tideglass-256color', PROJECT_ROOT: '/home/mara/projects',
      __status: 0
    };
  }

  function loadPrefs() {
    try {
      const p = JSON.parse(localStorage.getItem(LS_PREFS) || '{}');
      if (p.scheme) shell.scheme = p.scheme;
      applyScheme(p.scheme || 'harbor');
      if (p.crt) document.body.classList.add('crt');
      if (p.fontSize) setFontSize(p.fontSize, false);
      document.getElementById('scheme-select').value = shell.scheme;
      document.getElementById('crt-toggle').checked = !!p.crt;
    } catch (e) { applyScheme('harbor'); }
  }
  function savePrefs() {
    try {
      localStorage.setItem(LS_PREFS, JSON.stringify({
        scheme: shell.scheme,
        crt: document.body.classList.contains('crt'),
        fontSize: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--term-font') ) || 15
      }));
    } catch (e) {}
  }

  function applyScheme(name) {
    shell.scheme = name;
    document.body.setAttribute('data-scheme', name);
  }
  function setFontSize(px, persist = true) {
    px = Math.max(11, Math.min(24, px));
    document.documentElement.style.setProperty('--term-font', px + 'px');
    if (persist) savePrefs();
  }

  // ---- Output helpers ----------------------------------------------
  function writeBlock(html, cls) {
    const div = document.createElement('div');
    div.className = 'line' + (cls ? ' ' + cls : '');
    div.innerHTML = html;
    output.appendChild(div);
    scrollToBottom();
    return div;
  }
  function writeText(text, cls) {
    if (text === '') return;
    // Command output is trusted HTML: commands escape user-derived content
    // themselves (via esc) and add their own coloring spans. Newlines are
    // preserved by white-space:pre-wrap on .line.
    const div = document.createElement('div');
    div.className = 'line' + (cls ? ' ' + cls : '');
    div.innerHTML = text.replace(/\n$/, '');
    output.appendChild(div);
    scrollToBottom();
  }
  function scrollToBottom() { screen.scrollTop = screen.scrollHeight; }

  function promptString() {
    const home = shell.env.HOME || '/home/mara';
    let disp = shell.cwd;
    if (disp === home) disp = '~';
    else if (disp.startsWith(home + '/')) disp = '~' + disp.slice(home.length);
    return `<span class="p-user">${esc(shell.env.USER)}@${esc(shell.env.HOSTNAME)}</span><span class="p-colon">:</span><span class="p-path">${esc(disp)}</span><span class="p-lambda">λ</span> `;
  }
  function renderPrompt() { promptEl.innerHTML = promptString(); }

  // Echo the entered command into scrollback as a finished prompt line.
  function echoCommand(line) {
    writeBlock(promptString() + `<span class="cmd-echo">${esc(line)}</span>`, 'prompt-echo');
  }

  // ---- The visible editable line (custom caret) ---------------------
  function renderLine() {
    if (reverseSearch) { renderReverseSearch(); return; }
    const before = esc(buffer.slice(0, caret));
    const at = caret < buffer.length ? esc(buffer[caret]) : '&nbsp;';
    const after = esc(buffer.slice(caret + 1));
    editEl.innerHTML =
      `<span class="pre">${before}</span>` +
      `<span class="cursor" id="cursor">${at}</span>` +
      `<span class="post">${after}</span>`;
    scrollToBottom();
  }

  function renderReverseSearch() {
    const rs = reverseSearch;
    const match = rs.results[rs.idx] || '';
    promptEl.innerHTML = `<span class="p-rsearch">(reverse-i-search)\`${esc(rs.query)}': </span>`;
    editEl.innerHTML = `<span class="pre">${esc(match)}</span><span class="cursor">&nbsp;</span>`;
    scrollToBottom();
  }

  function setBuffer(str, caretPos) {
    buffer = str;
    caret = caretPos === undefined ? str.length : Math.max(0, Math.min(caretPos, str.length));
    renderLine();
  }

  // ---- Run a full input line ---------------------------------------
  function submit() {
    const line = buffer;
    echoCommand(line);
    if (line.trim()) {
      shell.history.push(line);
      try { localStorage.setItem(LS_HIST, JSON.stringify(shell.history.slice(-500))); } catch (e) {}
    }
    histIdx = -1; histDraft = '';
    setBuffer('', 0);
    if (line.trim()) execLine(line.trim());
    shell.env.PWD = shell.cwd;
    renderPrompt();
  }

  // Parse + run a line: a series of pipelines joined by ; && ||.
  function execLine(line) {
    const aliased = applyAliases(line);
    const parsed = Parser.parse(aliased, shell.env);
    if (parsed.error) { writeText('glass: ' + parsed.error + '\n', 'stderr'); shell.env.__status = 2; return; }
    if (!parsed.segments.length) return;

    let prevJoiner = null; // how we arrived at this segment
    for (const seg of parsed.segments) {
      // honor && / || short-circuiting based on previous status
      if (prevJoiner === '&&' && shell.env.__status !== 0) { prevJoiner = seg.joiner; continue; }
      if (prevJoiner === '||' && shell.env.__status === 0) { prevJoiner = seg.joiner; continue; }
      runPipeline(seg.pipeline);
      prevJoiner = seg.joiner;
    }
  }

  // Run one pipeline (cmd | cmd | ...) applying redirects.
  function runPipeline(pipeline) {
    let stdin = '';
    let lastStatus = 0;

    for (let i = 0; i < pipeline.length; i++) {
      const cmd = pipeline[i];

      // input redirection (<) overrides piped stdin for this stage
      const inRedir = cmd.redirects.find(r => r.op === '<');
      if (inRedir) {
        const node = FS.getNode(FS.resolve(shell.cwd, inRedir.target));
        if (!node || node.type === 'dir') { writeText(`glass: ${inRedir.target}: No such file\n`, 'stderr'); lastStatus = 1; break; }
        stdin = node.content;
      }

      // Re-expand variables against the CURRENT env so that $? / $VARS set
      // by an earlier segment in the same line are visible here.
      const argv = cmd.argv.map((v, idx) => {
        const re = cmd.argvParts && Parser.materialize(cmd.argvParts[idx], shell.env);
        return re === null || re === undefined ? v : re;
      });
      const name = argv[0];
      // glob-expand arguments (except the command name)
      const expanded = [name];
      for (let a = 1; a < argv.length; a++) {
        expanded.push(...FS.glob(shell.cwd, argv[a]));
      }

      let result;
      if (!Commands.has(name)) {
        result = { out: '', err: `glass: command not found: ${name}\n`, status: 127 };
      } else {
        const ctx = { argv: expanded, stdin, env: shell.env, shell, fs: FS };
        try { result = Commands.run(name, ctx); }
        catch (e) { result = { out: '', err: `glass: ${name}: ${e.message}\n`, status: 1 }; }
      }

      lastStatus = result.status;
      const isLast = i === pipeline.length - 1;

      // output redirection
      const outRedir = cmd.redirects.find(r => r.op === '>' || r.op === '>>');
      if (outRedir) {
        const plain = stripHtml(result.out);
        const r = outRedir.op === '>>'
          ? FS.appendFile(shell.cwd, outRedir.target, plain)
          : FS.writeFile(shell.cwd, outRedir.target, plain);
        if (!r.ok) writeText(`glass: ${r.err}\n`, 'stderr');
        if (result.err) writeText(result.err, 'stderr');
        stdin = '';
      } else if (isLast) {
        if (result.out) writeText(result.out, 'stdout');
        if (result.err) writeText(result.err, 'stderr');
      } else {
        // pipe: feed plain text forward; show stderr immediately
        if (result.err) writeText(result.err, 'stderr');
        stdin = stripHtml(result.out);
      }
    }
    shell.env.__status = lastStatus;
  }

  function stripHtml(html) {
    if (html.indexOf('<') === -1 && html.indexOf('&') === -1) return html;
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent;
  }

  // Expand leading alias on a line (display-level convenience).
  const ALIASES = { ll: 'ls -l', la: 'ls -a', '..': 'cd ..', l: 'ls' };
  function applyAliases(line) {
    const first = line.split(/\s+/)[0];
    if (ALIASES[first] !== undefined) return ALIASES[first] + line.slice(first.length);
    return line;
  }

  // ===================================================================
  //  TAB COMPLETION
  // ===================================================================
  function complete() {
    const upto = buffer.slice(0, caret);
    const tokens = upto.split(/\s+/);
    const isFirst = tokens.length <= 1;
    const frag = tokens[tokens.length - 1] || '';

    let candidates;
    if (isFirst && !frag.includes('/')) {
      candidates = Commands.names().concat(Object.keys(ALIASES)).filter(c => c.startsWith(frag));
    } else {
      candidates = completePath(frag);
    }
    if (!candidates.length) return;

    if (candidates.length === 1) {
      const completion = candidates[0];
      replaceLastToken(frag, completion + (completion.endsWith('/') ? '' : ' '));
    } else {
      // common prefix
      const common = longestCommonPrefix(candidates);
      if (common.length > frag.length) {
        replaceLastToken(frag, common);
      } else {
        // list options
        echoLineEcho();
        const cols = candidates.map(c => {
          const base = c.endsWith('/') ? c : c;
          const isDir = c.endsWith('/');
          return isDir ? `<span class="t-dir">${esc(c)}</span>` : esc(c);
        }).join('   ');
        writeText(cols + '\n', 'completion');
      }
    }
  }

  function echoLineEcho() {
    writeBlock(promptString() + `<span class="cmd-echo">${esc(buffer)}</span>`, 'prompt-echo');
  }

  function completePath(frag) {
    const slash = frag.lastIndexOf('/');
    const dirPart = slash >= 0 ? frag.slice(0, slash + 1) : '';
    const namePart = slash >= 0 ? frag.slice(slash + 1) : frag;
    const dirAbs = FS.resolve(shell.cwd, dirPart || '.');
    const node = FS.getNode(dirAbs);
    if (!node || node.type !== 'dir') return [];
    return Object.keys(node.children)
      .filter(n => n.startsWith(namePart) && (namePart.startsWith('.') || !n.startsWith('.')))
      .sort()
      .map(n => dirPart + n + (node.children[n].type === 'dir' ? '/' : ''));
  }

  function replaceLastToken(frag, replacement) {
    const start = caret - frag.length;
    const newBuf = buffer.slice(0, start) + replacement + buffer.slice(caret);
    setBuffer(newBuf, start + replacement.length);
  }

  function longestCommonPrefix(arr) {
    if (!arr.length) return '';
    let prefix = arr[0];
    for (const s of arr) {
      while (!s.startsWith(prefix)) prefix = prefix.slice(0, -1);
      if (!prefix) break;
    }
    return prefix;
  }

  // ===================================================================
  //  HISTORY NAVIGATION + REVERSE SEARCH
  // ===================================================================
  function historyPrev() {
    if (!shell.history.length) return;
    if (histIdx === -1) { histDraft = buffer; histIdx = shell.history.length; }
    if (histIdx > 0) { histIdx--; setBuffer(shell.history[histIdx]); }
  }
  function historyNext() {
    if (histIdx === -1) return;
    if (histIdx < shell.history.length - 1) { histIdx++; setBuffer(shell.history[histIdx]); }
    else { histIdx = -1; setBuffer(histDraft); }
  }

  function startReverseSearch() {
    reverseSearch = { query: '', results: [], idx: 0 };
    updateReverseSearch();
  }
  function updateReverseSearch() {
    const rs = reverseSearch;
    rs.results = [];
    for (let i = shell.history.length - 1; i >= 0; i--) {
      if (shell.history[i].includes(rs.query)) rs.results.push(shell.history[i]);
    }
    rs.idx = 0;
    renderReverseSearch();
  }
  function endReverseSearch(accept) {
    const match = reverseSearch.results[reverseSearch.idx] || '';
    reverseSearch = null;
    renderPrompt();
    if (accept && match) setBuffer(match);
    else renderLine();
  }

  // ===================================================================
  //  KEY HANDLING
  // ===================================================================
  function onKey(e) {
    // Reverse-search mode captures most keys
    if (reverseSearch) {
      if (e.key === 'Escape' || (e.ctrlKey && e.key === 'g')) { e.preventDefault(); endReverseSearch(false); return; }
      if (e.key === 'Enter') { e.preventDefault(); endReverseSearch(true); submit(); return; }
      if (e.ctrlKey && e.key === 'r') { e.preventDefault(); if (reverseSearch.idx < reverseSearch.results.length - 1) reverseSearch.idx++; renderReverseSearch(); return; }
      if (e.key === 'Backspace') { e.preventDefault(); reverseSearch.query = reverseSearch.query.slice(0, -1); updateReverseSearch(); return; }
      if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) { e.preventDefault(); reverseSearch.query += e.key; updateReverseSearch(); return; }
      return;
    }

    // Ctrl combos (line editing)
    if (e.ctrlKey && !e.altKey && !e.metaKey) {
      switch (e.key) {
        case 'a': e.preventDefault(); caret = 0; renderLine(); return;
        case 'e': e.preventDefault(); caret = buffer.length; renderLine(); return;
        case 'u': e.preventDefault(); setBuffer(buffer.slice(caret), 0); return;
        case 'k': e.preventDefault(); setBuffer(buffer.slice(0, caret), caret); return;
        case 'w': e.preventDefault(); killWord(); return;
        case 'l': e.preventDefault(); shell.clearScreen(); return;
        case 'r': e.preventDefault(); startReverseSearch(); return;
        case 'c': e.preventDefault();
          echoCommand(buffer + '^C'); setBuffer('', 0); histIdx = -1; return;
        case 'd': e.preventDefault();
          if (!buffer) writeText('logout (just kidding — this is a browser tab)\n', 'stdout');
          return;
        default: return;
      }
    }

    switch (e.key) {
      case 'Enter': e.preventDefault(); submit(); return;
      case 'Tab': e.preventDefault(); complete(); return;
      case 'ArrowUp': e.preventDefault(); historyPrev(); return;
      case 'ArrowDown': e.preventDefault(); historyNext(); return;
      case 'ArrowLeft': e.preventDefault(); if (caret > 0) { caret--; renderLine(); } return;
      case 'ArrowRight': e.preventDefault(); if (caret < buffer.length) { caret++; renderLine(); } return;
      case 'Home': e.preventDefault(); caret = 0; renderLine(); return;
      case 'End': e.preventDefault(); caret = buffer.length; renderLine(); return;
      case 'Backspace': e.preventDefault();
        if (caret > 0) setBuffer(buffer.slice(0, caret - 1) + buffer.slice(caret), caret - 1);
        return;
      case 'Delete': e.preventDefault();
        if (caret < buffer.length) setBuffer(buffer.slice(0, caret) + buffer.slice(caret + 1), caret);
        return;
      default:
        if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) {
          e.preventDefault();
          setBuffer(buffer.slice(0, caret) + e.key + buffer.slice(caret), caret + 1);
        }
    }
  }

  function killWord() {
    let i = caret;
    while (i > 0 && buffer[i - 1] === ' ') i--;
    while (i > 0 && buffer[i - 1] !== ' ') i--;
    setBuffer(buffer.slice(0, i) + buffer.slice(caret), i);
  }

  // Keep focus on the hidden capture; clicking the terminal focuses it.
  const capture = document.getElementById('capture');
  function focusInput() { capture.focus(); document.body.classList.add('focused'); }
  term.addEventListener('mousedown', (e) => {
    // allow text selection; only focus if not selecting
    setTimeout(() => { if (!window.getSelection().toString()) focusInput(); }, 0);
  });
  capture.addEventListener('keydown', onKey);
  capture.addEventListener('blur', () => document.body.classList.remove('focused'));

  // Paste support
  capture.addEventListener('paste', (e) => {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\n/g, ' ');
    setBuffer(buffer.slice(0, caret) + text + buffer.slice(caret), caret + text.length);
  });

  // ===================================================================
  //  TOUR
  // ===================================================================
  // Each step is either {say:'...'} (narration) or {do:'command'} (run it).
  const TOUR_STEPS = [
    { say: "Welcome to the Tideglass tour. I'll feed a few commands in — watch the output." },
    { do: 'pwd' },
    { do: 'ls' },
    { do: 'cat README.txt' },
    { say: 'Pipes chain commands. This counts the markdown files under projects:' },
    { do: 'find projects -name "*.md" | wc -l' },
    { say: "Redirects write output to files. Let's make one:" },
    { do: 'echo "the spike is not the tide" > notes/finding.txt' },
    { do: 'cat notes/finding.txt' },
    { say: 'Variables expand with $. The exit status of the last command is $?:' },
    { do: 'echo "user=$USER home=$HOME last-status=$?"' },
    { say: "That's the gist. Type 'help' for everything, or just explore. Tour complete!" }
  ];
  let tourTimer = null;
  function startTour() {
    if (tourTimer) return;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let i = 0;
    const step = () => {
      if (i >= TOUR_STEPS.length) { tourTimer = null; return; }
      const s = TOUR_STEPS[i++];
      if (s.do) {
        echoCommand(s.do);
        execLine(s.do);
        shell.env.PWD = shell.cwd;
        renderPrompt();
      } else {
        writeText(`<span class="t-tour">▸ ${esc(s.say)}</span>\n`, 'stdout');
      }
      tourTimer = setTimeout(step, reduce ? 350 : 1100);
    };
    step();
  }

  // ===================================================================
  //  MATRIX easter egg
  // ===================================================================
  function runMatrix() {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cols = 60, rows = reduce ? 4 : 18;
    const chars = 'アイウエカキクケコサシ01<>{}[]/$#%&*';
    let r = 0;
    const block = writeBlock('', 'matrix-block');
    const tick = () => {
      let line = '';
      for (let c = 0; c < cols; c++) {
        const ch = chars[Math.floor(Math.random() * chars.length)];
        const cls = Math.random() < 0.1 ? 'mx-bright' : 'mx-dim';
        line += `<span class="${cls}">${esc(ch)}</span>`;
      }
      block.innerHTML += line + '\n';
      scrollToBottom();
      if (++r < rows) setTimeout(tick, reduce ? 200 : 70);
      else { block.innerHTML += '<span class="t-accent">Wake up.</span>\n'; scrollToBottom(); }
    };
    tick();
  }

  // ===================================================================
  //  BANNER + BOOT
  // ===================================================================
  function banner() {
    const art =
`<span class="t-accent"> ╔════════════════════════════════════════════════╗
 ║   ~ Tideglass ~   a shell that runs on nothing  ║
 ║                   but a browser tab.            ║
 ╚════════════════════════════════════════════════╝</span>`;
    writeText(art + '\n', 'banner');
    const motd = FS.getNode('/etc/motd');
    if (motd) writeText('\n' + esc(motd.content) + '\n', 'motd');
    writeText('\nType <span class="t-accent">help</span> for commands, <span class="t-accent">tour</span> for a walkthrough, or just start exploring.\n', 'stdout');
  }

  // ---- Toolbar wiring -----------------------------------------------
  function wireToolbar() {
    document.getElementById('scheme-select').addEventListener('change', (e) => {
      applyScheme(e.target.value); savePrefs(); focusInput();
    });
    const crt = document.getElementById('crt-toggle');
    crt.addEventListener('change', () => {
      document.body.classList.toggle('crt', crt.checked); savePrefs(); focusInput();
    });
    document.getElementById('font-inc').addEventListener('click', () => {
      setFontSize((parseInt(getComputedStyle(document.documentElement).getPropertyValue('--term-font')) || 15) + 1); focusInput();
    });
    document.getElementById('font-dec').addEventListener('click', () => {
      setFontSize((parseInt(getComputedStyle(document.documentElement).getPropertyValue('--term-font')) || 15) - 1); focusInput();
    });
    document.getElementById('reset-fs').addEventListener('click', () => {
      if (confirm('Reset the virtual filesystem to its original seeded state? Files you created will be lost.')) {
        FS.reset();
        shell.cwd = '/home/mara'; shell.prevCwd = '/home/mara';
        shell.clearScreen();
        writeText('<span class="t-accent">Filesystem reset.</span> Back to a clean harbor.\n', 'stdout');
        renderPrompt(); focusInput();
      }
    });
  }

  // ---- init ---------------------------------------------------------
  function init() {
    // env
    shell.env = defaultEnv();
    try {
      const saved = JSON.parse(localStorage.getItem(LS_ENV) || 'null');
      if (saved) shell.env = Object.assign(defaultEnv(), saved);
    } catch (e) {}
    // filesystem
    if (!FS.load()) FS.save();
    // history
    try { shell.history = JSON.parse(localStorage.getItem(LS_HIST) || '[]'); } catch (e) { shell.history = []; }

    loadPrefs();
    wireToolbar();
    renderPrompt();
    renderLine();
    banner();
    focusInput();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
