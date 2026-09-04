/* =========================================================
   DRIFTLANE — Drag & Drop (pointer events, hand-written)
   - Card drag: lift a clone "ghost" that follows the pointer,
     show a live insertion placeholder, drop to reorder within
     a column or move across columns.
   - Column drag: grab the column grip to reorder columns.
   Touch + mouse + pen via Pointer Events. Respects
   prefers-reduced-motion. Commits via callbacks into board.js
   ========================================================= */
(function () {
  'use strict';
  const D = window.Driftlane;

  const REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function init(root, handlers) {
    // handlers: { moveCard(cardId, fromCol, toCol, toIndex),
    //             moveColumn(colId, toIndex) }
    let drag = null;          // active drag session

    // After a real drag, swallow the synthetic click so the card
    // modal doesn't pop open on drop.
    let suppressClickUntil = 0;
    root.addEventListener('click', (e) => {
      if (Date.now() < suppressClickUntil) {
        e.stopPropagation();
        e.preventDefault();
        suppressClickUntil = 0;
      }
    }, true);

    root.addEventListener('pointerdown', onDown);

    function onDown(e) {
      if (e.button !== undefined && e.button !== 0) return;

      const grip = e.target.closest('.col-grip, .col-head');
      const cardNode = e.target.closest('.card');

      // Column drag takes priority when starting on the grip/head
      if (grip && !e.target.closest('.col-menu, .col-name, .col-count, .col-wip')) {
        const colNode = grip.closest('.column');
        if (colNode) return startColumn(e, colNode);
      }

      if (cardNode) {
        // don't start a drag from inside an interactive element
        return startCard(e, cardNode);
      }
    }

    /* ───────────────── CARD DRAG ───────────────── */
    function startCard(e, cardNode) {
      const startX = e.clientX, startY = e.clientY;
      const rect = cardNode.getBoundingClientRect();
      const offX = startX - rect.left;
      const offY = startY - rect.top;
      let started = false;
      const pointerId = e.pointerId;

      function move(ev) {
        if (!started) {
          if (Math.abs(ev.clientX - startX) < 5 && Math.abs(ev.clientY - startY) < 5) return;
          beginCard();
        }
        positionGhost(ev.clientX, ev.clientY);
        updateCardPlaceholder(ev.clientX, ev.clientY);
        autoScroll(ev.clientX, ev.clientY);
      }

      function up() {
        cleanup();
        if (!started) return;
        suppressClickUntil = Date.now() + 350;
        commitCard();
        endGhost();
      }

      function cancel() {
        cleanup();
        if (started) { restoreCard(); endGhost(); }
      }

      function cleanup() {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
        try { cardNode.releasePointerCapture(pointerId); } catch (_) {}
      }

      function beginCard() {
        started = true;
        document.body.classList.add('is-dragging');
        const w = rect.width, h = rect.height;

        drag = {
          type: 'card',
          node: cardNode,
          cardId: cardNode.dataset.cardId,
          fromCol: cardNode.dataset.colId,
          offX, offY, w, h,
          ghost: makeGhost(cardNode, w, h),
          placeholder: makePlaceholder(h)
        };

        // placeholder sits where the card was
        cardNode.parentNode.insertBefore(drag.placeholder, cardNode);
        cardNode.classList.add('card-source-hidden');
        positionGhost(startX, startY);
      }

      try { cardNode.setPointerCapture(pointerId); } catch (_) {}
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
      window.addEventListener('pointercancel', cancel);
    }

    function makeGhost(node, w, h) {
      const g = node.cloneNode(true);
      g.classList.add('drag-ghost');
      g.classList.remove('card-source-hidden');
      g.style.width = w + 'px';
      g.style.height = h + 'px';
      g.removeAttribute('tabindex');
      document.body.appendChild(g);
      return g;
    }

    function makePlaceholder(h) {
      const p = document.createElement('div');
      p.className = 'drop-placeholder';
      p.style.height = h + 'px';
      return p;
    }

    function positionGhost(x, y) {
      if (!drag || !drag.ghost) return;
      drag.ghost.style.transform =
        'translate(' + (x - drag.offX) + 'px,' + (y - drag.offY) + 'px) rotate(2.5deg)';
    }

    function updateCardPlaceholder(x, y) {
      if (!drag) return;
      // find the column under the pointer
      const colNode = elementColumnAt(x, y);
      if (!colNode) return;
      const list = colNode.querySelector('.col-cards');
      const empty = list.querySelector('.col-empty');
      if (empty) empty.remove();

      const cards = [...list.querySelectorAll('.card:not(.card-source-hidden)')];
      let ref = null;
      for (const c of cards) {
        const r = c.getBoundingClientRect();
        if (y < r.top + r.height / 2) { ref = c; break; }
      }
      if (ref) list.insertBefore(drag.placeholder, ref);
      else list.appendChild(drag.placeholder);
    }

    function commitCard() {
      const ph = drag.placeholder;
      const list = ph.parentNode;
      const toCol = list ? list.dataset.dropzone : drag.fromCol;
      // Index among the *model* cards after the source is removed.
      // Exclude the hidden source card so reordering within the same
      // column past the original slot doesn't go off-by-one.
      const siblings = [...list.children].filter(n =>
        (n.classList.contains('card') && !n.classList.contains('card-source-hidden')) || n === ph);
      const toIndex = siblings.indexOf(ph);
      handlers.moveCard(drag.cardId, drag.fromCol, toCol, toIndex);
    }

    function restoreCard() {
      // board re-render will fix everything; just trigger a no-op refresh
      handlers.refresh && handlers.refresh();
    }

    function endGhost() {
      if (!drag) return;
      const g = drag.ghost, ph = drag.placeholder;
      drag.node && drag.node.classList.remove('card-source-hidden');
      if (ph && ph.parentNode) ph.remove();
      if (g) {
        if (REDUCE) { g.remove(); }
        else {
          g.style.transition = 'transform .16s ease, opacity .16s ease';
          g.style.opacity = '0';
          setTimeout(() => g.remove(), 170);
        }
      }
      document.body.classList.remove('is-dragging');
      drag = null;
    }

    /* ───────────────── COLUMN DRAG ───────────────── */
    function startColumn(e, colNode) {
      const startX = e.clientX, startY = e.clientY;
      const rect = colNode.getBoundingClientRect();
      const offX = startX - rect.left;
      let started = false;
      const pointerId = e.pointerId;

      function move(ev) {
        if (!started) {
          if (Math.abs(ev.clientX - startX) < 6 && Math.abs(ev.clientY - startY) < 6) return;
          begin();
        }
        if (!drag) return;
        drag.ghost.style.transform =
          'translate(' + (ev.clientX - offX) + 'px,' + rect.top + 'px) rotate(1.2deg)';
        updateColumnPlaceholder(ev.clientX);
        autoScroll(ev.clientX, ev.clientY);
      }
      function up() { cleanup(); if (!started) return; suppressClickUntil = Date.now() + 350; commitColumn(); endColGhost(); }
      function cancel() { cleanup(); if (started) { handlers.refresh && handlers.refresh(); endColGhost(); } }
      function cleanup() {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
        try { colNode.releasePointerCapture(pointerId); } catch (_) {}
      }
      function begin() {
        started = true;
        document.body.classList.add('is-dragging', 'is-dragging-col');
        const g = colNode.cloneNode(true);
        g.classList.add('col-ghost');
        g.style.width = rect.width + 'px';
        g.style.height = rect.height + 'px';
        document.body.appendChild(g);
        const ph = document.createElement('div');
        ph.className = 'col-placeholder';
        ph.style.width = rect.width + 'px';
        colNode.parentNode.insertBefore(ph, colNode);
        colNode.classList.add('col-source-hidden');
        drag = { type: 'column', node: colNode, colId: colNode.dataset.colId, ghost: g, placeholder: ph, w: rect.width };
        g.style.transform = 'translate(' + (startX - offX) + 'px,' + rect.top + 'px)';
      }

      try { colNode.setPointerCapture(pointerId); } catch (_) {}
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
      window.addEventListener('pointercancel', cancel);
    }

    function updateColumnPlaceholder(x) {
      if (!drag) return;
      const cols = [...root.querySelectorAll('.column:not(.col-source-hidden)')];
      let ref = null;
      for (const c of cols) {
        const r = c.getBoundingClientRect();
        if (x < r.left + r.width / 2) { ref = c; break; }
      }
      const addCol = root.querySelector('.add-column');
      if (ref) root.insertBefore(drag.placeholder, ref);
      else root.insertBefore(drag.placeholder, addCol || null);
    }

    function commitColumn() {
      const ph = drag.placeholder;
      const cols = [...root.children].filter(n =>
        (n.classList.contains('column') && !n.classList.contains('col-source-hidden')) || n === ph);
      const toIndex = cols.indexOf(ph);
      handlers.moveColumn(drag.colId, toIndex);
    }

    function endColGhost() {
      if (!drag) return;
      drag.node && drag.node.classList.remove('col-source-hidden');
      if (drag.placeholder && drag.placeholder.parentNode) drag.placeholder.remove();
      if (drag.ghost) drag.ghost.remove();
      document.body.classList.remove('is-dragging', 'is-dragging-col');
      drag = null;
    }

    /* ───────────────── helpers ───────────────── */
    function elementColumnAt(x, y) {
      // temporarily hide ghost so elementFromPoint doesn't hit it
      let prev;
      if (drag && drag.ghost) { prev = drag.ghost.style.pointerEvents; drag.ghost.style.pointerEvents = 'none'; }
      const el = document.elementFromPoint(x, y);
      if (drag && drag.ghost) drag.ghost.style.pointerEvents = prev;
      return el ? el.closest('.column') : null;
    }

    let scrollRAF = null;
    function autoScroll(x, y) {
      const scroller = root.parentElement; // .board-scroll
      if (!scroller) return;
      const r = scroller.getBoundingClientRect();
      const edge = 70;
      let dx = 0;
      if (x < r.left + edge) dx = -((r.left + edge - x) / edge) * 18;
      else if (x > r.right - edge) dx = ((x - (r.right - edge)) / edge) * 18;
      cancelAnimationFrame(scrollRAF);
      if (dx !== 0) {
        const step = () => {
          scroller.scrollLeft += dx;
          scrollRAF = requestAnimationFrame(step);
        };
        scrollRAF = requestAnimationFrame(step);
      }
    }
  }

  D.dnd = { init };
})();
