/* =========================================================
   RELAY — Matcher
   Uses the real JS RegExp engine to find all matches in the
   test text, with a wall-clock guard against catastrophic
   backtracking. Returns structured match objects with
   capture-group ranges resolved to absolute string offsets.
   ========================================================= */

(function (global) {
  'use strict';

  var BUDGET_MS = 250; // soft budget per run

  /* Build a RegExp; throws SyntaxError on bad pattern. */
  function build(pattern, flags) {
    // Always include 'g' internally for iteration but report user flags separately.
    var f = flags.indexOf('g') === -1 ? flags + 'g' : flags;
    // 'd' (indices) is great when supported; fall back gracefully.
    var withD = f.indexOf('d') === -1 ? f + 'd' : f;
    try {
      return { re: new RegExp(pattern, withD), hasIndices: true, flags: f };
    } catch (e) {
      // 'd' not supported in this engine — retry without it.
      return { re: new RegExp(pattern, f), hasIndices: false, flags: f };
    }
  }

  /*
    Returns:
      { ok:true, matches:[ {index, end, full, groups:[{name,num,value,start,end}] } ],
        truncated, ms }
    or
      { ok:false, error: "message" }
  */
  function run(pattern, flags, text) {
    var built;
    try {
      // validate pattern with the user's exact flags first (so flag errors surface)
      new RegExp(pattern, flags);
      built = build(pattern, flags);
    } catch (e) {
      return { ok: false, error: e.message };
    }

    var re = built.re;
    var matches = [];
    var t0 = (global.performance && performance.now) ? performance.now() : Date.now();
    var now = function () { return (global.performance && performance.now) ? performance.now() : Date.now(); };
    var truncated = false;
    var guard = false;
    var m, last = -1, iterations = 0;

    try {
      while (true) {
        // Time-check BEFORE each exec. A single exec() can backtrack
        // for a long time and is not interruptible from JS, but this
        // catches runaway many-match loops between calls.
        if (now() - t0 > BUDGET_MS) { guard = true; break; }
        m = re.exec(text);
        if (m === null) break;
        iterations++;

        var groups = [];
        var indices = built.hasIndices ? m.indices : null;
        for (var i = 1; i < m.length; i++) {
          var val = m[i];
          var rng = indices ? indices[i] : null;
          groups.push({
            num: i,
            name: groupNameFor(m, indices, i),
            value: val === undefined ? null : val,
            start: rng ? rng[0] : null,
            end: rng ? rng[1] : null
          });
        }

        matches.push({
          index: m.index,
          end: m.index + m[0].length,
          full: m[0],
          groups: groups
        });

        // zero-width guard — advance lastIndex to avoid infinite loop
        if (m.index === re.lastIndex) re.lastIndex++;
        if (m.index === last && m[0] === '') { /* still progress via bump above */ }
        last = m.index;

        if (re.flags.indexOf('g') === -1) break; // shouldn't happen, we force g
        if (matches.length > 20000) { truncated = true; break; }
      }
    } catch (e) {
      return { ok: false, error: e.message };
    }

    return {
      ok: true,
      matches: matches,
      truncated: truncated,
      guarded: guard,
      hasIndices: built.hasIndices,
      ms: Math.round((now() - t0) * 100) / 100
    };
  }

  function groupNameFor(m, indices, i) {
    if (!m.groups) return null;
    // map named groups to their numeric index via indices.groups when present
    if (indices && indices.groups) {
      for (var name in indices.groups) {
        var rng = indices.groups[name];
        if (rng && indices[i] && rng[0] === indices[i][0] && rng[1] === indices[i][1]) return name;
      }
    }
    // fallback: match by value (less precise but workable)
    for (var n in m.groups) {
      if (m.groups[n] === m[i] && m.groups[n] !== undefined) return n;
    }
    return null;
  }

  /* Replace / split helpers --------------------------------- */
  function replace(pattern, flags, text, replacement) {
    try {
      var re = new RegExp(pattern, flags);
      return { ok: true, value: text.replace(re, replacement) };
    } catch (e) {
      return { ok: false, error: e.message };
    }
  }

  function split(pattern, flags, text, limit) {
    try {
      var f = flags.replace(/g/g, ''); // split ignores g anyway
      var re = new RegExp(pattern, f);
      var parts = (limit && limit > 0) ? text.split(re, limit) : text.split(re);
      return { ok: true, parts: parts };
    } catch (e) {
      return { ok: false, error: e.message };
    }
  }

  global.RelayMatcher = { run: run, replace: replace, split: split, BUDGET_MS: BUDGET_MS };

})(typeof window !== 'undefined' ? window : this);
