/* =========================================================
   RELAY — Storage
   Persists the lab state to localStorage and encodes it in
   the URL hash for shareable links. The hash is the source of
   truth on load (so a shared link wins); otherwise we fall
   back to localStorage, then the bundled default.
   ========================================================= */

(function (global) {
  'use strict';

  var KEY = 'relay.lab.v1';

  function b64encode(obj) {
    var json = JSON.stringify(obj);
    // encodeURIComponent → handle UTF-8 before btoa
    return btoa(unescape(encodeURIComponent(json)));
  }
  function b64decode(str) {
    try {
      return JSON.parse(decodeURIComponent(escape(atob(str))));
    } catch (e) { return null; }
  }

  function saveLocal(state) {
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
  }
  function loadLocal() {
    try {
      var raw = localStorage.getItem(KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function loadHash() {
    var h = global.location.hash.replace(/^#/, '');
    if (!h) return null;
    var params = new URLSearchParams(h);
    if (params.has('s')) {
      var dec = b64decode(params.get('s'));
      if (dec) return dec;
    }
    // also support readable form #p=...&f=gim
    if (params.has('p')) {
      return {
        pattern: params.get('p') || '',
        flags: params.get('f') || '',
        text: params.has('t') ? decodeURIComponent(params.get('t')) : undefined,
        replace: params.has('r') ? params.get('r') : undefined,
        mode: params.get('m') || undefined
      };
    }
    return null;
  }

  function writeHash(state) {
    var payload = {
      pattern: state.pattern, flags: state.flags,
      text: state.text, replace: state.replace, mode: state.mode
    };
    var encoded = '#s=' + b64encode(payload);
    // replaceState avoids spamming history on every keystroke
    try {
      history.replaceState(null, '', global.location.pathname + global.location.search + encoded);
    } catch (e) {
      global.location.hash = encoded;
    }
  }

  function shareURL(state) {
    var payload = {
      pattern: state.pattern, flags: state.flags,
      text: state.text, replace: state.replace, mode: state.mode
    };
    return global.location.origin + global.location.pathname +
           global.location.search + '#s=' + b64encode(payload);
  }

  global.RelayStorage = {
    saveLocal: saveLocal, loadLocal: loadLocal,
    loadHash: loadHash, writeHash: writeHash, shareURL: shareURL
  };

})(typeof window !== 'undefined' ? window : this);
