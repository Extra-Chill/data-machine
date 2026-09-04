/* ============================================================
   Keystroke — localStorage layer: settings, results history,
   per-key error tallies, and personal bests.
   ============================================================ */
(function (global) {
  'use strict';

  var KEY_SETTINGS = 'keystroke.settings.v1';
  var KEY_HISTORY = 'keystroke.history.v1';
  var KEY_KEYSTATS = 'keystroke.keystats.v1';

  var DEFAULT_SETTINGS = {
    mode: 'time',          // 'time' | 'words' | 'quote'
    duration: 30,          // seconds for time mode
    wordCount: 25,         // words for words mode
    punctuation: false,
    numbers: false,
    theme: 'midnight',     // midnight | paper | forest | sunset
    sound: false
  };

  function read(key, fallback) {
    try {
      var raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) { return fallback; }
  }
  function write(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ }
  }

  var mem = {}; // in-memory fallback when storage is unavailable

  function getSettings() {
    var s = read(KEY_SETTINGS, null) || mem.settings || {};
    var out = {};
    for (var k in DEFAULT_SETTINGS) { out[k] = (k in s) ? s[k] : DEFAULT_SETTINGS[k]; }
    return out;
  }
  function saveSettings(s) { mem.settings = s; write(KEY_SETTINGS, s); }

  function getHistory() { return read(KEY_HISTORY, null) || mem.history || []; }
  function addResult(r) {
    var h = getHistory();
    h.push(r);
    if (h.length > 200) h = h.slice(h.length - 200);
    mem.history = h;
    write(KEY_HISTORY, h);
    return h;
  }
  function clearHistory() { mem.history = []; write(KEY_HISTORY, []); }

  // Per-key error/total tallies for the problem-key heatmap.
  function getKeyStats() { return read(KEY_KEYSTATS, null) || mem.keystats || {}; }
  function recordKey(char, wasError) {
    if (!/^[a-z0-9]$/i.test(char)) return;
    var c = char.toLowerCase();
    var ks = getKeyStats();
    if (!ks[c]) ks[c] = { total: 0, errors: 0 };
    ks[c].total++;
    if (wasError) ks[c].errors++;
    mem.keystats = ks;
    write(KEY_KEYSTATS, ks);
  }
  function clearKeyStats() { mem.keystats = {}; write(KEY_KEYSTATS, {}); }

  // Best WPM for a given mode signature, considering only completed runs.
  function bestFor(signature) {
    var best = 0;
    getHistory().forEach(function (r) {
      if (r.signature === signature && r.wpm > best) best = r.wpm;
    });
    return best;
  }

  global.KeystrokeStore = {
    getSettings: getSettings, saveSettings: saveSettings,
    getHistory: getHistory, addResult: addResult, clearHistory: clearHistory,
    getKeyStats: getKeyStats, recordKey: recordKey, clearKeyStats: clearKeyStats,
    bestFor: bestFor, DEFAULTS: DEFAULT_SETTINGS
  };
})(window);
