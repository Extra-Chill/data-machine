/* ============================================================================
   THE WAKING DEPTH  —  terminal UI
   Wires the engine to the CRT terminal DOM: input, scrollback, typewriter
   reveal, command history, the example-command buttons, the explored-map SVG,
   the HUD, and localStorage save/load + transcript persistence.

   Respects prefers-reduced-motion: typewriter + CRT flicker are disabled.
   ============================================================================ */

(function () {
  "use strict";

  const REDUCED = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const SAVE_KEY = "waking-depth-save-v1";
  const TRANSCRIPT_KEY = "waking-depth-transcript-v1";

  const engine = new window.WakingEngine();

  // DOM refs
  const out = document.getElementById("screen");
  const input = document.getElementById("cmd");
  const form = document.getElementById("cmd-form");
  const hudLoc = document.getElementById("hud-loc");
  const hudMoves = document.getElementById("hud-moves");
  const hudScore = document.getElementById("hud-score");
  const mapSvg = document.getElementById("mapsvg");
  const exampleBar = document.getElementById("examples");

  // command history
  let history = [];
  let histIdx = -1;

  /* ---------- output helpers ---------- */

  function scrollDown() {
    out.scrollTop = out.scrollHeight;
  }

  function appendBlock(text, cls) {
    const div = document.createElement("div");
    div.className = "line " + (cls || "");
    out.appendChild(div);
    if (REDUCED || cls === "echo" || cls === "sys") {
      div.textContent = text;
      scrollDown();
      return Promise.resolve();
    }
    return typeInto(div, text);
  }

  // typewriter reveal
  let typing = false;
  function typeInto(div, text) {
    return new Promise((resolve) => {
      typing = true;
      let i = 0;
      const speed = text.length > 400 ? 4 : 12; // ms-ish via rAF batching
      const chunk = text.length > 400 ? 3 : 1;
      function step() {
        if (i >= text.length) {
          typing = false;
          resolve();
          return;
        }
        div.textContent += text.slice(i, i + chunk);
        i += chunk;
        scrollDown();
        setTimeout(step, speed);
      }
      step();
    });
  }

  /* ---------- HUD + map ---------- */

  function updateHud() {
    hudLoc.textContent = window.WAKING.ROOMS[engine.room].name;
    hudMoves.textContent = engine.moves;
    hudScore.textContent = engine.score + "/" + engine.maxScore;
  }

  // Map layout: hand-placed grid coordinates for the 10 rooms.
  const MAP_POS = {
    boathouse:  [0, 3],
    jetty:      [1, 3],
    shorePath:  [1, 2],
    lightDoor:  [1, 1],
    foyer:      [1, 0],
    kitchen:    [0, 0],
    quarters:   [2, 0],
    watchRoom:  [3, 0],
    lampRoom:   [4, 0]
  };
  const MAP_LINKS = [
    ["boathouse", "jetty"], ["jetty", "shorePath"], ["shorePath", "lightDoor"],
    ["lightDoor", "foyer"], ["foyer", "kitchen"], ["foyer", "quarters"],
    ["quarters", "watchRoom"], ["watchRoom", "lampRoom"]
  ];

  function renderMap() {
    const cell = 78, pad = 22, w = 36, h = 24;
    let svg = "";
    // links first
    MAP_LINKS.forEach(([a, b]) => {
      if (!engine.visited[a] || !engine.visited[b]) return;
      const pa = MAP_POS[a], pb = MAP_POS[b];
      const x1 = pad + pa[0] * cell + w / 2, y1 = pad + pa[1] * cell + h / 2;
      const x2 = pad + pb[0] * cell + w / 2, y2 = pad + pb[1] * cell + h / 2;
      svg += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" class="mlink"/>`;
    });
    // nodes
    for (const id in MAP_POS) {
      if (!engine.visited[id]) continue;
      const p = MAP_POS[id];
      const x = pad + p[0] * cell, y = pad + p[1] * cell;
      const here = id === engine.room;
      svg += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="3" class="mnode${here ? " mhere" : ""}"/>`;
      const label = shortLabel(id);
      svg += `<text x="${x + w / 2}" y="${y + h / 2 + 3}" class="mtext">${label}</text>`;
    }
    mapSvg.innerHTML = svg ||
      `<text x="50%" y="50%" class="mtext" text-anchor="middle">— unexplored —</text>`;
  }

  function shortLabel(id) {
    return {
      boathouse: "BOAT", jetty: "JETTY", shorePath: "PATH", lightDoor: "DOOR",
      foyer: "FOYER", kitchen: "KTCHN", quarters: "QURTS", watchRoom: "WATCH",
      lampRoom: "LAMP"
    }[id] || id.slice(0, 5).toUpperCase();
  }

  /* ---------- transcript persistence ---------- */

  let transcript = [];
  function saveTranscript() {
    try {
      localStorage.setItem(TRANSCRIPT_KEY, JSON.stringify(transcript.slice(-200)));
    } catch (e) { /* ignore quota */ }
  }
  function pushTranscript(text, cls) {
    transcript.push({ t: text, c: cls || "" });
  }

  /* ---------- command processing ---------- */

  async function run(rawInput, fromHistory) {
    const raw = rawInput.trim();
    if (!raw) return;
    if (!fromHistory) {
      history.push(raw);
      if (history.length > 100) history.shift();
    }
    histIdx = history.length;

    await appendBlock("> " + raw, "echo");
    pushTranscript("> " + raw, "echo");

    const res = engine.execute(raw);

    // engine-level actions (save/load/restart)
    if (res.action === "save") {
      doSave();
    } else if (res.action === "load") {
      doLoad();
      return;
    } else if (res.action === "restart") {
      doRestart();
      return;
    } else if (res.text) {
      await appendBlock(res.text, res.win ? "win" : "");
      pushTranscript(res.text, res.win ? "win" : "");
    }

    updateHud();
    renderMap();
    saveTranscript();
    persistAuto();
  }

  /* ---------- save / load / restart ---------- */

  function persistAuto() {
    // autosave-on-every-move keeps the world recoverable on reload
    try {
      localStorage.setItem(SAVE_KEY, JSON.stringify(engine.serialize()));
    } catch (e) { /* ignore */ }
  }

  async function doSave() {
    try {
      localStorage.setItem(SAVE_KEY, JSON.stringify(engine.serialize()));
      await appendBlock("[Game saved to this browser. Use LOAD to restore it later.]", "sys");
      pushTranscript("[Game saved.]", "sys");
    } catch (e) {
      await appendBlock("[Save failed — your browser refused storage.]", "sys");
    }
  }

  async function doLoad() {
    const raw = localStorage.getItem(SAVE_KEY);
    if (!raw) {
      await appendBlock("[No saved game found in this browser.]", "sys");
      return;
    }
    try {
      const ok = engine.restore(JSON.parse(raw));
      if (!ok) throw new Error("bad save");
      clearScreen();
      await appendBlock("[Saved game loaded.]", "sys");
      await appendBlock(engine.describeRoom(false), "");
      updateHud();
      renderMap();
    } catch (e) {
      await appendBlock("[That save is corrupt and could not be loaded.]", "sys");
    }
  }

  async function doRestart() {
    if (!confirm("Restart THE WAKING DEPTH? Your current watch will be lost.")) {
      await appendBlock("[Restart cancelled. The watch continues.]", "sys");
      return;
    }
    engine.reset();
    transcript = [];
    clearScreen();
    localStorage.removeItem(SAVE_KEY);
    localStorage.removeItem(TRANSCRIPT_KEY);
    await intro();
    updateHud();
    renderMap();
  }

  function clearScreen() {
    out.innerHTML = "";
  }

  /* ---------- intro ---------- */

  async function intro() {
    for (const line of window.WAKING.INTRO) {
      await appendBlock(line, line === window.WAKING.INTRO[0] ? "title" : "");
      pushTranscript(line, "");
    }
    await appendBlock(engine.describeRoom(false), "");
  }

  /* ---------- example command chips ---------- */

  const EXAMPLES = [
    "look", "examine door", "inventory", "west", "take all",
    "read journal", "use oilcan on lamp", "map", "score", "help"
  ];
  function buildExamples() {
    EXAMPLES.forEach((ex) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip";
      b.textContent = ex;
      b.addEventListener("click", () => {
        input.value = ex;
        input.focus();
        form.requestSubmit();
      });
      exampleBar.appendChild(b);
    });
  }

  /* ---------- input handlers ---------- */

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = "";
    run(v);
  });

  input.addEventListener("keydown", (e) => {
    if (e.key === "ArrowUp") {
      e.preventDefault();
      if (history.length === 0) return;
      histIdx = Math.max(0, histIdx - 1);
      input.value = history[histIdx] || "";
      moveCaretEnd();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      if (history.length === 0) return;
      histIdx = Math.min(history.length, histIdx + 1);
      input.value = history[histIdx] || "";
      moveCaretEnd();
    } else if (e.key === "l" && e.ctrlKey) {
      e.preventDefault();
      clearScreen();
    }
  });

  function moveCaretEnd() {
    requestAnimationFrame(() => {
      input.selectionStart = input.selectionEnd = input.value.length;
    });
  }

  // keep focus on the input when clicking the screen
  document.getElementById("terminal").addEventListener("click", (e) => {
    if (window.getSelection().toString()) return; // allow copy
    if (e.target.tagName !== "BUTTON" && e.target.tagName !== "A") input.focus();
  });

  /* ---------- toolbar buttons ---------- */

  document.querySelectorAll("[data-cmd]").forEach((btn) => {
    btn.addEventListener("click", () => {
      run(btn.getAttribute("data-cmd"));
      input.focus();
    });
  });

  /* ---------- boot ---------- */

  async function boot() {
    buildExamples();
    // attempt to resume an autosave silently? No — start fresh, offer LOAD.
    await intro();
    updateHud();
    renderMap();
    input.focus();
  }

  // CRT power-on flicker (skipped for reduced motion)
  if (!REDUCED) {
    document.getElementById("terminal").classList.add("power-on");
  }

  boot();
})();
