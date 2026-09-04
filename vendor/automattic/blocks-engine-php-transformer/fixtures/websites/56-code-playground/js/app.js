/* =========================================================
   FORKBENCH — App orchestrator
   Wires editors + runner + console + layout + persistence.
   ========================================================= */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- State ---------- */
  var editors = {};      // { html, css, js }
  var pen = { title: 'Untitled pen', html: '', css: '', js: '' };
  var runner;
  var debounceTimer = null;
  var autoRun = true;
  var consoleLog = $('#console-log');
  var consoleCount = $('#console-count');
  var consoleEntries = 0;
  var filters = { log: true, warn: true, error: true };

  /* ---------- Boot ---------- */
  function boot() {
    buildEditors();
    runner = new ForkbenchRunner($('#preview'), onConsoleMessage);
    bindToolbar();
    bindSplitters();
    bindConsole();
    bindKeyboard();
    bindTitle();

    var loaded = resolveInitialPen();
    setPen(loaded);
    run(true);
  }

  /* ---------- Editors ---------- */
  function buildEditors() {
    $$('.editor-pane').forEach(function (paneEl) {
      var lang = paneEl.dataset.lang;
      var host = $('[data-editor]', paneEl);
      editors[lang] = new ForkbenchEditor(host, {
        lang: lang,
        value: '',
        onChange: function (val) {
          pen[lang] = val;
          updateStat(paneEl, val);
          scheduleSave();
          if (autoRun) scheduleRun();
        },
        onCursor: function (info) {
          $('#sb-cursor').textContent = 'Ln ' + info.line + ', Col ' + info.col;
          $('#sb-lang').textContent = info.lang.toUpperCase();
        }
      });
    });
  }

  function updateStat(paneEl, val) {
    var lines = val ? val.split('\n').length : 0;
    $('[data-stat]', paneEl).textContent = lines + (lines === 1 ? ' line' : ' lines');
    updateSize();
  }

  function updateSize() {
    var bytes = (pen.html + pen.css + pen.js).length;
    var el = $('#sb-size');
    el.textContent = bytes < 1024 ? bytes + ' B' : (bytes / 1024).toFixed(1) + ' KB';
  }

  /* ---------- Pen lifecycle ---------- */
  function setPen(p) {
    pen = {
      title: p.title || 'Untitled pen',
      html: p.html || '',
      css: p.css || '',
      js: p.js || ''
    };
    $('#pen-title').value = pen.title;
    updateSlug();
    ['html', 'css', 'js'].forEach(function (lang) {
      editors[lang].setValue(pen[lang]);
      updateStat($('.editor-pane[data-lang="' + lang + '"]'), pen[lang]);
    });
  }

  function resolveInitialPen() {
    var hash = ForkbenchStorage.readHash();
    if (hash.type === 'pen' && hash.pen) {
      toast('Loaded shared pen from link');
      return hash.pen;
    }
    if (hash.type === 'template' && hash.id) {
      var tpl = findTemplate(hash.id);
      if (tpl) { toast('Loaded template: ' + tpl.title); return tplToPen(tpl); }
    }
    var saved = ForkbenchStorage.load();
    if (saved) return saved;
    return tplToPen(findTemplate(window.FORKBENCH_DEFAULT));
  }

  function findTemplate(id) {
    return window.FORKBENCH_TEMPLATES.filter(function (t) { return t.id === id; })[0];
  }
  function tplToPen(t) {
    return { title: t.title, html: t.html, css: t.css, js: t.js };
  }

  /* ---------- Run / debounce ---------- */
  function scheduleRun() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () { run(false); }, 600);
  }

  function run(clearConsole) {
    if (clearConsole) clearConsoleLog();
    runner.run(pen);
    flashRun();
  }

  function flashRun() {
    var f = $('#run-flash');
    f.classList.remove('show');
    void f.offsetWidth; // reflow to restart animation
    f.classList.add('show');
  }

  /* ---------- Console ---------- */
  function onConsoleMessage(msg) {
    if (msg.level === 'system') {
      if (msg.text === '__cleared__') clearConsoleLog();
      return;
    }
    addConsoleEntry(msg.level, msg.text);
  }

  function addConsoleEntry(level, text) {
    var empty = $('.console-empty', consoleLog);
    if (empty) empty.remove();
    var row = document.createElement('div');
    row.className = 'cline level-' + level;
    if (!filters[level]) row.classList.add('is-hidden');
    var mark = document.createElement('span');
    mark.className = 'cmark';
    mark.textContent = level === 'error' ? '✕' : level === 'warn' ? '▲' : '›';
    var body = document.createElement('span');
    body.className = 'cbody';
    body.textContent = text;
    row.appendChild(mark);
    row.appendChild(body);
    consoleLog.appendChild(row);
    consoleLog.scrollTop = consoleLog.scrollHeight;
    consoleEntries++;
    consoleCount.textContent = consoleEntries;
  }

  function clearConsoleLog() {
    consoleLog.innerHTML = '<div class="console-empty">No output yet. Use <code>console.log()</code> in your JS, then Run.</div>';
    consoleEntries = 0;
    consoleCount.textContent = '0';
  }

  function bindConsole() {
    $('#console-clear').addEventListener('click', clearConsoleLog);
    $$('.cfilter').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var lvl = btn.dataset.level;
        filters[lvl] = !filters[lvl];
        btn.classList.toggle('is-on', filters[lvl]);
        btn.setAttribute('aria-pressed', String(filters[lvl]));
        $$('.cline.level-' + lvl, consoleLog).forEach(function (r) {
          r.classList.toggle('is-hidden', !filters[lvl]);
        });
      });
    });
  }

  /* ---------- Persistence ---------- */
  var saveTimer = null;
  function scheduleSave() {
    setSaveState('saving');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      ForkbenchStorage.save(pen);
      setSaveState('saved');
    }, 500);
  }

  function setSaveState(state) {
    var el = $('#save-state');
    el.textContent = state;
    el.dataset.state = state;
  }

  function updateSlug() {
    var slug = (pen.title || 'untitled')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'untitled';
    $('#preview-slug').textContent = slug;
  }

  /* ---------- Title ---------- */
  function bindTitle() {
    var input = $('#pen-title');
    input.addEventListener('input', function () {
      pen.title = input.value || 'Untitled pen';
      updateSlug();
      scheduleSave();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); editors.html.focus(); }
    });
  }

  /* ---------- Toolbar ---------- */
  function bindToolbar() {
    $('#btn-run').addEventListener('click', function () { run(true); });

    var autoEl = $('#auto-run');
    autoEl.addEventListener('change', function () {
      autoRun = autoEl.checked;
      toast(autoRun ? 'Auto-run on' : 'Auto-run off');
      if (autoRun) run(true);
    });

    $('#layout-row').addEventListener('click', function () { setLayout('row'); });
    $('#layout-col').addEventListener('click', function () { setLayout('col'); });

    $$('.pane-toggles .chip').forEach(function (chip) {
      chip.addEventListener('click', function () { togglePane(chip.dataset.pane, chip); });
    });

    $('#btn-fork').addEventListener('click', forkPen);
    $('#btn-reset').addEventListener('click', resetPen);
    $('#btn-share').addEventListener('click', sharePen);
    $('#btn-export').addEventListener('click', exportPen);
  }

  function setLayout(dir) {
    var ws = $('#workspace');
    ws.classList.toggle('layout-row', dir === 'row');
    ws.classList.toggle('layout-col', dir === 'col');
    $('#layout-row').classList.toggle('is-active', dir === 'row');
    $('#layout-col').classList.toggle('is-active', dir === 'col');
    $('#layout-row').setAttribute('aria-pressed', String(dir === 'row'));
    $('#layout-col').setAttribute('aria-pressed', String(dir === 'col'));
    // reset inline editor flex bases so the new direction lays out evenly
    $$('.editor-pane').forEach(function (p) { p.style.flexBasis = ''; });
    $('#editors').style.flexBasis = '';
    $('#output').style.flexBasis = '';
  }

  function togglePane(lang, chip) {
    var pane = $('.editor-pane[data-lang="' + lang + '"]');
    var visiblePanes = $$('.editor-pane:not(.is-hidden)');
    var on = !pane.classList.contains('is-hidden');
    if (on && visiblePanes.length === 1) { toast('Keep at least one pane open'); return; }
    pane.classList.toggle('is-hidden');
    chip.classList.toggle('is-on');
    chip.setAttribute('aria-pressed', String(pane.classList.contains('is-hidden') ? false : true));
    // hide adjacent splitters when a neighbour is gone
    refreshSplitters();
    $$('.editor-pane').forEach(function (p) { p.style.flexBasis = ''; });
  }

  function refreshSplitters() {
    $$('.vsplitter').forEach(function (sp) {
      var parts = sp.dataset.split.split('-');
      var a = $('.editor-pane[data-lang="' + parts[0] + '"]');
      var b = $('.editor-pane[data-lang="' + parts[1] + '"]');
      var aVis = a && !a.classList.contains('is-hidden');
      var bVis = b && !b.classList.contains('is-hidden');
      sp.style.display = (aVis && bVis) ? '' : 'none';
    });
  }

  function forkPen() {
    pen.title = pen.title.replace(/ \(fork.*\)$/, '') + ' (fork)';
    $('#pen-title').value = pen.title;
    updateSlug();
    ForkbenchStorage.setHash('');
    ForkbenchStorage.save(pen);
    toast('Forked — saved as a new pen');
  }

  function resetPen() {
    if (!confirm('Clear all three editors? This cannot be undone.')) return;
    setPen({ title: 'Untitled pen', html: '', css: '', js: '' });
    ForkbenchStorage.setHash('');
    ForkbenchStorage.save(pen);
    clearConsoleLog();
    run(true);
    toast('Reset to a blank pen');
    editors.html.focus();
  }

  function sharePen() {
    var enc = ForkbenchStorage.encodeHash(pen);
    ForkbenchStorage.setHash(enc);
    var url = location.href;
    copyText(url).then(function (ok) {
      toast(ok ? 'Share link copied to clipboard' : 'Share link written to the URL bar');
    });
  }

  function exportPen() {
    var html = runner.buildExport(pen);
    var blob = new Blob([html], { type: 'text/html' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = (pen.title || 'forkbench-pen')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') + '.html';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    toast('Downloaded ' + a.download);
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = document.execCommand('copy');
      ta.remove();
      return Promise.resolve(ok);
    } catch (e) { return Promise.resolve(false); }
  }

  /* ---------- Splitters (drag to resize) ---------- */
  function bindSplitters() {
    $$('.vsplitter').forEach(function (sp) {
      makeDraggable(sp, function (delta, e) {
        var parts = sp.dataset.split.split('-');
        resizeFlex($('.editor-pane[data-lang="' + parts[0] + '"]'),
                   $('.editor-pane[data-lang="' + parts[1] + '"]'),
                   delta, 'x');
      }, 'x');
    });

    makeDraggable($('#msplitter'), function (delta) {
      var ws = $('#workspace');
      var horiz = ws.classList.contains('layout-row');
      resizeFlex($('#editors'), $('#output'), delta, horiz ? 'x' : 'y');
    }, 'auto');

    makeDraggable($('#osplitter'), function (delta) {
      resizeFlex($('.preview-wrap'), $('#console-wrap'), delta, 'y');
    }, 'y');

    // keyboard resize for accessibility
    $$('[role="separator"]').forEach(function (sp) {
      sp.addEventListener('keydown', function (e) {
        var step = 24;
        var horiz = sp.getAttribute('aria-orientation') === 'vertical';
        var d = 0;
        if (horiz && e.key === 'ArrowLeft') d = -step;
        else if (horiz && e.key === 'ArrowRight') d = step;
        else if (!horiz && e.key === 'ArrowUp') d = -step;
        else if (!horiz && e.key === 'ArrowDown') d = step;
        if (d !== 0) { e.preventDefault(); sp._drag(d); }
      });
    });
  }

  function resizeFlex(a, b, delta, axis) {
    if (!a || !b) return;
    var prop = axis === 'x' ? 'offsetWidth' : 'offsetHeight';
    var total = a[prop] + b[prop];
    var aNew = a[prop] + delta;
    var min = 60;
    aNew = Math.max(min, Math.min(total - min, aNew));
    var pct = (aNew / total) * 100;
    a.style.flex = '0 0 ' + pct + '%';
    b.style.flex = '0 0 ' + (100 - pct) + '%';
  }

  function makeDraggable(handle, onMove, axis) {
    var dragging = false, last = 0, vertAxis = 'x';

    function detectAxis() {
      var ws = $('#workspace');
      if (axis === 'auto') return ws.classList.contains('layout-row') ? 'x' : 'y';
      return axis;
    }

    function down(e) {
      dragging = true;
      vertAxis = detectAxis();
      last = vertAxis === 'x' ? clientX(e) : clientY(e);
      handle.classList.add('dragging');
      document.body.classList.add('is-resizing');
      e.preventDefault();
    }
    function move(e) {
      if (!dragging) return;
      var pos = vertAxis === 'x' ? clientX(e) : clientY(e);
      var delta = pos - last;
      last = pos;
      onMove(delta, e);
    }
    function up() {
      if (!dragging) return;
      dragging = false;
      handle.classList.remove('dragging');
      document.body.classList.remove('is-resizing');
    }

    handle.addEventListener('mousedown', down);
    handle.addEventListener('touchstart', down, { passive: false });
    document.addEventListener('mousemove', move);
    document.addEventListener('touchmove', move, { passive: false });
    document.addEventListener('mouseup', up);
    document.addEventListener('touchend', up);

    // for keyboard
    handle._drag = function (delta) {
      vertAxis = detectAxis();
      onMove(delta, null);
    };
  }

  function clientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }
  function clientY(e) { return e.touches ? e.touches[0].clientY : e.clientY; }

  /* ---------- Keyboard shortcuts ---------- */
  function bindKeyboard() {
    document.addEventListener('keydown', function (e) {
      var mod = e.metaKey || e.ctrlKey;
      if (!mod) return;
      if (e.key === 'Enter') { e.preventDefault(); run(true); }
      else if (e.key.toLowerCase() === 's') { e.preventDefault(); ForkbenchStorage.save(pen); setSaveState('saved'); toast('Pen saved'); }
      else if (e.key.toLowerCase() === 'k' && e.shiftKey) { e.preventDefault(); clearConsoleLog(); }
      else if (e.key === '1') { e.preventDefault(); editors.html.focus(); }
      else if (e.key === '2') { e.preventDefault(); editors.css.focus(); }
      else if (e.key === '3') { e.preventDefault(); editors.js.focus(); }
    });
  }

  /* ---------- Toast ---------- */
  var toastTimer = null;
  function toast(msg) {
    var el = $('#toast');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('show'); }, 2400);
  }

  // React to hash changes (e.g. clicking a template link in another tab)
  window.addEventListener('hashchange', function () {
    var h = ForkbenchStorage.readHash();
    if (h.type === 'template' && h.id) {
      var t = findTemplate(h.id);
      if (t) { setPen(tplToPen(t)); run(true); toast('Loaded template: ' + t.title); }
    } else if (h.type === 'pen' && h.pen) {
      setPen(h.pen); run(true); toast('Loaded shared pen');
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
