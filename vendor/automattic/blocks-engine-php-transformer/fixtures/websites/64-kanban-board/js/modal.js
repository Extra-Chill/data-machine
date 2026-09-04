/* =========================================================
   DRIFTLANE — Card detail modal
   Open a card to edit every field: title, description, labels,
   assignee, due date, priority, checklist (with live progress
   bar) and comment count. Commits back through onSave / onDelete.
   ========================================================= */
(function () {
  'use strict';
  const D = window.Driftlane;
  const data = D.data;

  let modalRoot, current, callbacks, lastFocused;

  function ensureRoot() {
    if (modalRoot) return modalRoot;
    modalRoot = document.createElement('div');
    modalRoot.className = 'modal-root';
    modalRoot.hidden = true;
    modalRoot.innerHTML =
      '<div class="modal-scrim" data-close></div>' +
      '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"></div>';
    document.body.appendChild(modalRoot);
    modalRoot.addEventListener('click', (e) => {
      if (e.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', (e) => {
      if (modalRoot.hidden) return;
      if (e.key === 'Escape') { e.stopPropagation(); close(); }
    });
    return modalRoot;
  }

  function open(card, colId, cbs) {
    ensureRoot();
    callbacks = cbs;
    lastFocused = document.activeElement;
    // deep clone so edits are atomic until "Save"
    current = JSON.parse(JSON.stringify(card));
    current._colId = colId;
    render();
    modalRoot.hidden = false;
    document.body.classList.add('modal-open');
    const firstInput = modalRoot.querySelector('.m-title-input');
    if (firstInput) firstInput.focus();
  }

  function close() {
    if (!modalRoot || modalRoot.hidden) return;
    modalRoot.hidden = true;
    document.body.classList.remove('modal-open');
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  function progress() {
    const cl = current.checklist || [];
    const done = cl.filter(t => t.done).length;
    return { done, total: cl.length, pct: cl.length ? Math.round(done / cl.length * 100) : 0 };
  }

  function render() {
    const m = modalRoot.querySelector('.modal');
    const p = data.person(current.assignee);

    const labelToggles = data.LABELS.map(l => {
      const on = current.labels.includes(l.id);
      return '<button class="m-label' + (on ? ' on' : '') + '" data-label="' + l.id + '" ' +
        'style="--lc:' + l.color + '" aria-pressed="' + on + '">' +
        '<span class="m-label-dot"></span>' + l.name + '</button>';
    }).join('');

    const assigneeOpts = ['<option value="">Unassigned</option>'].concat(
      data.PEOPLE.map(pp => '<option value="' + pp.id + '"' +
        (pp.id === current.assignee ? ' selected' : '') + '>' + pp.name + ' — ' + pp.role + '</option>')
    ).join('');

    const prog = progress();
    const checklistItems = (current.checklist || []).map(t =>
      '<li class="m-task' + (t.done ? ' done' : '') + '" data-task="' + t.id + '">' +
        '<button class="m-task-check" data-toggle="' + t.id + '" aria-label="Toggle ' + escAttr(t.text) + '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4 10-11"/></svg></button>' +
        '<input class="m-task-text" data-edit="' + t.id + '" value="' + escAttr(t.text) + '">' +
        '<button class="m-task-del" data-deltask="' + t.id + '" aria-label="Delete subtask">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
      '</li>'
    ).join('');

    m.innerHTML =
      '<header class="modal-head">' +
        '<input class="m-title-input" id="modalTitle" value="' + escAttr(current.title) + '" placeholder="Card title" aria-label="Card title">' +
        '<button class="modal-x" data-close aria-label="Close">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
      '</header>' +
      '<div class="modal-body">' +
        '<div class="m-main">' +
          '<label class="m-label-h">Description</label>' +
          '<textarea class="m-desc" rows="4" placeholder="Add a more detailed description…">' + escHtml(current.desc) + '</textarea>' +

          '<div class="m-checklist">' +
            '<div class="m-checklist-head">' +
              '<label class="m-label-h">Checklist</label>' +
              '<span class="m-checklist-stat">' + prog.done + '/' + prog.total + '</span>' +
            '</div>' +
            '<div class="m-progress"><div class="m-progress-fill" style="width:' + prog.pct + '%"></div></div>' +
            '<ul class="m-tasks">' + checklistItems + '</ul>' +
            '<form class="m-addtask"><input class="m-addtask-input" placeholder="Add a subtask…" aria-label="New subtask">' +
              '<button class="btn btn-ghost" type="submit">Add</button></form>' +
          '</div>' +
        '</div>' +

        '<aside class="m-side">' +
          '<div class="m-field"><label class="m-label-h">Assignee</label>' +
            '<div class="m-assignee-row"><span class="m-assignee-av"></span>' +
            '<select class="m-assignee">' + assigneeOpts + '</select></div></div>' +

          '<div class="m-field"><label class="m-label-h">Priority</label>' +
            '<div class="m-priority seg">' +
              ['low', 'medium', 'high', 'urgent'].map(pr =>
                '<button data-pri="' + pr + '" class="' + (current.priority === pr ? 'active' : '') +
                '">' + D.render.PRIORITY[pr].label + '</button>').join('') +
            '</div></div>' +

          '<div class="m-field"><label class="m-label-h">Due date</label>' +
            '<input type="date" class="m-due" value="' + (current.due || '') + '"></div>' +

          '<div class="m-field"><label class="m-label-h">Labels</label>' +
            '<div class="m-labels">' + labelToggles + '</div></div>' +

          '<div class="m-field"><label class="m-label-h">Comments</label>' +
            '<input type="number" class="m-comments" min="0" value="' + (current.comments || 0) + '"></div>' +
        '</aside>' +
      '</div>' +
      '<footer class="modal-foot">' +
        '<button class="btn btn-danger m-delete">Delete card</button>' +
        '<div class="spacer"></div>' +
        '<button class="btn btn-ghost" data-close>Cancel</button>' +
        '<button class="btn btn-primary m-save">Save changes</button>' +
      '</footer>';

    // assignee avatar
    const avHost = m.querySelector('.m-assignee-av');
    avHost.replaceWith(D.render.avatarEl(current.assignee, 30));

    wire(m);
  }

  function refreshProgress() {
    const m = modalRoot.querySelector('.modal');
    const prog = progress();
    m.querySelector('.m-progress-fill').style.width = prog.pct + '%';
    m.querySelector('.m-checklist-stat').textContent = prog.done + '/' + prog.total;
  }

  function wire(m) {
    m.querySelector('.m-title-input').addEventListener('input', e => current.title = e.target.value);
    m.querySelector('.m-desc').addEventListener('input', e => current.desc = e.target.value);
    m.querySelector('.m-due').addEventListener('input', e => current.due = e.target.value || null);
    m.querySelector('.m-comments').addEventListener('input', e => current.comments = Math.max(0, parseInt(e.target.value, 10) || 0));

    m.querySelector('.m-assignee').addEventListener('change', e => {
      current.assignee = e.target.value || null;
      const host = m.querySelector('.m-assignee-av') || m.querySelector('.avatar');
      const fresh = D.render.avatarEl(current.assignee, 30);
      fresh.className = (fresh.className + ' m-assignee-av').trim();
      host.replaceWith(fresh);
    });

    m.querySelectorAll('.m-priority button').forEach(b => b.addEventListener('click', () => {
      current.priority = b.dataset.pri;
      m.querySelectorAll('.m-priority button').forEach(x => x.classList.toggle('active', x === b));
    }));

    m.querySelectorAll('.m-label').forEach(b => b.addEventListener('click', () => {
      const id = b.dataset.label;
      const i = current.labels.indexOf(id);
      if (i >= 0) current.labels.splice(i, 1); else current.labels.push(id);
      const on = current.labels.includes(id);
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on);
    }));

    // checklist
    m.querySelectorAll('[data-toggle]').forEach(b => b.addEventListener('click', () => {
      const t = current.checklist.find(x => x.id === b.dataset.toggle);
      if (t) { t.done = !t.done; b.closest('.m-task').classList.toggle('done', t.done); refreshProgress(); }
    }));
    m.querySelectorAll('[data-edit]').forEach(inp => inp.addEventListener('input', () => {
      const t = current.checklist.find(x => x.id === inp.dataset.edit);
      if (t) t.text = inp.value;
    }));
    m.querySelectorAll('[data-deltask]').forEach(b => b.addEventListener('click', () => {
      current.checklist = current.checklist.filter(x => x.id !== b.dataset.deltask);
      render();
    }));
    m.querySelector('.m-addtask').addEventListener('submit', e => {
      e.preventDefault();
      const inp = e.target.querySelector('.m-addtask-input');
      const text = inp.value.trim();
      if (!text) return;
      current.checklist = current.checklist || [];
      current.checklist.push({ id: data.uid('t'), text, done: false });
      render();
      const fresh = modalRoot.querySelector('.m-addtask-input');
      if (fresh) fresh.focus();
    });

    m.querySelector('.m-save').addEventListener('click', () => {
      const out = JSON.parse(JSON.stringify(current));
      delete out._colId;
      callbacks.onSave && callbacks.onSave(out, current._colId);
      close();
    });
    m.querySelector('.m-delete').addEventListener('click', () => {
      if (confirm('Delete this card? This cannot be undone.')) {
        callbacks.onDelete && callbacks.onDelete(current.id, current._colId);
        close();
      }
    });
  }

  function escHtml(s) { return (s || '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
  function escAttr(s) { return (s || '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  D.modal = { open, close };
})();
