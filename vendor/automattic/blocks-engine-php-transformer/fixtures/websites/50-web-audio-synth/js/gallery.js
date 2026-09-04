/* =========================================================
   VOLTROVE — patches.html gallery renderer
   Builds patch cards (with mini-waveform SVG) and pattern
   cards (with a mini step grid) from the shared data.
   ========================================================= */

(function () {
  'use strict';
  const VP = window.VoltrovePatches;
  const V = window.Voltrove;
  if (!VP) return;

  /* mini waveform preview based on osc A type */
  function wavePath(type, w, h) {
    const mid = h / 2, amp = h * 0.32, pts = [];
    for (let x = 0; x <= w; x += 2) {
      const ph = (x / w) * Math.PI * 4; // 2 cycles
      let y;
      switch (type) {
        case 'square': y = Math.sin(ph) >= 0 ? -1 : 1; break;
        case 'sawtooth': y = ((ph / Math.PI) % 2) - 1; break;
        case 'triangle': y = Math.abs(((ph / Math.PI) % 2) - 1) * 2 - 1; break;
        default: y = -Math.sin(ph);
      }
      pts.push(x + ',' + (mid + y * amp).toFixed(1));
    }
    return 'M' + pts.join(' L');
  }

  function waveSVG(type) {
    const w = 240, h = 60;
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" width="100%" height="100%">' +
      '<path d="' + wavePath(type, w, h) + '" fill="none" stroke="#6cf2c8" stroke-width="2"/></svg>';
  }

  function renderPatches() {
    const wrap = document.getElementById('patch-gallery');
    if (!wrap) return;
    VP.FACTORY_PATCHES.forEach(function (fp) {
      const card = document.createElement('article');
      card.className = 'pcard';
      card.innerHTML =
        '<div class="pcard-wave">' + waveSVG(fp.patch.oscA) + '</div>' +
        '<div><h3>' + fp.name + '</h3><div class="by">by ' + fp.author + '</div></div>' +
        '<div class="tags">' + fp.tags.map(function (t) { return '<span class="tag">' + t + '</span>'; }).join('') + '</div>' +
        '<p>' + fp.blurb + '</p>' +
        '<div class="pcard-actions">' +
          '<button class="btn-audition" data-audition="' + fp.id + '"><span class="ico" aria-hidden="true"></span>Audition</button>' +
          '<a class="btn-load" href="index.html#load=' + fp.id + '">Load in synth →</a>' +
        '</div>';
      wrap.appendChild(card);
    });
  }

  function miniGrid(pattern) {
    const rows = VP.DRUM_ROWS;
    let html = '<div class="mini-grid">';
    rows.forEach(function (r) {
      for (let i = 0; i < 16; i++) {
        const on = pattern.grid[r] && pattern.grid[r][i];
        html += '<span class="mini-cell' + (on ? ' on' : '') + '"></span>';
      }
    });
    html += '</div>';
    return html;
  }

  function renderPatterns() {
    const wrap = document.getElementById('pattern-gallery');
    if (!wrap) return;
    VP.PATTERNS.forEach(function (pat) {
      const notes = (pat.synthLine || []).filter(function (n) { return n != null; }).length;
      const card = document.createElement('article');
      card.className = 'pcard';
      card.innerHTML =
        '<div><h3>' + pat.name + '</h3></div>' +
        miniGrid(pat) +
        '<div class="pattern-meta"><span>Tempo <b>' + pat.bpm + ' BPM</b></span><span>Synth notes <b>' + notes + '</b></span></div>' +
        '<p>' + pat.blurb + '</p>' +
        '<div class="pcard-actions">' +
          '<a class="btn-load" href="index.html#load=' + pat.id + '">Load pattern →</a>' +
        '</div>';
      wrap.appendChild(card);
    });
  }

  renderPatches();
  renderPatterns();
})();
