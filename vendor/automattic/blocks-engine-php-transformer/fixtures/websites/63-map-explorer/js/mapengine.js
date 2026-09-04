/* =========================================================
   VAELORA ATLAS — Map Engine
   A slippy-map style pan/zoom camera over an SVG viewport.

   Model:
   - The world is a fixed 1000x700 coordinate space (VAELORA.WORLD).
   - The camera is described by a "view" rectangle in world units
     { x, y, w, h }. The SVG <svg> uses this as its viewBox, so the
     browser maps world coords -> screen for us. This keeps pan/zoom
     math exact and hit-testing trivial (we invert viewBox manually).
   - Zoom level z = WORLD.w / view.w  (z=1 means whole world fits the
     view width). We clamp z to [minZ, maxZ] and clamp the view to
     stay within a padded world rectangle.
   ========================================================= */
(function () {
  'use strict';

  function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }

  function MapEngine(svgEl, opts) {
    opts = opts || {};
    const WORLD = window.VAELORA.WORLD;
    const PAD = opts.pad != null ? opts.pad : 80;   // world units of overscroll
    const minZ = opts.minZ || 0.85;                  // can't zoom out past ~world
    const maxZ = opts.maxZ || 14;                    // deep zoom

    // bounded world (with padding) the view must stay inside
    const BOUND = { x: WORLD.x - PAD, y: WORLD.y - PAD, w: WORLD.w + 2 * PAD, h: WORLD.h + 2 * PAD };

    const aspect = () => {
      const r = svgEl.getBoundingClientRect();
      return r.height > 0 ? r.width / r.height : WORLD.w / WORLD.h;
    };

    // current view rect in world units
    let view = fitView();
    const listeners = {};

    function emit(name, payload) { (listeners[name] || []).forEach(fn => fn(payload)); }
    function on(name, fn) { (listeners[name] = listeners[name] || []).push(fn); return () => off(name, fn); }
    function off(name, fn) { listeners[name] = (listeners[name] || []).filter(f => f !== fn); }

    function zoomLevel() { return WORLD.w / view.w; }

    /* ---- compute the "zoom to fit whole world" view, honouring aspect ---- */
    function fitView() {
      const a = aspect();
      // Fit WORLD into the element while preserving the element's aspect ratio.
      let w = WORLD.w, h = WORLD.w / a;
      if (h < WORLD.h) { h = WORLD.h; w = WORLD.h * a; }
      // center on world center
      return {
        x: WORLD.x + WORLD.w / 2 - w / 2,
        y: WORLD.y + WORLD.h / 2 - h / 2,
        w, h
      };
    }

    /* ---- enforce zoom clamp + keep view inside BOUND ---- */
    function constrain(v) {
      const a = aspect();
      // keep correct aspect (height follows width)
      v.h = v.w / a;

      // clamp zoom: view.w must be within [WORLD.w/maxZ, WORLD.w/minZ]
      const minW = WORLD.w / maxZ;
      const maxW = WORLD.w / minZ;
      if (v.w < minW) { v.w = minW; v.h = v.w / a; }
      if (v.w > maxW) { v.w = maxW; v.h = v.w / a; }

      // clamp position to BOUND; if the view is larger than BOUND on an
      // axis, center it on that axis.
      if (v.w >= BOUND.w) v.x = BOUND.x + (BOUND.w - v.w) / 2;
      else v.x = clamp(v.x, BOUND.x, BOUND.x + BOUND.w - v.w);

      if (v.h >= BOUND.h) v.y = BOUND.y + (BOUND.h - v.h) / 2;
      else v.y = clamp(v.y, BOUND.y, BOUND.y + BOUND.h - v.h);

      return v;
    }

    function apply(emitName) {
      constrain(view);
      svgEl.setAttribute('viewBox', `${view.x} ${view.y} ${view.w} ${view.h}`);
      emit('view', { view: getView(), zoom: zoomLevel() });
      if (emitName) emit(emitName, { view: getView(), zoom: zoomLevel() });
    }

    function getView() { return { x: view.x, y: view.y, w: view.w, h: view.h }; }

    /* ---- screen <-> world conversions (we invert the viewBox ourselves
            so hit-testing works even mid-CSS-transform) ---- */
    function screenToWorld(clientX, clientY) {
      const r = svgEl.getBoundingClientRect();
      const fx = (clientX - r.left) / r.width;
      const fy = (clientY - r.top) / r.height;
      return { x: view.x + fx * view.w, y: view.y + fy * view.h };
    }
    function worldToScreen(wx, wy) {
      const r = svgEl.getBoundingClientRect();
      return {
        x: r.left + ((wx - view.x) / view.w) * r.width,
        y: r.top + ((wy - view.y) / view.h) * r.height
      };
    }

    /* ---- zoom centred on a world point (zoom-to-cursor) ---- */
    function zoomAt(worldPt, factor, opt) {
      const a = aspect();
      let newW = view.w / factor;
      const minW = WORLD.w / maxZ, maxW = WORLD.w / minZ;
      newW = clamp(newW, minW, maxW);
      const newH = newW / a;
      // keep worldPt under the same fractional position
      const fx = view.w !== 0 ? (worldPt.x - view.x) / view.w : 0.5;
      const fy = view.h !== 0 ? (worldPt.y - view.y) / view.h : 0.5;
      view = { x: worldPt.x - fx * newW, y: worldPt.y - fy * newH, w: newW, h: newH };
      apply((opt && opt.silent) ? null : 'zoom');
    }

    function panByScreen(dxPx, dyPx) {
      const r = svgEl.getBoundingClientRect();
      view.x -= (dxPx / r.width) * view.w;
      view.y -= (dyPx / r.height) * view.h;
      apply('pan');
    }

    /* ---- animated fly-to a world rect or point ---- */
    let raf = null;
    function cancelAnim() { if (raf) { cancelAnimationFrame(raf); raf = null; } }

    function flyToRect(rect, opt) {
      opt = opt || {};
      cancelAnim();
      const a = aspect();
      // expand rect by margin and to the view aspect
      const margin = opt.margin != null ? opt.margin : 0.35;
      let w = rect.w * (1 + margin), h = rect.h * (1 + margin);
      if (w / h < a) w = h * a; else h = w / a;
      const cx = rect.x + rect.w / 2, cy = rect.y + rect.h / 2;
      let target = { x: cx - w / 2, y: cy - h / 2, w, h };
      // constrain a *copy* so we animate toward the legal destination
      target = constrain(target);

      animateTo(target, opt.duration || 620);
    }

    function flyToPoint(wx, wy, zoom, opt) {
      opt = opt || {};
      const a = aspect();
      const z = clamp(zoom || 6, minZ, maxZ);
      const w = WORLD.w / z, h = w / a;
      let target = constrain({ x: wx - w / 2, y: wy - h / 2, w, h });
      animateTo(target, opt.duration || 620);
    }

    function animateTo(target, dur) {
      cancelAnim();
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce || dur <= 0) { view = target; apply('flyend'); return; }
      const start = getView(), t0 = performance.now();
      // ease in/out cubic
      const ease = t => t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
      (function step(now) {
        const t = clamp((now - t0) / dur, 0, 1);
        const e = ease(t);
        view = {
          x: start.x + (target.x - start.x) * e,
          y: start.y + (target.y - start.y) * e,
          w: start.w + (target.w - start.w) * e,
          h: start.h + (target.h - start.h) * e
        };
        apply(null);
        if (t < 1) raf = requestAnimationFrame(step);
        else { raf = null; emit('flyend', { view: getView(), zoom: zoomLevel() }); }
      })(performance.now());
    }

    function reset(animated) {
      cancelAnim();
      const target = fitView();
      if (animated) animateTo(target, 520);
      else { view = target; apply('reset'); }
    }

    function setView(v, silent) {
      view = { x: v.x, y: v.y, w: v.w, h: v.h };
      apply(silent ? null : 'set');
    }

    /* ---- input wiring (pointer drag, wheel, pinch, dblclick) ---- */
    let dragging = false, lastX = 0, lastY = 0, moved = 0;
    const pointers = new Map();
    let pinchDist = 0, pinchMid = null;

    function onPointerDown(e) {
      cancelAnim();
      svgEl.setPointerCapture && svgEl.setPointerCapture(e.pointerId);
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pointers.size === 1) {
        dragging = true; moved = 0; lastX = e.clientX; lastY = e.clientY;
        svgEl.classList.add('grabbing');
      } else if (pointers.size === 2) {
        dragging = false;
        const pts = [...pointers.values()];
        pinchDist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        pinchMid = { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 };
      }
    }
    function onPointerMove(e) {
      if (!pointers.has(e.pointerId)) {
        emit('hover', screenToWorld(e.clientX, e.clientY));
        return;
      }
      pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

      if (pointers.size >= 2) {
        const pts = [...pointers.values()];
        const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        const mid = { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 };
        if (pinchDist > 0) {
          const wpt = screenToWorld(mid.x, mid.y);
          zoomAt(wpt, dist / pinchDist, { silent: false });
        }
        pinchDist = dist; pinchMid = mid;
        return;
      }
      if (dragging) {
        const dx = e.clientX - lastX, dy = e.clientY - lastY;
        moved += Math.abs(dx) + Math.abs(dy);
        lastX = e.clientX; lastY = e.clientY;
        panByScreen(dx, dy);
      }
    }
    function onPointerUp(e) {
      const wasDragging = dragging;
      pointers.delete(e.pointerId);
      if (pointers.size < 2) pinchDist = 0;
      if (pointers.size === 0) {
        dragging = false;
        svgEl.classList.remove('grabbing');
        // treat as click if barely moved
        if (wasDragging && moved < 6) {
          emit('tap', screenToWorld(e.clientX, e.clientY));
        }
      }
    }
    function onWheel(e) {
      e.preventDefault();
      cancelAnim();
      const wpt = screenToWorld(e.clientX, e.clientY);
      // normalise deltas; trackpads send many small events
      const intensity = e.deltaMode === 1 ? 0.06 : 0.0016; // line vs pixel
      const factor = Math.exp(-e.deltaY * intensity);
      zoomAt(wpt, factor);
    }
    function onDblClick(e) {
      e.preventDefault();
      const wpt = screenToWorld(e.clientX, e.clientY);
      zoomAt(wpt, e.shiftKey ? 1 / 2 : 2);
    }

    svgEl.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);
    svgEl.addEventListener('wheel', onWheel, { passive: false });
    svgEl.addEventListener('dblclick', onDblClick);
    // keep hover tooltips even when not capturing on the svg itself
    svgEl.addEventListener('pointermove', e => { if (!pointers.has(e.pointerId)) emit('hover', screenToWorld(e.clientX, e.clientY)); });
    svgEl.addEventListener('pointerleave', () => emit('hoverout'));

    // re-fit aspect on resize without losing the centre
    let resizeRAF = null;
    window.addEventListener('resize', () => {
      if (resizeRAF) cancelAnimationFrame(resizeRAF);
      resizeRAF = requestAnimationFrame(() => { apply(null); });
    });

    // keyboard panning/zooming (engine-level; UI layer focuses the svg)
    function nudge(dxFrac, dyFrac) {
      view.x += dxFrac * view.w; view.y += dyFrac * view.h; apply('pan');
    }

    // initial paint
    apply(null);

    return {
      on, off,
      getView, setView, zoomLevel,
      screenToWorld, worldToScreen,
      zoomAt, panByScreen, nudge,
      flyToRect, flyToPoint, reset,
      WORLD, BOUND, minZ, maxZ,
      get view() { return getView(); }
    };
  }

  window.MapEngine = MapEngine;
})();
