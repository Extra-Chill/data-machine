/* =========================================================
   FORKBENCH — Templates gallery renderer (templates.html)
   Renders the starter templates from templates-data.js into
   cards. Each card links to index.html#t=<id> so the editor
   loads that template, and shows a tiny generated SVG preview.
   ========================================================= */
(function () {
  'use strict';

  // Small distinctive SVG "thumbnail" per template id so each
  // card has a real, on-brand visual (no remote images).
  var THUMBS = {
    starter: '<svg viewBox="0 0 300 150"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1d2b53"/><stop offset="1" stop-color="#0b0e1a"/></linearGradient></defs><rect width="300" height="150" fill="url(#g1)"/><rect x="95" y="40" width="110" height="70" rx="10" fill="rgba(255,255,255,.05)" stroke="rgba(255,255,255,.14)"/><rect x="108" y="55" width="60" height="8" rx="4" fill="#6cf2c8"/><rect x="108" y="72" width="84" height="5" rx="2" fill="#9fa6c0"/><rect x="108" y="88" width="46" height="14" rx="7" fill="#6cf2c8"/></svg>',
    aurora: '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#04060f"/><ellipse cx="90" cy="60" rx="120" ry="70" fill="#0ff" opacity="0.25"/><ellipse cx="210" cy="100" rx="120" ry="70" fill="#6cf2c8" opacity="0.3"/><ellipse cx="150" cy="40" rx="90" ry="50" fill="#f0f" opacity="0.2"/><text x="150" y="82" fill="#dff" font-family="monospace" font-size="14" letter-spacing="4" text-anchor="middle">aurora</text></svg>',
    'canvas-orbits': '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#05060c"/><g><circle cx="150" cy="75" r="3" fill="hsl(20,90%,65%)"/><circle cx="210" cy="75" r="3" fill="hsl(80,90%,65%)"/><circle cx="90" cy="75" r="3" fill="hsl(200,90%,65%)"/><circle cx="150" cy="40" r="3" fill="hsl(300,90%,65%)"/><circle cx="150" cy="110" r="3" fill="hsl(140,90%,65%)"/><ellipse cx="150" cy="75" rx="60" ry="35" fill="none" stroke="rgba(255,255,255,.08)"/><ellipse cx="150" cy="75" rx="100" ry="55" fill="none" stroke="rgba(255,255,255,.06)"/></g></svg>',
    todo: '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#0e1018"/><rect x="70" y="28" width="160" height="20" rx="6" fill="#161a26" stroke="#2a2e40"/><rect x="195" y="32" width="30" height="12" rx="6" fill="#6cf2c8"/><rect x="70" y="58" width="160" height="22" rx="6" fill="#161a26"/><circle cx="84" cy="69" r="5" fill="none" stroke="#6cf2c8"/><line x1="81" y1="69" x2="87" y2="69" stroke="#6cf2c8"/><rect x="98" y="65" width="80" height="6" rx="3" fill="#555c74"/><rect x="70" y="86" width="160" height="22" rx="6" fill="#161a26"/><rect x="98" y="93" width="100" height="6" rx="3" fill="#9fa6c0"/></svg>',
    clock: '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#0b0d16"/><circle cx="150" cy="72" r="50" fill="#11141f" stroke="#2b3147" stroke-width="2"/><line x1="150" y1="72" x2="150" y2="40" stroke="#e8eaf2" stroke-width="3" stroke-linecap="round"/><line x1="150" y1="72" x2="178" y2="84" stroke="#cdd2e6" stroke-width="2" stroke-linecap="round"/><line x1="150" y1="72" x2="132" y2="100" stroke="#6cf2c8" stroke-width="1.5" stroke-linecap="round"/><circle cx="150" cy="72" r="3" fill="#6cf2c8"/></svg>',
    errors: '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#0c0e18"/><rect x="40" y="30" width="220" height="18" rx="4" fill="#161a26"/><text x="48" y="43" fill="#9fa6c0" font-family="monospace" font-size="10">› plain log</text><rect x="40" y="54" width="220" height="18" rx="4" fill="rgba(255,209,102,.08)"/><text x="48" y="67" fill="#ffd166" font-family="monospace" font-size="10">▲ a warning</text><rect x="40" y="78" width="220" height="18" rx="4" fill="rgba(255,122,144,.1)"/><text x="48" y="91" fill="#ff7a90" font-family="monospace" font-size="10">✕ Uncaught SyntaxError</text></svg>'
  };

  function render() {
    var grid = document.getElementById('tpl-grid');
    if (!grid || !window.FORKBENCH_TEMPLATES) return;
    var html = window.FORKBENCH_TEMPLATES.map(function (t) {
      var tags = t.tags.map(function (tag) {
        return '<span class="tpl-tag">' + tag + '</span>';
      }).join('');
      var thumb = THUMBS[t.id] || '<svg viewBox="0 0 300 150"><rect width="300" height="150" fill="#12151f"/></svg>';
      return (
        '<article class="tpl-card">' +
          '<a class="tpl-preview" href="index.html#t=' + t.id + '" aria-label="Open ' + t.title + '">' + thumb + '</a>' +
          '<div class="tpl-body">' +
            '<h3 class="tpl-title">' + t.title + '</h3>' +
            '<p class="tpl-blurb">' + t.blurb + '</p>' +
            '<div class="tpl-tags">' + tags + '</div>' +
            '<a class="tpl-open" href="index.html#t=' + t.id + '">Open in editor →</a>' +
          '</div>' +
        '</article>'
      );
    }).join('');
    grid.innerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else { render(); }
})();
