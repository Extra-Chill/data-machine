/* =========================================================
   FRAGMENT FOUNDRY — Gallery + stage controller (index.html)
   Runs one full-bleed ShaderToy as the hero/stage, lets the
   visitor cycle between the gallery shaders, tweak per-shader
   uniform sliders live, pause/play, go fullscreen, and feed
   the mouse. Selection + settings persist in localStorage.
   ========================================================= */
(function () {
  'use strict';
  var FF = window.FF;

  document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('stage-canvas');
    if (!canvas) return;

    var fallback = document.getElementById('webgl-fallback');
    var errorBar = document.getElementById('stage-error');

    // ── No WebGL? show fallback, animate a CSS/canvas-2d field
    if (!FF.supported()) {
      if (fallback) fallback.hidden = false;
      mount2DFallback(canvas);
      return;
    }

    var reduced = FF.prefersReducedMotion();

    // DOM refs
    var titleEl   = document.getElementById('stage-title');
    var tagEl     = document.getElementById('stage-tag');
    var descEl    = document.getElementById('stage-desc');
    var metaEl    = document.getElementById('stage-meta');
    var fpsEl     = document.getElementById('stage-fps');
    var controlsEl= document.getElementById('stage-controls');
    var playBtn   = document.getElementById('btn-play');
    var resetBtn  = document.getElementById('btn-reset');
    var fsBtn     = document.getElementById('btn-fullscreen');
    var prevBtn   = document.getElementById('btn-prev');
    var nextBtn   = document.getElementById('btn-next');
    var grid      = document.getElementById('shader-grid');
    var stageWrap = document.getElementById('stage');

    var toy = new FF.ShaderToy({
      canvas: canvas,
      onError: function (msg) {
        if (errorBar) {
          errorBar.hidden = false;
          errorBar.textContent = 'Shader error: ' + msg.split('\n')[0];
        }
      },
      onFrame: function (s) {
        if (fpsEl) fpsEl.textContent = s.fps + ' fps';
      }
    });

    // restore last selection + saved uniform values
    var savedId = FF.store.get('lastShader', 'plasma');
    var savedUniforms = FF.store.get('uniforms', {}) || {};
    var current = null;

    function loadShader(shader) {
      current = shader;
      if (errorBar) errorBar.hidden = true;
      toy.clearCustomUniforms();

      // declare custom uniforms (apply saved overrides if present)
      var saved = savedUniforms[shader.id] || {};
      shader.uniforms.forEach(function (u) {
        var val = (saved[u.name] !== undefined) ? saved[u.name] : u.value;
        toy.setUniform(u.name, val, 'float');
      });

      var ok = toy.setShader(shader.source);
      if (!ok) return;

      // header text
      if (titleEl) titleEl.textContent = shader.name;
      if (tagEl)   tagEl.textContent   = shader.tag;
      if (descEl)  descEl.textContent  = shader.desc;
      if (metaEl)  metaEl.textContent  = shader.author + ' · ' + shader.year;

      buildControls(shader, saved);
      markActiveCard(shader.id);

      FF.store.set('lastShader', shader.id);

      if (reduced) {
        toy.renderStill(8.0); // a representative still frame
      }
    }

    function buildControls(shader, saved) {
      if (!controlsEl) return;
      controlsEl.innerHTML = '';
      shader.uniforms.forEach(function (u) {
        var val = (saved[u.name] !== undefined) ? saved[u.name] : u.value;

        var row = document.createElement('div');
        row.className = 'ctrl';

        var lab = document.createElement('label');
        var id = 'ctrl-' + shader.id + '-' + u.name;
        lab.setAttribute('for', id);
        lab.innerHTML = u.label + ' <b>' + fmt(val) + '</b>';

        var input = document.createElement('input');
        input.type = 'range';
        input.id = id;
        input.min = u.min; input.max = u.max; input.step = u.step;
        input.value = val;

        var out = lab.querySelector('b');
        input.addEventListener('input', function () {
          var v = parseFloat(input.value);
          toy.updateUniform(u.name, v);
          out.textContent = fmt(v);
          persistUniform(shader.id, u.name, v);
          if (reduced && !toy.running) toy.renderStill();
        });

        row.appendChild(lab);
        row.appendChild(input);
        controlsEl.appendChild(row);
      });
    }

    function persistUniform(shaderId, name, value) {
      savedUniforms = FF.store.get('uniforms', {}) || {};
      if (!savedUniforms[shaderId]) savedUniforms[shaderId] = {};
      savedUniforms[shaderId][name] = value;
      FF.store.set('uniforms', savedUniforms);
    }

    function fmt(v) {
      return (Math.abs(v) >= 100 || v % 1 === 0) ? String(Math.round(v)) : v.toFixed(2);
    }

    // ── Gallery grid: a small live preview ShaderToy per card
    var previews = [];
    function buildGrid() {
      if (!grid) return;
      FF.SHADERS.forEach(function (shader, i) {
        var card = document.createElement('button');
        card.className = 'shader-card';
        card.type = 'button';
        card.dataset.id = shader.id;
        card.setAttribute('aria-label', 'Load ' + shader.name);

        var pvWrap = document.createElement('div');
        pvWrap.className = 'card-preview';
        var pv = document.createElement('canvas');
        pvWrap.appendChild(pv);

        var body = document.createElement('div');
        body.className = 'card-body';
        body.innerHTML =
          '<span class="card-num">' + String(i + 1).padStart(2, '0') + '</span>' +
          '<h3 class="card-name">' + shader.name + '</h3>' +
          '<span class="card-tag">' + shader.tag + '</span>';

        card.appendChild(pvWrap);
        card.appendChild(body);
        card.addEventListener('click', function () {
          loadShader(shader);
          stageWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        grid.appendChild(card);

        // tiny preview toy (low res, capped)
        var pToy = new FF.ShaderToy({ canvas: pv, pixelRatio: 1 });
        var defs = FF.defaultUniformValues(shader);
        for (var k in defs) pToy.setUniform(k, defs[k], 'float');
        var ok = pToy.setShader(shader.source);
        if (ok) {
          if (reduced) pToy.renderStill(6.0 + i);
          else startWhenVisible(pToy, pvWrap);
        }
        previews.push(pToy);
      });
    }

    // only run a preview while it's on screen (perf)
    function startWhenVisible(pToy, el) {
      if (!('IntersectionObserver' in window)) { pToy.start(); return; }
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) pToy.start(); else pToy.stop();
        });
      }, { threshold: 0.05 });
      io.observe(el);
    }

    function markActiveCard(id) {
      document.querySelectorAll('.shader-card').forEach(function (c) {
        c.classList.toggle('active', c.dataset.id === id);
      });
    }

    function indexOf(id) {
      for (var i = 0; i < FF.SHADERS.length; i++)
        if (FF.SHADERS[i].id === id) return i;
      return 0;
    }
    function cycle(dir) {
      var i = (indexOf(current.id) + dir + FF.SHADERS.length) % FF.SHADERS.length;
      loadShader(FF.SHADERS[i]);
    }

    // ── Controls wiring
    function setPlayLabel() {
      if (!playBtn) return;
      playBtn.textContent = toy.running ? '❚❚ Pause' : '▶ Play';
      playBtn.setAttribute('aria-pressed', toy.running ? 'true' : 'false');
    }
    if (playBtn) playBtn.addEventListener('click', function () {
      toy.toggle(); setPlayLabel();
    });
    if (resetBtn) resetBtn.addEventListener('click', function () {
      toy.reset(); if (!toy.running) toy.renderStill();
    });
    if (prevBtn) prevBtn.addEventListener('click', function () { cycle(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { cycle(1); });
    if (fsBtn) fsBtn.addEventListener('click', function () {
      var el = stageWrap;
      if (!document.fullscreenElement) {
        (el.requestFullscreen || el.webkitRequestFullscreen || function () {}).call(el);
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen || function () {}).call(document);
      }
    });

    // keyboard: space=play/pause, arrows=cycle, f=fullscreen
    document.addEventListener('keydown', function (e) {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
      if (e.code === 'Space') { e.preventDefault(); toy.toggle(); setPlayLabel(); }
      else if (e.code === 'ArrowRight') cycle(1);
      else if (e.code === 'ArrowLeft') cycle(-1);
      else if (e.key === 'f' || e.key === 'F') { if (fsBtn) fsBtn.click(); }
    });

    // mouse feeds u_mouse
    canvas.addEventListener('pointermove', function (e) {
      toy.setMouse(e.clientX, e.clientY);
      if (reduced && !toy.running) toy.renderStill();
    });

    window.addEventListener('beforeunload', function () {
      toy.destroy();
      previews.forEach(function (p) { p.destroy(); });
    });

    // ── boot
    buildGrid();
    loadShader(FF.getShader(savedId));
    if (reduced) {
      // present a still + a play button instead of auto-running
      setPlayLabel();
      if (playBtn) playBtn.textContent = '▶ Play (motion paused)';
    } else {
      toy.start();
      setPlayLabel();
    }
  });

  /* ── 2D canvas fallback when WebGL is unavailable ──────── */
  function mount2DFallback(canvas) {
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    var raf, t = 0;
    function size() {
      canvas.width = canvas.clientWidth;
      canvas.height = canvas.clientHeight;
    }
    size();
    window.addEventListener('resize', size);
    var reduced = FF.prefersReducedMotion();
    function draw() {
      t += 0.012;
      var w = canvas.width, h = canvas.height;
      var img = ctx.createImageData(w, h);
      var d = img.data;
      for (var y = 0; y < h; y += 2) {
        for (var x = 0; x < w; x += 2) {
          var v = Math.sin(x * 0.02 + t) + Math.sin(y * 0.02 - t) +
                  Math.sin((x + y) * 0.015 + t * 1.3);
          var c = Math.floor(128 + v * 60);
          for (var dy = 0; dy < 2; dy++) for (var dx = 0; dx < 2; dx++) {
            var i = ((y + dy) * w + (x + dx)) * 4;
            d[i] = c * 0.4; d[i + 1] = c * 0.8; d[i + 2] = 200; d[i + 3] = 255;
          }
        }
      }
      ctx.putImageData(img, 0, 0);
      if (!reduced) raf = requestAnimationFrame(draw);
    }
    draw();
    window.addEventListener('beforeunload', function () { if (raf) cancelAnimationFrame(raf); });
  }

})();
