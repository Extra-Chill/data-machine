/* =========================================================
   FLUXNODE — preview.js
   Renders static thumbnails of seed graphs onto <canvas> elements
   on the examples / about pages, by running the same evaluation
   engine and drawing the Output node's composed sampler.
   ========================================================= */
'use strict';

(function () {
  function renderSeedTo(canvas, seedKey) {
    const doc = Seeds.get(seedKey);
    if (!doc) return;
    const g = Graph.fromJSON(doc);
    const res = g.evaluate();
    const ctx = canvas.getContext('2d');
    // render at the canvas's intrinsic resolution
    renderSampler(ctx, res.output, canvas.width, canvas.height, 0);
  }

  document.querySelectorAll('canvas[data-seed]').forEach(c => {
    // ensure crisp pixel size matches CSS box for nicer output
    if (!c.width) c.width = c.clientWidth || 320;
    if (!c.height) c.height = c.clientHeight || 180;
    try { renderSeedTo(c, c.dataset.seed); } catch (e) { /* ignore */ }
  });

  window.FluxPreview = { renderSeedTo };
})();
