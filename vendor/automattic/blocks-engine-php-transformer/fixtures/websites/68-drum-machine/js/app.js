/* =========================================================
   PULSEFORGE — RB-16 · Main app
   Wires the DOM to the DrumEngine + Sequencer + Visualizer.
   Builds the step grid, transport, mixer, pattern banks,
   song arranger, kit/groove pickers, localStorage, JSON I/O,
   keyboard shortcuts, tap-tempo and panic.
   ========================================================= */

(function (global) {
  'use strict';

  var PF = global.Pulseforge;
  var K = global.PulseforgeKits;
  if (!PF) return;

  var VOICES = PF.VOICES;
  var LS_KITS = 'pulseforge.kits.v1';
  var LS_PATS = 'pulseforge.patterns.v1';
  var BANKS = ['A', 'B', 'C', 'D'];

  var engine = new PF.DrumEngine();
  var seq = null, viz = null;
  var currentKitId = '808';
  var supported = engine.supported;

  /* ── tiny DOM helpers ───────────────────────────────────── */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function status(msg) {
    var s = $('#machine-status');
    if (s) { s.textContent = msg; }
  }

  /* ── boot ───────────────────────────────────────────────── */
  function boot() {
    seq = new global.PulseforgeSequencer(engine);
    var groove = K.grooveById('four-floor');
    seq.loadGroove(groove);
    seq.setBpm(groove.bpm);
    engine.loadKit(K.kitById('909'));
    currentKitId = '909';

    buildGrid();
    buildMixer();
    buildBanks();
    buildKitChips();
    buildGrooveChips();
    bindTransport();
    bindParamPanel();
    bindSongMode();
    bindIO();
    bindShortcuts();
    refreshUserLists();
    syncTransportUI();
    syncKitUI();

    seq.onStep = onStep;
    seq.onPatternChange = function (idx) { highlightBank(idx); syncTransportUI(); refreshGridFromPattern(); };
  }

  /* ── audio gate ─────────────────────────────────────────── */
  function enableAudio() {
    if (!supported) {
      status('Web Audio is unavailable in this browser.');
      return;
    }
    engine.init();
    engine.resume().then(function () {
      viz = new global.PulseforgeVisualizer($('#viz-canvas'), engine);
      viz.start();
      engine.loadKit(K.kitById(currentKitId));
      syncMixerLevels();
      status('Ready · press Play or Space');
    });
    var gate = $('#audio-gate');
    if (gate) gate.classList.add('is-hidden');
  }

  /* =========================================================
     STEP GRID
     ========================================================= */
  var gridCells = {}; // id → [cellEls]

  function buildGrid() {
    var host = $('#seq-grid');
    host.innerHTML = '';
    gridCells = {};

    VOICES.forEach(function (v) {
      var rowEl = el('div', 'seq-row');
      rowEl.dataset.voice = v.id;

      // label / mini-trigger
      var lab = el('button', 'seq-label');
      lab.style.setProperty('--vc', v.color);
      lab.innerHTML = '<span class="dot" style="background:' + v.color + '"></span>' +
        '<span class="nm">' + v.name + '</span>';
      lab.title = 'Click to audition ' + v.name;
      lab.addEventListener('click', function () {
        engine.resume(); engine.trigger(v.id, engine.now() + 0.01, 1);
      });
      rowEl.appendChild(lab);

      var cells = el('div', 'seq-cells');
      gridCells[v.id] = [];
      for (var s = 0; s < 16; s++) {
        var cell = el('button', 'cell');
        cell.dataset.step = s;
        cell.dataset.voice = v.id;
        cell.setAttribute('aria-label', v.name + ' step ' + (s + 1));
        if (s % 4 === 0) cell.classList.add('beat');
        (function (voice, stepIdx, cellEl) {
          cellEl.addEventListener('click', function (e) {
            engine.resume();
            if (e.shiftKey) {
              var nv = seq.cycleVel(voice, stepIdx);
              if (nv != null) renderCell(voice, stepIdx);
              return;
            }
            var on = seq.toggleCell(voice, stepIdx);
            renderCell(voice, stepIdx);
            if (on) engine.trigger(voice, engine.now() + 0.01, 1);
          });
          cellEl.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            seq.cycleVel(voice, stepIdx); renderCell(voice, stepIdx);
          });
        })(v.id, s, cell);
        cells.appendChild(cell);
        gridCells[v.id].push(cell);
      }
      rowEl.appendChild(cells);
      host.appendChild(rowEl);
    });

    // step header (numbers + playhead)
    refreshGridFromPattern();
    applyStepsVisibility();
  }

  function renderCell(id, step) {
    var lane = seq.pattern().grid[id];
    var cell = gridCells[id][step];
    if (!cell) return;
    cell.classList.toggle('on', !!lane.hits[step]);
    cell.classList.remove('accent', 'ghost');
    if (lane.hits[step]) {
      if (lane.vel[step] >= 1.15) cell.classList.add('accent');
      else if (lane.vel[step] <= 0.6) cell.classList.add('ghost');
    }
  }

  function refreshGridFromPattern() {
    VOICES.forEach(function (v) {
      for (var s = 0; s < 16; s++) renderCell(v.id, s);
    });
    applyStepsVisibility();
  }

  function applyStepsVisibility() {
    var n = seq.steps;
    VOICES.forEach(function (v) {
      gridCells[v.id].forEach(function (cell, s) {
        cell.classList.toggle('disabled-step', s >= n);
      });
    });
  }

  var lastStep = -1;
  function onStep(step, patIdx) {
    // clear previous
    if (lastStep >= 0) {
      VOICES.forEach(function (v) {
        var c = gridCells[v.id][lastStep]; if (c) c.classList.remove('playing');
      });
    }
    if (step >= 0) {
      VOICES.forEach(function (v) {
        var c = gridCells[v.id][step]; if (c) c.classList.add('playing');
      });
    }
    lastStep = step;
    // playhead bar
    var ph = $('#playhead');
    if (ph) {
      if (step < 0) ph.style.opacity = '0';
      else {
        ph.style.opacity = '1';
        ph.style.left = 'calc(' + (step / 16 * 100) + '% )';
      }
    }
  }

  /* =========================================================
     MIXER (per-track level + mute/solo)
     ========================================================= */
  function buildMixer() {
    var host = $('#mixer');
    host.innerHTML = '';
    VOICES.forEach(function (v) {
      var ch = el('div', 'mix-ch');
      ch.style.setProperty('--vc', v.color);
      ch.innerHTML =
        '<span class="mix-name">' + v.name + '</span>' +
        '<input type="range" class="mix-fader" min="0" max="1" step="0.01" ' +
        'value="' + engine.params[v.id].level + '" aria-label="' + v.name + ' level" orient="vertical">' +
        '<div class="mix-btns">' +
          '<button class="ms mute" aria-pressed="false" title="Mute">M</button>' +
          '<button class="ms solo" aria-pressed="false" title="Solo">S</button>' +
        '</div>';
      var fader = $('.mix-fader', ch);
      fader.addEventListener('input', function () {
        engine.setLevel(v.id, parseFloat(fader.value));
      });
      var mute = $('.mute', ch), solo = $('.solo', ch);
      mute.addEventListener('click', function () {
        var on = !engine.tracks[v.id].mute;
        engine.setMute(v.id, on);
        mute.classList.toggle('active', on); mute.setAttribute('aria-pressed', on);
      });
      solo.addEventListener('click', function () {
        var on = !engine.tracks[v.id].solo;
        engine.setSolo(v.id, on);
        solo.classList.toggle('active', on); solo.setAttribute('aria-pressed', on);
      });
      host.appendChild(ch);
    });
  }
  function syncMixerLevels() {
    $$('.mix-ch').forEach(function (ch, i) {
      var f = $('.mix-fader', ch);
      if (f) f.value = engine.params[VOICES[i].id].level;
    });
  }

  /* =========================================================
     PATTERN BANKS  (A B C D)
     ========================================================= */
  function buildBanks() {
    var host = $('#pattern-banks');
    host.innerHTML = '';
    BANKS.forEach(function (name, i) {
      var b = el('button', 'bank' + (i === seq.current ? ' active' : ''), name);
      b.dataset.bank = i;
      b.addEventListener('click', function () {
        seq.selectPattern(i);
        highlightBank(i);
        refreshGridFromPattern();
        syncTransportUI();
      });
      host.appendChild(b);
    });
    // copy / clear / random / shift
    bindEl('#pat-copy', function () {
      var dest = (seq.current + 1) % BANKS.length;
      seq.copyTo(dest);
      flash('Copied ' + BANKS[seq.current] + ' → ' + BANKS[dest]);
    });
    bindEl('#pat-clear', function () { seq.clearPattern(); refreshGridFromPattern(); flash('Pattern cleared'); });
    bindEl('#pat-random', function () { engine.resume(); seq.randomize(); refreshGridFromPattern(); flash('Randomised'); });
    bindEl('#pat-shift-l', function () { seq.shiftPattern(-1); refreshGridFromPattern(); });
    bindEl('#pat-shift-r', function () { seq.shiftPattern(1); refreshGridFromPattern(); });
  }
  function highlightBank(idx) {
    $$('.bank').forEach(function (b) { b.classList.toggle('active', +b.dataset.bank === idx); });
  }

  /* =========================================================
     KIT + GROOVE chips
     ========================================================= */
  function buildKitChips() {
    var host = $('#kit-chips');
    host.innerHTML = '';
    K.KITS.forEach(function (kit) {
      var c = el('button', 'kit-chip' + (kit.id === currentKitId ? ' active' : ''));
      c.innerHTML = '<b>' + kit.name + '</b><span>' + kit.id.toUpperCase() + '</span>';
      c.dataset.kit = kit.id;
      c.addEventListener('click', function () {
        engine.resume();
        engine.loadKit(kit);
        currentKitId = kit.id;
        syncMixerLevels();
        syncKitUI();
        flash('Loaded kit: ' + kit.name);
      });
      host.appendChild(c);
    });
  }
  function syncKitUI() {
    $$('.kit-chip').forEach(function (c) { c.classList.toggle('active', c.dataset.kit === currentKitId); });
    // refresh the voice-edit panel to reflect the kit's params
    if (currentVoice) loadVoiceParams(currentVoice);
  }

  function buildGrooveChips() {
    var host = $('#groove-chips');
    if (!host) return;
    host.innerHTML = '';
    K.GROOVES.forEach(function (gr) {
      var c = el('button', 'groove-chip');
      c.innerHTML = '<b>' + gr.name + '</b><span>' + gr.bpm + ' BPM · ' + gr.kit.toUpperCase() + '</span>';
      c.addEventListener('click', function () {
        engine.resume();
        seq.loadGroove(gr);
        var kit = K.kitById(gr.kit);
        engine.loadKit(kit); currentKitId = gr.kit;
        syncMixerLevels(); syncKitUI();
        refreshGridFromPattern(); syncTransportUI();
        flash('Loaded groove: ' + gr.name);
      });
      host.appendChild(c);
    });
  }

  /* =========================================================
     TRANSPORT (play / bpm / swing / steps / tap / metro)
     ========================================================= */
  function bindTransport() {
    bindEl('#btn-play', togglePlay);
    bindEl('#btn-stop', function () { seq.stop(); syncTransportUI(); });
    bindEl('#btn-panic', panic);

    var bpm = $('#bpm'), bpmVal = $('#bpm-val');
    bpm.addEventListener('input', function () {
      seq.setBpm(parseInt(bpm.value, 10)); bpmVal.textContent = seq.bpm;
    });
    var swing = $('#swing'), swingVal = $('#swing-val');
    swing.addEventListener('input', function () {
      seq.setSwing(parseFloat(swing.value));
      swingVal.textContent = Math.round(seq.swing * 100) + '%';
    });
    var steps = $('#steps'), stepsVal = $('#steps-val');
    steps.addEventListener('input', function () {
      seq.setSteps(parseInt(steps.value, 10));
      stepsVal.textContent = seq.steps;
      applyStepsVisibility();
    });
    var master = $('#master'), masterVal = $('#master-val');
    master.addEventListener('input', function () {
      engine.setMaster(parseFloat(master.value));
      masterVal.textContent = Math.round(master.value * 100) + '%';
    });
    var drive = $('#drive'), driveVal = $('#drive-val');
    drive.addEventListener('input', function () {
      engine.setDrive(parseFloat(drive.value));
      driveVal.textContent = Math.round(drive.value * 100) + '%';
    });

    bindEl('#btn-metro', function () {
      seq.metronome = !seq.metronome;
      $('#btn-metro').classList.toggle('active', seq.metronome);
      $('#btn-metro').setAttribute('aria-pressed', seq.metronome);
    });

    bindEl('#btn-tap', tapTempo);
  }

  function togglePlay() {
    engine.resume().then(function () {
      seq.toggle();
      syncTransportUI();
    });
  }

  function syncTransportUI() {
    var play = $('#btn-play');
    if (play) {
      play.classList.toggle('playing', seq.isPlaying);
      $('.label', play).textContent = seq.isPlaying ? 'Stop' : 'Play';
    }
    $('#bpm').value = seq.bpm; $('#bpm-val').textContent = seq.bpm;
    $('#swing').value = seq.swing; $('#swing-val').textContent = Math.round(seq.swing * 100) + '%';
    $('#steps').value = seq.steps; $('#steps-val').textContent = seq.steps;
  }

  function panic() {
    seq.stop();
    if (engine.ready) {
      // duck master briefly to kill ringing tails
      var t = engine.now();
      engine.master.gain.cancelScheduledValues(t);
      engine.master.gain.setValueAtTime(0.0001, t);
      engine.master.gain.setTargetAtTime(parseFloat($('#master').value), t + 0.12, 0.05);
    }
    onStep(-1, seq.current);
    syncTransportUI();
    flash('Panic — all sound stopped');
  }

  // tap tempo
  var taps = [];
  function tapTempo() {
    var now = performance.now();
    taps.push(now);
    taps = taps.filter(function (t) { return now - t < 2500; });
    if (taps.length >= 2) {
      var deltas = [];
      for (var i = 1; i < taps.length; i++) deltas.push(taps[i] - taps[i - 1]);
      var avg = deltas.reduce(function (a, b) { return a + b; }, 0) / deltas.length;
      var bpm = Math.round(60000 / avg);
      if (bpm >= 40 && bpm <= 240) {
        seq.setBpm(bpm); syncTransportUI();
        flash('Tap tempo: ' + bpm + ' BPM');
      }
    }
  }

  /* =========================================================
     VOICE PARAM EDITOR  (tune / decay / level / tone)
     ========================================================= */
  var currentVoice = null;
  function bindParamPanel() {
    var sel = $('#voice-select');
    sel.innerHTML = '';
    VOICES.forEach(function (v) {
      var o = el('option', null, v.name); o.value = v.id; sel.appendChild(o);
    });
    sel.addEventListener('change', function () { loadVoiceParams(sel.value); });
    currentVoice = VOICES[0].id;

    ['tune', 'decay', 'tone', 'level'].forEach(function (key) {
      var input = $('#param-' + key);
      if (!input) return;
      input.addEventListener('input', function () {
        if (!currentVoice) return;
        var val = parseFloat(input.value);
        engine.setParam(currentVoice, key, val);
        if (key === 'level') syncMixerLevels();
        $('#param-' + key + '-val').textContent = Math.round(val * 100);
        // audition the tweak
        engine.resume(); engine.trigger(currentVoice, engine.now() + 0.01, 1);
      });
    });
    bindEl('#param-audition', function () {
      engine.resume(); engine.trigger(currentVoice, engine.now() + 0.01, 1);
    });
    loadVoiceParams(currentVoice);
  }
  function loadVoiceParams(id) {
    currentVoice = id;
    $('#voice-select').value = id;
    var p = engine.params[id];
    ['tune', 'decay', 'tone', 'level'].forEach(function (key) {
      var input = $('#param-' + key);
      if (input && p[key] != null) {
        input.value = p[key];
        $('#param-' + key + '-val').textContent = Math.round(p[key] * 100);
      }
    });
  }

  /* =========================================================
     SONG / CHAIN ARRANGER
     ========================================================= */
  function bindSongMode() {
    var toggle = $('#song-toggle');
    toggle.addEventListener('change', function () {
      seq.setSongMode(toggle.checked);
      $('#song-arranger').classList.toggle('active', toggle.checked);
      flash(toggle.checked ? 'Song mode on — chain plays in order' : 'Pattern mode');
    });
    bindEl('#song-add', function () {
      var rep = parseInt($('#song-repeats').value, 10) || 1;
      seq.songAdd(seq.current, rep);
      renderSong();
    });
    bindEl('#song-clear', function () { seq.songClear(); renderSong(); });
    renderSong();
  }
  function renderSong() {
    var host = $('#song-chain');
    host.innerHTML = '';
    if (!seq.song.length) {
      host.appendChild(el('p', 'song-empty', 'Empty chain. Select a pattern bank, set repeats, then "Add to chain".'));
      return;
    }
    seq.song.forEach(function (slot, i) {
      var s = el('div', 'song-slot');
      s.innerHTML = '<b>' + BANKS[slot.pattern] + '</b><span>×' + slot.repeats + '</span>';
      var rm = el('button', 'song-rm', '×'); rm.title = 'Remove';
      rm.addEventListener('click', function () { seq.songRemove(i); renderSong(); });
      s.appendChild(rm);
      host.appendChild(s);
    });
  }

  /* =========================================================
     SAVE / LOAD (localStorage) + JSON export / import
     ========================================================= */
  function bindIO() {
    bindEl('#save-pattern', function () {
      var name = ($('#pattern-name').value || '').trim() || ('Pattern ' + new Date().toLocaleTimeString());
      var store = readLS(LS_PATS);
      store.push({ id: 'u' + Date.now(), name: name, kit: currentKitId, data: seq.toJSON(currentKitId) });
      writeLS(LS_PATS, store);
      $('#pattern-name').value = '';
      refreshUserLists();
      flash('Saved "' + name + '" to this browser');
    });
    bindEl('#save-kit', function () {
      var name = ('My ' + K.kitById(currentKitId).name + ' ' + new Date().toLocaleTimeString());
      var params = {};
      VOICES.forEach(function (v) { params[v.id] = Object.assign({}, engine.params[v.id]); });
      var store = readLS(LS_KITS);
      store.push({ id: 'uk' + Date.now(), name: name, params: params });
      writeLS(LS_KITS, store);
      refreshUserLists();
      flash('Saved kit "' + name + '"');
    });

    bindEl('#export-json', function () {
      var blob = new Blob([JSON.stringify(seq.toJSON(currentKitId), null, 2)], { type: 'application/json' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'pulseforge-song.json';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      flash('Exported song JSON');
    });
    var fileInput = $('#import-json');
    bindEl('#import-btn', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      var f = fileInput.files[0]; if (!f) return;
      var reader = new FileReader();
      reader.onload = function () {
        try {
          var data = JSON.parse(reader.result);
          if (seq.fromJSON(data)) {
            if (data.kit) { engine.loadKit(K.kitById(data.kit)); currentKitId = data.kit; syncMixerLevels(); syncKitUI(); }
            buildBanks(); refreshGridFromPattern(); syncTransportUI(); renderSong();
            flash('Imported song from file');
          } else flash('That file is not a valid Pulseforge song.');
        } catch (e) { flash('Could not parse JSON file.'); }
      };
      reader.readAsText(f);
      fileInput.value = '';
    });
  }

  function refreshUserLists() {
    // patterns
    var pHost = $('#user-patterns');
    if (pHost) {
      pHost.innerHTML = '';
      var pats = readLS(LS_PATS);
      if (!pats.length) pHost.appendChild(el('p', 'empty-note', 'No saved patterns yet.'));
      pats.forEach(function (item) {
        var c = el('div', 'user-chip');
        var load = el('button', 'user-load');
        load.innerHTML = '<b>' + item.name + '</b><span>' + (item.kit || '').toUpperCase() + '</span>';
        load.addEventListener('click', function () {
          engine.resume();
          if (seq.fromJSON(item.data)) {
            if (item.kit) { engine.loadKit(K.kitById(item.kit)); currentKitId = item.kit; syncMixerLevels(); syncKitUI(); }
            buildBanks(); refreshGridFromPattern(); syncTransportUI(); renderSong();
            flash('Loaded "' + item.name + '"');
          }
        });
        var del = el('button', 'user-del', '×'); del.title = 'Delete';
        del.addEventListener('click', function () {
          writeLS(LS_PATS, readLS(LS_PATS).filter(function (x) { return x.id !== item.id; }));
          refreshUserLists();
        });
        c.appendChild(load); c.appendChild(del); pHost.appendChild(c);
      });
    }
    // kits
    var kHost = $('#user-kits');
    if (kHost) {
      kHost.innerHTML = '';
      var kits = readLS(LS_KITS);
      if (!kits.length) kHost.appendChild(el('p', 'empty-note', 'No saved kits yet.'));
      kits.forEach(function (item) {
        var c = el('div', 'user-chip');
        var load = el('button', 'user-load');
        load.innerHTML = '<b>' + item.name + '</b><span>KIT</span>';
        load.addEventListener('click', function () {
          engine.resume();
          engine.loadKit(item); currentKitId = item.id;
          syncMixerLevels(); if (currentVoice) loadVoiceParams(currentVoice);
          flash('Loaded kit "' + item.name + '"');
        });
        var del = el('button', 'user-del', '×'); del.title = 'Delete';
        del.addEventListener('click', function () {
          writeLS(LS_KITS, readLS(LS_KITS).filter(function (x) { return x.id !== item.id; }));
          refreshUserLists();
        });
        c.appendChild(load); c.appendChild(del); kHost.appendChild(c);
      });
    }
  }

  function readLS(key) {
    try { return JSON.parse(localStorage.getItem(key)) || []; } catch (e) { return []; }
  }
  function writeLS(key, val) {
    try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {}
  }

  /* =========================================================
     KEYBOARD SHORTCUTS
     ========================================================= */
  function bindShortcuts() {
    document.addEventListener('keydown', function (e) {
      if (e.target.matches('input[type="text"], textarea')) return;
      var k = e.key;
      if (k === ' ') { e.preventDefault(); togglePlay(); }
      else if (k === 'Escape') { panic(); }
      else if (k === 'm' || k === 'M') { $('#btn-metro').click(); }
      else if (k === 't' || k === 'T') { tapTempo(); }
      else if (k === 'r' || k === 'R') { $('#pat-random').click(); }
      else if (k === 'c' || k === 'C') { $('#pat-clear').click(); }
      else if (k === 'ArrowUp') { e.preventDefault(); seq.setBpm(seq.bpm + 1); syncTransportUI(); }
      else if (k === 'ArrowDown') { e.preventDefault(); seq.setBpm(seq.bpm - 1); syncTransportUI(); }
      else if (k >= '1' && k <= '4') {
        var i = parseInt(k, 10) - 1;
        seq.selectPattern(i); highlightBank(i); refreshGridFromPattern(); syncTransportUI();
      }
    });
  }

  /* ── misc UI helpers ────────────────────────────────────── */
  function bindEl(sel, fn) { var e = $(sel); if (e) e.addEventListener('click', fn); }
  var flashTimer = null;
  function flash(msg) {
    status(msg);
    var t = $('#toast');
    if (!t) return;
    t.textContent = msg; t.classList.add('show');
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () { t.classList.remove('show'); }, 1800);
  }

  /* ── init ───────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    if (!$('#seq-grid')) return; // not the machine page
    if (!supported) {
      status('Web Audio API not supported here.');
      var b = $('#enable-audio');
      if (b) { b.textContent = 'Audio unavailable'; b.disabled = true; }
    }
    var enable = $('#enable-audio');
    if (enable) enable.addEventListener('click', enableAudio);
    boot();
  });

})(window);
