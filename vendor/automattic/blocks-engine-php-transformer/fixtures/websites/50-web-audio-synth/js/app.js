/* =========================================================
   VOLTROVE — Filament One playground wiring (index.html)
   Builds the on-screen keyboard, binds every control to the
   live audio engine, drives the step sequencer UI, and saves
   user patches to localStorage.
   ========================================================= */

(function () {
  'use strict';

  const V = window.Voltrove;
  const VP = window.VoltrovePatches;
  if (!V) return;

  const engine = new V.SynthEngine();
  let seq = null;
  let viz = null;
  let enabled = false;

  /* ── DOM refs ───────────────────────────────────────── */
  const $ = function (s, r) { return (r || document).querySelector(s); };
  const $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  const gate = $('#audio-gate');
  const gateBtn = $('#enable-audio');
  const statusEl = $('#engine-status');

  /* =========================================================
     1. Audio gate — create context on first user gesture
     ========================================================= */
  function enableAudio() {
    if (enabled) return;
    if (!engine.supported) {
      showUnsupported();
      return;
    }
    engine.init();
    engine.resume().then(setupAfterEnable).catch(setupAfterEnable);
  }

  function setupAfterEnable() {
    enabled = true;
    if (gate) gate.classList.add('is-hidden');
    document.body.classList.add('audio-on');
    if (statusEl) {
      statusEl.textContent = 'live · ' + Math.round(engine.ctx.sampleRate / 1000) + 'kHz';
      statusEl.classList.add('is-live');
    }
    // start visualizer
    if (viz) viz.start();
    // push current control values into the engine
    syncAllControls();
  }

  function showUnsupported() {
    if (gate) {
      gate.innerHTML = '<div class="gate-card"><h2>Web Audio unavailable</h2>' +
        '<p>This browser does not expose the Web Audio API, so the Filament One can’t make sound here. ' +
        'Try a recent version of Chrome, Firefox, Safari or Edge.</p></div>';
    }
    if (statusEl) { statusEl.textContent = 'no audio'; }
  }

  if (gateBtn) gateBtn.addEventListener('click', enableAudio);

  /* =========================================================
     2. On-screen keyboard
     ========================================================= */
  // Computer-keyboard → semitone map (A-row = white keys, W/E/T/Y/U = black)
  // Lower octave starts on Z too, for a two-row layout.
  const KEY_MAP = {
    // upper row (octave 0)
    'a': 0, 'w': 1, 's': 2, 'e': 3, 'd': 4, 'f': 5, 't': 6,
    'g': 7, 'y': 8, 'h': 9, 'u': 10, 'j': 11, 'k': 12, 'o': 13,
    'l': 14, 'p': 15, ';': 16,
    // lower row (octave -1) for bass
    'z': -12, 'x': -10, 'c': -8, 'v': -7, 'b': -5, 'n': -3, 'm': -1, ',': 0
  };

  let baseOctave = 4;           // middle region
  const baseMidi = function () { return 12 * (baseOctave + 1); }; // C of baseOctave
  const heldKeys = {};          // key → midi (for keyup)

  const kbEl = $('#keyboard');

  function buildKeyboard() {
    if (!kbEl) return;
    kbEl.innerHTML = '';
    // build two octaves of keys from base
    const start = baseMidi();
    const count = 24; // two octaves
    // white & black layout
    const whiteOffsets = [0, 2, 4, 5, 7, 9, 11];
    const blackAfter = { 0: 1, 2: 3, 5: 6, 7: 8, 9: 10 }; // semitone → has black after

    // We render white keys as the row, black keys absolutely positioned.
    const whites = [];
    for (let m = start; m < start + count; m++) {
      if (whiteOffsets.indexOf(m % 12) !== -1) whites.push(m);
    }

    const wrap = document.createElement('div');
    wrap.className = 'kb-keys';

    whites.forEach(function (midi) {
      const key = document.createElement('button');
      key.className = 'kb-key white';
      key.dataset.midi = midi;
      key.setAttribute('aria-label', V.noteName(midi));
      key.innerHTML = '<span class="kb-label">' + V.noteName(midi) + '</span>';
      // mouse / touch
      bindKeyElement(key, midi);
      wrap.appendChild(key);

      // black key after this white, if any
      const semi = midi % 12;
      if (blackAfter[semi] != null) {
        const bmidi = midi + 1;
        if (bmidi < start + count) {
          const bk = document.createElement('button');
          bk.className = 'kb-key black';
          bk.dataset.midi = bmidi;
          bk.setAttribute('aria-label', V.noteName(bmidi));
          bindKeyElement(bk, bmidi);
          key.appendChild(bk);
        }
      }
    });
    kbEl.appendChild(wrap);
    updateKeyHints();
  }

  function bindKeyElement(el, midi) {
    let down = false;
    const start = function (ev) {
      ev.preventDefault();
      if (!ensureAudio()) return;
      down = true;
      el.classList.add('active');
      engine.noteOn(midi, 0.9);
    };
    const end = function () {
      if (!down) return;
      down = false;
      el.classList.remove('active');
      engine.noteOff(midi);
    };
    el.addEventListener('mousedown', start);
    el.addEventListener('mouseup', end);
    el.addEventListener('mouseleave', end);
    el.addEventListener('touchstart', start, { passive: false });
    el.addEventListener('touchend', end);
    el.addEventListener('touchcancel', end);
    // keyboard accessibility on the button itself
    el.addEventListener('keydown', function (ev) {
      if (ev.key === ' ' || ev.key === 'Enter') { ev.preventDefault(); start(ev); }
    });
    el.addEventListener('keyup', function (ev) {
      if (ev.key === ' ' || ev.key === 'Enter') end();
    });
  }

  function updateKeyHints() {
    // annotate the white keys with their computer-key letter, if mapped
    const start = baseMidi();
    $$('.kb-key', kbEl).forEach(function (el) {
      const midi = parseInt(el.dataset.midi, 10);
      const offset = midi - start;
      let letter = '';
      for (const k in KEY_MAP) { if (KEY_MAP[k] === offset) { letter = k; break; } }
      let hint = el.querySelector('.kb-hint');
      if (letter) {
        if (!hint) { hint = document.createElement('span'); hint.className = 'kb-hint'; el.appendChild(hint); }
        hint.textContent = letter === ';' ? ';' : letter.toUpperCase();
      } else if (hint) { hint.remove(); }
    });
  }

  function ensureAudio() {
    if (!enabled) { enableAudio(); }
    return enabled;
  }

  // Computer keyboard
  document.addEventListener('keydown', function (ev) {
    if (ev.repeat) return;
    const target = ev.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) return;

    const k = ev.key.toLowerCase();

    // global shortcuts
    if (k === ' ') { ev.preventDefault(); if (seq) { ensureAudio(); seq.toggle(); reflectTransport(); } return; }
    if (k === 'escape') { engine.panic(); clearActiveKeys(); return; }
    if (k === 'arrowleft' || k === '-' || k === '_') { setOctave(baseOctave - 1); return; }
    if (k === 'arrowright' || k === '=' || k === '+') { setOctave(baseOctave + 1); return; }

    if (KEY_MAP.hasOwnProperty(k) && !heldKeys[k]) {
      if (!ensureAudio()) return;
      const midi = baseMidi() + KEY_MAP[k];
      heldKeys[k] = midi;
      engine.noteOn(midi, 0.9);
      highlightKey(midi, true);
    }
  });

  document.addEventListener('keyup', function (ev) {
    const k = ev.key.toLowerCase();
    if (heldKeys.hasOwnProperty(k)) {
      const midi = heldKeys[k];
      delete heldKeys[k];
      engine.noteOff(midi);
      highlightKey(midi, false);
    }
  });

  function highlightKey(midi, on) {
    const el = kbEl && kbEl.querySelector('.kb-key[data-midi="' + midi + '"]');
    if (el) el.classList.toggle('active', on);
  }

  function clearActiveKeys() {
    for (const k in heldKeys) delete heldKeys[k];
    $$('.kb-key.active', kbEl).forEach(function (el) { el.classList.remove('active'); });
  }

  function setOctave(o) {
    baseOctave = Math.max(1, Math.min(6, o));
    clearActiveKeys();
    engine.panic();
    buildKeyboard();
    const lbl = $('#octave-label');
    if (lbl) lbl.textContent = 'C' + baseOctave;
  }

  /* =========================================================
     3. Sound-design controls
     ========================================================= */
  // [id, patch key, transform fn for display, isFloat]
  const CONTROLS = [
    { id: 'ctrl-cutoff', key: 'cutoff', fmt: function (v) { return Math.round(v) + ' Hz'; } },
    { id: 'ctrl-res', key: 'resonance', fmt: function (v) { return (+v).toFixed(1); } },
    { id: 'ctrl-filterenv', key: 'filterEnv', fmt: function (v) { return Math.round(v) + ' Hz'; } },
    { id: 'ctrl-attack', key: 'attack', fmt: function (v) { return (v * 1000).toFixed(0) + ' ms'; } },
    { id: 'ctrl-decay', key: 'decay', fmt: function (v) { return (v * 1000).toFixed(0) + ' ms'; } },
    { id: 'ctrl-sustain', key: 'sustain', fmt: function (v) { return Math.round(v * 100) + '%'; } },
    { id: 'ctrl-release', key: 'release', fmt: function (v) { return (v * 1000).toFixed(0) + ' ms'; } },
    { id: 'ctrl-detune', key: 'detune', fmt: function (v) { return Math.round(v) + ' ct'; } },
    { id: 'ctrl-mixb', key: 'mixB', fmt: function (v) { return Math.round(v * 100) + '%'; } },
    { id: 'ctrl-drive', key: 'drive', fmt: function (v) { return Math.round(v * 100) + '%'; } },
    { id: 'ctrl-delaytime', key: 'delayTime', fmt: function (v) { return (v * 1000).toFixed(0) + ' ms'; } },
    { id: 'ctrl-delayfb', key: 'delayFeedback', fmt: function (v) { return Math.round(v * 100) + '%'; } },
    { id: 'ctrl-delaymix', key: 'delayMix', fmt: function (v) { return Math.round(v * 100) + '%'; } },
    { id: 'ctrl-volume', key: 'volume', fmt: function (v) { return Math.round(v * 100) + '%'; } }
  ];

  function bindControls() {
    CONTROLS.forEach(function (c) {
      const el = document.getElementById(c.id);
      if (!el) return;
      const out = document.getElementById(c.id + '-val');
      const update = function () {
        const v = parseFloat(el.value);
        engine.set(c.key, v);
        if (out) out.textContent = c.fmt(v);
      };
      el.addEventListener('input', update);
      // initialise display
      if (out) out.textContent = c.fmt(parseFloat(el.value));
    });

    // waveform selects + octB select
    const oscA = $('#ctrl-osca');
    if (oscA) oscA.addEventListener('change', function () { engine.set('oscA', oscA.value); });
    const oscB = $('#ctrl-oscb');
    if (oscB) oscB.addEventListener('change', function () { engine.set('oscB', oscB.value); });
    const octB = $('#ctrl-octb');
    if (octB) octB.addEventListener('change', function () { engine.set('octB', parseInt(octB.value, 10)); });
  }

  // push every control's current value into the engine
  function syncAllControls() {
    CONTROLS.forEach(function (c) {
      const el = document.getElementById(c.id);
      if (el) engine.set(c.key, parseFloat(el.value));
    });
    const oscA = $('#ctrl-osca'); if (oscA) engine.set('oscA', oscA.value);
    const oscB = $('#ctrl-oscb'); if (oscB) engine.set('oscB', oscB.value);
    const octB = $('#ctrl-octb'); if (octB) engine.set('octB', parseInt(octB.value, 10));
  }

  // write a patch object back into the UI controls
  function applyPatchToUI(patch) {
    CONTROLS.forEach(function (c) {
      const el = document.getElementById(c.id);
      const out = document.getElementById(c.id + '-val');
      if (el && patch[c.key] != null) {
        el.value = patch[c.key];
        if (out) out.textContent = c.fmt(parseFloat(el.value));
      }
    });
    const oscA = $('#ctrl-osca'); if (oscA && patch.oscA) oscA.value = patch.oscA;
    const oscB = $('#ctrl-oscb'); if (oscB && patch.oscB) oscB.value = patch.oscB;
    const octB = $('#ctrl-octb'); if (octB && patch.octB != null) octB.value = patch.octB;
  }

  function readUIPatch() {
    const p = {};
    CONTROLS.forEach(function (c) {
      const el = document.getElementById(c.id);
      if (el) p[c.key] = parseFloat(el.value);
    });
    const oscA = $('#ctrl-osca'); if (oscA) p.oscA = oscA.value;
    const oscB = $('#ctrl-oscb'); if (oscB) p.oscB = oscB.value;
    const octB = $('#ctrl-octb'); if (octB) p.octB = parseInt(octB.value, 10);
    return p;
  }

  /* =========================================================
     4. Sequencer UI
     ========================================================= */
  const SCALE = [0, 2, 3, 5, 7, 9, 10, 12]; // minor-ish pentatonic-plus for the note row picker

  function buildSequencer() {
    seq = new window.VoltroveSequencer(engine);
    const grid = $('#seq-grid');
    if (!grid) return;
    grid.innerHTML = '';

    const rows = VP.DRUM_ROWS.slice();
    const rowLabels = { kick: 'Kick', snare: 'Snare', hat: 'Hat', clap: 'Clap' };

    rows.forEach(function (r) {
      const rowEl = document.createElement('div');
      rowEl.className = 'seq-row';
      const label = document.createElement('div');
      label.className = 'seq-rowlabel';
      label.textContent = rowLabels[r];
      rowEl.appendChild(label);
      const cells = document.createElement('div');
      cells.className = 'seq-cells';
      for (let i = 0; i < 16; i++) {
        const cell = document.createElement('button');
        cell.className = 'seq-cell' + (i % 4 === 0 ? ' beat' : '');
        cell.dataset.row = r;
        cell.dataset.step = i;
        cell.setAttribute('aria-label', rowLabels[r] + ' step ' + (i + 1));
        cell.setAttribute('aria-pressed', 'false');
        cell.addEventListener('click', function () {
          ensureAudio();
          const on = seq.toggleCell(r, i);
          cell.classList.toggle('on', on);
          cell.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        cells.appendChild(cell);
      }
      rowEl.appendChild(cells);
      grid.appendChild(rowEl);
    });

    // synth note row — a select per step
    const synthRow = document.createElement('div');
    synthRow.className = 'seq-row synth-row';
    const sl = document.createElement('div');
    sl.className = 'seq-rowlabel';
    sl.textContent = 'Synth';
    synthRow.appendChild(sl);
    const scells = document.createElement('div');
    scells.className = 'seq-cells';
    for (let i = 0; i < 16; i++) {
      const cell = document.createElement('div');
      cell.className = 'seq-cell synth-cell' + (i % 4 === 0 ? ' beat' : '');
      cell.dataset.step = i;
      const sel = document.createElement('select');
      sel.className = 'seq-note';
      sel.setAttribute('aria-label', 'Synth note, step ' + (i + 1));
      const off = document.createElement('option'); off.value = ''; off.textContent = '·'; sel.appendChild(off);
      for (let s = 0; s < SCALE.length; s++) {
        const midi = 48 + SCALE[s];
        const opt = document.createElement('option');
        opt.value = midi;
        opt.textContent = V.noteName(midi);
        sel.appendChild(opt);
      }
      sel.addEventListener('change', function () {
        ensureAudio();
        const val = sel.value === '' ? null : parseInt(sel.value, 10);
        seq.setSynthNote(i, val);
        cell.classList.toggle('on', val != null);
      });
      cell.appendChild(sel);
      scells.appendChild(cell);
    }
    synthRow.appendChild(scells);
    grid.appendChild(synthRow);

    // step indicator row
    const indicator = document.createElement('div');
    indicator.className = 'seq-row indicator-row';
    indicator.innerHTML = '<div class="seq-rowlabel"></div>';
    const icells = document.createElement('div');
    icells.className = 'seq-cells';
    for (let i = 0; i < 16; i++) {
      const dot = document.createElement('div');
      dot.className = 'seq-indicator' + (i % 4 === 0 ? ' beat' : '');
      dot.dataset.step = i;
      icells.appendChild(dot);
    }
    indicator.appendChild(icells);
    grid.appendChild(indicator);

    // highlight callback
    seq.onStep = function (step) {
      $$('.seq-indicator', grid).forEach(function (d) {
        d.classList.toggle('playing', parseInt(d.dataset.step, 10) === step);
      });
      $$('.seq-cells', grid).forEach(function (row) {
        $$('.seq-cell', row).forEach(function (cell) {
          cell.classList.toggle('step-now', parseInt(cell.dataset.step, 10) === step);
        });
      });
    };
  }

  function refreshSequencerUI() {
    const grid = $('#seq-grid');
    if (!grid || !seq) return;
    VP.DRUM_ROWS.forEach(function (r) {
      for (let i = 0; i < 16; i++) {
        const cell = grid.querySelector('.seq-cell[data-row="' + r + '"][data-step="' + i + '"]');
        if (cell) {
          const on = !!seq.grid[r][i];
          cell.classList.toggle('on', on);
          cell.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
      }
    });
    $$('.synth-cell', grid).forEach(function (cell) {
      const i = parseInt(cell.dataset.step, 10);
      const sel = cell.querySelector('select');
      const v = seq.synthLine[i];
      sel.value = v == null ? '' : String(v);
      cell.classList.toggle('on', v != null);
    });
  }

  function reflectTransport() {
    const btn = $('#seq-play');
    if (!btn) return;
    btn.classList.toggle('playing', seq.isPlaying);
    btn.querySelector('.label').textContent = seq.isPlaying ? 'Stop' : 'Play';
  }

  function bindTransport() {
    const play = $('#seq-play');
    if (play) play.addEventListener('click', function () {
      ensureAudio();
      seq.toggle();
      reflectTransport();
    });

    const bpm = $('#seq-bpm');
    const bpmVal = $('#seq-bpm-val');
    if (bpm) {
      bpm.addEventListener('input', function () {
        seq.setBpm(parseInt(bpm.value, 10));
        if (bpmVal) bpmVal.textContent = bpm.value;
      });
      if (bpmVal) bpmVal.textContent = bpm.value;
    }

    const swing = $('#seq-swing');
    const swingVal = $('#seq-swing-val');
    if (swing) {
      swing.addEventListener('input', function () {
        seq.setSwing(parseFloat(swing.value));
        if (swingVal) swingVal.textContent = Math.round(parseFloat(swing.value) * 100) + '%';
      });
    }

    const clear = $('#seq-clear');
    if (clear) clear.addEventListener('click', function () {
      seq.clear();
      refreshSequencerUI();
    });

    // pattern preset buttons
    const presetWrap = $('#seq-presets');
    if (presetWrap) {
      VP.PATTERNS.forEach(function (pat) {
        const b = document.createElement('button');
        b.className = 'chip';
        b.textContent = pat.name;
        b.title = pat.blurb;
        b.addEventListener('click', function () {
          ensureAudio();
          seq.loadPattern(pat);
          refreshSequencerUI();
          const bpmEl = $('#seq-bpm'); if (bpmEl) { bpmEl.value = seq.bpm; }
          const bpmValEl = $('#seq-bpm-val'); if (bpmValEl) bpmValEl.textContent = seq.bpm;
        });
        presetWrap.appendChild(b);
      });
    }
  }

  /* =========================================================
     5. Patch presets + localStorage
     ========================================================= */
  const STORE_KEY = 'voltrove.userPatches.v1';

  function loadUserPatches() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY)) || []; }
    catch (e) { return []; }
  }
  function saveUserPatches(list) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(list)); } catch (e) {}
  }

  function loadPatchByObject(patch) {
    engine.loadPatch(patch);
    applyPatchToUI(Object.assign({}, V.DEFAULT_PATCH, patch));
    if (enabled) syncAllControls();
  }

  function buildFactoryPatchChips() {
    const wrap = $('#patch-presets');
    if (!wrap) return;
    VP.FACTORY_PATCHES.forEach(function (fp) {
      const b = document.createElement('button');
      b.className = 'chip patch-chip';
      b.innerHTML = '<b>' + fp.name + '</b><span>' + fp.tags.join(' · ') + '</span>';
      b.addEventListener('click', function () {
        loadPatchByObject(fp.patch);
        flashStatus('Loaded patch: ' + fp.name);
      });
      wrap.appendChild(b);
    });
  }

  function renderUserPatches() {
    const wrap = $('#user-patches');
    if (!wrap) return;
    const list = loadUserPatches();
    wrap.innerHTML = '';
    if (!list.length) {
      wrap.innerHTML = '<p class="empty-note">No saved patches yet. Dial in a sound and hit “Save patch”.</p>';
      return;
    }
    list.forEach(function (item, idx) {
      const row = document.createElement('div');
      row.className = 'user-patch';
      const load = document.createElement('button');
      load.className = 'chip';
      load.textContent = item.name;
      load.addEventListener('click', function () {
        loadPatchByObject(item.patch);
        flashStatus('Loaded: ' + item.name);
      });
      const del = document.createElement('button');
      del.className = 'icon-btn';
      del.setAttribute('aria-label', 'Delete patch ' + item.name);
      del.textContent = '✕';
      del.addEventListener('click', function () {
        const l = loadUserPatches();
        l.splice(idx, 1);
        saveUserPatches(l);
        renderUserPatches();
      });
      row.appendChild(load);
      row.appendChild(del);
      wrap.appendChild(row);
    });
  }

  function bindPatchActions() {
    const saveBtn = $('#patch-save');
    if (saveBtn) saveBtn.addEventListener('click', function () {
      const nameInput = $('#patch-name');
      const name = (nameInput && nameInput.value.trim()) || ('My Patch ' + (loadUserPatches().length + 1));
      const list = loadUserPatches();
      list.push({ name: name, patch: readUIPatch(), savedAt: Date.now() });
      saveUserPatches(list);
      if (nameInput) nameInput.value = '';
      renderUserPatches();
      flashStatus('Saved patch: ' + name);
    });

    const initBtn = $('#patch-init');
    if (initBtn) initBtn.addEventListener('click', function () {
      loadPatchByObject(V.DEFAULT_PATCH);
      flashStatus('Reset to Init Filament');
    });

    const randBtn = $('#patch-random');
    if (randBtn) randBtn.addEventListener('click', function () {
      loadPatchByObject(randomPatch());
      flashStatus('Randomised patch');
    });

    const panicBtn = $('#panic');
    if (panicBtn) panicBtn.addEventListener('click', function () {
      engine.panic();
      clearActiveKeys();
      if (seq && seq.isPlaying) { seq.stop(); reflectTransport(); }
      flashStatus('Panic — all notes off');
    });
  }

  function randomPatch() {
    const waves = ['sawtooth', 'square', 'triangle', 'sine'];
    const r = function (a, b) { return a + Math.random() * (b - a); };
    const pick = function (arr) { return arr[Math.floor(Math.random() * arr.length)]; };
    return {
      oscA: pick(waves), oscB: pick(waves), mixB: r(0.1, 0.7), detune: r(2, 24),
      octB: pick([-12, 0, 12]), cutoff: r(500, 5000), resonance: r(2, 14),
      filterEnv: r(800, 4500), attack: r(0.002, 0.6), decay: r(0.1, 1.0),
      sustain: r(0.1, 0.85), release: r(0.1, 1.2), drive: r(0.02, 0.35),
      delayTime: r(0.12, 0.5), delayFeedback: r(0.1, 0.5), delayMix: r(0.05, 0.4),
      glide: 0, volume: 0.7
    };
  }

  let statusTimer = null;
  function flashStatus(msg) {
    const el = $('#patch-status');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(statusTimer);
    statusTimer = setTimeout(function () { el.classList.remove('show'); }, 2200);
  }

  /* =========================================================
     6. Cross-page patch loading (from patches.html links)
     ========================================================= */
  function checkUrlPatch() {
    const m = location.hash.match(/load=([\w-]+)/);
    if (!m) return;
    const id = m[1];
    const fp = VP.FACTORY_PATCHES.filter(function (p) { return p.id === id; })[0];
    if (fp) { loadPatchByObject(fp.patch); flashStatus('Loaded patch: ' + fp.name); }
    const pat = VP.PATTERNS.filter(function (p) { return p.id === id; })[0];
    if (pat && seq) { seq.loadPattern(pat); refreshSequencerUI(); }
  }

  /* =========================================================
     7. Octave buttons + boot
     ========================================================= */
  function bindOctaveButtons() {
    const up = $('#oct-up'), down = $('#oct-down');
    if (up) up.addEventListener('click', function () { setOctave(baseOctave + 1); });
    if (down) down.addEventListener('click', function () { setOctave(baseOctave - 1); });
  }

  function boot() {
    // visualizer (draw idle even before audio)
    const canvas = $('#viz-canvas');
    if (canvas) {
      viz = new window.VoltroveVisualizer(canvas, engine);
      viz.drawIdle();
    }
    buildKeyboard();
    bindControls();
    buildSequencer();
    bindTransport();
    bindOctaveButtons();
    buildFactoryPatchChips();
    renderUserPatches();
    bindPatchActions();

    const lbl = $('#octave-label');
    if (lbl) lbl.textContent = 'C' + baseOctave;

    if (!engine.supported) showUnsupported();

    // load default into UI display
    applyPatchToUI(V.DEFAULT_PATCH);
    // sequencer default pattern
    if (seq) { seq.loadPattern(VP.PATTERNS[0]); refreshSequencerUI();
      const bpmEl = $('#seq-bpm'); if (bpmEl) bpmEl.value = seq.bpm;
      const bpmValEl = $('#seq-bpm-val'); if (bpmValEl) bpmValEl.textContent = seq.bpm;
    }

    checkUrlPatch();
    window.addEventListener('hashchange', checkUrlPatch);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else { boot(); }

})();
