/* =========================================================
   RELAY — Highlight overlay
   Given the test text and the matcher's match list, build an
   HTML string of <mark> spans that overlay the textarea. The
   overlay div sits behind a transparent-text textarea and is
   kept in perfect sync (same font/metrics/scroll).

   Handles:
     - alternating match colors (.m0 / .m1)
     - capture-group sub-spans nested inside each match
     - zero-width matches (rendered as a caret marker)
     - multiline text (newlines preserved; spans don't cross
       structural boundaries because we slice on offsets)
   ========================================================= */

(function (global) {
  'use strict';

  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /*
    Build event list of boundaries so overlapping group ranges
    nest correctly. We render each match container, and within
    it lay out capture groups as separate styled segments.
  */
  function buildOverlay(text, matches, hasIndices) {
    if (!matches.length) return esc(text) + '​';

    var out = [];
    var cursor = 0;

    matches.forEach(function (m, mi) {
      if (m.index > cursor) out.push(esc(text.slice(cursor, m.index)));

      var cls = 'hl m' + (mi % 2);
      if (m.full === '') {
        // zero-width match → caret marker, do not consume text
        out.push('<span class="hl zerowidth" data-mi="' + mi + '" title="zero-width match #' + mi + '">​</span>');
        cursor = Math.max(cursor, m.index);
        return;
      }

      out.push('<span class="' + cls + '" data-mi="' + mi + '">');

      if (hasIndices && hasResolvedGroups(m)) {
        out.push(renderWithGroups(text, m));
      } else {
        out.push(esc(text.slice(m.index, m.end)));
      }

      out.push('</span>');
      cursor = m.end;
    });

    if (cursor < text.length) out.push(esc(text.slice(cursor)));
    // trailing zero-width char keeps overlay height in sync with empty last line
    out.push('​');
    return out.join('');
  }

  function hasResolvedGroups(m) {
    return m.groups.some(function (g) { return g.start != null && g.end != null && g.value != null; });
  }

  /* Render a match's interior, wrapping resolved capture-group
     ranges in their own colored sub-spans. Groups can nest; we
     handle that by a simple boundary sweep. */
  function renderWithGroups(text, m) {
    var ranges = m.groups
      .filter(function (g) { return g.start != null && g.end != null && g.end > g.start; })
      .map(function (g) { return { start: g.start, end: g.end, num: g.num, name: g.name }; })
      .sort(function (a, b) { return a.start - b.start || b.end - a.end; });

    if (!ranges.length) return esc(text.slice(m.index, m.end));

    // Non-nesting flat sweep: pick outermost non-overlapping groups for coloring.
    var picked = [];
    var lastEnd = -1;
    ranges.forEach(function (r) {
      if (r.start >= lastEnd) { picked.push(r); lastEnd = r.end; }
    });

    var html = [];
    var pos = m.index;
    picked.forEach(function (r) {
      if (r.start > pos) html.push(esc(text.slice(pos, r.start)));
      var gi = ((r.num - 1) % 4);
      var label = r.name ? '‹' + r.name + '›' : '$' + r.num;
      html.push('<span class="hl-g g' + gi + '" data-g="' + r.num + '" title="group ' + label + '">' +
                esc(text.slice(r.start, r.end)) + '</span>');
      pos = r.end;
    });
    if (pos < m.end) html.push(esc(text.slice(pos, m.end)));
    return html.join('');
  }

  global.RelayHighlight = { buildOverlay: buildOverlay, esc: esc };

})(typeof window !== 'undefined' ? window : this);
