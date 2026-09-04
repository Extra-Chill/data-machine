/* =========================================================
   BOUNDLESS — preview.js
   Renders thumbnail previews of each template on templates.html by
   reusing the real Shapes renderer, fit into a small canvas.
   ========================================================= */
'use strict';

(function () {
  function resolveConnectors(shapes) {
    const byId = id => shapes.find(s => s.id === id);
    for (const s of shapes) {
      if (s.type !== 'connector') continue;
      const A = s.from ? byId(s.from) : null, B = s.to ? byId(s.to) : null;
      const ca = A ? Geo.rectCenter(Shapes.bounds(A)) : { x: s.x1, y: s.y1 };
      const cb = B ? Geo.rectCenter(Shapes.bounds(B)) : { x: s.x2, y: s.y2 };
      s._a = A ? Geo.rectBorderPoint(Shapes.bounds(A), cb.x, cb.y) : ca;
      s._b = B ? Geo.rectBorderPoint(Shapes.bounds(B), ca.x, ca.y) : cb;
    }
  }

  function renderPreview(canvas, doc) {
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const r = canvas.getBoundingClientRect();
    canvas.width = Math.round(r.width * dpr);
    canvas.height = Math.round(r.height * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#f6f8fc';
    ctx.fillRect(0, 0, r.width, r.height);

    const shapes = JSON.parse(JSON.stringify(doc.shapes));
    resolveConnectors(shapes);
    const bb = Geo.unionRects(shapes.map(Shapes.bounds));
    if (!bb) return;
    const pad = 22;
    const s = Math.min((r.width - pad * 2) / bb.w, (r.height - pad * 2) / bb.h);
    const ox = (r.width - bb.w * s) / 2 - bb.x * s;
    const oy = (r.height - bb.h * s) / 2 - bb.y * s;
    ctx.setTransform(dpr * s, 0, 0, dpr * s, dpr * ox, dpr * oy);
    for (const sh of shapes) Shapes.draw(ctx, sh, s);
  }

  function init() {
    document.querySelectorAll('[data-template]').forEach(card => {
      const key = card.dataset.template;
      const tpl = Seed.templates[key];
      if (!tpl) return;
      const cv = card.querySelector('canvas.tpl-preview');
      if (cv) {
        const doc = tpl.build();
        const draw = () => renderPreview(cv, doc);
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
        draw();
      }
    });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
  window.addEventListener('resize', () => {
    document.querySelectorAll('[data-template] canvas.tpl-preview').forEach(cv => {
      const key = cv.closest('[data-template]').dataset.template;
      const tpl = Seed.templates[key];
      if (tpl) renderPreview(cv, tpl.build());
    });
  });
})();
