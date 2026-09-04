/* =========================================================
   BOUNDLESS — store.js
   The board document: an ordered list of shapes (z-order) plus
   the camera. Handles undo/redo via snapshot diffing, localStorage
   autosave, and JSON import/export.
   ========================================================= */
'use strict';

const Store = (() => {
  const KEY = 'boundless.board.v1';
  const MAX_HISTORY = 80;

  const state = {
    shapes: [],                 // z-order: last = top
    camera: { x: 0, y: 0, zoom: 1 },
    name: 'Untitled board',
  };

  const undoStack = [];
  const redoStack = [];
  const listeners = new Set();

  function emit(reason) { listeners.forEach(fn => fn(reason)); }
  function subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); }

  /* deep clone via structured copy (shapes are plain JSON) */
  function clone(v) { return JSON.parse(JSON.stringify(v)); }

  function snapshot() {
    return clone({ shapes: state.shapes, name: state.name });
  }
  function restore(snap) {
    state.shapes = clone(snap.shapes);
    state.name = snap.name;
  }

  /* ---- commit a mutation with undo support ----
     Pattern: capture before, mutate via fn, then commit() to push history.
     We snapshot lazily so drags can batch into one history entry. */
  let pending = null;
  function begin() {
    if (!pending) pending = snapshot();
  }
  function commit(reason = 'edit') {
    if (!pending) pending = snapshot();
    // skip no-op commits
    const now = JSON.stringify({ shapes: state.shapes, name: state.name });
    if (JSON.stringify(pending) === now) { pending = null; return; }
    undoStack.push(pending);
    if (undoStack.length > MAX_HISTORY) undoStack.shift();
    redoStack.length = 0;
    pending = null;
    save();
    emit(reason);
  }
  /* convenience: run a mutation and commit it atomically */
  function transact(fn, reason) {
    begin();
    fn();
    commit(reason);
  }

  function canUndo() { return undoStack.length > 0; }
  function canRedo() { return redoStack.length > 0; }

  function undo() {
    if (!undoStack.length) return false;
    redoStack.push(snapshot());
    restore(undoStack.pop());
    save(); emit('undo'); return true;
  }
  function redo() {
    if (!redoStack.length) return false;
    undoStack.push(snapshot());
    restore(redoStack.pop());
    save(); emit('redo'); return true;
  }

  /* ---- shape access ---- */
  function shapes() { return state.shapes; }
  function byId(id) { return state.shapes.find(s => s.id === id); }
  function indexOf(id) { return state.shapes.findIndex(s => s.id === id); }

  function add(shape) { state.shapes.push(shape); }
  function addMany(arr) { arr.forEach(s => state.shapes.push(s)); }
  function remove(id) {
    const i = indexOf(id);
    if (i >= 0) state.shapes.splice(i, 1);
    // also drop connectors that dangle to a deleted shape
    state.shapes = state.shapes.filter(s =>
      !(s.type === 'connector' &&
        ((s.from && !byId(s.from)) || (s.to && !byId(s.to)))));
  }

  /* z-order ops */
  function bringForward(id) {
    const i = indexOf(id);
    if (i < 0 || i === state.shapes.length - 1) return;
    [state.shapes[i], state.shapes[i + 1]] = [state.shapes[i + 1], state.shapes[i]];
  }
  function sendBackward(id) {
    const i = indexOf(id);
    if (i <= 0) return;
    [state.shapes[i], state.shapes[i - 1]] = [state.shapes[i - 1], state.shapes[i]];
  }
  function bringToFront(id) {
    const i = indexOf(id);
    if (i < 0) return;
    const [s] = state.shapes.splice(i, 1);
    state.shapes.push(s);
  }
  function sendToBack(id) {
    const i = indexOf(id);
    if (i < 0) return;
    const [s] = state.shapes.splice(i, 1);
    state.shapes.unshift(s);
  }

  /* ---- persistence ---- */
  let saveTimer = null;
  function save() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try {
        localStorage.setItem(KEY, JSON.stringify({
          shapes: state.shapes,
          camera: state.camera,
          name: state.name,
          savedAt: Date.now(),
        }));
      } catch (e) { /* quota / private mode — non-fatal */ }
    }, 250);
  }
  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      if (!data || !Array.isArray(data.shapes)) return false;
      state.shapes = data.shapes;
      state.camera = data.camera || { x: 0, y: 0, zoom: 1 };
      state.name = data.name || 'Untitled board';
      return true;
    } catch (e) { return false; }
  }

  /* replace the whole document (templates / import / new board) */
  function setDocument(doc, { history = true } = {}) {
    if (history) begin();
    state.shapes = clone(doc.shapes || []);
    if (doc.name) state.name = doc.name;
    if (history) commit('load');
    else { save(); emit('load'); }
  }
  function setName(name) {
    transact(() => { state.name = name; }, 'rename');
  }

  function exportJSON() {
    return JSON.stringify({
      app: 'Boundless', version: 1,
      name: state.name,
      shapes: state.shapes,
      camera: state.camera,
      exportedAt: new Date().toISOString(),
    }, null, 2);
  }
  function importJSON(text) {
    const data = JSON.parse(text);
    if (!data || !Array.isArray(data.shapes)) throw new Error('Not a Boundless board file.');
    setDocument({ shapes: data.shapes, name: data.name || 'Imported board' });
    if (data.camera) state.camera = data.camera;
    return true;
  }

  return {
    state, subscribe, emit,
    begin, commit, transact,
    undo, redo, canUndo, canRedo,
    shapes, byId, indexOf, add, addMany, remove,
    bringForward, sendBackward, bringToFront, sendToBack,
    save, load, setDocument, setName,
    exportJSON, importJSON, clone,
  };
})();

if (typeof window !== 'undefined') window.Store = Store;
