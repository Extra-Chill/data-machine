/* storage.js — persistence for Lumen Leap.
   Wraps localStorage so progress (unlocked levels, best times, best lumen
   counts, totals, mute preference) survives reloads. All access is namespaced
   under a single key so it is easy to reason about and reset. */
(function () {
  'use strict';

  const KEY = 'lumenleap.save.v1';

  const DEFAULTS = {
    unlocked: 1,        // highest level index unlocked (1-based)
    completed: [],      // array of completed level ids
    best: {},           // { levelId: { timeMs, lumens, stars } }
    totalLumens: 0,     // lifetime lumens collected
    muted: false,
    reducedMotion: null, // null = follow system; true/false = explicit override
    seenIntro: false
  };

  function read() {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return { ...DEFAULTS, best: {}, completed: [] };
      const parsed = JSON.parse(raw);
      return {
        ...DEFAULTS,
        ...parsed,
        best: parsed.best || {},
        completed: parsed.completed || []
      };
    } catch (e) {
      return { ...DEFAULTS, best: {}, completed: [] };
    }
  }

  function write(state) {
    try {
      localStorage.setItem(KEY, JSON.stringify(state));
    } catch (e) { /* storage may be unavailable under file:// in some setups */ }
  }

  const Save = {
    get all() { return read(); },

    get(field) { return read()[field]; },

    set(field, value) {
      const s = read();
      s[field] = value;
      write(s);
      return s;
    },

    isUnlocked(levelIndex /* 1-based */) {
      return levelIndex <= read().unlocked;
    },

    unlockUpTo(levelIndex) {
      const s = read();
      if (levelIndex > s.unlocked) {
        s.unlocked = levelIndex;
        write(s);
      }
      return s.unlocked;
    },

    /* Record a level result. Returns { newBest, prev }. */
    recordResult(levelId, { timeMs, lumens, maxLumens }) {
      const s = read();
      if (!s.completed.includes(levelId)) s.completed.push(levelId);
      const prev = s.best[levelId] || null;
      const stars = computeStars(timeMs, lumens, maxLumens);
      let newBest = false;
      if (!prev || lumens > prev.lumens ||
          (lumens === prev.lumens && timeMs < prev.timeMs)) {
        s.best[levelId] = { timeMs, lumens, stars: Math.max(stars, prev ? prev.stars : 0) };
        newBest = true;
      } else if (prev && stars > prev.stars) {
        s.best[levelId].stars = stars;
      }
      s.totalLumens += lumens;
      write(s);
      return { newBest, prev, stars };
    },

    best(levelId) { return read().best[levelId] || null; },

    reset() { write({ ...DEFAULTS, best: {}, completed: [] }); }
  };

  function computeStars(timeMs, lumens, maxLumens) {
    let stars = 1; // finishing earns one
    if (maxLumens > 0 && lumens >= maxLumens) stars++;        // all lumens
    if (timeMs <= (maxLumens > 0 ? 45000 : 45000)) stars++;   // quick clear
    return Math.min(stars, 3);
  }

  window.LumenSave = Save;
})();
