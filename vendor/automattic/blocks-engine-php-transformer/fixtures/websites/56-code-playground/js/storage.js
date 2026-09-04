/* =========================================================
   FORKBENCH — Persistence & sharing
   - autosave to localStorage
   - encode/decode a pen into the URL hash (base64 of JSON,
     UTF-8 safe) so pens can be "shared" as a link
   - load a starter template from #t=<id>
   ========================================================= */
(function (global) {
  'use strict';

  var KEY = 'forkbench:pen:v1';

  /* UTF-8 safe base64 (handles emoji / accented chars) */
  function b64encode(str) {
    return btoa(unescape(encodeURIComponent(str)));
  }
  function b64decode(str) {
    return decodeURIComponent(escape(atob(str)));
  }

  var Storage = {
    save: function (pen) {
      try { localStorage.setItem(KEY, JSON.stringify(pen)); return true; }
      catch (e) { return false; }
    },

    load: function () {
      try {
        var raw = localStorage.getItem(KEY);
        return raw ? JSON.parse(raw) : null;
      } catch (e) { return null; }
    },

    clear: function () {
      try { localStorage.removeItem(KEY); } catch (e) {}
    },

    /* ---- URL hash sharing ---- */
    encodeHash: function (pen) {
      var payload = {
        t: pen.title || 'Untitled pen',
        h: pen.html || '',
        c: pen.css || '',
        j: pen.js || ''
      };
      return 'pen=' + b64encode(JSON.stringify(payload));
    },

    /* Returns { type:'pen'|'template'|null, pen?, id? } */
    readHash: function () {
      var hash = global.location.hash.replace(/^#/, '');
      if (!hash) return { type: null };
      var params = {};
      hash.split('&').forEach(function (p) {
        var idx = p.indexOf('=');
        if (idx === -1) return;
        params[p.slice(0, idx)] = p.slice(idx + 1);
      });
      if (params.t) return { type: 'template', id: decodeURIComponent(params.t) };
      if (params.pen) {
        try {
          var obj = JSON.parse(b64decode(params.pen));
          return {
            type: 'pen',
            pen: { title: obj.t, html: obj.h, css: obj.c, js: obj.j }
          };
        } catch (e) { return { type: null }; }
      }
      return { type: null };
    },

    setHash: function (str) {
      // replaceState so we don't spam browser history
      try {
        global.history.replaceState(null, '', '#' + str);
      } catch (e) {
        global.location.hash = str;
      }
    }
  };

  global.ForkbenchStorage = Storage;
})(window);
