/* ============================================================
   Inkwell — localStorage persistence layer
   Documents + settings. Resilient to private-mode / quota.
   ============================================================ */
(function (global) {
  'use strict';

  var DOCS_KEY = 'inkwell.docs.v1';
  var SETTINGS_KEY = 'inkwell.settings.v1';
  var ACTIVE_KEY = 'inkwell.active.v1';

  var mem = {}; // in-memory fallback if localStorage is unavailable
  var hasLS = (function () {
    try {
      var t = '__inkwell_test__';
      global.localStorage.setItem(t, '1');
      global.localStorage.removeItem(t);
      return true;
    } catch (e) { return false; }
  })();

  function read(key, fallback) {
    try {
      var raw = hasLS ? global.localStorage.getItem(key) : mem[key];
      return raw == null ? fallback : JSON.parse(raw);
    } catch (e) { return fallback; }
  }
  function write(key, value) {
    var raw = JSON.stringify(value);
    try {
      if (hasLS) global.localStorage.setItem(key, raw);
      else mem[key] = raw;
    } catch (e) { mem[key] = raw; }
  }

  function uid() {
    return 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  }

  /* ---- Documents ---- */
  function loadDocs() {
    var docs = read(DOCS_KEY, null);
    if (!docs || !docs.length) {
      // seed first run
      var seed = {
        id: uid(),
        title: 'Welcome to Inkwell',
        body: (global.InkwellSeed && global.InkwellSeed.WELCOME) || '# Untitled\n',
        created: Date.now(),
        updated: Date.now()
      };
      docs = [seed];
      write(DOCS_KEY, docs);
      write(ACTIVE_KEY, seed.id);
    }
    return docs;
  }
  function saveDocs(docs) { write(DOCS_KEY, docs); }

  function getActiveId() { return read(ACTIVE_KEY, null); }
  function setActiveId(id) { write(ACTIVE_KEY, id); }

  function createDoc(title, body) {
    var d = {
      id: uid(),
      title: title || 'Untitled document',
      body: body != null ? body : '# Untitled document\n\nStart writing…\n',
      created: Date.now(),
      updated: Date.now()
    };
    var docs = loadDocs();
    docs.unshift(d);
    saveDocs(docs);
    return d;
  }

  function deriveTitle(body) {
    var m = String(body || '').match(/^\s*#{1,6}\s+(.+?)\s*#*\s*$/m);
    if (m) return m[1].replace(/[*_`~]/g, '').trim().slice(0, 80);
    var first = String(body || '').split('\n').find(function (l) { return l.trim(); });
    return (first || 'Untitled document').replace(/[#*_`~>]/g, '').trim().slice(0, 80) || 'Untitled document';
  }

  /* ---- Settings ---- */
  var DEFAULT_SETTINGS = {
    theme: 'dark',        // light | dark | sepia
    focus: false,         // focus / typewriter mode
    syncScroll: true,
    layout: 'split'       // split | editor | preview
  };
  function loadSettings() {
    var s = read(SETTINGS_KEY, {});
    var out = {};
    for (var k in DEFAULT_SETTINGS) out[k] = (s && s[k] != null) ? s[k] : DEFAULT_SETTINGS[k];
    return out;
  }
  function saveSettings(s) { write(SETTINGS_KEY, s); }

  global.InkwellStore = {
    loadDocs: loadDocs,
    saveDocs: saveDocs,
    createDoc: createDoc,
    getActiveId: getActiveId,
    setActiveId: setActiveId,
    deriveTitle: deriveTitle,
    loadSettings: loadSettings,
    saveSettings: saveSettings,
    uid: uid,
    hasLocalStorage: hasLS
  };

})(typeof window !== 'undefined' ? window : this);
