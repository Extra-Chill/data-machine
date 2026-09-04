/* =========================================================
   RELAY — App orchestrator
   Wires the pattern input + flags + test text to the matcher,
   highlight overlay, matches panel, explain panel, railroad
   diagram, and replace/split modes. Debounced live updates,
   localStorage + URL-hash persistence, example library.
   ========================================================= */

(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---- elements ----
  var patternInput = $('#pattern');
  var patternError = $('#pattern-error');
  var textArea = $('#test-text');
  var overlay = $('#overlay');
  var flagBtns = $$('.flag');
  var matchList = $('#match-list');
  var matchCount = $('#match-count');
  var matchTime = $('#match-time');
  var explainList = $('#explain-list');
  var railroad = $('#railroad');
  var modeTabs = $$('.mode-tab');
  var panes = { matches: $('#pane-matches'), explain: $('#pane-explain'), diagram: $('#pane-diagram') };
  var replaceWrap = $('#replace-wrap');
  var replaceInput = $('#replace-input');
  var replaceOut = $('#replace-out');
  var replaceOutWrap = $('#replace-out-wrap');
  var splitOut = $('#split-out');
  var replaceModeTabs = $$('.rep-tab');
  var libList = $('#lib-list');
  var sampleList = $('#sample-list');
  var toast = $('#toast');
  var statMatches = $('#stat-matches');
  var statGroups = $('#stat-groups');
  var guardNote = $('#guard-note');

  // ---- state ----
  var state = {
    pattern: '', flags: 'g', text: '', replace: '$&',
    mode: 'matches',      // matches | explain | diagram
    repMode: 'replace'    // replace | split
  };

  var lastMatches = [];

  // ---- init from hash / storage / default ----
  function initState() {
    var fromHash = RelayStorage.loadHash();
    var fromLocal = RelayStorage.loadLocal();
    var d = RelayLibrary.DEFAULT;
    var base = fromHash || fromLocal || {};
    state.pattern = base.pattern != null ? base.pattern : d.pattern;
    state.flags = base.flags != null ? base.flags : d.flags;
    state.text = base.text != null ? base.text : d.text;
    state.replace = base.replace != null ? base.replace : d.replace;
    state.mode = base.mode || 'matches';
    if (base.repMode) state.repMode = base.repMode;
  }

  function syncUIFromState() {
    patternInput.value = state.pattern;
    textArea.value = state.text;
    replaceInput.value = state.replace;
    flagBtns.forEach(function (b) {
      var on = state.flags.indexOf(b.dataset.flag) !== -1;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on);
    });
    setMode(state.mode, true);
    setRepMode(state.repMode, true);
  }

  // ---- flags ----
  function toggleFlag(f) {
    if (state.flags.indexOf(f) === -1) state.flags += f;
    else state.flags = state.flags.replace(f, '');
    // keep a stable canonical order
    var order = 'gimsuy';
    state.flags = order.split('').filter(function (c) { return state.flags.indexOf(c) !== -1; }).join('');
    syncFlagButtons();
    update();
  }
  function syncFlagButtons() {
    flagBtns.forEach(function (b) {
      var on = state.flags.indexOf(b.dataset.flag) !== -1;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on);
    });
  }

  // ---- mode tabs ----
  function setMode(mode, silent) {
    state.mode = mode;
    modeTabs.forEach(function (t) {
      var on = t.dataset.mode === mode;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on);
    });
    Object.keys(panes).forEach(function (k) {
      panes[k].hidden = k !== mode;
    });
    if (!silent) persist();
  }

  function setRepMode(mode, silent) {
    state.repMode = mode;
    replaceModeTabs.forEach(function (t) {
      var on = t.dataset.rep === mode;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on);
    });
    splitOut.hidden = mode !== 'split';
    replaceOutWrap.hidden = mode !== 'replace';
    $('#replace-row').hidden = mode !== 'replace';
    if (!silent) { persist(); renderReplace(); }
  }

  // ---- the big update ----
  function update() {
    state.pattern = patternInput.value;
    state.text = textArea.value;

    // 1) match
    var result = RelayMatcher.run(state.pattern, state.flags, state.text);
    if (!result.ok) {
      showError(result.error);
      lastMatches = [];
      overlay.innerHTML = RelayHighlight.esc(state.text) + '​';
      matchList.innerHTML = '<li class="empty">No matches — fix the pattern above.</li>';
      matchCount.textContent = '—';
      statMatches.textContent = '0';
      statGroups.textContent = '0';
      renderExplainError();
      renderReplace();
      persist();
      return;
    }
    clearError();
    lastMatches = result.matches;

    // 2) overlay highlights
    overlay.innerHTML = RelayHighlight.buildOverlay(state.text, result.matches, result.hasIndices);
    bindOverlayHover();

    // 3) matches panel
    renderMatches(result);

    // 4) explain + diagram (parse AST)
    renderExplainAndDiagram();

    // 5) replace / split
    renderReplace();

    // 6) stats + guard
    statMatches.textContent = result.matches.length;
    var groupTotal = result.matches.reduce(function (a, m) {
      return a + m.groups.filter(function (g) { return g.value != null; }).length;
    }, 0);
    statGroups.textContent = groupTotal;
    matchCount.textContent = result.matches.length;
    matchTime.textContent = result.ms + ' ms';
    guardNote.hidden = !(result.guarded || result.truncated);
    if (result.guarded) guardNote.textContent = '⚠ Stopped early after ' + RelayMatcher.BUDGET_MS + 'ms — possible catastrophic backtracking.';
    else if (result.truncated) guardNote.textContent = '⚠ Showing first 20,000 matches.';

    persist();
  }

  function showError(msg) {
    patternError.textContent = msg;
    patternError.hidden = false;
    patternInput.classList.add('is-invalid');
    patternInput.setAttribute('aria-invalid', 'true');
  }
  function clearError() {
    patternError.hidden = true;
    patternInput.classList.remove('is-invalid');
    patternInput.removeAttribute('aria-invalid');
  }

  // ---- matches panel ----
  function renderMatches(result) {
    if (!result.matches.length) {
      matchList.innerHTML = '<li class="empty">No matches in the test text.</li>';
      return;
    }
    var html = result.matches.map(function (m, i) {
      var groups = m.groups.map(function (g) {
        var lbl = g.name ? '<span class="g-name">' + esc(g.name) + '</span>' : '<span class="g-num">#' + g.num + '</span>';
        var val = g.value == null ? '<i class="g-undef">undefined</i>' : '<code>' + esc(g.value) + '</code>';
        return '<div class="g-row"><span class="g-tag g' + ((g.num - 1) % 4) + '">' + lbl + '</span>' + val + '</div>';
      }).join('');
      return '<li class="match-item" data-mi="' + i + '" tabindex="0">' +
               '<div class="m-head"><span class="m-idx">match ' + i + '</span>' +
               '<span class="m-range">@' + m.index + '–' + m.end + '</span></div>' +
               '<code class="m-full">' + (m.full === '' ? '<i>«empty»</i>' : esc(m.full)) + '</code>' +
               (groups ? '<div class="m-groups">' + groups + '</div>' : '') +
             '</li>';
    }).join('');
    matchList.innerHTML = html;

    $$('.match-item', matchList).forEach(function (li) {
      li.addEventListener('mouseenter', function () { flashMatch(+li.dataset.mi); });
      li.addEventListener('focus', function () { flashMatch(+li.dataset.mi); });
      li.addEventListener('click', function () { scrollToMatch(+li.dataset.mi); });
    });
  }

  function flashMatch(mi) {
    var span = overlay.querySelector('[data-mi="' + mi + '"]');
    if (!span) return;
    span.classList.add('flash');
    setTimeout(function () { span.classList.remove('flash'); }, 700);
  }
  function scrollToMatch(mi) {
    var span = overlay.querySelector('[data-mi="' + mi + '"]');
    if (!span) return;
    var top = span.offsetTop - overlay.parentElement.clientHeight / 2;
    overlay.parentElement.scrollTop = Math.max(0, top);
    textArea.scrollTop = overlay.parentElement.scrollTop;
    flashMatch(mi);
  }
  function bindOverlayHover() {
    $$('.hl', overlay).forEach(function (sp) {
      sp.addEventListener('mouseenter', function () {
        var mi = sp.dataset.mi;
        var li = matchList.querySelector('[data-mi="' + mi + '"]');
        if (li) li.classList.add('hover-peer');
      });
      sp.addEventListener('mouseleave', function () {
        $$('.hover-peer', matchList).forEach(function (x) { x.classList.remove('hover-peer'); });
      });
    });
  }

  // ---- explain + diagram ----
  function renderExplainAndDiagram() {
    var parsed;
    try {
      parsed = RelayParser.parse(state.pattern, state.flags);
    } catch (e) {
      explainList.innerHTML = '<li class="ex-err">Cannot parse: ' + esc(e.message) + '</li>';
      railroad.innerHTML = '<p class="rr-err">Diagram unavailable — ' + esc(e.message) + '</p>';
      return;
    }
    // explain
    var rows = RelayExplain.explain(parsed.ast);
    if (!rows.length) {
      explainList.innerHTML = '<li class="empty">Empty pattern — type a regex to see the breakdown.</li>';
    } else {
      explainList.innerHTML = rows.map(function (r) {
        return '<li class="ex-row d' + Math.min(r.depth, 6) + ' k-' + r.kind + '">' +
                 '<code class="ex-raw">' + esc(r.raw) + '</code>' +
                 '<span class="ex-desc">' + esc(r.desc) + '</span></li>';
      }).join('');
    }
    // diagram
    try {
      RelayRailroad.render(parsed.ast, railroad);
    } catch (e) {
      railroad.innerHTML = '<p class="rr-err">Diagram error: ' + esc(e.message) + '</p>';
    }
  }
  function renderExplainError() {
    explainList.innerHTML = '<li class="ex-err">Pattern is invalid — see the error above.</li>';
    railroad.innerHTML = '<p class="rr-err">No diagram for an invalid pattern.</p>';
  }

  // ---- replace / split ----
  function renderReplace() {
    state.replace = replaceInput.value;
    if (state.repMode === 'split') {
      var sp = RelayMatcher.split(state.pattern, state.flags, state.text);
      if (!sp.ok) { splitOut.innerHTML = '<div class="ro-err">' + esc(sp.error) + '</div>'; return; }
      splitOut.innerHTML = sp.parts.map(function (p, i) {
        return '<div class="split-part"><span class="sp-i">' + i + '</span><code>' + (p === '' ? '<i>«empty»</i>' : esc(p)) + '</code></div>';
      }).join('') || '<div class="empty">No splits.</div>';
      return;
    }
    var r = RelayMatcher.replace(state.pattern, state.flags, state.text, state.replace);
    if (!r.ok) { replaceOut.innerHTML = '<div class="ro-err">' + esc(r.error) + '</div>'; return; }
    replaceOut.textContent = r.value;
  }

  // ---- library ----
  function buildLibrary() {
    libList.innerHTML = RelayLibrary.EXAMPLES.map(function (ex) {
      return '<button class="lib-item" data-id="' + ex.id + '" type="button" title="' + esc(ex.note) + '">' +
               '<span class="lib-name">' + esc(ex.name) + '</span>' +
               '<code class="lib-pat">' + esc(ex.pattern) + '</code>' +
               '<span class="lib-tag">' + esc(ex.tag) + '</span>' +
             '</button>';
    }).join('');
    $$('.lib-item', libList).forEach(function (b) {
      b.addEventListener('click', function () { loadExample(b.dataset.id); });
    });

    sampleList.innerHTML = RelayLibrary.SAMPLES.map(function (s) {
      return '<button class="sample-item" data-id="' + s.id + '" type="button">' + esc(s.name) + '</button>';
    }).join('');
    $$('.sample-item', sampleList).forEach(function (b) {
      b.addEventListener('click', function () {
        var s = RelayLibrary.SAMPLES.filter(function (x) { return x.id === b.dataset.id; })[0];
        if (s) { textArea.value = s.text; syncOverlayScroll(); update(); flashToast('Loaded sample: ' + s.name); }
      });
    });
  }

  function loadExample(id) {
    var ex = RelayLibrary.EXAMPLES.filter(function (x) { return x.id === id; })[0];
    if (!ex) return;
    state.pattern = ex.pattern; state.flags = ex.flags;
    state.text = ex.sample; state.replace = ex.replace;
    patternInput.value = ex.pattern;
    textArea.value = ex.sample;
    replaceInput.value = ex.replace;
    syncFlagButtons();
    syncOverlayScroll();
    update();
    flashToast('Loaded: ' + ex.name);
  }

  // ---- overlay scroll sync ----
  function syncOverlayScroll() {
    overlay.scrollTop = textArea.scrollTop;
    overlay.scrollLeft = textArea.scrollLeft;
  }

  // ---- persistence ----
  var persistTimer;
  function persist() {
    clearTimeout(persistTimer);
    persistTimer = setTimeout(function () {
      RelayStorage.saveLocal(state);
      RelayStorage.writeHash(state);
    }, 250);
  }

  // ---- toast ----
  var toastTimer;
  function flashToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1800);
  }

  function esc(s) { return RelayHighlight.esc(String(s)); }

  // ---- debounce ----
  function debounce(fn, ms) {
    var t;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
  }
  var debouncedUpdate = debounce(update, 90);

  // ---- wire events ----
  function wire() {
    patternInput.addEventListener('input', debouncedUpdate);
    textArea.addEventListener('input', debouncedUpdate);
    textArea.addEventListener('scroll', syncOverlayScroll);
    replaceInput.addEventListener('input', debounce(renderReplace, 80));

    flagBtns.forEach(function (b) {
      b.addEventListener('click', function () { toggleFlag(b.dataset.flag); });
    });
    modeTabs.forEach(function (t) {
      t.addEventListener('click', function () { setMode(t.dataset.mode); });
    });
    replaceModeTabs.forEach(function (t) {
      t.addEventListener('click', function () { setRepMode(t.dataset.rep); });
    });

    // tab key inserts a tab in the textarea (don't lose focus)
    textArea.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        var s = textArea.selectionStart, en = textArea.selectionEnd;
        textArea.value = textArea.value.slice(0, s) + '  ' + textArea.value.slice(en);
        textArea.selectionStart = textArea.selectionEnd = s + 2;
        debouncedUpdate();
      }
    });

    // share button
    $('#btn-share').addEventListener('click', function () {
      var url = RelayStorage.shareURL(state);
      copyText(url);
      flashToast('Share link copied to clipboard');
    });
    $('#btn-copy-pattern').addEventListener('click', function () {
      copyText('/' + state.pattern + '/' + state.flags);
      flashToast('Pattern copied');
    });
    $('#btn-clear').addEventListener('click', function () {
      state.pattern = ''; patternInput.value = '';
      update(); patternInput.focus();
    });
    $('#btn-reset').addEventListener('click', function () {
      var d = RelayLibrary.DEFAULT;
      state.pattern = d.pattern; state.flags = d.flags;
      state.text = d.text; state.replace = d.replace;
      syncUIFromState(); syncFlagButtons(); update();
      flashToast('Reset to default');
    });

    // keyboard: Ctrl/Cmd+Enter focuses test text; Ctrl+/ focuses pattern
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); update(); flashToast('Re-ran'); }
      if ((e.metaKey || e.ctrlKey) && e.key === '/') { e.preventDefault(); patternInput.focus(); patternInput.select(); }
    });

    window.addEventListener('hashchange', function () {
      var fromHash = RelayStorage.loadHash();
      if (fromHash) {
        Object.assign(state, fromHash);
        syncUIFromState(); syncFlagButtons(); update();
      }
    });
  }

  function copyText(t) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).catch(fallbackCopy.bind(null, t));
    } else { fallbackCopy(t); }
  }
  function fallbackCopy(t) {
    var ta = document.createElement('textarea');
    ta.value = t; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  // ---- go ----
  initState();
  syncUIFromState();
  buildLibrary();
  wire();
  update();

})();
