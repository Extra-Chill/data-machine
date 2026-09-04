/* =========================================================
   DRIFTLANE — Board controller
   Owns the board state, wires DnD, modal, composer, columns,
   search/filter, activity log, stats, persistence & I/O.
   ========================================================= */
(function () {
  'use strict';
  const D = window.Driftlane;
  const data = D.data;

  const boardRoot = document.getElementById('board');
  if (!boardRoot) return;

  /* ---- state ---- */
  let board = data.load() || initialBoard();
  let activity = (data.loadPrefs().activity) || [];
  const filters = { text: '', label: null, assignee: null, priority: null, mine: false };
  const ME = 'u-amara'; // "my cards" identity

  function initialBoard() {
    const b = data.seedBoard();
    return b;
  }

  /* ---- persistence ---- */
  let saveTimer = null;
  function persist() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      data.save(board);
      const prefs = data.loadPrefs();
      prefs.activity = activity.slice(0, 40);
      data.savePrefs(prefs);
    }, 180);
  }

  function logActivity(text) {
    activity.unshift({ text, t: Date.now() });
    activity = activity.slice(0, 40);
    renderActivity();
  }

  /* ---- lookups ---- */
  function findCard(id) {
    for (const col of board.columns) {
      const i = col.cards.findIndex(c => c.id === id);
      if (i >= 0) return { col, card: col.cards[i], index: i };
    }
    return null;
  }
  const findCol = (id) => board.columns.find(c => c.id === id);

  /* ---- filtering ---- */
  function matches(card) {
    const f = filters;
    if (f.mine && card.assignee !== ME) return false;
    if (f.label && !(card.labels || []).includes(f.label)) return false;
    if (f.assignee && card.assignee !== f.assignee) return false;
    if (f.priority && card.priority !== f.priority) return false;
    if (f.text) {
      const hay = (card.title + ' ' + card.desc + ' ' +
        (card.labels || []).map(l => (data.label(l) || {}).name || '').join(' ')).toLowerCase();
      if (!hay.includes(f.text.toLowerCase())) return false;
    }
    return true;
  }
  const filtering = () => filters.text || filters.label || filters.assignee || filters.priority || filters.mine;

  /* ---- render ---- */
  function render() {
    D.render.renderBoard(boardRoot, board, { matches, filtering: filtering() });
    renderStats();
    document.getElementById('boardName').textContent = board.name;
  }

  /* ============ DnD handlers ============ */
  D.dnd.init(boardRoot, {
    moveCard(cardId, fromColId, toColId, toIndex) {
      const found = findCard(cardId);
      if (!found) return render();
      const fromCol = found.col;
      const toCol = findCol(toColId) || fromCol;
      // remove from source
      fromCol.cards.splice(found.index, 1);
      // clamp index
      toIndex = Math.max(0, Math.min(toIndex, toCol.cards.length));
      toCol.cards.splice(toIndex, 0, found.card);
      if (fromCol !== toCol)
        logActivity('moved “' + trunc(found.card.title) + '” to ' + toCol.name);
      else
        logActivity('reordered “' + trunc(found.card.title) + '” in ' + toCol.name);
      persist();
      render();
    },
    moveColumn(colId, toIndex) {
      const i = board.columns.findIndex(c => c.id === colId);
      if (i < 0) return render();
      const [col] = board.columns.splice(i, 1);
      toIndex = Math.max(0, Math.min(toIndex, board.columns.length));
      board.columns.splice(toIndex, 0, col);
      logActivity('reordered column “' + col.name + '”');
      persist();
      render();
    },
    refresh() { render(); }
  });

  /* ============ click / interaction delegation ============ */
  boardRoot.addEventListener('click', (e) => {
    // open card detail (but not while a drag just happened)
    const cardNode = e.target.closest('.card');
    if (cardNode && !document.body.classList.contains('is-dragging')) {
      const f = findCard(cardNode.dataset.cardId);
      if (f) openCard(f.card, f.col.id);
      return;
    }

    // quick-add composer
    const addBtn = e.target.closest('[data-add-card]');
    if (addBtn) return showComposer(addBtn.dataset.addCard);
    if (e.target.closest('.composer-cancel')) return hideComposer(e.target.closest('.composer'));
    if (e.target.closest('.composer-submit')) {
      return submitComposer(e.target.closest('.composer'));
    }

    // column menu
    const menuBtn = e.target.closest('[data-col-menu]');
    if (menuBtn) return openColMenu(menuBtn);

    // add column
    if (e.target.closest('#addColumnBtn')) return showAddColumn();
    if (e.target.closest('.add-column-cancel')) return hideAddColumn();
    if (e.target.closest('.add-column-submit')) return submitAddColumn();
  });

  // rename column inline
  boardRoot.addEventListener('dblclick', (e) => {
    const name = e.target.closest('.col-name');
    if (name) startRename(name);
  });
  boardRoot.addEventListener('keydown', (e) => {
    const name = e.target.closest('.col-name');
    if (name && (e.key === 'Enter' || e.key === 'F2')) { e.preventDefault(); startRename(name); }
  });

  function openCard(card, colId) {
    D.modal.open(card, colId, {
      onSave(updated, cId) {
        const f = findCard(updated.id);
        if (!f) return;
        Object.assign(f.card, updated);
        logActivity('edited “' + trunc(updated.title) + '”');
        persist(); render();
      },
      onDelete(id) {
        const f = findCard(id);
        if (!f) return;
        const title = f.card.title;
        f.col.cards.splice(f.index, 1);
        logActivity('deleted “' + trunc(title) + '”');
        persist(); render();
      }
    });
  }

  /* ---- composer ---- */
  function showComposer(colId) {
    boardRoot.querySelectorAll('.composer').forEach(c => hideComposer(c));
    const comp = boardRoot.querySelector('.composer[data-col-id="' + colId + '"]');
    if (!comp) return;
    comp.querySelector('.composer-add').hidden = true;
    const form = comp.querySelector('.composer-form');
    form.hidden = false;
    const ta = comp.querySelector('.composer-input');
    ta.value = '';
    ta.focus();
    ta.onkeydown = (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitComposer(comp); }
      else if (e.key === 'Escape') { hideComposer(comp); }
    };
    const col = boardRoot.querySelector('.column[data-col-id="' + colId + '"] .col-cards');
    if (col) col.scrollTop = col.scrollHeight;
  }
  function hideComposer(comp) {
    if (!comp) return;
    comp.querySelector('.composer-add').hidden = false;
    comp.querySelector('.composer-form').hidden = true;
  }
  function submitComposer(comp) {
    const colId = comp.dataset.colId;
    const ta = comp.querySelector('.composer-input');
    const title = ta.value.trim();
    if (!title) { ta.focus(); return; }
    const col = findCol(colId);
    const c = data.card({ title, priority: 'medium' });
    col.cards.push(c);
    logActivity('added “' + trunc(title) + '” to ' + col.name);
    persist();
    render();
    // reopen composer in same column for fast entry
    showComposer(colId);
  }

  /* ---- column menu (lightweight popover) ---- */
  let popover = null;
  function closePopover() { if (popover) { popover.remove(); popover = null; } }
  document.addEventListener('click', (e) => {
    if (popover && !e.target.closest('.col-pop') && !e.target.closest('[data-col-menu]')) closePopover();
  });

  function openColMenu(btn) {
    closePopover();
    const colId = btn.dataset.colMenu;
    const col = findCol(colId);
    const r = btn.getBoundingClientRect();
    popover = document.createElement('div');
    popover.className = 'col-pop';
    popover.style.top = (r.bottom + 6) + 'px';
    popover.style.left = Math.min(r.left, window.innerWidth - 220) + 'px';
    popover.innerHTML =
      '<button data-act="rename"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>Rename</button>' +
      '<div class="pop-wip"><label>WIP limit</label>' +
        '<input type="number" min="0" value="' + (col.wip || 0) + '" class="pop-wip-input"></div>' +
      '<button data-act="clear"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>Clear cards</button>' +
      '<button data-act="delete" class="danger"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>Delete column</button>';
    document.body.appendChild(popover);

    popover.querySelector('.pop-wip-input').addEventListener('change', (e) => {
      col.wip = Math.max(0, parseInt(e.target.value, 10) || 0);
      persist(); render();
    });
    popover.addEventListener('click', (e) => {
      const act = e.target.closest('[data-act]');
      if (!act) return;
      const a = act.dataset.act;
      if (a === 'rename') {
        closePopover();
        const nameEl = boardRoot.querySelector('.column[data-col-id="' + colId + '"] .col-name');
        if (nameEl) startRename(nameEl);
      } else if (a === 'clear') {
        if (col.cards.length && confirm('Remove all ' + col.cards.length + ' cards from “' + col.name + '”?')) {
          col.cards = []; logActivity('cleared column “' + col.name + '”'); persist(); render();
        }
        closePopover();
      } else if (a === 'delete') {
        if (board.columns.length <= 1) { alert('A board needs at least one column.'); return; }
        if (confirm('Delete column “' + col.name + '” and its ' + col.cards.length + ' cards?')) {
          board.columns = board.columns.filter(c => c.id !== colId);
          logActivity('deleted column “' + col.name + '”'); persist(); render();
        }
        closePopover();
      }
    });
  }

  /* ---- rename column ---- */
  function startRename(nameEl) {
    const colNode = nameEl.closest('.column');
    const colId = colNode.dataset.colId;
    const col = findCol(colId);
    const input = document.createElement('input');
    input.className = 'col-name-edit';
    input.value = col.name;
    nameEl.replaceWith(input);
    input.focus(); input.select();
    const commit = (save) => {
      if (save) {
        const v = input.value.trim();
        if (v && v !== col.name) { col.name = v; logActivity('renamed column to “' + v + '”'); persist(); }
      }
      render();
    };
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') commit(true);
      else if (e.key === 'Escape') commit(false);
    });
    input.addEventListener('blur', () => commit(true));
  }

  /* ---- add column ---- */
  function showAddColumn() {
    const wrap = boardRoot.querySelector('.add-column');
    wrap.querySelector('.add-column-btn').hidden = true;
    const form = wrap.querySelector('.add-column-form');
    form.hidden = false;
    const inp = form.querySelector('.add-column-input');
    inp.value = ''; inp.focus();
    inp.onkeydown = (e) => {
      if (e.key === 'Enter') submitAddColumn();
      else if (e.key === 'Escape') hideAddColumn();
    };
    boardRoot.scrollLeft = boardRoot.scrollWidth;
    const sc = boardRoot.parentElement;
    if (sc) sc.scrollLeft = sc.scrollWidth;
  }
  function hideAddColumn() {
    const wrap = boardRoot.querySelector('.add-column');
    if (!wrap) return;
    wrap.querySelector('.add-column-btn').hidden = false;
    wrap.querySelector('.add-column-form').hidden = true;
  }
  function submitAddColumn() {
    const inp = boardRoot.querySelector('.add-column-input');
    const name = inp.value.trim();
    if (!name) { inp.focus(); return; }
    board.columns.push({ id: data.uid('col'), name, wip: 0, cards: [] });
    logActivity('added column “' + name + '”');
    persist(); render();
  }

  /* ============ keyboard card movement ============ */
  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea, select')) return;
    if (!document.getElementById('board')) return;
    const focused = document.activeElement;
    if (!focused || !focused.classList || !focused.classList.contains('card')) return;
    const dir = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down' }[e.key];
    if (!dir) return;
    e.preventDefault();
    moveFocusedCard(focused.dataset.cardId, dir, e.shiftKey);
  });

  function moveFocusedCard(cardId, dir, withShift) {
    const f = findCard(cardId);
    if (!f) return;
    const colIdx = board.columns.indexOf(f.col);

    if (dir === 'up' || dir === 'down') {
      const ni = dir === 'up' ? f.index - 1 : f.index + 1;
      if (ni < 0 || ni >= f.col.cards.length) return;
      f.col.cards.splice(f.index, 1);
      f.col.cards.splice(ni, 0, f.card);
      logActivity('moved “' + trunc(f.card.title) + '” ' + dir + ' in ' + f.col.name);
    } else {
      const ncolIdx = dir === 'left' ? colIdx - 1 : colIdx + 1;
      if (ncolIdx < 0 || ncolIdx >= board.columns.length) return;
      const target = board.columns[ncolIdx];
      f.col.cards.splice(f.index, 1);
      const ti = Math.min(f.index, target.cards.length);
      target.cards.splice(ti, 0, f.card);
      logActivity('moved “' + trunc(f.card.title) + '” to ' + target.name);
    }
    persist();
    render();
    // restore focus to the moved card
    const again = boardRoot.querySelector('.card[data-card-id="' + cardId + '"]');
    if (again) again.focus();
  }

  /* ============ stats + activity ============ */
  function renderStats() {
    const host = document.getElementById('statBars');
    if (!host) return;
    const total = board.columns.reduce((s, c) => s + c.cards.length, 0);
    const max = Math.max(1, ...board.columns.map(c => c.cards.length));
    host.innerHTML = board.columns.map(c => {
      const pct = Math.round((c.cards.length / max) * 100);
      return '<div class="stat-row"><span class="stat-label" title="' + escAttr(c.name) + '">' + escHtml(c.name) + '</span>' +
        '<span class="stat-track"><span class="stat-fill" style="width:' + pct + '%"></span></span>' +
        '<span class="stat-val">' + c.cards.length + '</span></div>';
    }).join('');

    const doneCol = board.columns.find(c => /done|published|complete/i.test(c.name)) || board.columns[board.columns.length - 1];
    const donePct = total ? Math.round((doneCol.cards.length / total) * 100) : 0;
    const dEl = document.getElementById('statDone');
    if (dEl) {
      dEl.querySelector('.donut-num').textContent = donePct + '%';
      const ring = dEl.querySelector('.donut-ring');
      const circ = 2 * Math.PI * 26;
      ring.style.strokeDasharray = circ;
      ring.style.strokeDashoffset = circ * (1 - donePct / 100);
    }
    const tc = document.getElementById('statTotal');
    if (tc) tc.textContent = total;
  }

  function renderActivity() {
    const host = document.getElementById('activityList');
    if (!host) return;
    if (!activity.length) { host.innerHTML = '<li class="act-empty">No recent activity</li>'; return; }
    host.innerHTML = activity.slice(0, 12).map(a =>
      '<li class="act-item"><span class="act-dot"></span><span class="act-text">' + escHtml(a.text) +
      '</span><time>' + ago(a.t) + '</time></li>').join('');
  }
  function ago(t) {
    const s = Math.round((Date.now() - t) / 1000);
    if (s < 60) return 'now';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h';
    return Math.floor(s / 86400) + 'd';
  }

  /* ============ toolbar: search + filters ============ */
  function buildFilterUI() {
    // assignee filter
    const aSel = document.getElementById('filterAssignee');
    if (aSel) {
      aSel.innerHTML = '<option value="">All assignees</option>' +
        data.PEOPLE.map(p => '<option value="' + p.id + '">' + p.name + '</option>').join('');
      aSel.addEventListener('change', () => { filters.assignee = aSel.value || null; render(); });
    }
    const lSel = document.getElementById('filterLabel');
    if (lSel) {
      lSel.innerHTML = '<option value="">All labels</option>' +
        data.LABELS.map(l => '<option value="' + l.id + '">' + l.name + '</option>').join('');
      lSel.addEventListener('change', () => { filters.label = lSel.value || null; render(); });
    }
    const pSel = document.getElementById('filterPriority');
    if (pSel) pSel.addEventListener('change', () => { filters.priority = pSel.value || null; render(); });

    const search = document.getElementById('searchInput');
    if (search) search.addEventListener('input', () => { filters.text = search.value.trim(); render(); });

    const mine = document.getElementById('mineToggle');
    if (mine) mine.addEventListener('click', () => {
      filters.mine = !filters.mine;
      mine.classList.toggle('active', filters.mine);
      mine.setAttribute('aria-pressed', filters.mine);
      render();
    });
  }

  /* ============ board I/O ============ */
  function wireBoardActions() {
    const exportBtn = document.getElementById('exportBtn');
    if (exportBtn) exportBtn.addEventListener('click', exportJSON);

    const importBtn = document.getElementById('importBtn');
    const importFile = document.getElementById('importFile');
    if (importBtn && importFile) {
      importBtn.addEventListener('click', () => importFile.click());
      importFile.addEventListener('change', () => {
        const file = importFile.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
          try {
            const b = JSON.parse(reader.result);
            if (!b.columns || !Array.isArray(b.columns)) throw new Error('bad');
            board = b; logActivity('imported board “' + (b.name || 'Untitled') + '”');
            persist(); render();
          } catch (err) { alert('That file is not a valid Driftlane board.'); }
          importFile.value = '';
        };
        reader.readAsText(file);
      });
    }

    const resetBtn = document.getElementById('resetBtn');
    if (resetBtn) resetBtn.addEventListener('click', () => {
      if (confirm('Reset to the sample Atlas Mobile sprint board? Your current board will be replaced.')) {
        board = data.seedBoard();
        activity = [];
        logActivity('reset to sample board');
        persist(); render();
      }
    });

    // rename board
    const nameEl = document.getElementById('boardName');
    if (nameEl) {
      nameEl.addEventListener('dblclick', () => {
        const v = prompt('Rename board', board.name);
        if (v && v.trim()) { board.name = v.trim(); persist(); render(); }
      });
    }

    // load template from a query string (?template=sprint) — used by templates.html
    const params = new URLSearchParams(location.search);
    const tpl = params.get('template');
    if (tpl && data.TEMPLATES[tpl]) {
      board = data.TEMPLATES[tpl].build();
      activity = [];
      logActivity('loaded template “' + data.TEMPLATES[tpl].name + '”');
      persist();
      history.replaceState(null, '', location.pathname);
    }
  }

  function exportJSON() {
    const blob = new Blob([JSON.stringify(board, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (board.name || 'driftlane-board').replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    logActivity('exported board JSON');
  }

  /* ---- utils ---- */
  function trunc(s, n) { n = n || 32; return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function escHtml(s) { return (s || '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
  function escAttr(s) { return (s || '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  /* ============ boot ============ */
  buildFilterUI();
  wireBoardActions();
  render();
  renderActivity();

  // expose for debugging
  D.board = { get: () => board, render };
})();
