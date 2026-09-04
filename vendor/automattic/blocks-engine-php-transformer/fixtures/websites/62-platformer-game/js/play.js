/* play.js — glue between play.html and the Lumen Leap engine.
   Handles canvas sizing, overlay buttons, mute + reduced-motion toggles,
   the help overlay, and reading ?level= from the URL (from levels.html).
   The game itself lives in game.js; this file is just orchestration. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('game');
    const stage = document.querySelector('.stage');

    /* Size the canvas to its stage. We render directly in logical pixels and
       scale the backing store by devicePixelRatio for crispness. The 2D
       context transform absorbs the DPR, so all engine math stays in CSS px
       (canvas.width / canvas.height report CSS px after this). */
    function doResize() {
      const r = stage.getBoundingClientRect();
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const w = Math.max(320, Math.round(r.width));
      const h = Math.max(240, Math.round(r.height));
      // backing store at DPR
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      canvas.style.width = w + 'px';
      canvas.style.height = h + 'px';
      const c = canvas.getContext('2d');
      c.setTransform(dpr, 0, 0, dpr, 0, 0);
      // expose logical size to the engine (it reads view.w / view.h)
      canvas.viewW = w;
      canvas.viewH = h;
    }
    doResize();
    window.addEventListener('resize', doResize);

    /* ── HUD + overlay references ── */
    const hud = {
      score: document.querySelector('[data-hud="score"]'),
      lumens: document.querySelector('[data-hud="lumens"]'),
      lives: document.querySelector('[data-hud="lives"]'),
      level: document.querySelector('[data-hud="level"]'),
      time: document.querySelector('[data-hud="time"]')
    };
    const overlays = {
      title: document.querySelector('[data-overlay="title"]'),
      paused: document.querySelector('[data-overlay="paused"]'),
      levelclear: document.querySelector('[data-overlay="levelclear"]'),
      gameover: document.querySelector('[data-overlay="gameover"]'),
      win: document.querySelector('[data-overlay="win"]')
    };

    const muteBtn = document.querySelector('[data-action="mute"]');
    const rmBtn = document.querySelector('[data-action="reduced"]');
    const helpBtn = document.querySelector('[data-action="help"]');
    const helpOverlay = document.querySelector('[data-overlay="help"]');

    /* ── instantiate engine ── */
    const game = LumenGame({
      canvas, hud, overlays,
      on: {
        jump() { /* hook for future */ },
        mute() { toggleMute(); },
        confirm(state) { handleConfirm(state); },
        levelComplete(info) { fillClearScreen(info); },
        gameOver(info) { fillGameOver(info); },
        state(s) { reflectState(s); }
      }
    });

    /* ── reduced motion ── */
    function reducedActive() {
      const pref = LumenSave.get('reducedMotion');
      if (pref === true) return true;
      if (pref === false) return false;
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    function syncReduced() {
      const on = reducedActive();
      game.setReduced(on);
      if (rmBtn) {
        rmBtn.setAttribute('aria-pressed', String(on));
        rmBtn.querySelector('.lbl').textContent = on ? 'Calm: on' : 'Calm: off';
      }
      document.body.classList.toggle('reduced', on);
    }
    syncReduced();
    if (rmBtn) rmBtn.addEventListener('click', () => {
      LumenSave.set('reducedMotion', !reducedActive());
      LumenAudio.sfx.ui();
      syncReduced();
    });

    /* ── mute ── */
    function toggleMute() {
      LumenAudio.resume();
      const m = LumenAudio.toggleMuted();
      reflectMute(m);
    }
    function reflectMute(m) {
      if (!muteBtn) return;
      muteBtn.setAttribute('aria-pressed', String(m));
      muteBtn.querySelector('.lbl').textContent = m ? 'Sound: off' : 'Sound: on';
    }
    reflectMute(LumenAudio.isMuted());
    if (muteBtn) muteBtn.addEventListener('click', () => { LumenAudio.sfx.ui(); toggleMute(); });

    /* ── help overlay ── */
    function toggleHelp(force) {
      const show = force !== undefined ? force : !helpOverlay.classList.contains('show');
      helpOverlay.classList.toggle('show', show);
    }
    if (helpBtn) helpBtn.addEventListener('click', () => { LumenAudio.sfx.ui(); toggleHelp(); });
    document.querySelectorAll('[data-action="close-help"]').forEach(b =>
      b.addEventListener('click', () => toggleHelp(false)));

    /* ── overlay action buttons ── */
    function bind(sel, fn) {
      document.querySelectorAll(sel).forEach(el =>
        el.addEventListener('click', (e) => { e.preventDefault(); LumenAudio.resume(); LumenAudio.sfx.ui(); fn(el); }));
    }
    bind('[data-action="start"]', () => game.newGame(startLevelFromURL()));
    bind('[data-action="resume"]', () => game.resume());
    bind('[data-action="restart"]', () => game.restartLevel());
    bind('[data-action="next"]', () => game.nextLevel());
    bind('[data-action="retry"]', () => game.newGame(game.levelIndex));
    bind('[data-action="title"]', () => game.quitToTitle());
    bind('[data-action="pause"]', () => game.togglePause());
    bind('[data-action="levels"]', () => { window.location.href = 'levels.html'; });

    /* ── touch controls ── */
    LumenInput.bindTouch(document.querySelector('.touch-controls'));

    /* ── confirm/Enter behaviour per state ── */
    function handleConfirm(state) {
      LumenAudio.resume();
      if (state === 'title') game.newGame(startLevelFromURL());
      else if (state === 'paused') game.resume();
      else if (state === 'levelclear') game.nextLevel();
      else if (state === 'gameover') game.newGame(game.levelIndex);
      else if (state === 'win') game.quitToTitle();
    }

    /* ── level-clear / win / gameover screen content ── */
    function fillClearScreen(info) {
      const root = info.isFinal ? overlays.win : overlays.levelclear;
      if (!root) return;
      const t = root.querySelector('[data-clear="time"]');
      const l = root.querySelector('[data-clear="lumens"]');
      const sc = root.querySelector('[data-clear="score"]');
      const stars = root.querySelector('[data-clear="stars"]');
      const best = root.querySelector('[data-clear="best"]');
      if (t) t.textContent = LumenFmtTime(info.elapsed);
      if (l) l.textContent = `${info.lumens}/${info.level.maxLumens}`;
      if (sc) sc.textContent = String(info.score).padStart(6, '0');
      if (stars) stars.innerHTML = '★'.repeat(info.stars) + '<span class="dim">' + '★'.repeat(3 - info.stars) + '</span>';
      if (best) best.textContent = info.newBest ? 'New best!' : '';
      const nameEl = root.querySelector('[data-clear="name"]');
      if (nameEl) nameEl.textContent = info.level.name;
    }
    function fillGameOver(info) {
      const root = overlays.gameover;
      if (!root) return;
      const sc = root.querySelector('[data-clear="score"]');
      if (sc) sc.textContent = String(info.score).padStart(6, '0');
    }

    function reflectState(s) {
      document.body.dataset.gameState = s;
    }

    /* ── ?level= deep link from level select ── */
    function startLevelFromURL() {
      const params = new URLSearchParams(window.location.search);
      const lvl = parseInt(params.get('level'), 10);
      if (lvl >= 1 && lvl <= LumenLevels.count && LumenSave.isUnlocked(lvl)) return lvl;
      return 1;
    }

    // If we arrived with ?level=N and ?autostart, jump straight in.
    const params = new URLSearchParams(window.location.search);
    if (params.get('autostart') === '1') {
      // small delay so audio context can attach to the gesture that navigated here
      game.newGame(startLevelFromURL());
    } else {
      game.setState('title');
    }

    // Resume audio on first interaction anywhere (autoplay policy).
    const kick = () => { LumenAudio.resume(); window.removeEventListener('pointerdown', kick); window.removeEventListener('keydown', kick); };
    window.addEventListener('pointerdown', kick);
    window.addEventListener('keydown', kick);
  });
})();
