/* ============================================================
   Inkwell — main editor controller
   Wires the textarea, live preview, outline, doc sidebar,
   themes, focus mode, autosave, export and slides together.
   ============================================================ */
(function () {
  'use strict';

  var Store = window.InkwellStore;
  var Editor = window.InkwellEditor;
  var Slides = window.InkwellSlides;
  var Exp = window.InkwellExport;

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---- elements ----
  var ta = $('#md-input');
  var previewEl = $('#preview');
  var previewScroll = $('#preview-scroll');
  var editorScroll = $('#editor-scroll');
  var outlineEl = $('#outline');
  var docListEl = $('#doc-list');
  var titleInput = $('#doc-title');
  var saveState = $('#save-state');
  var toastEl = $('#toast');

  var editor = new Editor(ta);
  var slides = new Slides($('#slides-overlay'));

  // ---- state ----
  var docs = Store.loadDocs();
  var settings = Store.loadSettings();
  var activeId = Store.getActiveId() || (docs[0] && docs[0].id);
  if (!docs.some(function (d) { return d.id === activeId; })) activeId = docs[0].id;

  function activeDoc() { return docs.find(function (d) { return d.id === activeId; }); }

  // ---- toast ----
  var toastTimer;
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 1900);
  }

  // ---- theme & layout ----
  function applySettings() {
    document.documentElement.setAttribute('data-theme', settings.theme);
    document.body.classList.toggle('focus-mode', !!settings.focus);
    document.body.setAttribute('data-layout', settings.layout);
    $$('.theme-btn').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.theme === settings.theme));
    });
    var fb = $('#btn-focus');
    if (fb) fb.setAttribute('aria-pressed', String(!!settings.focus));
    var ss = $('#btn-syncscroll');
    if (ss) ss.setAttribute('aria-pressed', String(!!settings.syncScroll));
    $$('.layout-btn').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.layout === settings.layout));
    });
  }
  function persistSettings() { Store.saveSettings(settings); applySettings(); }

  // ---- render preview + outline + stats ----
  var headings = [];
  function renderPreview() {
    var result = window.Inkwell.render(ta.value);
    previewEl.innerHTML = result.html || '<p class="preview-empty">Nothing to preview yet — start typing on the left.</p>';
    headings = result.headings;
    buildOutline();
    updateStats();
    syncTaskCheckboxes();
  }

  function buildOutline() {
    if (!headings.length) {
      outlineEl.innerHTML = '<p class="outline-empty">Headings appear here as you write them.</p>';
      return;
    }
    var min = Math.min.apply(null, headings.map(function (h) { return h.level; }));
    outlineEl.innerHTML = headings.map(function (h) {
      return '<a class="outline-item lvl-' + (h.level - min) + '" href="#' + h.id +
        '" data-id="' + h.id + '" style="--depth:' + (h.level - min) + '">' +
        window.Inkwell.escapeHtml(h.text) + '</a>';
    }).join('');
  }

  // clicking a rendered task checkbox toggles the source [ ] / [x]
  function syncTaskCheckboxes() {
    $$('.md-task', previewEl).forEach(function (box, idx) {
      box.disabled = false;
      box.dataset.taskIndex = idx;
    });
  }
  previewEl.addEventListener('change', function (ev) {
    var box = ev.target.closest('.md-task');
    if (!box) return;
    var n = +box.dataset.taskIndex;
    var count = -1;
    var newBody = ta.value.replace(/^(\s*(?:[-*+]|\d+[.)])\s+)\[([ xX])\]/gm, function (m, pre, mark) {
      count++;
      if (count === n) return pre + '[' + (box.checked ? 'x' : ' ') + ']';
      return m;
    });
    ta.value = newBody;
    onInput();
  });

  function updateStats() {
    var s = Editor.computeStats(ta.value);
    $('#stat-words').textContent = s.words.toLocaleString();
    $('#stat-chars').textContent = s.chars.toLocaleString();
    $('#stat-read').textContent = s.reading;
    $('#stat-read-foot').textContent = s.reading + ' read';
    $('#stat-words-foot').textContent = s.words.toLocaleString() + (s.words === 1 ? ' word' : ' words');
  }

  // ---- scroll sync ----
  var syncing = false;
  function syncFromEditor() {
    if (!settings.syncScroll || syncing) return;
    syncing = true;
    var max = editorScroll.scrollHeight - editorScroll.clientHeight;
    var ratio = max > 0 ? editorScroll.scrollTop / max : 0;
    var pmax = previewScroll.scrollHeight - previewScroll.clientHeight;
    previewScroll.scrollTop = ratio * pmax;
    requestAnimationFrame(function () { syncing = false; });
  }
  function syncFromPreview() {
    if (!settings.syncScroll || syncing) return;
    syncing = true;
    var max = previewScroll.scrollHeight - previewScroll.clientHeight;
    var ratio = max > 0 ? previewScroll.scrollTop / max : 0;
    var emax = editorScroll.scrollHeight - editorScroll.clientHeight;
    editorScroll.scrollTop = ratio * emax;
    requestAnimationFrame(function () { syncing = false; });
  }
  editorScroll.addEventListener('scroll', syncFromEditor, { passive: true });
  previewScroll.addEventListener('scroll', syncFromPreview, { passive: true });

  // ---- typewriter / focus mode: keep caret line centred ----
  function typewriterScroll() {
    if (!settings.focus) return;
    var before = ta.value.slice(0, ta.selectionStart);
    var lineNo = (before.match(/\n/g) || []).length;
    var lineHeight = parseFloat(getComputedStyle(ta).lineHeight) || 28;
    var target = lineNo * lineHeight - editorScroll.clientHeight / 2 + lineHeight;
    editorScroll.scrollTop = Math.max(0, target);
  }

  // ---- autosave ----
  var saveTimer;
  function scheduleSave() {
    saveState.textContent = 'saving…';
    saveState.className = 'save-state saving';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(commit, 500);
  }
  function commit() {
    var d = activeDoc();
    if (!d) return;
    d.body = ta.value;
    var derived = Store.deriveTitle(ta.value);
    if (!d.titleManual) { d.title = derived; titleInput.value = derived; }
    d.updated = Date.now();
    Store.saveDocs(docs);
    Store.setActiveId(activeId);
    saveState.textContent = 'saved';
    saveState.className = 'save-state saved';
    renderDocList();
  }

  function onInput() {
    renderPreview();
    scheduleSave();
    typewriterScroll();
  }
  ta.addEventListener('input', onInput);
  ta.addEventListener('keyup', function (e) {
    updateCursor();
    if (['ArrowUp', 'ArrowDown', 'Enter'].indexOf(e.key) !== -1) typewriterScroll();
  });
  ta.addEventListener('click', updateCursor);

  function updateCursor() {
    var before = ta.value.slice(0, ta.selectionStart);
    var line = (before.match(/\n/g) || []).length + 1;
    var col = before.length - before.lastIndexOf('\n');
    $('#cursor-pos').textContent = 'Ln ' + line + ', Col ' + col;
  }

  // ---- document sidebar ----
  function renderDocList() {
    docs.sort(function (a, b) { return b.updated - a.updated; });
    docListEl.innerHTML = docs.map(function (d) {
      var preview = (d.body || '').replace(/[#>*_`~\-]/g, '').replace(/\n+/g, ' ').trim().slice(0, 48);
      var when = relTime(d.updated);
      return '<li class="doc-row' + (d.id === activeId ? ' active' : '') + '" data-id="' + d.id + '" tabindex="0" role="button">' +
        '<span class="doc-row-title">' + window.Inkwell.escapeHtml(d.title || 'Untitled') + '</span>' +
        '<span class="doc-row-sub">' + window.Inkwell.escapeHtml(preview || 'Empty document') + '</span>' +
        '<span class="doc-row-meta">' + when + '</span>' +
        '<span class="doc-row-actions">' +
          '<button class="row-act" data-act="duplicate" title="Duplicate" aria-label="Duplicate document">⧉</button>' +
          '<button class="row-act" data-act="rename" title="Rename" aria-label="Rename document">✎</button>' +
          '<button class="row-act danger" data-act="delete" title="Delete" aria-label="Delete document">🗑</button>' +
        '</span>' +
        '</li>';
    }).join('');
    $('#doc-count').textContent = docs.length + (docs.length === 1 ? ' document' : ' documents');
  }

  function relTime(ts) {
    var diff = (Date.now() - ts) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return new Date(ts).toLocaleDateString();
  }

  function loadDoc(id) {
    var d = docs.find(function (x) { return x.id === id; });
    if (!d) return;
    activeId = id;
    Store.setActiveId(id);
    ta.value = d.body;
    titleInput.value = d.title || '';
    renderPreview();
    renderDocList();
    updateCursor();
    editorScroll.scrollTop = 0;
    saveState.textContent = 'saved';
    saveState.className = 'save-state saved';
  }

  docListEl.addEventListener('click', function (ev) {
    var actBtn = ev.target.closest('.row-act');
    var row = ev.target.closest('.doc-row');
    if (!row) return;
    var id = row.dataset.id;
    if (actBtn) {
      ev.stopPropagation();
      var act = actBtn.dataset.act;
      if (act === 'delete') deleteDoc(id);
      else if (act === 'rename') renameDoc(id);
      else if (act === 'duplicate') duplicateDoc(id);
      return;
    }
    loadDoc(id);
  });
  docListEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' || ev.key === ' ') {
      var row = ev.target.closest('.doc-row');
      if (row) { ev.preventDefault(); loadDoc(row.dataset.id); }
    }
  });

  function newDoc(title, body) {
    var d = Store.createDoc(title, body);
    docs = Store.loadDocs();
    loadDoc(d.id);
    titleInput.focus();
    titleInput.select();
    toast('New document created');
  }
  function duplicateDoc(id) {
    var src = docs.find(function (x) { return x.id === id; });
    if (!src) return;
    var d = Store.createDoc((src.title || 'Untitled') + ' (copy)', src.body);
    d.titleManual = true;
    docs = Store.loadDocs();
    Store.saveDocs(docs);
    loadDoc(d.id);
    toast('Duplicated');
  }
  function renameDoc(id) {
    var d = docs.find(function (x) { return x.id === id; });
    if (!d) return;
    var name = prompt('Rename document', d.title || '');
    if (name == null) return;
    d.title = name.trim() || 'Untitled';
    d.titleManual = true;
    Store.saveDocs(docs);
    if (id === activeId) titleInput.value = d.title;
    renderDocList();
  }
  function deleteDoc(id) {
    var d = docs.find(function (x) { return x.id === id; });
    if (!d) return;
    if (!confirm('Delete "' + (d.title || 'Untitled') + '"? This cannot be undone.')) return;
    docs = docs.filter(function (x) { return x.id !== id; });
    if (!docs.length) {
      var fresh = Store.createDoc('Untitled document', '# Untitled document\n\n');
      docs = Store.loadDocs();
      Store.saveDocs(docs);
      loadDoc(fresh.id);
    } else {
      Store.saveDocs(docs);
      if (id === activeId) loadDoc(docs[0].id);
      else renderDocList();
    }
    toast('Document deleted');
  }

  // ---- title editing ----
  titleInput.addEventListener('input', function () {
    var d = activeDoc();
    if (!d) return;
    d.title = titleInput.value;
    d.titleManual = true;
    scheduleSave();
  });

  // ---- outline click -> scroll preview ----
  outlineEl.addEventListener('click', function (ev) {
    var a = ev.target.closest('.outline-item');
    if (!a) return;
    ev.preventDefault();
    var id = a.dataset.id;
    var target = previewEl.querySelector('#' + CSS.escape(id));
    if (target) {
      target.scrollIntoView({ behavior: prefersReduced() ? 'auto' : 'smooth', block: 'start' });
      target.classList.add('flash');
      setTimeout(function () { target.classList.remove('flash'); }, 900);
    }
  });
  function prefersReduced() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  // highlight current outline entry while scrolling preview
  previewScroll.addEventListener('scroll', function () {
    var hs = $$('.md-h', previewEl);
    var top = previewScroll.scrollTop + 80;
    var current = null;
    for (var i = 0; i < hs.length; i++) {
      if (hs[i].offsetTop <= top) current = hs[i].id; else break;
    }
    $$('.outline-item', outlineEl).forEach(function (a) {
      a.classList.toggle('current', a.dataset.id === current);
    });
  }, { passive: true });

  // ---- toolbar buttons ----
  $$('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var cmd = btn.dataset.cmd;
      switch (cmd) {
        case 'bold': editor.wrap('**', '**', 'bold'); break;
        case 'italic': editor.wrap('*', '*', 'italic'); break;
        case 'strike': editor.wrap('~~', '~~', 'strikethrough'); break;
        case 'code': editor.wrap('`', '`', 'code'); break;
        case 'codeblock': editor.codeBlock(); break;
        case 'h1': editor.heading(1); break;
        case 'h2': editor.heading(2); break;
        case 'h3': editor.heading(3); break;
        case 'ul': editor.bulletList(); break;
        case 'ol': editor.numberList(); break;
        case 'task': editor.taskList(); break;
        case 'quote': editor.quote(); break;
        case 'link': editor.link(); break;
        case 'image': editor.image(); break;
        case 'table': editor.table(); break;
        case 'hr': editor.hr(); break;
        case 'slide': editor.slideBreak(); break;
      }
      ta.focus();
    });
  });

  // ---- header actions ----
  $('#btn-new').addEventListener('click', function () { newDoc('Untitled document', '# Untitled document\n\nStart writing…\n'); });
  $('#btn-present').addEventListener('click', function () { slides.open(ta.value); });
  $('#btn-export-md').addEventListener('click', function () { Exp.exportMarkdown(activeDoc()); toast('Markdown downloaded'); });
  $('#btn-export-html').addEventListener('click', function () { Exp.exportHtml(activeDoc()); toast('HTML exported'); });
  $('#btn-print').addEventListener('click', function () {
    if (!Exp.printDocument(activeDoc())) toast('Pop-up blocked — allow pop-ups to print');
  });
  $('#btn-focus').addEventListener('click', function () { settings.focus = !settings.focus; persistSettings(); typewriterScroll(); toast(settings.focus ? 'Focus mode on' : 'Focus mode off'); });
  $('#btn-syncscroll').addEventListener('click', function () { settings.syncScroll = !settings.syncScroll; persistSettings(); toast(settings.syncScroll ? 'Scroll sync on' : 'Scroll sync off'); });

  $$('.theme-btn').forEach(function (b) {
    b.addEventListener('click', function () { settings.theme = b.dataset.theme; persistSettings(); });
  });
  $$('.layout-btn').forEach(function (b) {
    b.addEventListener('click', function () { settings.layout = b.dataset.layout; persistSettings(); });
  });

  // sidebar collapse
  var sidebarBtn = $('#btn-sidebar');
  if (sidebarBtn) sidebarBtn.addEventListener('click', function () {
    document.body.classList.toggle('sidebar-collapsed');
    sidebarBtn.setAttribute('aria-pressed', String(document.body.classList.contains('sidebar-collapsed')));
  });

  // ---- global shortcuts ----
  document.addEventListener('keydown', function (ev) {
    var mod = ev.metaKey || ev.ctrlKey;
    if (mod && ev.key.toLowerCase() === 's') { ev.preventDefault(); commit(); toast('Saved'); }
    if (mod && ev.key.toLowerCase() === 'p' && !ev.shiftKey) { ev.preventDefault(); slides.open(ta.value); }
    if (mod && ev.altKey && ev.key.toLowerCase() === 'n') { ev.preventDefault(); newDoc('Untitled document', '# Untitled document\n\n'); }
  });

  // ---- load-from-templates handoff (set by templates.html) ----
  function consumeTemplateHandoff() {
    try {
      var raw = sessionStorage.getItem('inkwell.loadTemplate');
      if (!raw) return;
      sessionStorage.removeItem('inkwell.loadTemplate');
      var tpl = JSON.parse(raw);
      newDoc(tpl.name || 'Untitled', tpl.body || '');
      var d = activeDoc(); if (d) { d.titleManual = false; commit(); }
      toast('Template loaded: ' + (tpl.name || ''));
    } catch (e) {}
  }

  // ---- boot ----
  applySettings();
  renderDocList();
  loadDoc(activeId);
  consumeTemplateHandoff();

  if (!Store.hasLocalStorage) {
    toast('Private mode: changes won\'t persist after closing');
  }
})();
