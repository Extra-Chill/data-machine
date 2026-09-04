/* =========================================================
   DRIFTLANE — Rendering
   Turns board state into DOM. No event wiring here beyond
   what render needs; interaction lives in board.js / dnd.js.
   ========================================================= */
(function () {
  'use strict';
  const D = window.Driftlane;
  const data = D.data;

  /* ---- generate an avatar as an inline SVG data-uri-ish element ---- */
  function avatarEl(personId, size) {
    size = size || 26;
    const p = data.person(personId);
    const el = document.createElement('span');
    el.className = 'avatar';
    el.style.width = el.style.height = size + 'px';
    el.style.fontSize = Math.round(size * 0.4) + 'px';
    if (p) {
      el.style.background = p.color;
      el.textContent = data.initials(p.name);
      el.title = p.name + ' · ' + p.role;
      el.setAttribute('aria-label', p.name);
    } else {
      el.classList.add('unassigned');
      el.textContent = '?';
      el.title = 'Unassigned';
      el.setAttribute('aria-label', 'Unassigned');
    }
    return el;
  }

  const PRIORITY = {
    low:    { label: 'Low',    cls: 'p-low' },
    medium: { label: 'Medium', cls: 'p-medium' },
    high:   { label: 'High',   cls: 'p-high' },
    urgent: { label: 'Urgent', cls: 'p-urgent' }
  };

  function dueMeta(due) {
    if (!due) return null;
    const d = new Date(due + 'T00:00:00');
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const diff = Math.round((d - today) / 86400000);
    let state = 'future', text;
    if (diff < 0) { state = 'overdue'; text = Math.abs(diff) + 'd late'; }
    else if (diff === 0) { state = 'today'; text = 'Today'; }
    else if (diff === 1) { state = 'soon'; text = 'Tomorrow'; }
    else if (diff <= 3) { state = 'soon'; text = diff + 'd'; }
    else {
      text = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }
    return { state, text };
  }

  function checklistProgress(card) {
    const cl = card.checklist || [];
    if (!cl.length) return null;
    const done = cl.filter(t => t.done).length;
    return { done, total: cl.length, pct: Math.round((done / cl.length) * 100) };
  }

  /* ---- a single card ---- */
  function cardEl(card, colId) {
    const el = document.createElement('article');
    el.className = 'card priority-' + card.priority;
    el.dataset.cardId = card.id;
    el.dataset.colId = colId;
    el.tabIndex = 0;
    el.setAttribute('role', 'listitem');
    el.setAttribute('aria-roledescription', 'Draggable card');

    // priority rail
    const rail = document.createElement('span');
    rail.className = 'card-rail ' + PRIORITY[card.priority].cls;
    el.appendChild(rail);

    // labels
    if (card.labels && card.labels.length) {
      const lr = document.createElement('div');
      lr.className = 'card-labels';
      card.labels.forEach(id => {
        const l = data.label(id); if (!l) return;
        const chip = document.createElement('span');
        chip.className = 'label-chip';
        chip.style.setProperty('--lc', l.color);
        chip.textContent = l.name;
        lr.appendChild(chip);
      });
      el.appendChild(lr);
    }

    // title
    const t = document.createElement('div');
    t.className = 'card-title';
    t.textContent = card.title;
    el.appendChild(t);

    // checklist progress bar
    const prog = checklistProgress(card);
    if (prog) {
      const wrap = document.createElement('div');
      wrap.className = 'card-progress' + (prog.pct === 100 ? ' complete' : '');
      wrap.innerHTML =
        '<div class="cp-track"><div class="cp-fill" style="width:' + prog.pct + '%"></div></div>' +
        '<span class="cp-num">' + prog.done + '/' + prog.total + '</span>';
      el.appendChild(wrap);
    }

    // footer meta
    const foot = document.createElement('div');
    foot.className = 'card-foot';
    const left = document.createElement('div');
    left.className = 'cf-left';

    const due = dueMeta(card.due);
    if (due) {
      const dEl = document.createElement('span');
      dEl.className = 'meta due due-' + due.state;
      dEl.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>' + due.text;
      left.appendChild(dEl);
    }
    if (card.comments) {
      const c = document.createElement('span');
      c.className = 'meta comments';
      c.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/></svg>' + card.comments;
      left.appendChild(c);
    }
    foot.appendChild(left);
    foot.appendChild(avatarEl(card.assignee, 24));
    el.appendChild(foot);

    return el;
  }

  /* ---- a column ---- */
  function columnEl(col, board, opts) {
    opts = opts || {};
    const el = document.createElement('section');
    el.className = 'column';
    el.dataset.colId = col.id;
    el.setAttribute('aria-roledescription', 'Board column');

    // header
    const head = document.createElement('div');
    head.className = 'col-head';
    head.dataset.colHandle = '1';

    const nameWrap = document.createElement('div');
    nameWrap.className = 'col-name-wrap';

    const dragGrip = document.createElement('span');
    dragGrip.className = 'col-grip';
    dragGrip.title = 'Drag to reorder column';
    dragGrip.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>';
    nameWrap.appendChild(dragGrip);

    const name = document.createElement('h2');
    name.className = 'col-name';
    name.textContent = col.name;
    name.tabIndex = 0;
    name.title = 'Click to rename';
    nameWrap.appendChild(name);

    const count = document.createElement('span');
    count.className = 'col-count';
    count.textContent = col.cards.length;
    nameWrap.appendChild(count);

    head.appendChild(nameWrap);

    // WIP indicator
    if (col.wip > 0) {
      const wip = document.createElement('span');
      const over = col.cards.length > col.wip;
      wip.className = 'col-wip' + (over ? ' over' : (col.cards.length === col.wip ? ' at' : ''));
      wip.textContent = 'WIP ' + col.cards.length + '/' + col.wip;
      wip.title = over ? 'Over WIP limit' : 'Work-in-progress limit';
      head.appendChild(wip);
    }

    // column menu
    const menu = document.createElement('button');
    menu.className = 'col-menu';
    menu.dataset.colMenu = col.id;
    menu.setAttribute('aria-label', 'Column actions');
    menu.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>';
    head.appendChild(menu);

    el.appendChild(head);

    // card list (drop zone)
    const list = document.createElement('div');
    list.className = 'col-cards';
    list.dataset.dropzone = col.id;
    list.setAttribute('role', 'list');
    list.setAttribute('aria-label', col.name + ' cards');

    col.cards.forEach(c => {
      if (opts.matches && !opts.matches(c)) return;
      list.appendChild(cardEl(c, col.id));
    });

    if (!list.children.length) {
      const empty = document.createElement('div');
      empty.className = 'col-empty';
      empty.textContent = opts.filtering ? 'No matching cards' : 'Drop cards here';
      list.appendChild(empty);
    }

    el.appendChild(list);

    // quick-add composer
    const composer = document.createElement('div');
    composer.className = 'composer';
    composer.dataset.colId = col.id;
    composer.innerHTML =
      '<button class="composer-add" data-add-card="' + col.id + '">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Add a card</button>' +
      '<div class="composer-form" hidden>' +
        '<textarea class="composer-input" rows="2" placeholder="Card title… (Enter to add, Esc to cancel)" aria-label="New card title"></textarea>' +
        '<div class="composer-actions">' +
          '<button class="btn btn-primary composer-submit">Add card</button>' +
          '<button class="btn btn-ghost composer-cancel" aria-label="Cancel">Cancel</button>' +
        '</div>' +
      '</div>';
    el.appendChild(composer);

    return el;
  }

  /* ---- full board ---- */
  function renderBoard(boardRoot, board, opts) {
    boardRoot.innerHTML = '';
    board.columns.forEach(col => {
      boardRoot.appendChild(columnEl(col, board, opts));
    });

    // "add column" trailing element
    const add = document.createElement('div');
    add.className = 'add-column';
    add.innerHTML =
      '<button class="add-column-btn" id="addColumnBtn">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Add column</button>' +
      '<div class="add-column-form" hidden>' +
        '<input class="add-column-input" placeholder="Column name…" aria-label="New column name">' +
        '<div class="composer-actions">' +
          '<button class="btn btn-primary add-column-submit">Add</button>' +
          '<button class="btn btn-ghost add-column-cancel">Cancel</button>' +
        '</div>' +
      '</div>';
    boardRoot.appendChild(add);
  }

  D.render = {
    avatarEl, cardEl, columnEl, renderBoard,
    dueMeta, checklistProgress, PRIORITY
  };
})();
