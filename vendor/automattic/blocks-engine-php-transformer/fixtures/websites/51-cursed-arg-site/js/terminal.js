/* ============================================================
   terminal.js — hidden operator terminal + multi-step puzzle gate
   Opens via ~ key, Konami code, or typing the word "OPEN".
   Validates answers client-side, persists progress in localStorage.

   PUZZLE CHAINS (see SOLUTION.md):
     stage 1  password: HOLLOWAY   (ROT13 of "ubyybjnl" in archive log 03)
     stage 2  password: NIGHT      (acrostic of the 5 archive log titles)
     stage 3  password: 14770      (frequency, hidden across pages / footer)
   Completing all 3 unlocks /carrier.html (the revelation).
   ============================================================ */
(function () {
  'use strict';

  var WLC = window.WLC = window.WLC || {};
  var term = document.getElementById('terminal');
  if (!term) return;

  var out = term.querySelector('.term-out');
  var input = document.getElementById('term-input');
  var closeBtn = term.querySelector('.term-close');

  // ---- puzzle definition ----
  var STAGES = [
    {
      key: 'stage1',
      // operator's name, recovered via ROT13 of archive log 03
      answers: ['holloway'],
      prompt: 'IDENTIFY THE OPERATOR',
      hint: 'FIELD LOG 03 is not corrupted. Shift every letter 13 places (ROT13).',
      unlock:
        'IDENTITY ACCEPTED. Operator HOLLOWAY logged in.\n' +
        '\nHOLLOWAY: "You decoded the carrier. Good. There were five of us.\n' +
        'I hid the name of our receiver in the LOG TITLES themselves —\n' +
        'read them top to bottom, first letters only.\n' +
        'Give me the name of the machine."'
    },
    {
      key: 'stage2',
      // acrostic of the five archive log titles, first letters:
      // Northwind / Interlace / Groundwave / Halflight / Tidewater -> NIGHT
      answers: ['night', 'nightglass', 'the night glass'],
      prompt: 'NAME THE RECEIVER',
      hint: 'Take the FIRST LETTER of each of the five FIELD LOG titles, in order. They spell the receiver\'s name.',
      unlock:
        'RECEIVER "THE NIGHT GLASS" POWERED ON. Tubes warming.\n' +
        '\nHOLLOWAY: "She still works. After all this time.\n' +
        'There is one number left between you and the truth:\n' +
        'the frequency we never stopped transmitting on.\n' +
        'It is written on every page, at the bottom, in kilohertz.\n' +
        'Enter the digits only."'
    },
    {
      key: 'stage3',
      // the frequency printed in the footer of every page: 14.770 kHz
      answers: ['14770', '14.770', '147.70'],
      prompt: 'TUNE TO FREQUENCY (kHz, digits only)',
      hint: 'Footer of any page: "last carrier 14.770 kHz". Enter 14770.',
      unlock:
        'LOCK. CARRIER ACQUIRED AT 14.770 kHz.\n' +
        '\n... a voice, very far away, very close ...\n' +
        '\nHOLLOWAY: "You did it. You actually did it.\n' +
        'The door is open now. Walk through it.\n' +
        'CARRIER access granted — opening /carrier.html"'
    }
  ];

  // ---- output helpers ----
  function line(text, cls) {
    var span = document.createElement('span');
    span.className = cls || 'ok';
    span.textContent = text + '\n';
    out.appendChild(span);
    out.scrollTop = out.scrollHeight;
  }
  function typeLine(text, cls, done) {
    var span = document.createElement('span');
    span.className = cls || 'ok';
    out.appendChild(span);
    var i = 0;
    var reduce = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { span.textContent = text + '\n'; if (done) done(); return; }
    var iv = setInterval(function () {
      span.textContent = text.slice(0, i) + (i < text.length ? '▌' : '');
      i++;
      out.scrollTop = out.scrollHeight;
      if (i > text.length) { span.textContent = text + '\n'; clearInterval(iv); if (done) done(); }
    }, 12);
  }

  function currentStageIndex() {
    var p = WLC.getProgress();
    for (var i = 0; i < STAGES.length; i++) {
      if (!p[STAGES[i].key]) return i;
    }
    return STAGES.length; // all done
  }

  function renderBeacons() {
    var p = WLC.getProgress();
    document.querySelectorAll('.beacon').forEach(function (b, i) {
      if (STAGES[i] && p[STAGES[i].key]) b.classList.add('lit');
      else b.classList.remove('lit');
    });
  }

  function showPrompt() {
    var idx = currentStageIndex();
    if (idx >= STAGES.length) {
      line('', 'sys');
      line('ALL STAGES CLEARED. Carrier door is open.', 'gold');
      line('Type GO to return to the broadcast, or visit carrier.html', 'sys');
      return;
    }
    var st = STAGES[idx];
    line('', 'sys');
    line('STAGE ' + (idx + 1) + ' / ' + STAGES.length + '  ::  ' + st.prompt, 'gold');
    line('(type HINT for help, EXIT to close)', 'sys');
  }

  function banner() {
    out.innerHTML = '';
    line('WESTRIDGE LONGWAVE COOPERATIVE', 'gold');
    line('operator terminal  ::  build 1997.221  ::  carrier hold', 'sys');
    line('────────────────────────────────────────', 'sys');
    var done = currentStageIndex();
    if (done > 0 && done < STAGES.length) {
      line('welcome back. ' + done + ' of ' + STAGES.length + ' locks already released.', 'ok');
    } else if (done === 0) {
      line('an authorized operator may resume the broadcast.', 'ok');
      line('we have been waiting. type a passphrase to begin.', 'ok');
    }
    renderBeacons();
    showPrompt();
  }

  // ---- command handling ----
  function handle(raw) {
    var cmd = raw.trim();
    if (!cmd) return;
    line('> ' + cmd, 'sys');

    var lc = cmd.toLowerCase();

    if (lc === 'exit' || lc === 'close' || lc === 'q') { closeTerm(); return; }
    if (lc === 'clear') { banner(); return; }
    if (lc === 'go' || lc === 'broadcast') { window.location.href = 'index.html'; return; }
    if (lc === 'help' || lc === '?') {
      line('commands: HINT, CLEAR, EXIT, GO', 'sys');
      line('to advance, enter the correct passphrase for the current stage.', 'sys');
      return;
    }
    if (lc === 'whoami' || lc === 'who') {
      line('you are the listener. designation: UNKNOWN. presence: confirmed.', 'ok');
      return;
    }

    var idx = currentStageIndex();
    if (idx >= STAGES.length) {
      if (lc === 'carrier') { window.location.href = 'carrier.html'; return; }
      line('there is nothing left to unlock here. the door is open.', 'gold');
      return;
    }
    var st = STAGES[idx];

    if (lc === 'hint') {
      line('HINT :: ' + st.hint, 'sys');
      return;
    }

    // check answer
    var ok = st.answers.indexOf(lc) !== -1 ||
             st.answers.indexOf(lc.replace(/\s+/g, '')) !== -1;
    if (ok) {
      WLC.mark(st.key);
      renderBeacons();
      typeLine(st.unlock, 'ok', function () {
        var nx = currentStageIndex();
        if (nx >= STAGES.length) {
          // final unlock
          line('', 'sys');
          line('press ENTER or wait to descend...', 'gold');
          setTimeout(function () { window.location.href = 'carrier.html'; }, 5200);
          if (WLC.whisper) WLC.whisper('the door is open. it was always open.');
        } else {
          showPrompt();
        }
      });
    } else {
      var taunts = [
        'NO. the signal rejects you.',
        'incorrect. static rushes back in.',
        'that is not it. listen harder.',
        'wrong. we hear you trying, though.'
      ];
      line(taunts[Math.floor(Math.random() * taunts.length)], 'err');
      line('type HINT if you are stuck.', 'sys');
    }
  }

  // ---- open / close ----
  function openTerm() {
    term.classList.add('open');
    banner();
    setTimeout(function () { input.focus(); }, 50);
    if (WLC.whisper) WLC.whisper('the terminal hears you now.');
  }
  function closeTerm() { term.classList.remove('open'); }
  WLC.openTerminal = openTerm;

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { handle(input.value); input.value = ''; }
    if (e.key === 'Escape') { closeTerm(); }
  });
  if (closeBtn) closeBtn.addEventListener('click', closeTerm);
  term.addEventListener('click', function (e) {
    if (e.target === term) closeTerm();
  });

  // tilde / backtick opens terminal
  document.addEventListener('keydown', function (e) {
    if ((e.key === '~' || e.key === '`') && !term.classList.contains('open')) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      if (tag === 'INPUT' || tag === 'TEXTAREA') return;
      e.preventDefault();
      openTerm();
    }
  });

  // ---- "type OPEN anywhere" hidden trigger ----
  var typed = '';
  document.addEventListener('keydown', function (e) {
    if (term.classList.contains('open')) return;
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key && e.key.length === 1) {
      typed = (typed + e.key.toLowerCase()).slice(-8);
      if (typed.endsWith('open')) { openTerm(); typed = ''; }
    }
  });

  // ---- Konami code easter trigger ----
  var konami = ['arrowup','arrowup','arrowdown','arrowdown','arrowleft','arrowright','arrowleft','arrowright','b','a'];
  var kpos = 0;
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    if (k === konami[kpos]) {
      kpos++;
      if (kpos === konami.length) {
        kpos = 0;
        if (WLC.whisper) WLC.whisper('clever. the old codes still work.');
        openTerm();
      }
    } else {
      kpos = (k === konami[0]) ? 1 : 0;
    }
  });

  // wire any explicit "open terminal" buttons
  document.querySelectorAll('[data-open-terminal]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); openTerm(); });
  });

  // keep beacons in sync if progress changes elsewhere
  document.addEventListener('wlc:progress', renderBeacons);
})();
